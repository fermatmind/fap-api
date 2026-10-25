<?php

declare(strict_types=1);

// Only the dedicated print signing key may be initialized. Existing keys never rotate here.
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
    $handle = fopen($environmentPath, 'rb');
    if ($handle === false || ! flock($handle, LOCK_EX)) {
        throw new RuntimeException('PDF_PRINT_RUNTIME_LOCK_FAILED');
    }
    $stat = fstat($handle);
    $pathStat = lstat($environmentPath);
    $original = stream_get_contents($handle);
    if (! is_array($stat) || ! is_array($pathStat) || $stat['ino'] !== $pathStat['ino']
        || $stat['dev'] !== $pathStat['dev'] || ! is_string($original)) {
        throw new RuntimeException('PDF_PRINT_RUNTIME_ENV_CHANGED');
    }
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
        $temporary = tempnam(dirname($environmentPath), '.pdf-print-env-');
        if (! is_string($temporary)) {
            throw new RuntimeException('PDF_PRINT_RUNTIME_TEMP_FAILED');
        }
        try {
            if (file_put_contents($temporary, $updated, LOCK_EX) !== strlen($updated)
                || ! chmod($temporary, $stat['mode'] & 0777)
                || ! chown($temporary, $stat['uid']) || ! chgrp($temporary, $stat['gid'])) {
                throw new RuntimeException('PDF_PRINT_RUNTIME_WRITE_FAILED');
            }
            $file = fopen($temporary, 'rb');
            if ($file === false || ! fsync($file)) {
                throw new RuntimeException('PDF_PRINT_RUNTIME_SYNC_FAILED');
            }
            fclose($file);
            clearstatcache(true, $environmentPath);
            $current = lstat($environmentPath);
            if (is_link($environmentPath) || ! is_array($current) || $current['ino'] !== $stat['ino']
                || $current['dev'] !== $stat['dev'] || ! rename($temporary, $environmentPath)) {
                throw new RuntimeException('PDF_PRINT_RUNTIME_ACTIVATE_FAILED');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    echo "pdf_print_runtime=configured\n";
} catch (Throwable $error) {
    $code = preg_match('/\APDF_PRINT_RUNTIME_[A-Z_]+\z/D', $error->getMessage()) === 1
        ? $error->getMessage() : 'PDF_PRINT_RUNTIME_FAILED';
    fwrite(STDERR, $code."\n");
    exit(1);
}
