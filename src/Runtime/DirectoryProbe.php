<?php

declare(strict_types=1);

namespace App\Runtime;

/**
 * Proves that a private directory accepts writes instead of relying on
 * is_writable(), which can report false on Windows when an ACL contains a
 * narrow deny entry even though creating files is permitted.
 */
final class DirectoryProbe
{
    public static function isWritable(string $path): bool
    {
        if (!is_dir($path) || !is_readable($path)) {
            return false;
        }

        $probe = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . '.write-probe-' . bin2hex(random_bytes(8));
        $handle = @fopen($probe, 'xb');
        if ($handle === false) {
            return false;
        }

        $written = @fwrite($handle, 'probe');
        @fclose($handle);
        @unlink($probe);

        return $written === 5;
    }
}
