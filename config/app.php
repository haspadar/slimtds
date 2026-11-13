<?php

declare(strict_types=1);

use DI\Bridge\Slim\Bridge as SlimBridge;
use Slim\App;

return static function (): App {
    $container = (require __DIR__ . '/di.php')();
    $app = SlimBridge::create($container);

    $app->addRoutingMiddleware();
    $app->addBodyParsingMiddleware();
    $app->addErrorMiddleware(
        displayErrorDetails: filter_var($_ENV['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOL),
        logErrors: true,
        logErrorDetails: true,
    );
    // Added last, so it runs first: the connection is checked before routing.
    $app->add(\App\Shared\Db\RecoverConnectionMiddleware::class);

    (require __DIR__ . '/routes.php')($app);

    return $app;
};
