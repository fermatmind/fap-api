<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$envFile = $argv[1] ?? '';
$sourcePath = trim((string) getenv('SEO_INTEL_CRAWLER_LOG_SOURCE_AUTHORITY'));

if ($envFile === '' || $envFile[0] !== '/' || basename($envFile) !== '.env' || ! is_file($envFile)) {
    fwrite(STDERR, "crawler_runtime_env_invalid\n");
    exit(1);
}

if ($sourcePath === ''
    || $sourcePath[0] !== '/'
    || preg_match('#\A/[A-Za-z0-9._~+/\-]+\z#', $sourcePath) !== 1
    || str_contains($sourcePath, '/./')
    || str_contains($sourcePath, '/../')
    || ! is_file($sourcePath)
    || ! is_readable($sourcePath)) {
    fwrite(STDERR, "crawler_runtime_source_invalid\n");
    exit(1);
}

$updates = [
    'SEO_INTEL_CRAWLER_LOG_SOURCE' => $sourcePath,
    'SEO_INTEL_CRAWLER_LOG_AGGREGATE_WRITE_ENABLED' => 'true',
    'SEO_INTEL_CRAWLER_LOG_PRODUCTION_READ_ENABLED' => 'true',
    'SEO_INTEL_CRAWLER_LOG_SCHEDULER_ENABLED' => 'true',
];
$handle = fopen($envFile, 'c+');

if ($handle === false || ! flock($handle, LOCK_EX)) {
    fwrite(STDERR, "crawler_runtime_env_lock_failed\n");
    exit(1);
}

$previous = stream_get_contents($handle);
$previous = is_string($previous) ? $previous : '';
$lines = preg_split('/\R/', $previous) ?: [];
$filtered = array_values(array_filter($lines, static function (string $line) use ($updates): bool {
    foreach (array_keys($updates) as $key) {
        if (preg_match('/^\s*(?:export\s+)?'.preg_quote($key, '/').'\s*=/', $line) === 1) {
            return false;
        }
    }

    return true;
}));

while ($filtered !== [] && end($filtered) === '') {
    array_pop($filtered);
}

foreach ($updates as $key => $value) {
    $filtered[] = $key.'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
}

$next = implode("\n", $filtered)."\n";
$ok = rewind($handle)
    && ftruncate($handle, 0)
    && fwrite($handle, $next) === strlen($next)
    && fflush($handle);

if (! $ok) {
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, $previous);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    fwrite(STDERR, "crawler_runtime_env_write_failed\n");
    exit(1);
}

flock($handle, LOCK_UN);
fclose($handle);

$readback = (string) file_get_contents($envFile);
foreach ($updates as $key => $value) {
    $expected = $key.'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    if (substr_count($readback, $expected) !== 1) {
        file_put_contents($envFile, $previous, LOCK_EX);
        fwrite(STDERR, "crawler_runtime_env_readback_failed\n");
        exit(1);
    }
}

echo "crawler_aggregate_runtime_configured keys=4\n";
