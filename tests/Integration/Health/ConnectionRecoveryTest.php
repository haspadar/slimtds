<?php

declare(strict_types=1);

use App\Health\ReadinessController;
use App\Shared\Db\Connection;
use Slim\Psr7\Response;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Real server-side disconnects: another session terminates the backend the
 * worker connection is using, exactly what a PostgreSQL restart does to it.
 */

function freshConnection(): Connection
{
    return new Connection(null, static fn (): PDO => new PDO(
        $_ENV['DB_DSN'],
        $_ENV['DB_USER'] ?? 'slimtds',
        $_ENV['DB_PASSWORD'] ?? 'slimtds',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
    ));
}

function killBackendOf(Connection $db): void
{
    $pid = (int) $db->fetchScalar('SELECT pg_backend_pid()');
    pdo()->exec("SELECT pg_terminate_backend({$pid})");
    usleep(200_000);
}

beforeEach(function (): void {
    pdo()->exec('DROP TABLE IF EXISTS public.recovery_probe');
    pdo()->exec('CREATE TABLE public.recovery_probe (id serial PRIMARY KEY, note text NOT NULL)');
});

afterEach(function (): void {
    pdo()->exec('DROP TABLE IF EXISTS public.recovery_probe');
});

function probeRows(): int
{
    return (int) pdo()->query('SELECT count(*) FROM public.recovery_probe')->fetchColumn();
}

test('recover replaces a connection the server terminated', function (): void {
    $db = freshConnection();
    $db->fetchScalar('SELECT 1');
    killBackendOf($db);

    expect($db->isAlive())->toBeFalse();
    expect($db->recover())->toBeTrue();
    expect($db->isAlive())->toBeTrue();
    expect($db->fetchScalar('SELECT 41 + 1'))->toBe(42);
});

test('a write cut off by a lost connection fails and is not replayed', function (): void {
    $db = freshConnection();
    $db->fetchScalar('SELECT 1');
    killBackendOf($db);

    expect(fn () => $db->execute("INSERT INTO public.recovery_probe (note) VALUES ('once')"))
        ->toThrow(PDOException::class);
    expect(probeRows())->toBe(0);

    expect($db->recover())->toBeTrue();
    $db->execute("INSERT INTO public.recovery_probe (note) VALUES ('once')");
    expect(probeRows())->toBe(1);
});

test('a failed statement marks the connection so the next boundary replaces it', function (): void {
    $db = freshConnection();
    $db->fetchScalar('SELECT 1');
    killBackendOf($db);

    try {
        $db->fetchScalar('SELECT 1');
    } catch (PDOException) {
    }

    expect($db->isAlive())->toBeFalse();
    $db->recover();
    expect($db->fetchScalar('SELECT 1'))->toBe(1);
});

test('a transaction cut off mid-way is not committed and not replayed', function (): void {
    $db = freshConnection();
    $db->fetchScalar('SELECT 1');

    expect(fn () => $db->transactional(function (Connection $tx): void {
        $tx->execute("INSERT INTO public.recovery_probe (note) VALUES ('inside')");
        killBackendOf($tx);
        $tx->execute("INSERT INTO public.recovery_probe (note) VALUES ('after loss')");
    }))->toThrow(PDOException::class);

    expect(probeRows())->toBe(0);
    expect($db->recover())->toBeTrue();
    expect($db->fetchScalar('SELECT 1'))->toBe(1);
    expect(probeRows())->toBe(0);
});

test('a connection abandoned inside a transaction is replaced and rolled back', function (): void {
    $db = freshConnection();
    $db->pdo->beginTransaction();
    $db->execute("INSERT INTO public.recovery_probe (note) VALUES ('dangling')");

    expect($db->recover())->toBeTrue();
    expect($db->pdo->inTransaction())->toBeFalse();
    expect(probeRows())->toBe(0);
});

test('a healthy connection is kept', function (): void {
    $db = freshConnection();
    $pid = $db->fetchScalar('SELECT pg_backend_pid()');

    $db->recover();

    expect($db->fetchScalar('SELECT pg_backend_pid()'))->toBe($pid);
});

test('recover reports failure instead of throwing when the database is unreachable', function (): void {
    $db = new Connection(null, static fn (): PDO => new PDO(
        'pgsql:host=127.0.0.1;port=1;dbname=none;connect_timeout=1',
        'x',
        'x',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    ));

    expect(fn () => $db->fetchScalar('SELECT 1'))->toThrow(PDOException::class);

    expect($db->recover())->toBeFalse();
    expect($db->isAlive())->toBeFalse();
});

test('readiness is 200 on a live worker and 503 once its connection is gone', function (): void {
    $db = freshConnection();
    $ready = new ReadinessController($db);
    $call = fn () => $ready((new ServerRequestFactory())->createServerRequest("GET", "/__ready"), new Response());

    $ok = $call();
    expect($ok->getStatusCode())->toBe(200);
    expect(json_decode((string) $ok->getBody(), true)['ok'])->toBeTrue();

    killBackendOf($db);
    $down = $call();
    expect($down->getStatusCode())->toBe(503);
    expect((string) $down->getBody())->not->toContain('SQLSTATE');

    $db->recover();
    expect($call()->getStatusCode())->toBe(200);
});

test('readiness is 503 when the server only accepts reads', function (): void {
    $db = freshConnection();
    $db->execute('SET default_transaction_read_only = on');
    $ready = new ReadinessController($db);

    $response = $ready((new ServerRequestFactory())->createServerRequest('GET', '/__ready'), new Response());

    expect($response->getStatusCode())->toBe(503);
});

test('the outermost middleware heals the connection before the route runs', function (): void {
    $db = freshConnection();
    $db->fetchScalar('SELECT 1');
    killBackendOf($db);

    $middleware = new App\Shared\Db\RecoverConnectionMiddleware($db);
    $route = new class ($db) implements Psr\Http\Server\RequestHandlerInterface {
        public function __construct(private readonly Connection $db) {}

        public function handle(Psr\Http\Message\ServerRequestInterface $request): Psr\Http\Message\ResponseInterface
        {
            $response = new Response();
            $response->getBody()->write((string) $this->db->fetchScalar('SELECT 7'));

            return $response;
        }
    };

    $response = $middleware->process((new ServerRequestFactory())->createServerRequest('GET', '/'), $route);

    expect((string) $response->getBody())->toBe('7');
});

test('a never-used lazy connection is left unopened by the middleware', function (): void {
    $opened = 0;
    $db = new Connection(null, function () use (&$opened): PDO {
        ++$opened;

        return pdo();
    });

    $db->recover();

    expect($opened)->toBe(0);
});

test('the real app heals a dropped connection before routing /__ready', function (): void {
    $app = (require dirname(__DIR__, 3) . '/config/app.php')();
    $db = $app->getContainer()->get(Connection::class);
    $db->fetchScalar('SELECT 1');
    killBackendOf($db);

    $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/__ready'));

    expect($response->getStatusCode())->toBe(200);
    expect($db->isAlive())->toBeTrue();
});

test('the recovery middleware is the outermost layer of the real app', function (): void {
    $app = (require dirname(__DIR__, 3) . '/config/app.php')();
    $db = $app->getContainer()->get(Connection::class);
    $db->fetchScalar('SELECT 1');
    killBackendOf($db);

    // A route that reads the database straight away must find a healed connection.
    $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/__ready'));

    expect(json_decode((string) $response->getBody(), true)['ok'])->toBeTrue();
});

test('a fresh connect failure is not retried on every access', function (): void {
    $attempts = 0;
    $db = new Connection(null, function () use (&$attempts): PDO {
        ++$attempts;
        throw new PDOException('connection refused');
    });

    foreach (range(1, 5) as $_) {
        try {
            $db->fetchScalar('SELECT 1');
        } catch (PDOException) {
        }
    }

    expect($attempts)->toBe(1);
});
