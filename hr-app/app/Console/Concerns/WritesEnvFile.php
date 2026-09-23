<?php

namespace App\Console\Concerns;

/**
 * Writes a single key into .env, keeping a timestamped backup.
 *
 * Shared by the commands that complete an OAuth handshake. They write the
 * refresh token here rather than printing it, so a long-lived credential never
 * lands in a terminal transcript or a screen share.
 */
trait WritesEnvFile
{
    /** Replaces the key if present, appends it otherwise. */
    protected function writeEnv(string $key, string $value): bool
    {
        $path = base_path('.env');

        if (! is_file($path) || ! is_writable($path)) {
            return false;
        }

        copy($path, $path.'.bak-'.now()->format('Ymd-His'));

        $contents = file_get_contents($path);
        $line = $key.'='.$value;

        $updated = preg_replace(
            '/^'.preg_quote($key, '/').'=.*$/m',
            $line,
            $contents,
            1,
            $count,
        );

        if ($count === 0) {
            $updated = rtrim($contents, "\n")."\n".$line."\n";
        }

        return file_put_contents($path, $updated) !== false;
    }
}
