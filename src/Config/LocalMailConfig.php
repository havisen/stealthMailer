<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * Loads the gitignored, local-only mail test configuration.
 *
 * Secrets in this file must never be logged, returned by a route, or committed.
 */
final class LocalMailConfig
{
    /** @return array<string, mixed> */
    public static function load(string $basePath): array
    {
        $path = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'mail.local.php';
        if (!is_file($path)) {
            return [];
        }

        $config = require $path;
        if (!is_array($config)) {
            throw new RuntimeException('config/mail.local.php must return an array.');
        }

        return $config;
    }
}
