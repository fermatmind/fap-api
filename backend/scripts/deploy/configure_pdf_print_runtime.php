<?php

declare(strict_types=1);

// Only the dedicated print signing key may be initialized. Existing keys never rotate here.
$phase = 'input';
$activated = false;
set_error_handler(static function (int $severity, string $_message, string $_file, int $line): never {
    throw new ErrorException('', 0, $severity, '', $line);
});
try {
    $environmentPath = $argv[1] ?? '';
    $inputPath = $argv[2] ?? '';
    if (is_link($environmentPath) || ! is_file($environmentPath)
        || is_link($inputPath) || ! is_file($inputPath)
        || (fileperms($inputPath) & 0077) !== 0 || filesize($inputPath) !== 64) {
        throw new RuntimeException('PDF_PRINT_RUNTIME_INPUT_INVALID');
    }
    $secret = file_get_contents($inputPath);
    if (! is_string($secret) || preg_match('/\A[0-9a-f]{64}\z/D', $secret) !== 1) {
        throw new RuntimeException('PDF_PRINT_RUNTIME_KEY_INVALID');
    }
    $phase = 'lock';
    $handle = fopen($environmentPath, 'rb');
    if ($handle === false || ! flock($handle, LOCK_EX)) {
        throw new RuntimeException('PDF_PRINT_RUNTIME_LOCK_FAILED');
    }
    $phase = 'read';
    $stat = fstat($handle);
    $pathStat = lstat($environmentPath);
    $original = stream_get_contents($handle);
    if (! is_array($stat) || ! is_array($pathStat) || $stat['ino'] !== $pathStat['ino']
        || $stat['dev'] !== $pathStat['dev'] || ! is_string($original)) {
        throw new RuntimeException('PDF_PRINT_RUNTIME_ENV_CHANGED');
    }
    $phase = 'prepare';
    $key = 'GOTENBERG_RESULT_PRINT_TOKEN_SECRET';
    $line = $key.'='.$secret;
    $seen = false;
    $updated = '';
    foreach (preg_split('/(?<=\n)/', $original, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $segment) {
        $body = preg_replace('/\r?\n\z/', '', $segment);
        if (preg_match('/\A\s*(?:export\s+)?'.preg_quote($key, '/').'\s*=(.*)\z/D', (string) $body, $matches) === 1) {
            $old = trim($matches[1]);
            if (! in_array($old, ['', '""', "''", $secret, '"'.$secret.'"', "'".$secret."'"], true)) {
                throw new RuntimeException('PDF_PRINT_RUNTIME_EXISTING_KEY_CONFLICT');
            }
            if (! $seen) {
                $updated .= $line.substr($segment, strlen((string) $body));
                $seen = true;
            }
        } else {
            $updated .= $segment;
        }
    }
    if (! $seen) {
        $updated .= ($updated !== '' && ! str_ends_with($updated, "\n") ? "\n" : '').$line."\n";
    }
    if ($updated !== $original) {
        $phase = 'temporary';
        $temporary = tempnam(dirname($environmentPath), '.pdf-print-env-');
        if (! is_string($temporary)) {
            throw new RuntimeException('PDF_PRINT_RUNTIME_TEMP_FAILED');
        }
        try {
            if (realpath(dirname($temporary)) !== realpath(dirname($environmentPath))) {
                throw new RuntimeException('PDF_PRINT_RUNTIME_TEMP_DIRECTORY_INVALID');
            }
            $phase = 'write';
            if (file_put_contents($temporary, $updated, LOCK_EX) !== strlen($updated)) {
                throw new RuntimeException('PDF_PRINT_RUNTIME_WRITE_FAILED');
            }
            $phase = 'owner';
            if (fileowner($temporary) !== $stat['uid'] && ! chown($temporary, $stat['uid'])) {
                throw new RuntimeException('PDF_PRINT_RUNTIME_OWNER_FAILED');
            }
            $phase = 'group';
            if (filegroup($temporary) !== $stat['gid'] && ! chgrp($temporary, $stat['gid'])) {
                throw new RuntimeException('PDF_PRINT_RUNTIME_GROUP_FAILED');
            }
            $phase = 'mode';
            if (! chmod($temporary, $stat['mode'] & 0777)) {
                throw new RuntimeException('PDF_PRINT_RUNTIME_MODE_FAILED');
            }
            clearstatcache(true, $temporary);
            $prepared = lstat($temporary);
            if (! is_array($prepared) || $prepared['uid'] !== $stat['uid'] || $prepared['gid'] !== $stat['gid']
                || ($prepared['mode'] & 0777) !== ($stat['mode'] & 0777)) {
                throw new RuntimeException('PDF_PRINT_RUNTIME_METADATA_MISMATCH');
            }
            $phase = 'sync';
            $file = fopen($temporary, 'r+b');
            if ($file === false) {
                throw new RuntimeException('PDF_PRINT_RUNTIME_SYNC_FAILED');
            }
            // On failure this CLI exits below; PHP closes its descriptors. Do not
            // let an explicit close replace a preceding sync exception.
            if (! fsync($file)) {
                throw new RuntimeException('PDF_PRINT_RUNTIME_SYNC_FAILED');
            }
            $phase = 'close';
            fclose($file);
            $phase = 'activate';
            clearstatcache(true, $environmentPath);
            $current = lstat($environmentPath);
            if (is_link($environmentPath) || ! is_array($current) || $current['ino'] !== $stat['ino']
                || $current['dev'] !== $stat['dev'] || ! rename($temporary, $environmentPath)) {
                throw new RuntimeException('PDF_PRINT_RUNTIME_ACTIVATE_FAILED');
            }
            $activated = true;
        } finally {
            // Cleanup must not replace the original, sanitized failure.
            try {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            } catch (Throwable) {
                // No secret value or exception message is emitted.
            }
        }
    }
    $phase = 'unlock';
    flock($handle, LOCK_UN);
    fclose($handle);
    echo "pdf_print_runtime=configured\n";
} catch (Throwable $error) {
    $code = preg_match('/\APDF_PRINT_RUNTIME_[A-Z_]+\z/D', $error->getMessage()) === 1
        ? $error->getMessage() : 'PDF_PRINT_RUNTIME_FAILED';
    if ($code === 'PDF_PRINT_RUNTIME_FAILED') {
        $class = get_class($error);
        $class = in_array($class, ['Error', 'TypeError', 'ValueError', 'ErrorException', 'RuntimeException'], true) ? $class : 'Other';
        $code .= ' stage='.$phase.' exception='.$class.' line='.$error->getLine().' activated='.(int) $activated;
    }
    fwrite(STDERR, $code."\n");
    exit(1);
}
