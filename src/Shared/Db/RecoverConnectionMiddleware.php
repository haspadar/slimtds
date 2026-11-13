<?php

declare(strict_types=1);

namespace App\Shared\Db;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Outermost middleware: before any route runs, make sure the worker's database
 * connection still works and replace it if not. A long-lived worker otherwise
 * keeps serving on a connection the server dropped long ago. The request in
 * flight is never replayed; this only prepares the connection for it.
 */
final class RecoverConnectionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Connection $db) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->db->recover();

        return $handler->handle($request);
    }
}
