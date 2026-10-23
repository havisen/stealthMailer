<?php

declare(strict_types=1);

/**
 * Real HTTPS cookie acceptance check.
 *
 * Requires the application to be served over TLS with a CA file that validates
 * the certificate (Caddy internal CA for the local verification run). It uses
 * PHP's OpenSSL stream wrapper, so the trust decision is the same TLS stack the
 * application uses for SMTP.
 *
 * Environment:
 *   MAILPANEL_HTTPS_BASE_URL       e.g. https://localhost:8443
 *   MAILPANEL_HTTPS_CA_FILE        PEM CA that signed the server certificate
 *   MAILPANEL_HTTPS_TEST_EMAIL     disposable administrator email
 *   MAILPANEL_HTTPS_TEST_PASSWORD  disposable administrator password
 *
 * The script changes the password and changes it back before logout.
 */

$baseUrl = getenv('MAILPANEL_HTTPS_BASE_URL');
$caFile = getenv('MAILPANEL_HTTPS_CA_FILE');
$email = getenv('MAILPANEL_HTTPS_TEST_EMAIL');
$password = getenv('MAILPANEL_HTTPS_TEST_PASSWORD');

if (
    !is_string($baseUrl) || $baseUrl === ''
    || !is_string($caFile) || !is_file($caFile)
    || !is_string($email) || $email === ''
    || !is_string($password) || $password === ''
) {
    fwrite(STDERR, "SKIP: set MAILPANEL_HTTPS_BASE_URL, MAILPANEL_HTTPS_CA_FILE, MAILPANEL_HTTPS_TEST_EMAIL, and MAILPANEL_HTTPS_TEST_PASSWORD.\n");
    exit(77);
}

$parts = parse_url($baseUrl);
$host = is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : 'localhost';
$port = is_array($parts) && isset($parts['port']) ? (int) $parts['port'] : 443;

$sslOptions = [
    'cafile' => $caFile,
    'verify_peer' => true,
    'verify_peer_name' => true,
    'SNI_enabled' => true,
    'peer_name' => $host,
    'capture_peer_cert' => true,
];

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

/** @return array{status:int,headers:array<string,list<string>>,body:string,set_cookies:list<string>} */
$request = static function (
    string $method,
    string $url,
    array $cookies,
    ?array $form = null,
    array $extraHeaders = [],
) use ($sslOptions): array {
    $headers = ['Connection: close', 'Accept: text/html'];
    if ($cookies !== []) {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . rawurlencode((string) $value);
        }
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $content = '';
    if ($form !== null) {
        $content = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    foreach ($extraHeaders as $header) {
        $headers[] = $header;
    }

    $context = stream_context_create([
        'ssl' => $sslOptions,
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
            'protocol_version' => 1.1,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $rawHeaders = $http_response_header ?? [];
    $status = 0;
    $parsed = [];
    foreach ($rawHeaders as $index => $line) {
        if ($index === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
            $status = (int) $match[1];
            continue;
        }
        $separator = strpos($line, ':');
        if ($separator !== false) {
            $name = strtolower(trim(substr($line, 0, $separator)));
            $value = trim(substr($line, $separator + 1));
            $parsed[$name][] = $value;
        }
    }

    return [
        'status' => $status,
        'headers' => $parsed,
        'body' => is_string($body) ? $body : '',
        'set_cookies' => $parsed['set-cookie'] ?? [],
    ];
};

/** @return array<string, array{value:string,secure:bool,httponly:bool,samesite:?string,path:?string,max_age:?int}> */
$parseCookies = static function (array $setCookies): array {
    $parsed = [];
    foreach ($setCookies as $raw) {
        $segments = explode(';', $raw);
        $pair = trim((string) array_shift($segments));
        $separator = strpos($pair, '=');
        if ($separator === false) {
            continue;
        }
        $name = substr($pair, 0, $separator);
        $cookie = [
            'value' => rawurldecode(substr($pair, $separator + 1)),
            'secure' => false,
            'httponly' => false,
            'samesite' => null,
            'path' => null,
            'max_age' => null,
        ];
        foreach ($segments as $segment) {
            $attribute = trim($segment);
            if (strcasecmp($attribute, 'Secure') === 0) {
                $cookie['secure'] = true;
            } elseif (strcasecmp($attribute, 'HttpOnly') === 0) {
                $cookie['httponly'] = true;
            } elseif (stripos($attribute, 'SameSite=') === 0) {
                $cookie['samesite'] = substr($attribute, 9);
            } elseif (stripos($attribute, 'Path=') === 0) {
                $cookie['path'] = substr($attribute, 5);
            } elseif (stripos($attribute, 'Max-Age=') === 0) {
                $cookie['max_age'] = (int) substr($attribute, 8);
            }
        }
        $parsed[$name] = $cookie;
    }

    return $parsed;
};

$csrf = static function (string $html): string {
    if (preg_match('/name="_csrf" value="([^"]+)"/', $html, $match) !== 1) {
        throw new RuntimeException('CSRF field was not rendered.');
    }
    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
};

$applyCookies = static function (array &$jar, array $setCookies) use ($parseCookies): void {
    foreach ($parseCookies($setCookies) as $name => $cookie) {
        if ($cookie['value'] === '' && ($cookie['max_age'] === 0 || $cookie['max_age'] === null)) {
            unset($jar[$name]);
            continue;
        }
        if ($cookie['value'] !== '') {
            $jar[$name] = $cookie['value'];
        }
    }
};

$header = static function (array $response, string $name): string {
    return (string) ($response['headers'][strtolower($name)][0] ?? '');
};

try {
    // TLS trust: verify the peer chain against the configured CA and SNI name.
    $socket = @stream_socket_client(
        'ssl://' . $host . ':' . $port,
        $errorCode,
        $errorMessage,
        10,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => $sslOptions]),
    );
    $assert($socket !== false, 'TLS connection failed: ' . (string) $errorMessage);
    $parameters = stream_context_get_params($socket);
    $certificate = $parameters['options']['ssl']['peer_certificate'] ?? null;
    $assert($certificate !== null, 'Peer certificate was not exposed by the TLS stream.');
    $parsedCertificate = openssl_x509_parse($certificate);
    $assert(is_array($parsedCertificate), 'Peer certificate could not be parsed.');
    $issuerChain = [$caFile];
    $intermediate = dirname($caFile) . DIRECTORY_SEPARATOR . 'intermediate.crt';
    if (is_file($intermediate)) {
        $issuerChain[] = $intermediate;
    }
    $assert(
        openssl_x509_checkpurpose($certificate, X509_PURPOSE_SSL_SERVER, $issuerChain) === true,
        'Peer certificate does not validate for the TLS server purpose and host.',
    );
    $subject = $parsedCertificate['subject'] ?? [];
    $commonName = is_array($subject) ? (string) ($subject['CN'] ?? '') : '';
    $assert($commonName === $host || $host === 'localhost', 'Unexpected certificate common name.');
    fclose($socket);

    $jar = [];

    $anonymous = $request('GET', $baseUrl . '/', $jar);
    $assert($anonymous['status'] === 303, 'Anonymous dashboard request was not redirected.');
    $assert($header($anonymous, 'location') === '/login', 'Anonymous redirect target is not /login.');

    $loginPage = $request('GET', $baseUrl . '/login', $jar);
    $assert($loginPage['status'] === 200, 'Login page did not render over HTTPS.');
    $loginCookies = $parseCookies($loginPage['set_cookies']);
    $assert(isset($loginCookies['mailpanel_login_csrf']), 'Login CSRF cookie was not issued.');
    $loginChallenge = $loginCookies['mailpanel_login_csrf'];
    $assert($loginChallenge['secure'], 'Login CSRF cookie is not Secure.');
    $assert($loginChallenge['httponly'], 'Login CSRF cookie is not HttpOnly.');
    $assert($loginChallenge['samesite'] === 'Strict', 'Login CSRF cookie is not SameSite=Strict.');
    $assert($loginChallenge['path'] === '/', 'Login CSRF cookie Path is not /.');
    $assert($loginChallenge['max_age'] === 900, 'Login CSRF cookie lifetime is not 900 seconds.');
    $applyCookies($jar, $loginPage['set_cookies']);

    $assert($header($loginPage, 'strict-transport-security') !== '', 'HSTS header is missing.');
    $assert(str_contains($header($loginPage, 'cache-control'), 'no-store'), 'Login page is cacheable.');
    $assert(str_contains($header($loginPage, 'content-security-policy'), "default-src 'none'"), 'CSP is missing.');
    $assert(preg_match('/^[a-f0-9]{32}$/', $header($loginPage, 'x-request-id')) === 1, 'Request ID is missing or malformed.');
    $loginToken = $csrf($loginPage['body']);

    $missingCsrf = $request('POST', $baseUrl . '/login', $jar, [
        'email' => $email,
        'password' => $password,
    ]);
    $assert($missingCsrf['status'] === 403, 'Login without CSRF was accepted over HTTPS.');

    $wrongPassword = $request('POST', $baseUrl . '/login', $jar, [
        '_csrf' => $loginToken,
        'email' => $email,
        'password' => 'synthetic wrong password value',
    ]);
    $assert($wrongPassword['status'] === 401, 'Wrong password did not return the generic 401 challenge.');
    $assert(!isset($parseCookies($wrongPassword['set_cookies'])['mailpanel_admin']), 'Wrong password issued a session.');
    $assert(str_contains(strtolower($wrongPassword['body']), 'incorrect'), 'Wrong-password message is not generic.');
    $applyCookies($jar, $wrongPassword['set_cookies']);
    $loginToken = $csrf($wrongPassword['body']);

    $login = $request('POST', $baseUrl . '/login', $jar, [
        '_csrf' => $loginToken,
        'email' => $email,
        'password' => $password,
    ]);
    $assert($login['status'] === 303 && $header($login, 'location') === '/', 'Valid login did not redirect to /.');
    $sessionCookies = $parseCookies($login['set_cookies']);
    $assert(isset($sessionCookies['mailpanel_admin']), 'Session cookie was not issued over HTTPS.');
    $sessionCookie = $sessionCookies['mailpanel_admin'];
    $assert($sessionCookie['secure'], 'Session cookie is not Secure.');
    $assert($sessionCookie['httponly'], 'Session cookie is not HttpOnly.');
    $assert($sessionCookie['samesite'] === 'Strict', 'Session cookie is not SameSite=Strict.');
    $assert($sessionCookie['path'] === '/', 'Session cookie Path is not /.');
    $oldSession = $sessionCookie['value'];
    $assert($oldSession !== '', 'Session token is empty.');
    $applyCookies($jar, $login['set_cookies']);

    $dashboard = $request('GET', $baseUrl . '/', $jar);
    $assert($dashboard['status'] === 200, 'Authenticated dashboard did not render over HTTPS.');
    $assert(str_contains($dashboard['body'], $email), 'Dashboard does not identify the administrator.');
    $logoutToken = $csrf($dashboard['body']);

    $accountsPage = $request('GET', $baseUrl . '/accounts', $jar);
    $assert($accountsPage['status'] === 200, 'Authenticated sending-account list did not render over HTTPS.');
    $assert(str_contains($accountsPage['body'], 'Sending accounts'), 'Sending-account list content is missing.');
    $accountLabel = getenv('MAILPANEL_HTTPS_TEST_ACCOUNT_LABEL');
    if (is_string($accountLabel) && $accountLabel !== '') {
        $assert(str_contains($accountsPage['body'], $accountLabel), 'Imported sending account is not listed.');
    }
    $accountCreatePage = $request('GET', $baseUrl . '/accounts/create', $jar);
    $assert($accountCreatePage['status'] === 200, 'Sending-account form did not render over HTTPS.');
    $assert(
        preg_match('/name="password"[^>]*value="[^"]*"/i', $accountCreatePage['body']) !== 1,
        'Sending-account form rendered a stored password value.',
    );
    $accountCreateToken = $csrf($accountCreatePage['body']);
    $accountWithoutCsrf = $request('POST', $baseUrl . '/accounts', $jar, [
        'label' => 'Synthetic account',
        'smtp_host' => 'evil.example.test',
        'smtp_port' => '587',
        'smtp_username' => 'sender@example.test',
        'password' => 'synthetic password value',
        'from_email' => 'sender@example.test',
        'enabled' => '1',
    ]);
    $assert($accountWithoutCsrf['status'] === 403, 'Sending-account creation without CSRF was accepted over HTTPS.');
    $assert($accountCreateToken !== '', 'Sending-account form did not render a CSRF token.');

    $passwordPage = $request('GET', $baseUrl . '/settings/password', $jar);
    $assert($passwordPage['status'] === 200, 'Password page did not render over HTTPS.');
    $passwordToken = $csrf($passwordPage['body']);

    $passwordWithoutCsrf = $request('POST', $baseUrl . '/settings/password', $jar, [
        'current_password' => $password,
        'new_password' => 'synthetic replacement password',
        'new_password_confirmation' => 'synthetic replacement password',
    ]);
    $assert($passwordWithoutCsrf['status'] === 403, 'Password change without CSRF was accepted over HTTPS.');

    $newPassword = 'Https-' . bin2hex(random_bytes(12));
    $change = $request('POST', $baseUrl . '/settings/password', $jar, [
        '_csrf' => $passwordToken,
        'current_password' => $password,
        'new_password' => $newPassword,
        'new_password_confirmation' => $newPassword,
    ]);
    $assert($change['status'] === 303, 'Password change failed over HTTPS.');
    $changedCookies = $parseCookies($change['set_cookies']);
    $assert(isset($changedCookies['mailpanel_admin']), 'Password change did not issue a replacement session.');
    $replacement = $changedCookies['mailpanel_admin'];
    $assert($replacement['value'] !== $oldSession, 'Password change reused the old session token.');
    $assert($replacement['secure'] && $replacement['httponly'] && $replacement['samesite'] === 'Strict', 'Replacement cookie flags are wrong.');
    $applyCookies($jar, $change['set_cookies']);

    $oldSessionRequest = $request('GET', $baseUrl . '/', ['mailpanel_admin' => $oldSession]);
    $assert($oldSessionRequest['status'] === 303, 'Pre-change session survived the password change.');

    $replacementDashboard = $request('GET', $baseUrl . '/', $jar);
    $assert($replacementDashboard['status'] === 200, 'Replacement session did not authenticate.');

    $passwordPageAgain = $request('GET', $baseUrl . '/settings/password', $jar);
    $assert($passwordPageAgain['status'] === 200, 'Password page did not render with the replacement session.');
    $restoreToken = $csrf($passwordPageAgain['body']);
    $restore = $request('POST', $baseUrl . '/settings/password', $jar, [
        '_csrf' => $restoreToken,
        'current_password' => $newPassword,
        'new_password' => $password,
        'new_password_confirmation' => $password,
    ]);
    $assert($restore['status'] === 303, 'Restoring the original password failed over HTTPS.');
    $applyCookies($jar, $restore['set_cookies']);

    $restoredDashboard = $request('GET', $baseUrl . '/', $jar);
    $assert($restoredDashboard['status'] === 200, 'Restored session did not authenticate.');
    $logoutToken = $csrf($restoredDashboard['body']);

    $logoutWithoutCsrf = $request('POST', $baseUrl . '/logout', $jar, []);
    $assert($logoutWithoutCsrf['status'] === 403, 'Logout without CSRF was accepted over HTTPS.');

    $logout = $request('POST', $baseUrl . '/logout', $jar, ['_csrf' => $logoutToken]);
    $assert($logout['status'] === 303, 'Logout did not redirect over HTTPS.');
    $assert($header($logout, 'location') === '/login?logged_out=1', 'Logout redirect target is wrong.');
    $applyCookies($jar, $logout['set_cookies']);

    $afterLogout = $request('GET', $baseUrl . '/', $jar);
    $assert($afterLogout['status'] === 303, 'Session survived logout over HTTPS.');

    $privatePath = $request('GET', $baseUrl . '/storage/logs/application.log', []);
    $assert($privatePath['status'] === 404, 'Private storage path is web-accessible.');

    $traversal = $request('GET', $baseUrl . '/../storage/logs/application.log', []);
    $assert($traversal['status'] === 404 || $traversal['status'] === 400, 'Traversal into private storage was not rejected.');

    $asset = $request('GET', $baseUrl . '/assets/css/app.css', []);
    $assert($asset['status'] === 200, 'Public CSS asset was not served over HTTPS.');
    $assert(str_contains($header($asset, 'content-type'), 'text/css'), 'Public CSS asset has the wrong content type.');

    $plainHttp = $request('GET', 'http://' . $host . ':8080/login', []);
    $assert(in_array($plainHttp['status'], [301, 302, 307, 308], true), 'Plain HTTP request was not redirected.');
    $assert(str_starts_with($header($plainHttp, 'location'), 'https://' . $host . ':8443/'), 'Plain HTTP redirect does not target HTTPS.');

    fwrite(STDOUT, sprintf("Real HTTPS cookie checks passed (%d assertions).\n", $assertions));
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Real HTTPS cookie checks failed: ' . $throwable->getMessage() . "\n");
    exit(1);
}
