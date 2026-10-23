<?php

declare(strict_types=1);

namespace App\Mail;

use PHPMailer\PHPMailer\PHPMailer;

if (!interface_exists(SmtpProbe::class)) {
    require_once __DIR__ . '/SmtpConnectionTester.php';
}

/**
 * Connects, negotiates TLS, authenticates, and quits without sending a
 * message. It never performs MAIL FROM, RCPT TO, or DATA.
 */
final class PhpMailerSmtpProbe implements SmtpProbe
{
    public function __construct(private string $caBundlePath = '')
    {
    }

    public function probe(
        array $configuration,
        int $connectTimeoutSeconds,
        int $operationTimeoutSeconds
    ): ?string {
        $validated = $this->validate($configuration);
        if ($validated === null) {
            return 'protocol';
        }
        [$host, $port, $tls, $username, $password] = $validated;

        if (!$this->resolves($host)) {
            return 'dns';
        }

        $smtp = new InstrumentedSmtp();
        $mailer = new PHPMailer(true);
        $mailer->setSMTPInstance($smtp);
        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->Port = $port;
        $mailer->SMTPSecure = $tls === 'implicit'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->SMTPAutoTLS = true;
        $mailer->SMTPAuth = true;
        $mailer->Username = $username;
        $mailer->Password = $password;
        $mailer->Timeout = max(1, $connectTimeoutSeconds);
        $smtp->Timelimit = max(1, $operationTimeoutSeconds);
        $mailer->SMTPDebug = 0;
        $mailer->Debugoutput = static function (string $message, int $level): void {
            // SMTP transcripts can contain credentials and provider data.
        };
        $options = [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ];
        if ($this->caBundlePath !== '') {
            $options['ssl']['cafile'] = $this->caBundlePath;
        }
        $mailer->SMTPOptions = $options;

        try {
            if (!$mailer->smtpConnect()) {
                return self::classifyFailure($smtp->safeLastCode(), $smtp->safeLastErrorDetail());
            }
            return null;
        } catch (\Throwable $throwable) {
            return self::classifyFailure(
                $smtp->safeLastCode(),
                $throwable->getMessage() . ' ' . $smtp->safeLastErrorDetail(),
            );
        } finally {
            try {
                $mailer->smtpClose();
            } catch (\Throwable $throwable) {
                // Reply text and transport exceptions must never escape.
            }
            $mailer->Password = '';
        }
    }

    /**
     * Pure mapping used by probe failures and dependency-free verification.
     */
    public static function classifyFailure(int $smtpCode, string $errorMessage = ''): string
    {
        if (in_array($smtpCode, [530, 534, 535, 538], true)) {
            return 'auth';
        }
        if (in_array($smtpCode, [421, 450, 451, 452], true)) {
            return 'quota';
        }

        $message = strtolower($errorMessage);
        if ($smtpCode === 454 || self::mentionsTls($message)) {
            return 'tls';
        }
        if ($smtpCode >= 500 && $smtpCode <= 599) {
            return 'protocol';
        }
        if (self::mentionsAuthentication($message)) {
            return 'auth';
        }
        if ($smtpCode >= 400 && $smtpCode <= 599) {
            return 'protocol';
        }
        return 'connectivity';
    }

    /**
     * @param array<string, mixed> $configuration
     * @return array{0:string,1:int,2:string,3:string,4:string}|null
     */
    private function validate(array $configuration): ?array
    {
        $host = $configuration['host'] ?? null;
        $port = $configuration['port'] ?? null;
        $tls = $configuration['tls'] ?? null;
        $username = $configuration['username'] ?? null;
        $password = $configuration['password'] ?? null;
        $fromEmail = $configuration['from_email'] ?? null;

        if (!is_string($host)
            || !is_string($tls)
            || !is_string($username)
            || !is_string($password)
            || !is_string($fromEmail)) {
            return null;
        }

        $host = trim($host);
        if ($host === ''
            || strlen($host) > 253
            || preg_match('/\A[A-Za-z0-9._:\-\[\]]{1,253}\z/D', $host) !== 1) {
            return null;
        }

        if (is_int($port)) {
            $portNumber = $port;
        } elseif (is_string($port) && $port !== '' && ctype_digit($port)) {
            $portNumber = (int) $port;
        } else {
            return null;
        }
        if ($portNumber < 1 || $portNumber > 65535) {
            return null;
        }

        if (!in_array($tls, ['implicit', 'starttls'], true)) {
            return null;
        }

        $username = trim($username);
        if ($username === ''
            || strlen($username) > 512
            || preg_match('/[\x00-\x1f\x7f]/', $username) === 1) {
            return null;
        }

        if ($password === ''
            || strlen($password) > 4096
            || str_contains($password, "\0")) {
            return null;
        }

        $fromEmail = trim($fromEmail);
        if ($fromEmail === ''
            || strlen($fromEmail) > 254
            || preg_match('/[\x00-\x1f\x7f\s]/', $fromEmail) === 1
            || preg_match('/\A[^@]+@[^@]+\z/D', $fromEmail) !== 1) {
            return null;
        }

        return [$host, $portNumber, $tls, $username, $password];
    }

    private function resolves(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (!function_exists('dns_get_record')) {
            return false;
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        return is_array($records) && $records !== [];
    }

    private static function mentionsTls(string $message): bool
    {
        foreach ([
            'certificate',
            'crypto',
            'tls',
            'ssl',
            'openssl',
            'handshake',
            'self-signed',
            'self signed',
            'verify_peer',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function mentionsAuthentication(string $message): bool
    {
        foreach ([
            'authenticate',
            'authentication failed',
            'auth failed',
            'invalid credentials',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }
        return false;
    }
}
