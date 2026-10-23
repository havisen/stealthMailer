<?php

declare(strict_types=1);

use App\Controllers\Api\SendMailController;
use App\Middleware\ApiKeyMiddleware;
use Slim\App;

/** @param array<class-string, object> $dependencies */
return static function (App $app, array $dependencies): void {
    /** @var SendMailController $controller */
    $controller = $dependencies[SendMailController::class];
    /** @var ApiKeyMiddleware $middleware */
    $middleware = $dependencies[ApiKeyMiddleware::class];

    $app->post('/api/v1/mail/send', $controller)->add($middleware);
};
