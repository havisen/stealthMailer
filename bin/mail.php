<?php

declare(strict_types=1);

use App\Config\Config;
use App\Config\ConfigException;
use App\Config\LocalMailConfig;
use App\Mail\MailboxConnectionTester;
use App\Mail\SmtpConnectionTester;
use App\Services\AccountService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(64);
}

$root = dirname(__DIR__);
$action = '';
$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--password-stdin') {
        $options['password-stdin'] = true;
    } elseif ($argument === '--help') {
        $options['help'] = true;
    } elseif (str_starts_with($argument, '--id=')) {
        $options['id'] = substr($argument, 5);
    } elseif (!str_starts_with($argument, '--') && $action === '') {
        $action = $argument;
    }
}
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php bin/console.php mail:import|mail:test-smtp|mail:test-account|mail:test-imap|mail:test-pop3 [--password-stdin] [--id=acct_...]\n");
    exit(0);
}
if (!in_array($action, ['import', 'test-smtp', 'test-account', 'test-imap', 'test-pop3'], true)) {
    fwrite(STDERR, "Usage: php bin/console.php mail:import|mail:test-smtp|mail:test-account|mail:test-imap|mail:test-pop3 [--password-stdin] [--id=acct_...]\n");
    exit(64);
}

$readPassword = static function (bool $fromStdin, string $prompt): string {
    if ($fromStdin) {
        $line = fgets(STDIN);
        if (!is_string($line)) {
            throw new RuntimeException('Password input was unavailable.');
        }
        return rtrim($line, "\r\n");
    }

    if (PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec')) {
        $state = shell_exec('stty -g 2>/dev/null');
        if (is_string($state) && trim($state) !== '') {
            $state = trim($state);
            try {
                shell_exec('stty -echo 2>/dev/null');
                fwrite(STDERR, $prompt);
                $line = fgets(STDIN);
                fwrite(STDERR, "\n");
            } finally {
                shell_exec('stty ' . escapeshellarg($state) . ' 2>/dev/null');
            }
            if (!is_string($line)) {
                throw new RuntimeException('Password input was unavailable.');
            }
            return rtrim($line, "\r\n");
        }
    }

    fwrite(STDERR, $prompt . ' (input is visible; use --password-stdin to hide): ');
    $line = fgets(STDIN);
    fwrite(STDERR, "\n");
    if (!is_string($line)) {
        throw new RuntimeException('Password input was unavailable.');
    }
    return rtrim($line, "\r\n");
};

$reportSmtp = static function (array $result): void {
    if (($result['ok'] ?? false) === true) {
        fwrite(STDOUT, "SMTP connection and authentication succeeded.\n");
        return;
    }
    $category = (string) ($result['category'] ?? 'protocol');
    fwrite(STDERR, 'SMTP test failed: ' . $category . ".\n");
};
try {
    $dependencies = require $root . '/bootstrap/dependencies.php';
    $local = LocalMailConfig::load($root);
    /** @var Config $config */
    $config = $dependencies[Config::class];
    $caBundle = (string) ($config->get('mail.tls_ca_bundle') ?? '');
    $sendPassword = ($options['password-stdin'] ?? false) === true;

    if ($action === 'test-smtp' || $action === 'import') {
        $account = $local['test_account'] ?? null;
        if (!is_array($account)) {
            throw new RuntimeException('config/mail.local.php test_account is missing.');
        }
        $password = $sendPassword
            ? $readPassword(true, 'SMTP password: ')
            : (string) ($account['password'] ?? '');
        if ($password === '') {
            $password = $readPassword(false, 'SMTP password: ');
        }
        $account['password'] = $password;

        if ($action === 'import') {
            /** @var AccountService $accounts */
            $accounts = $dependencies[AccountService::class];
            $existing = null;
            foreach ($accounts->list() as $row) {
                if (
                    strcasecmp((string) ($row['smtp_host'] ?? ''), (string) $account['smtp_host']) === 0
                    && strcasecmp((string) ($row['smtp_username'] ?? ''), (string) $account['smtp_username']) === 0
                    && strcasecmp((string) ($row['from_email'] ?? ''), (string) $account['from_email']) === 0
                ) {
                    $existing = $row;
                    break;
                }
            }
            if ($existing !== null) {
                $accounts->update((string) $existing['public_id'], $account);
                fwrite(STDOUT, 'Updated encrypted sending account ' . $existing['public_id'] . ".\n");
            } else {
                $publicId = $accounts->create($account);
                fwrite(STDOUT, 'Created encrypted sending account ' . $publicId . ".\n");
            }
            exit(0);
        }

        /** @var SmtpConnectionTester $tester */
        $tester = $dependencies[SmtpConnectionTester::class];
        $reportSmtp($tester->test([
            'host' => (string) $account['smtp_host'],
            'port' => (int) $account['smtp_port'],
            'tls' => (string) (($account['smtp_port'] ?? 0) === 465 ? 'implicit' : 'starttls'),
            'username' => (string) $account['smtp_username'],
            'password' => $password,
            'from_email' => (string) $account['from_email'],
        ], $config->int('mail.connect_timeout_seconds'), $config->int('mail.operation_timeout_seconds')));
        exit(0);
    }

    if ($action === 'test-account') {
        $publicId = isset($options['id']) && is_string($options['id']) ? $options['id'] : '';
        if ($publicId === '') {
            throw new RuntimeException('Use --id=acct_... to select a stored account.');
        }
        /** @var AccountService $accounts */
        $accounts = $dependencies[AccountService::class];
        $account = $accounts->configurationForWorker($publicId);
        if ($account === null) {
            throw new RuntimeException('Account is missing or disabled.');
        }
        /** @var SmtpConnectionTester $tester */
        $tester = $dependencies[SmtpConnectionTester::class];
        $reportSmtp($tester->test([
            'host' => (string) $account['smtp_host'],
            'port' => (int) $account['smtp_port'],
            'tls' => (string) $account['smtp_tls'],
            'username' => (string) $account['smtp_username'],
            'password' => (string) $account['password'],
            'from_email' => (string) $account['from_email'],
        ], $config->int('mail.connect_timeout_seconds'), $config->int('mail.operation_timeout_seconds')));
        exit(0);
    }

    $receiving = $local['receiving'] ?? null;
    if (!is_array($receiving)) {
        throw new RuntimeException('config/mail.local.php receiving settings are missing.');
    }
    $username = (string) ($receiving['username'] ?? '');
    $endpoint = $action === 'test-imap' ? ($receiving['imap'] ?? null) : ($receiving['pop3'] ?? null);
    if (!is_array($endpoint)) {
        throw new RuntimeException('The requested receiving endpoint is not configured.');
    }

    $password = $readPassword($sendPassword, ($action === 'test-imap' ? 'IMAP' : 'POP3') . ' password: ');
    $mailbox = new MailboxConnectionTester($caBundle, $config->int('mail.connect_timeout_seconds'));
    $category = $action === 'test-imap'
        ? $mailbox->imap((string) $endpoint['host'], (int) $endpoint['port'], $username, $password)
        : $mailbox->pop3((string) $endpoint['host'], (int) $endpoint['port'], $username, $password);

    if ($category === null) {
        fwrite(STDOUT, strtoupper(substr($action, 5)) . " connection and authentication succeeded.\n");
        exit(0);
    }
    fwrite(STDERR, strtoupper(substr($action, 5)) . ' test failed: ' . $category . ".\n");
    exit(1);
} catch (ConfigException $exception) {
    fwrite(STDERR, 'Configuration error: ' . $exception->getMessage() . "\n");
    exit(78);
} catch (PDOException) {
    fwrite(STDERR, "Mail command database operation failed.\n");
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Mail command failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
