<?php

declare(strict_types=1);

use Slim\Psr7\Factory\ServerRequestFactory;

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "SKIP: Composer dependencies are not installed.\n");
    exit(77);
}
require $autoload;

if (getenv('MAILPANEL_TEST_ALLOW_RESET') !== '1') {
    fwrite(STDERR, "SKIP: set MAILPANEL_TEST_ALLOW_RESET=1 for a disposable database.\n");
    exit(77);
}
$dsn = getenv('MAILPANEL_TEST_DB_DSN');
$user = getenv('MAILPANEL_TEST_DB_USER');
$password = getenv('MAILPANEL_TEST_DB_PASSWORD');
if (!is_string($dsn) || $dsn === '' || !is_string($user)) {
    fwrite(STDERR, "SKIP: disposable database variables are not configured.\n");
    exit(77);
}

$pdo = new PDO($dsn, $user, is_string($password) ? $password : '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$drop = static function (PDO $pdo): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ([
        'api_key_audit_events',
        'api_key_accounts',
        'api_keys',
        'api_idempotency',
        'api_rate_limit_buckets',
        'submission_recipient_outcomes',
        'submission_recipients',
        'outgoing_submissions',
        'job_account_leases',
        'jobs',
        'worker_runs',
        'mail_accounts',
        'audit_events',
        'administrator_sessions',
        'administrator_login_throttles',
        'administrators',
    ] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
};
$cookie = static function (array $headers, string $name): ?string {
    foreach ($headers as $header) {
        if (str_starts_with($header, $name . '=')) {
            $pair = explode(';', $header, 2)[0];
            return rawurldecode(substr($pair, strlen($name) + 1));
        }
    }
    return null;
};
$csrf = static function (string $html): string {
    if (preg_match('/name="_csrf" value="([^"]+)"/', $html, $match) !== 1) {
        throw new RuntimeException('CSRF field was not rendered.');
    }
    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
};
$csrfForAction = static function (string $html, string $action): string {
    $pattern = '#action="' . preg_quote($action, '#') . '">\s*<input type="hidden" name="_csrf" value="([^"]+)"#s';
    if (preg_match($pattern, $html, $match) !== 1) {
        throw new RuntimeException('CSRF field for ' . $action . ' was not rendered.');
    }
    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $drop($pdo);
    (require $root . '/database/migrations/001_create_admin_and_jobs.php')($pdo);
    (require $root . '/database/migrations/002_create_accounts.php')($pdo);
    (require $root . '/database/migrations/006_create_audit_events.php')($pdo);
    $now = gmdate('Y-m-d H:i:s.u');
    $insert = $pdo->prepare(
        'INSERT INTO administrators
            (email, password_hash, security_version, password_changed_at, created_at, updated_at)
         VALUES (:email, :hash, 1, :now, :now, :now)'
    );
    $insert->execute([
        'email' => 'admin@example.test',
        'hash' => password_hash('correct horse battery staple', PASSWORD_ARGON2ID),
        'now' => $now,
    ]);

    $encodedKey = base64_encode(str_repeat('k', 32));
    $environment = [
        'APP_ENV' => 'test',
        'APP_DEBUG' => 'false',
        'MAILPANEL_DB_DSN' => $dsn,
        'MAILPANEL_DB_USER' => $user,
        'MAILPANEL_DB_PASSWORD' => is_string($password) ? $password : '',
        'MAILPANEL_CREDENTIAL_KEYSET' => json_encode(['primary' => $encodedKey], JSON_THROW_ON_ERROR),
        'MAILPANEL_CREDENTIAL_ACTIVE_KEY_ID' => 'primary',
        'MAILPANEL_CONTENT_KEYSET' => json_encode(['primary' => $encodedKey], JSON_THROW_ON_ERROR),
        'MAILPANEL_CONTENT_ACTIVE_KEY_ID' => 'primary',
        'MAILPANEL_API_FINGERPRINT_KEY' => base64_encode(str_repeat('f', 32)),
        'MAILPANEL_AUTH_CSRF_KEY' => base64_encode(str_repeat('c', 32)),
        'MAILPANEL_AUTH_THROTTLE_KEY' => base64_encode(str_repeat('t', 32)),
        'MAILPANEL_MESSAGE_ID_DOMAIN' => 'example.test',
        'MAILPANEL_APPROVED_SMTP_ENDPOINTS' => json_encode([
            ['host' => 'smtp.protonmail.ch', 'port' => 587, 'tls' => 'starttls'],
        ], JSON_THROW_ON_ERROR),
    ];
    foreach ($environment as $name => $value) {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    $app = (static function () use ($root): \Slim\App {
        return require $root . '/bootstrap/web.php';
    })();
    $factory = new ServerRequestFactory();
    $request = static fn (string $method, string $path) => $factory->createServerRequest(
        $method,
        'https://panel.example.test' . $path,
        ['REMOTE_ADDR' => '192.0.2.44'],
    )->withHeader('User-Agent', 'mailpanel-http-test');

    $anonymous = $app->handle($request('GET', '/'));
    $assert($anonymous->getStatusCode() === 303 && $anonymous->getHeaderLine('Location') === '/login', 'Anonymous dashboard access was not redirected.');

    $loginPage = $app->handle($request('GET', '/login'));
    $assert($loginPage->getStatusCode() === 200, 'Login page did not render.');
    $loginChallenge = $cookie($loginPage->getHeader('Set-Cookie'), 'mailpanel_login_csrf');
    $loginToken = $csrf((string) $loginPage->getBody());
    $assert(is_string($loginChallenge) && $loginChallenge !== '', 'Login CSRF cookie is missing.');

    $badCsrf = $app->handle($request('POST', '/login')->withParsedBody([
        '_csrf' => 'bad',
        'email' => 'admin@example.test',
        'password' => 'correct horse battery staple',
    ])->withCookieParams(['mailpanel_login_csrf' => $loginChallenge]));
    $assert($badCsrf->getStatusCode() === 403, 'Invalid login CSRF was accepted.');

    $login = $app->handle($request('POST', '/login')->withParsedBody([
        '_csrf' => $loginToken,
        'email' => 'admin@example.test',
        'password' => 'correct horse battery staple',
    ])->withCookieParams(['mailpanel_login_csrf' => $loginChallenge]));
    $assert($login->getStatusCode() === 303 && $login->getHeaderLine('Location') === '/', 'Valid login did not redirect.');
    $sessionToken = $cookie($login->getHeader('Set-Cookie'), 'mailpanel_admin');
    $assert(is_string($sessionToken) && $sessionToken !== '', 'Secure session cookie was not issued.');

    $dashboard = $app->handle($request('GET', '/')->withCookieParams(['mailpanel_admin' => $sessionToken]));
    $assert($dashboard->getStatusCode() === 200 && str_contains((string) $dashboard->getBody(), 'admin@example.test'), 'Authenticated dashboard failed.');

    $accountsPage = $app->handle($request('GET', '/accounts')->withCookieParams(['mailpanel_admin' => $sessionToken]));
    $assert($accountsPage->getStatusCode() === 200, 'Authenticated account list did not render.');
    $assert(str_contains((string) $accountsPage->getBody(), 'Sending accounts'), 'Account list content is missing.');
    $accountCreatePage = $app->handle($request('GET', '/accounts/create')->withCookieParams(['mailpanel_admin' => $sessionToken]));
    $assert($accountCreatePage->getStatusCode() === 200, 'Account create form did not render.');
    $accountCreateToken = $csrf((string) $accountCreatePage->getBody());
    $missingAccountCsrf = $app->handle($request('POST', '/accounts')
        ->withCookieParams(['mailpanel_admin' => $sessionToken])
        ->withParsedBody([
            'label' => 'Synthetic account',
            'smtp_host' => 'evil.example.test',
            'smtp_port' => 587,
            'smtp_username' => 'sender@example.test',
            'password' => 'synthetic password value',
            'from_email' => 'sender@example.test',
            'enabled' => '1',
        ]));
    $assert($missingAccountCsrf->getStatusCode() === 403, 'Account creation without CSRF was accepted.');
    $invalidAccount = $app->handle($request('POST', '/accounts')
        ->withCookieParams(['mailpanel_admin' => $sessionToken])
        ->withParsedBody([
            '_csrf' => $accountCreateToken,
            'label' => 'Synthetic account',
            'smtp_host' => 'evil.example.test',
            'smtp_port' => 587,
            'smtp_username' => 'sender@example.test',
            'password' => 'synthetic password value',
            'from_email' => 'sender@example.test',
            'enabled' => '1',
        ]));
    $assert($invalidAccount->getStatusCode() === 422, 'Unapproved SMTP endpoint was not rejected by the account form.');

    $validAccount = $app->handle($request('POST', '/accounts')
        ->withCookieParams(['mailpanel_admin' => $sessionToken])
        ->withParsedBody([
            '_csrf' => $accountCreateToken,
            'label' => 'Synthetic approved account',
            'smtp_host' => 'smtp.protonmail.ch',
            'smtp_port' => 587,
            'smtp_username' => 'sender@example.test',
            'password' => 'synthetic account password',
            'from_email' => 'sender@example.test',
            'enabled' => '1',
        ]));
    $assert($validAccount->getStatusCode() === 303, 'Approved account creation failed.');
    $accountId = $pdo->query("SELECT public_id FROM mail_accounts WHERE label = 'Synthetic approved account'")->fetchColumn();
    $assert(is_string($accountId) && str_starts_with($accountId, 'acct_'), 'Created account ID was not stored.');
    $stored = $pdo->query(
        'SELECT password_enc, enabled FROM mail_accounts WHERE public_id = ' . $pdo->quote($accountId)
    )->fetch(PDO::FETCH_ASSOC);
    $assert(($stored['password_enc'] ?? '') !== 'synthetic account password', 'Account password was stored in plaintext.');
    $assert((int) ($stored['enabled'] ?? 0) === 1, 'Created account is not enabled.');

    $editPage = $app->handle($request('GET', '/accounts/' . rawurlencode($accountId) . '/edit')->withCookieParams(['mailpanel_admin' => $sessionToken]));
    $assert($editPage->getStatusCode() === 200, 'Account edit form did not render.');
    $editHtml = (string) $editPage->getBody();
    $assert(str_contains($editHtml, 'Synthetic approved account'), 'Account edit form did not show the label.');
    $assert(preg_match('/name="password"[^>]*value="[^"]*"/i', $editHtml) !== 1, 'Account edit form rendered a stored password.');
    $editToken = $csrfForAction($editHtml, '/accounts/' . $accountId . '/edit');
    $update = $app->handle($request('POST', '/accounts/' . rawurlencode($accountId) . '/edit')
        ->withCookieParams(['mailpanel_admin' => $sessionToken])
        ->withParsedBody([
            '_csrf' => $editToken,
            'label' => 'Synthetic approved account updated',
            'smtp_host' => 'smtp.protonmail.ch',
            'smtp_port' => 587,
            'smtp_username' => 'sender@example.test',
            'password' => '',
            'from_email' => 'sender@example.test',
            'enabled' => '1',
        ]));
    $assert($update->getStatusCode() === 303, 'Account edit failed.');
    $updated = $pdo->query(
        'SELECT label, password_enc FROM mail_accounts WHERE public_id = ' . $pdo->quote($accountId)
    )->fetch(PDO::FETCH_ASSOC);
    $assert(($updated['label'] ?? '') === 'Synthetic approved account updated', 'Account label was not updated.');
    $assert(($updated['password_enc'] ?? '') === ($stored['password_enc'] ?? null), 'A blank edit password changed the stored secret.');

    $accountsForDisable = $app->handle($request('GET', '/accounts')->withCookieParams(['mailpanel_admin' => $sessionToken]));
    $disableToken = $csrfForAction((string) $accountsForDisable->getBody(), '/accounts/' . $accountId . '/disable');
    $disable = $app->handle($request('POST', '/accounts/' . rawurlencode($accountId) . '/disable')
        ->withCookieParams(['mailpanel_admin' => $sessionToken])
        ->withParsedBody(['_csrf' => $disableToken]));
    $assert($disable->getStatusCode() === 303, 'Account disable failed.');
    $assert(
        (int) $pdo->query('SELECT enabled FROM mail_accounts WHERE public_id = ' . $pdo->quote($accountId))->fetchColumn() === 0,
        'Disabled account is still enabled in the database.'
    );

    $accountsForDelete = $app->handle($request('GET', '/accounts')->withCookieParams(['mailpanel_admin' => $sessionToken]));
    $deleteToken = $csrfForAction((string) $accountsForDelete->getBody(), '/accounts/' . $accountId . '/delete');
    $delete = $app->handle($request('POST', '/accounts/' . rawurlencode($accountId) . '/delete')
        ->withCookieParams(['mailpanel_admin' => $sessionToken])
        ->withParsedBody([
            '_csrf' => $deleteToken,
            'confirm' => 'Synthetic approved account updated',
        ]));
    $assert($delete->getStatusCode() === 303, 'Account delete failed with status ' . $delete->getStatusCode() . ' and body ' . substr((string) $delete->getBody(), 0, 200));
    $assert(
        (int) $pdo->query('SELECT COUNT(*) FROM mail_accounts WHERE public_id = ' . $pdo->quote($accountId))->fetchColumn() === 0,
        'Deleted account is still present in the database.'
    );

    $passwordPage = $app->handle($request('GET', '/settings/password')->withCookieParams(['mailpanel_admin' => $sessionToken]));
    $assert($passwordPage->getStatusCode() === 200, 'Password page did not render.');
    $passwordToken = $csrf((string) $passwordPage->getBody());
    $missingPasswordCsrf = $app->handle($request('POST', '/settings/password')
        ->withCookieParams(['mailpanel_admin' => $sessionToken])
        ->withParsedBody([
            'current_password' => 'correct horse battery staple',
            'new_password' => 'a newly changed secure password',
            'new_password_confirmation' => 'a newly changed secure password',
        ]));
    $assert($missingPasswordCsrf->getStatusCode() === 403, 'Password change without CSRF was accepted.');
    $passwordChange = $app->handle($request('POST', '/settings/password')
        ->withCookieParams(['mailpanel_admin' => $sessionToken])
        ->withParsedBody([
            '_csrf' => $passwordToken,
            'current_password' => 'correct horse battery staple',
            'new_password' => 'a newly changed secure password',
            'new_password_confirmation' => 'a newly changed secure password',
        ]));
    $assert($passwordChange->getStatusCode() === 303, 'Valid password change failed.');
    $replacementToken = $cookie($passwordChange->getHeader('Set-Cookie'), 'mailpanel_admin');
    $assert(is_string($replacementToken) && $replacementToken !== $sessionToken, 'Password change did not replace the session token.');
    $oldSession = $app->handle($request('GET', '/')->withCookieParams(['mailpanel_admin' => $sessionToken]));
    $assert($oldSession->getStatusCode() === 303, 'Old session survived password change.');
    $sessionToken = $replacementToken;
    $dashboard = $app->handle($request('GET', '/')->withCookieParams(['mailpanel_admin' => $sessionToken]));
    $assert($dashboard->getStatusCode() === 200, 'Replacement session did not authenticate.');
    $logoutToken = $csrf((string) $dashboard->getBody());

    $missingLogoutCsrf = $app->handle($request('POST', '/logout')
        ->withCookieParams(['mailpanel_admin' => $sessionToken])
        ->withParsedBody([]));
    $assert($missingLogoutCsrf->getStatusCode() === 403, 'Logout without CSRF was accepted.');

    $logout = $app->handle($request('POST', '/logout')
        ->withCookieParams(['mailpanel_admin' => $sessionToken])
        ->withParsedBody(['_csrf' => $logoutToken]));
    $assert($logout->getStatusCode() === 303 && $logout->getHeaderLine('Location') === '/login?logged_out=1', 'Valid logout failed.');
    $afterLogout = $app->handle($request('GET', '/')->withCookieParams(['mailpanel_admin' => $sessionToken]));
    $assert($afterLogout->getStatusCode() === 303, 'Revoked session remained authenticated.');

    fwrite(STDOUT, sprintf("Authentication HTTP checks passed (%d assertions).\n", $assertions));
} finally {
    $drop($pdo);
}
