<?php

declare(strict_types=1);

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;

ini_set('memory_limit', '1024M');

$repoRoot = realpath(dirname(__DIR__, 4));
$output = $argv[1] ?? null;
if (! is_string($repoRoot) || ! is_string($output) || $output === '') {
    fwrite(STDERR, "VERSIONLESS_CURRENT_EXPORT_ARGUMENT_INVALID\n");
    exit(1);
}

require $repoRoot.'/backend/vendor/autoload.php';
$outputParent = realpath(dirname($output));
$temporaryRoot = realpath(sys_get_temp_dir());
if (! is_string($outputParent) || ! is_string($temporaryRoot)
    || ! str_starts_with($outputParent, $temporaryRoot.'/')
    || str_starts_with($outputParent, $repoRoot.'/')) {
    fwrite(STDERR, "VERSIONLESS_CURRENT_EXPORT_PATH_INVALID\n");
    exit(1);
}

$authority = (new CareerCurrentAuthorityPackage)->load($repoRoot.'/backend');
$bytes = implode("\n", array_map(
    static fn (array $row): string => CareerCurrentAuthorityPackage::encodeCanonical($row),
    array_values($authority['rows']),
))."\n";
$temporary = tempnam($outputParent, '.versionless-current-');
if (! is_string($temporary)
    || file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)
    || ! rename($temporary, $output)) {
    if (is_string($temporary) && is_file($temporary)) {
        unlink($temporary);
    }
    fwrite(STDERR, "VERSIONLESS_CURRENT_EXPORT_WRITE_FAILED\n");
    exit(1);
}

fwrite(STDOUT, json_encode([
    'career_count' => count($authority['rows']),
    'sha256' => hash('sha256', $bytes),
    'versionless_projection_sha256' => $authority['summary']['versionless_projection_sha256'],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
