<?php

declare(strict_types=1);

use App\Api\SendRequestValidator;
use App\App\AppContext;
use App\Config\Config;
use App\Controllers\AccountController;
use App\Controllers\Api\SendMailController;
use App\Controllers\AuthController;
use App\Database\MigrationRunner;
use App\Mail\PhpMailerSmtpProbe;
use App\Mail\PhpMailerSmtpTransport;
use App\Mail\SmtpConnectionTester;
use App\Mail\SmtpTransport;
use App\Middleware\ApiKeyMiddleware;
use App\Repositories\AccountRepository;
use App\Repositories\AdministratorRepository;
use App\Repositories\AdministratorSessionRepository;
use App\Repositories\ApiAccessRepository;
use App\Repositories\ApiKeyRepository;
use App\Repositories\AuditRepository;
use App\Repositories\JobRepository;
use App\Repositories\LoginThrottleRepository;
use App\Repositories\SubmissionRepository;
use App\Security\CredentialCipher;
use App\Security\CsrfTokenManager;
use App\Security\EndpointPolicy;
use App\Security\PasswordHasher;
use App\Security\PasswordPolicy;
use App\Security\QueuedContentCipher;
use App\Security\SecureCookieManager;
use App\Services\AccountService;
use App\Services\ApiKeyService;
use App\Services\ApiSendService;
use App\Services\AuthService;
use App\Services\SubmissionService;
use App\Submissions\SubmissionValidator;
use App\Workers\SendWorker;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;
use Twig\Environment as TwigEnvironment;

/** @var AppContext $context */
$context = require __DIR__ . '/app.php';
$config = $context->config;
$administrators = new AdministratorRepository($context->pdo);
$sessions = new AdministratorSessionRepository($context->pdo);
$throttles = new LoginThrottleRepository($context->pdo);
$audit = new AuditRepository($context->pdo);
$passwordHasher = new PasswordHasher();
$passwordPolicy = new PasswordPolicy(
    $config->int('security.minimum_password_characters'),
    $config->int('security.maximum_password_bytes'),
);
$auth = new AuthService(
    $context->pdo,
    $administrators,
    $sessions,
    $throttles,
    $audit,
    $passwordHasher,
    $passwordPolicy,
    $config->string('security.auth_throttle_key'),
    $config->int('app.session_idle_seconds'),
    $config->int('app.session_absolute_seconds'),
    $config->int('security.login_window_seconds'),
    $config->int('security.login_max_failures'),
    $config->int('security.login_block_seconds'),
);
$csrf = new CsrfTokenManager(
    $config->string('security.auth_csrf_key'),
    $config->int('security.csrf_ttl_seconds'),
);
$cookies = new SecureCookieManager(
    $config->string('app.session_cookie_name'),
    $config->string('app.login_csrf_cookie_name'),
    $config->int('security.csrf_ttl_seconds'),
);
$responses = new ResponseFactory();
$streams = new StreamFactory();
$authController = new AuthController($auth, $csrf, $cookies, $context->view);

$accounts = new AccountRepository($context->pdo);
$submissions = new SubmissionRepository($context->pdo);
$jobs = new JobRepository($context->pdo);
$apiKeys = new ApiKeyRepository($context->pdo);
$apiAccess = new ApiAccessRepository($context->pdo);

$credentialCipher = new CredentialCipher(
    (array) $config->get('security.credential_keyset', []),
    $config->string('security.credential_active_key_id'),
);
$contentCipher = new QueuedContentCipher(
    (array) $config->get('security.content_keyset', []),
    $config->string('security.content_active_key_id'),
);
$endpointPolicy = new EndpointPolicy((array) $config->get('mail.approved_endpoints', []));
$submissionValidator = new SubmissionValidator(
    300,
    86400,
    $config->string('mail.message_id_domain'),
);

$accountService = new AccountService($accounts, $credentialCipher, $endpointPolicy, $jobs);
$smtpConnectionTester = new SmtpConnectionTester(
    new PhpMailerSmtpProbe((string) ($config->get('mail.tls_ca_bundle') ?? '')),
);
$accountController = new AccountController(
    $accountService,
    $smtpConnectionTester,
    $csrf,
    $context->view,
    $responses,
    $config->int('mail.connect_timeout_seconds'),
    $config->int('mail.operation_timeout_seconds'),
);
$submissionService = new SubmissionService(
    $context->pdo,
    $accounts,
    $submissions,
    $jobs,
    $contentCipher,
    $submissionValidator,
);
$apiKeyService = new ApiKeyService($apiKeys);
$sendRequestValidator = new SendRequestValidator();
$apiSendService = new ApiSendService(
    $context->pdo,
    $apiAccess,
    $submissionService,
    $config->string('security.api_fingerprint_key'),
    $config->int('api.requests_per_minute_per_key'),
    $config->int('api.accepted_recipients_per_hour_per_account'),
    $config->int('api.idempotency_retention_seconds'),
);
$sendMailController = new SendMailController(
    $apiSendService,
    $sendRequestValidator,
    $responses,
    $streams,
    $config->int('api.maximum_body_bytes'),
);
$apiKeyMiddleware = new ApiKeyMiddleware($apiKeyService, $responses, $streams);
$transport = new PhpMailerSmtpTransport((string) ($config->get('mail.tls_ca_bundle') ?? ''));
$sendWorker = new SendWorker(
    $submissionService,
    $accountService,
    $transport,
    (array) $config->get('mail.retry_delays_seconds', []),
    $config->int('mail.connect_timeout_seconds'),
    $config->int('mail.operation_timeout_seconds'),
);

/** @var array<class-string, object> $dependencies */
$dependencies = [
    AppContext::class => $context,
    TwigEnvironment::class => $context->view,
    PDO::class => $context->pdo,
    Config::class => $config,
    LoggerInterface::class => $context->logger,
    MigrationRunner::class => new MigrationRunner(
        $context->pdo,
        dirname(__DIR__) . '/database/migrations',
    ),
    AdministratorRepository::class => $administrators,
    AdministratorSessionRepository::class => $sessions,
    LoginThrottleRepository::class => $throttles,
    AuditRepository::class => $audit,
    PasswordHasher::class => $passwordHasher,
    PasswordPolicy::class => $passwordPolicy,
    AuthService::class => $auth,
    CsrfTokenManager::class => $csrf,
    SecureCookieManager::class => $cookies,
    ResponseFactory::class => $responses,
    StreamFactoryInterface::class => $streams,
    StreamFactory::class => $streams,
    AuthController::class => $authController,
    AccountRepository::class => $accounts,
    SubmissionRepository::class => $submissions,
    JobRepository::class => $jobs,
    ApiKeyRepository::class => $apiKeys,
    ApiAccessRepository::class => $apiAccess,
    CredentialCipher::class => $credentialCipher,
    QueuedContentCipher::class => $contentCipher,
    EndpointPolicy::class => $endpointPolicy,
    SubmissionValidator::class => $submissionValidator,
    AccountService::class => $accountService,
    AccountController::class => $accountController,
    SmtpConnectionTester::class => $smtpConnectionTester,
    SubmissionService::class => $submissionService,
    ApiKeyService::class => $apiKeyService,
    SendRequestValidator::class => $sendRequestValidator,
    ApiSendService::class => $apiSendService,
    SendMailController::class => $sendMailController,
    ApiKeyMiddleware::class => $apiKeyMiddleware,
    SmtpTransport::class => $transport,
    PhpMailerSmtpTransport::class => $transport,
    SendWorker::class => $sendWorker,
];

return $dependencies;
