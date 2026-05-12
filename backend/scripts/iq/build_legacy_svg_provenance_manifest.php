#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__.'/iq_svg_provenance_lib.php';

/**
 * @return array<int, string>
 */
function iqBuildRequestedPackDirs(): array
{
    $options = getopt('', ['pack-dir::', 'check', 'stdout']);
    $packDirs = $options['pack-dir'] ?? [];

    if (is_string($packDirs) && $packDirs !== '') {
        $packDirs = [$packDirs];
    }

    if (! is_array($packDirs) || $packDirs === []) {
        $packDirs = iqSvgDefaultPackDirs();
    }

    return array_values(array_unique(array_map(
        static fn (string $path): string => rtrim($path, '/'),
        $packDirs
    )));
}

function iqBuildFail(string $message, int $code = 1): never
{
    fwrite(STDERR, "[iq-svg-build] {$message}\n");
    exit($code);
}

$options = getopt('', ['pack-dir::', 'check', 'stdout']);
$packDirs = iqBuildRequestedPackDirs();
$checkOnly = array_key_exists('check', $options);
$stdout = array_key_exists('stdout', $options);

if ($stdout && count($packDirs) !== 1) {
    iqBuildFail('--stdout requires exactly one --pack-dir target.', 2);
}

try {
    foreach ($packDirs as $packDir) {
        $manifest = iqSvgBuildLegacyProvenanceManifest($packDir);
        $encoded = iqSvgPrettyJson($manifest).PHP_EOL;
        $targetPath = iqSvgDefaultManifestPath($packDir);

        if ($stdout) {
            fwrite(STDOUT, $encoded);

            continue;
        }

        if ($checkOnly) {
            $existing = is_file($targetPath) ? file_get_contents($targetPath) : false;
            if (! is_string($existing) || $existing !== $encoded) {
                iqBuildFail('manifest drift detected: '.iqSvgRelativePath($targetPath), 3);
            }

            fwrite(STDOUT, '[iq-svg-build] verified '.iqSvgRelativePath($targetPath).PHP_EOL);

            continue;
        }

        if (file_put_contents($targetPath, $encoded) === false) {
            iqBuildFail('failed to write manifest: '.iqSvgRelativePath($targetPath), 4);
        }

        fwrite(STDOUT, '[iq-svg-build] wrote '.iqSvgRelativePath($targetPath).PHP_EOL);
    }
} catch (Throwable $throwable) {
    iqBuildFail($throwable->getMessage(), 10);
}
