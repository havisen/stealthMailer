<?php

declare(strict_types=1);

/**
 * Bootstrap/runtime acceptance for the shared foundation.
 *
 * Verifies validated configuration, PDO connectivity, private paths, JSON
 * logging with redaction, Twig template compilation, and the protected route
 * table. It performs no destructive database work.
 */

use App\Config\Config;
use App\Config\ConfigLoader;
use App\Database\DatabaseConfig;
use App\Database\PdoFactory;
use App\Logging\LoggerFactory;
use App\Runtime\DirectoryProbe;
use App\Runtime\Environment;
use App\View\TwigFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "SKIP: Composer dependencies are not installed.\n");
    exit(77);
}
require $autoload;

$environment = Environment::variables();
foreach (['APP_ENV', 'MAILPANEL_DB_DSN', 'MAILPANEL_DB_USER'] as $required) {
    if (!isset($environment[$required]) || !is_string($environment[$required]) || $environment[$required] === '') {
        fwrite(STDERR, "SKIP: {$required} is not configured.\n");
        exit(77);
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $config = ConfigLoader::fromEnvironment($environment, $root);
    $assert(in_array($config->string('app.environment'), ['production', 'staging', 'development', 'test'], true), 'APP_ENV was not validated.');
    $assert($config->string('paths.storage') !== $config->string('paths.public'), 'Private storage and public root are the same path.');
    $assert(DirectoryProbe::isWritable($config->string('paths.logs')), 'Log directory is not writable.');
    $redacted = $config->redacted();
    $assert($redacted['database']['password'] === '[redacted]', 'Configuration password was not redacted.');
    $assert($redacted['security']['auth_csrf_key'] === '[redacted]', 'Configuration CSRF key was not redacted.');

    $pdo = PdoFactory::create(DatabaseConfig::fromConfig($config));
    $assert($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql', 'PDO is not using the MySQL driver.');
    $assert((int) $pdo->query('SELECT 1')->fetchColumn() === 1, 'PDO connectivity query failed.');
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $assert($version !== '', 'Database version query returned nothing.');

    // JSON logging and context-key redaction.
    $temporaryLogDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mailpanel-log-' . bin2hex(random_bytes(6));
    if (!mkdir($temporaryLogDirectory, 0700) && !is_dir($temporaryLogDirectory)) {
        throw new RuntimeException('Could not create a temporary log directory.');
    }
    try {
        $logger = LoggerFactory::create(new Config(['paths' => ['logs' => $temporaryLogDirectory]]));
        $logger->info('bootstrap check', [
            'password' => 'synthetic-password-value',
            'authorization' => 'Bearer synthetic-token-value',
            'token' => 'synthetic-token-value',
            'safe' => 'visible-value',
        ]);
        $lines = file($temporaryLogDirectory . DIRECTORY_SEPARATOR . 'application.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $assert(is_array($lines) && $lines !== [], 'Logger did not write a record.');
        $record = json_decode((string) end($lines), true, 32, JSON_THROW_ON_ERROR);
        $assert(is_array($record), 'Log record is not JSON.');
        $assert(($record['message'] ?? null) === 'bootstrap check', 'Log message was not preserved.');
        $assert(($record['context']['password'] ?? null) === '[redacted]', 'Log password context was not redacted.');
        $assert(($record['context']['authorization'] ?? null) === '[redacted]', 'Log authorization context was not redacted.');
        $assert(($record['context']['token'] ?? null) === '[redacted]', 'Log token context was not redacted.');
        $assert(($record['context']['safe'] ?? null) === 'visible-value', 'Safe log context was altered.');
    } finally {
        @unlink($temporaryLogDirectory . DIRECTORY_SEPARATOR . 'application.log');
        @rmdir($temporaryLogDirectory);
    }

    // Every template must compile, not merely exist.
    $twig = TwigFactory::create($config);
    $templateRoot = str_replace('\\', '/', $config->string('app.base_path') . '/templates');
    $templates = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($templateRoot, FilesystemIterator::SKIP_DOTS)
    );
    $templateCount = 0;
    foreach ($templates as $template) {
        if (!$template->isFile() || $template->getExtension() !== 'twig') {
            continue;
        }
        $pathname = str_replace('\\', '/', $template->getPathname());
        $relative = substr($pathname, strlen($templateRoot) + 1);
        $twig->load($relative);
        ++$templateCount;
    }
    $assert($templateCount >= 15, 'Too few Twig templates were compiled.');

    // Route table and anonymous access behavior.
    $app = (static function () use ($root): \Slim\App {
        return require $root . '/bootstrap/web.php';
    })();
    $routes = [];
    foreach ($app->getRouteCollector()->getRoutes() as $route) {
        $routes[] = implode('|', $route->getMethods()) . ' ' . $route->getPattern();
    }
    sort($routes);
    foreach (['GET /login', 'POST /login', 'GET /', 'GET /settings/password', 'POST /settings/password', 'POST /logout'] as $expected) {
        $assert(in_array($expected, $routes, true), 'Missing route: ' . $expected);
    }
    $apiRoutes = array_values(array_filter($routes, static fn (string $route): bool => str_contains($route, '/api/')));
    $assert($apiRoutes === ['POST /api/v1/mail/send'], 'The external API surface is not exactly the send endpoint.');
    $factory = new ServerRequestFactory();
    $unauthenticatedApi = $app->handle(
        $factory->createServerRequest('POST', 'https://panel.example.test/api/v1/mail/send', ['REMOTE_ADDR' => '192.0.2.10'])
            ->withHeader('Content-Type', 'application/json')
    );
    $assert($unauthenticatedApi->getStatusCode() === 401, 'The send endpoint accepted an unauthenticated request.');

    $anonymous = $app->handle($factory->createServerRequest('GET', 'https://panel.example.test/', ['REMOTE_ADDR' => '192.0.2.10']));
    $assert($anonymous->getStatusCode() === 303 && $anonymous->getHeaderLine('Location') === '/login', 'Anonymous dashboard request was not redirected.');
    $assert($anonymous->getHeaderLine('X-Request-ID') !== '', 'Anonymous response has no request ID.');
    $assert($anonymous->getHeaderLine('Content-Security-Policy') !== '', 'Anonymous response has no CSP.');
    $login = $app->handle($factory->createServerRequest('GET', 'https://panel.example.test/login', ['REMOTE_ADDR' => '192.0.2.10']));
    $assert($login->getStatusCode() === 200 && str_contains((string) $login->getBody(), 'name="_csrf"'), 'Login page did not render a CSRF challenge.');

    fwrite(STDOUT, sprintf(
        "Bootstrap/runtime checks passed (%d assertions; PHP %s; MariaDB/MySQL %s; %d routes; %d templates).\n",
        $assertions,
        PHP_VERSION,
        $version,
        count($routes),
        $templateCount,
    ));
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Bootstrap/runtime checks failed: ' . $throwable->getMessage() . "\n");
    exit(1);
}
