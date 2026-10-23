<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Authenticates an IMAP or POP3 mailbox over implicit TLS without exposing
 * server text, credentials, or message content. It is a connectivity probe,
 * not a synchronization client.
 */
final class MailboxConnectionTester
{
    public const CATEGORIES = ['dns', 'connectivity', 'tls', 'auth', 'protocol'];

    public function __construct(
        private readonly string $caBundlePath = '',
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    public function imap(string $host, int $port, string $username, string $password): ?string
    {
        if (!$this->valid($host, $port, $username, $password)) {
            return 'protocol';
        }
        $connection = $this->open($host, $port);
        if (is_string($connection)) {
            return $connection;
        }

        try {
            $greeting = $this->readLine($connection);
            if ($greeting === null) {
                return 'connectivity';
            }
            if (preg_match('/^\* OK\b/i', $greeting) !== 1) {
                return 'protocol';
            }

            $tag = 'A1';
            $this->write($connection, $tag . ' LOGIN ' . $this->quoteImap($username) . ' ' . $this->quoteImap($password));
            $result = $this->readImapTagged($connection, $tag);
            if ($result !== null) {
                return $result;
            }

            $this->write($connection, 'A2 LOGOUT');
            $this->readLine($connection);
            return null;
        } finally {
            @fclose($connection);
        }
    }

    public function pop3(string $host, int $port, string $username, string $password): ?string
    {
        if (!$this->valid($host, $port, $username, $password)) {
            return 'protocol';
        }
        $connection = $this->open($host, $port);
        if (is_string($connection)) {
            return $connection;
        }

        try {
            $greeting = $this->readLine($connection);
            if ($greeting === null) {
                return 'connectivity';
            }
            if (preg_match('/^\+OK\b/i', $greeting) !== 1) {
                return 'protocol';
            }

            $this->write($connection, 'USER ' . $username);
            $userResponse = $this->readLine($connection);
            if ($userResponse === null) {
                return 'connectivity';
            }
            if (preg_match('/^\+OK\b/i', $userResponse) !== 1) {
                return 'auth';
            }

            $this->write($connection, 'PASS ' . $password);
            $passResponse = $this->readLine($connection);
            if ($passResponse === null) {
                return 'connectivity';
            }
            if (preg_match('/^\+OK\b/i', $passResponse) !== 1) {
                return 'auth';
            }

            $this->write($connection, 'QUIT');
            $this->readLine($connection);
            return null;
        } finally {
            @fclose($connection);
        }
    }

    /**
     * @return resource|string A connected TLS stream, or a safe failure category.
     */
    private function open(string $host, int $port): mixed
    {
        if (!$this->resolves($host)) {
            return 'dns';
        }

        $options = [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ];
        if ($this->caBundlePath !== '') {
            $options['ssl']['cafile'] = $this->caBundlePath;
        }

        $warning = '';
        $errno = 0;
        $errstr = '';
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });
        try {
            $connection = stream_socket_client(
                'ssl://' . $host . ':' . $port,
                $errno,
                $errstr,
                max(1, $this->timeoutSeconds),
                STREAM_CLIENT_CONNECT,
                stream_context_create($options),
            );
        } finally {
            restore_error_handler();
        }

        if (!is_resource($connection)) {
            if ($errno !== 0) {
                return 'connectivity';
            }
            $detail = strtolower($warning . ' ' . $errstr);
            if (preg_match('/certificate|tls|ssl|handshake|verify|crypto/i', $detail) === 1) {
                return 'tls';
            }
            return 'connectivity';
        }

        stream_set_timeout($connection, max(1, $this->timeoutSeconds));
        return $connection;
    }

    /** @param resource $connection */
    private function readImapTagged($connection, string $tag): ?string
    {
        while (true) {
            $line = $this->readLine($connection);
            if ($line === null) {
                return 'connectivity';
            }
            if (preg_match('/^' . preg_quote($tag, '/') . '\s+(OK|NO|BAD)\b/i', $line, $match) !== 1) {
                continue;
            }

            if (strtoupper($match[1]) === 'OK') {
                return null;
            }

            return strtoupper($match[1]) === 'NO' ? 'auth' : 'protocol';
        }
    }

    /**
     * @param resource $connection
     */
    private function readLine($connection): ?string
    {
        $line = @fgets($connection, 8192);
        if ($line === false) {
            return null;
        }
        $metadata = stream_get_meta_data($connection);
        if (($metadata['timed_out'] ?? false) === true) {
            return null;
        }

        return $line;
    }

    /**
     * @param resource $connection
     */
    private function write($connection, string $line): void
    {
        @fwrite($connection, $line . "\r\n");
    }

    private function quoteImap(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private function valid(string $host, int $port, string $username, string $password): bool
    {
        $host = trim($host);
        return $host !== ''
            && strlen($host) <= 253
            && preg_match('/[\x00-\x20\x7f]/', $host) !== 1
            && $port >= 1
            && $port <= 65535
            && $username !== ''
            && strlen($username) <= 512
            && preg_match('/[\x00-\x1f\x7f]/', $username) !== 1
            && $password !== ''
            && strlen($password) <= 4096
            && preg_match('/[\x00\r\n]/', $password) !== 1;
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
}
