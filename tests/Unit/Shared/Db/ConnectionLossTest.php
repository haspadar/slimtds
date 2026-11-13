<?php

declare(strict_types=1);

use App\Shared\Db\Connection;

function pdoFailure(string $message, string $state): PDOException
{
    $e = new PDOException($message);
    $e->errorInfo = [$state, 7, $message];

    return $e;
}

test('connection loss is recognised by state and by message', function (PDOException $e): void {
    expect(Connection::isConnectionLost($e))->toBeTrue();
})->with([
    'no connection (HY000)' => [fn () => pdoFailure('SQLSTATE[HY000]: General error: 7 no connection to the server', 'HY000')],
    'connection exception class' => [fn () => pdoFailure('could not connect', '08006')],
    'admin shutdown' => [fn () => pdoFailure('terminating connection due to administrator command', '57P01')],
    'server closed' => [fn () => pdoFailure('server closed the connection unexpectedly', 'HY000')],
]);

test('ordinary SQL errors are not treated as connection loss', function (PDOException $e): void {
    expect(Connection::isConnectionLost($e))->toBeFalse();
})->with([
    'unique violation' => [fn () => pdoFailure('duplicate key value', '23505')],
    'syntax error' => [fn () => pdoFailure('syntax error at or near', '42601')],
    'read only' => [fn () => pdoFailure('cannot execute INSERT in a read-only transaction', '25006')],
]);

test('a connection needs a handle or a factory', function (): void {
    expect(fn () => new Connection())->toThrow(InvalidArgumentException::class);
});

test('the factory connection is opened lazily', function (): void {
    $opened = 0;
    $db = new Connection(null, function () use (&$opened): PDO {
        ++$opened;

        return pdo();
    });

    expect($opened)->toBe(0);
    $db->fetchScalar('SELECT 1');
    $db->fetchScalar('SELECT 1');
    expect($opened)->toBe(1);
});
