<?php

declare(strict_types=1);

namespace App\Health;

use App\Shared\Db\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Readiness: can THIS worker, on the connection it really serves clicks with,
 * read the routing configuration. /__health only says the process answers;
 * a worker holding a dead connection answers it just fine while every click
 * fails. The body carries no error detail and the status is 503 on any
 * failure, so a probe can alert on the code alone.
 */
final class ReadinessController
{
    public function __construct(private readonly Connection $db) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $ok = true;
        try {
            // Routing lives in campaigns, flows and offers; reading all three
            // proves the connection, the schema and the permissions at once.
            $this->db->fetchScalar(
                'SELECT (SELECT count(*) FROM core.campaigns) + (SELECT count(*) FROM core.flows) + (SELECT count(*) FROM core.offers)',
            );
            // Clicks and conversions are writes: a reachable but read-only
            // server (standby, default_transaction_read_only) would redirect
            // visitors and silently record nothing.
            $writable = $this->db->fetchScalar(
                "SELECT current_setting('transaction_read_only') = 'off' AND NOT pg_is_in_recovery()",
            );
            if ($writable !== true) {
                throw new \RuntimeException('database does not accept writes');
            }
        } catch (\Throwable $e) {
            $ok = false;
            error_log('slimtds: readiness failed: ' . $e->getMessage());
        }

        $response->getBody()->write(json_encode(
            ['ok' => $ok, 'worker' => getmypid(), 'ts' => gmdate('c')],
            JSON_UNESCAPED_SLASHES,
        ));

        return $response
            ->withStatus($ok ? 200 : 503)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
