<?php

declare(strict_types=1);

/**
 * Local SMTP connectivity acceptance.
 *
 * Exercises the real PHPMailer probe and transport against a disposable SMTP
 * server with a certificate signed by a disposable CA. Certificate checks are
 * never disabled: the negative case proves an untrusted CA is rejected.
 */

use App\Mail\PhpMailerSmtpProbe;
use App\Mail\PhpMailerSmtpTransport;
use App\Mail\TransportMessage;

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "SKIP: Composer dependencies are not installed.\n");
    exit(77);
}
require $autoload;

$host = getenv('MAILPANEL_SMTP_TEST_HOST');
$implicitPort = getenv('MAILPANEL_SMTP_TEST_PORT_IMPLICIT');
$starttlsPort = getenv('MAILPANEL_SMTP_TEST_PORT_STARTTLS');
$username = getenv('MAILPANEL_SMTP_TEST_USER');
$password = getenv('MAILPANEL_SMTP_TEST_PASSWORD');
$caFile = getenv('MAILPANEL_SMTP_TEST_CA_FILE');
$publicCaFile = getenv('MAILPANEL_SMTP_TEST_PUBLIC_CA_FILE');
$stateFile = getenv('MAILPANEL_SMTP_STATE_FILE');

if (
    !is_string($host) || $host === ''
    || !is_string($implicitPort) || $implicitPort === ''
    || !is_string($starttlsPort) || $starttlsPort === ''
    || !is_string($username) || $username === ''
    || !is_string($password) || $password === ''
    || !is_string($caFile) || !is_file($caFile)
    || !is_string($publicCaFile) || !is_file($publicCaFile)
    || !is_string($stateFile) || !is_file($stateFile)
) {
    fwrite(STDERR, "SKIP: local SMTP verification variables or the SMTP state file are unavailable.\n");
    exit(77);
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$readState = static function () use ($stateFile): array {
    $decoded = json_decode((string) file_get_contents($stateFile), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('SMTP state file is not an object.');
    }
    return $decoded;
};

$configuration = static fn (string $port, string $tls, string $secret): array => [
    'host' => $host,
    'port' => (int) $port,
    'tls' => $tls,
    'username' => $username,
    'password' => $secret,
    'from_email' => 'sender@example.test',
];

try {
    $before = $readState();
    $probe = new PhpMailerSmtpProbe($caFile);

    $implicit = $probe->probe($configuration($implicitPort, 'implicit', $password), 5, 5);
    $assert($implicit === null, 'Implicit TLS probe failed: ' . (string) $implicit);

    $wrongPassword = $probe->probe($configuration($implicitPort, 'implicit', 'wrong synthetic password'), 5, 5);
    $assert($wrongPassword === 'auth', 'Wrong SMTP password was not classified as authentication: ' . (string) $wrongPassword);

    $starttls = $probe->probe($configuration($starttlsPort, 'starttls', $password), 5, 5);
    $assert($starttls === null, 'STARTTLS probe failed: ' . (string) $starttls);

    $untrusted = (new PhpMailerSmtpProbe($publicCaFile))->probe(
        $configuration($implicitPort, 'implicit', $password),
        5,
        5,
    );
    $assert($untrusted === 'tls', 'A server certificate from an untrusted CA was accepted: ' . (string) $untrusted);

    $transport = new PhpMailerSmtpTransport();
    $message = new TransportMessage(
        '<smtp-verification-' . bin2hex(random_bytes(8)) . '@example.test>',
        'sender@example.test',
        'Synthetic Sender',
        null,
        '',
        'SMTP connectivity verification',
        'Synthetic verification body. No private content.',
        null,
        [['ordinal' => 1, 'type' => 'to', 'email' => 'recipient@example.test', 'name' => 'Synthetic Recipient']],
        [
            'host' => $host,
            'port' => (int) $implicitPort,
            'tls' => 'implicit',
            'username' => $username,
            'password' => $password,
        ],
        5,
        5,
    );
    $result = $transport->deliver($message);
    $assert($result->disposition === 'accepted', 'Transport did not accept the message: ' . $result->disposition);
    $assert($result->failureCategory === null, 'Accepted transport result has a failure category.');
    $assert(
        ($result->recipientOutcomes[0]['outcome'] ?? null) === 'accepted',
        'Recipient outcome was not accepted.',
    );

    $after = $readState();
    $assert(
        (int) ($after['implicit']['messages'] ?? 0) === (int) ($before['implicit']['messages'] ?? 0) + 1,
        'The SMTP server did not record exactly one delivered message.',
    );
    $assert((int) ($after['implicit']['authOk'] ?? 0) > (int) ($before['implicit']['authOk'] ?? 0), 'SMTP authentication was not recorded.');
    $assert((int) ($after['implicit']['authFailed'] ?? 0) > (int) ($before['implicit']['authFailed'] ?? 0), 'SMTP authentication failure was not recorded.');

    fwrite(STDOUT, sprintf(
        "SMTP connectivity checks passed (%d assertions; implicit TLS and STARTTLS on %s; certificate rejection verified).\n",
        $assertions,
        $host,
    ));
} catch (Throwable $throwable) {
    fwrite(STDERR, 'SMTP connectivity checks failed: ' . $throwable->getMessage() . "\n");
    exit(1);
}
