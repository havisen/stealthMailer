<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Repositories\AuditRepository;
use App\Security\CsrfTokenManager;
use App\Security\SecureCookieManager;
use App\Services\AuthService;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;

/** @param array<class-string, object> $dependencies */
return static function (App $app, array $dependencies): void {
    /** @var AuthController $controller */
    $controller = $dependencies[AuthController::class];
    /** @var AccountController $accountsController */
    $accountsController = $dependencies[AccountController::class];
    /** @var AuthService $auth */
    $auth = $dependencies[AuthService::class];
    /** @var SecureCookieManager $cookies */
    $cookies = $dependencies[SecureCookieManager::class];
    /** @var ResponseFactory $responses */
    $responses = $dependencies[ResponseFactory::class];
    /** @var CsrfTokenManager $csrf */
    $csrf = $dependencies[CsrfTokenManager::class];
    /** @var AuditRepository $audit */
    $audit = $dependencies[AuditRepository::class];

    $authMiddleware = new AuthMiddleware($auth, $cookies, $responses);

    $app->get('/login', [$controller, 'showLogin']);
    $app->post('/login', [$controller, 'login']);

    $app->get('/', [$controller, 'dashboard'])->add($authMiddleware);
    $app->get('/settings/password', [$controller, 'showPassword'])->add($authMiddleware);
    $app->post('/settings/password', [$controller, 'changePassword'])
        ->add(new CsrfMiddleware($csrf, $audit, $auth, $responses, 'password-change'))
        ->add($authMiddleware);

    $app->get('/accounts', [$accountsController, 'index'])->add($authMiddleware);
    $app->get('/accounts/create', [$accountsController, 'create'])->add($authMiddleware);
    $app->post('/accounts', [$accountsController, 'store'])
        ->add(new CsrfMiddleware($csrf, $audit, $auth, $responses, 'account-create'))
        ->add($authMiddleware);
    $app->get('/accounts/{id}/edit', [$accountsController, 'edit'])->add($authMiddleware);
    $app->post('/accounts/{id}/edit', [$accountsController, 'update'])
        ->add(new CsrfMiddleware($csrf, $audit, $auth, $responses, 'account-update'))
        ->add($authMiddleware);
    $app->post('/accounts/{id}/test', [$accountsController, 'test'])
        ->add(new CsrfMiddleware($csrf, $audit, $auth, $responses, 'account-test'))
        ->add($authMiddleware);
    $app->post('/accounts/{id}/disable', [$accountsController, 'disable'])
        ->add(new CsrfMiddleware($csrf, $audit, $auth, $responses, 'account-disable'))
        ->add($authMiddleware);
    $app->post('/accounts/{id}/delete', [$accountsController, 'delete'])
        ->add(new CsrfMiddleware($csrf, $audit, $auth, $responses, 'account-delete'))
        ->add($authMiddleware);

    $app->post('/logout', [$controller, 'logout'])
        ->add(new CsrfMiddleware($csrf, $audit, $auth, $responses, 'logout'))
        ->add($authMiddleware);
};
