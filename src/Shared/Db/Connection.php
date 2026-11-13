<?php

declare(strict_types=1);

namespace App\Shared\Db;

use Closure;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Thin PDO wrapper with convenience helpers for prepared queries.
 * Always uses prepared statements (PDO::ATTR_EMULATE_PREPARES = false).
 *
 * In a long-lived worker the database can disappear under a live process
 * (restart, failover, a package upgrade restarting PostgreSQL). With a
 * factory the wrapper reconnects, but only at a safe boundary: recover() is
 * called by the worker before a request, never in the middle of one. A failed
 * statement is never replayed, because the outcome of a write cut off by a
 * lost connection is unknown; the request fails and the next one starts on a
 * working connection.
 */
final class Connection
{
    private const RETRY_AFTER_FAILURE = 2.0;

    private const LOG_EVERY = 10.0;

    private ?PDO $handle;

    private bool $broken = false;

    /** When opening last failed, to avoid paying the connect timeout again and again. */
    private ?float $failedAt = null;

    private float $lastLogAt = 0.0;

    /**
     * @param (Closure(): PDO)|null $factory when given, the connection is
     *        opened lazily and can be replaced by recover()
     */
    public function __construct(?PDO $pdo = null, private readonly ?Closure $factory = null)
    {
        if ($pdo === null && $factory === null) {
            throw new \InvalidArgumentException('Connection needs a PDO or a factory');
        }
        $this->handle = $pdo;
    }

    /** The live PDO. Opens it on first use when a factory was given. */
    public PDO $pdo {
        get => $this->handle ??= $this->open();
    }

    /**
     * Make the connection usable before a unit of work. Replaces it when it
     * was marked broken, is stuck inside an unfinished transaction, or fails a
     * ping. Returns false when no working connection could be had; the caller
     * keeps serving and the first query reports the real error.
     */
    public function recover(): bool
    {
        if ($this->factory === null) {
            return true;
        }
        if ($this->handle === null && !$this->broken) {
            return true; // never connected: the first query connects lazily
        }
        if ($this->handle !== null && !$this->broken && $this->isUsable($this->handle)) {
            return true;
        }
        $reconnect = $this->handle !== null;
        $this->handle = null; // closes the old socket; the server rolls back what was open
        try {
            $this->handle = $this->open();
        } catch (PDOException $e) {
            $this->broken = true;
            $this->logThrottled('slimtds: database reconnect failed: ' . $e->getMessage());
            return false;
        }
        if ($reconnect) {
            error_log('slimtds: database connection replaced');
        }
        return true;
    }

    /**
     * Whether a query goes through on the connection this process really uses,
     * without replacing it. In the worker the outermost middleware has already
     * healed the connection before any route runs, so a route that asks sees a
     * live one unless healing failed.
     */
    public function isAlive(): bool
    {
        if ($this->handle === null) {
            return false;
        }
        return !$this->broken && $this->isUsable($this->handle);
    }

    private function open(): PDO
    {
        if ($this->factory === null) {
            throw new \LogicException('Connection was closed and has no factory to reopen it');
        }
        // A server that drops packets costs the connect timeout per attempt, and one
        // request can touch the connection many times: remember a fresh failure.
        if ($this->failedAt !== null && microtime(true) - $this->failedAt < self::RETRY_AFTER_FAILURE) {
            $e = new PDOException('database unavailable: connect failed moments ago');
            $e->errorInfo = ['08006', 7, 'connect failed moments ago'];
            throw $e;
        }
        try {
            $pdo = ($this->factory)();
        } catch (PDOException $e) {
            $this->failedAt = microtime(true);
            throw $e;
        }
        $this->failedAt = null;
        $this->broken = false;
        return $pdo;
    }

    private function logThrottled(string $message): void
    {
        $now = microtime(true);
        if ($now - $this->lastLogAt >= self::LOG_EVERY) {
            $this->lastLogAt = $now;
            error_log($message);
        }
    }

    private function isUsable(PDO $pdo): bool
    {
        try {
            // A connection left inside a transaction by a failed request is
            // not reused; neither is one the server has dropped.
            if ($pdo->inTransaction()) {
                return false;
            }
            $pdo->query('SELECT 1')->fetchColumn();
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /** True when the exception means the connection itself is gone. */
    public static function isConnectionLost(PDOException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        if (str_starts_with($state, '08') || in_array($state, ['57P01', '57P02', '57P03'], true)) {
            return true;
        }
        return preg_match(
            '/no connection to the server|server closed the connection|terminating connection|could not receive data|SSL SYSCALL|connection (?:to server .*)?(?:was )?lost/i',
            $e->getMessage(),
        ) === 1;
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function guard(callable $work): mixed
    {
        try {
            return $work();
        } catch (PDOException $e) {
            if (self::isConnectionLost($e)) {
                $this->broken = true;
            }
            throw $e;
        }
    }

    /**
     * Run a query with bound params, return the statement (for fetching).
     * @param array<string,mixed> $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        return $this->guard(function () use ($sql, $params): PDOStatement {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        });
    }

    /**
     * Fetch a single row as assoc or null.
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Fetch all rows as list of assocs.
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string,mixed>> */
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch a single scalar (first column of first row) or null.
     * @param array<string,mixed> $params
     */
    public function fetchScalar(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /**
     * Execute a non-SELECT and return affected rows.
     * @param array<string,mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * Run callable in a transaction; rollback on exception.
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transactional(callable $fn): mixed
    {
        $this->guard(fn () => $this->pdo->beginTransaction());
        try {
            $result = $fn($this);
            // No retry on failure: after a lost connection nobody knows
            // whether the commit happened.
            $this->guard(fn () => $this->pdo->commit());
            return $result;
        } catch (\Throwable $e) {
            try {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (PDOException $rollbackFailure) {
                // Keep the original error; a dead connection is replaced
                // before the next request.
                $this->broken = true;
            }
            throw $e;
        }
    }
}
