#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__.'/iq_showcase12_bank_lib.php';

function iqShowcase12BuildFail(string $message, int $code = 1): never
{
    fwrite(STDERR, "[iq-showcase12-build] {$message}\n");
    exit($code);
}

$options = getopt('', ['check']);
$checkOnly = array_key_exists('check', $options);
$fileMap = iqShowcase12FileMap();
$payloads = iqShowcase12BankPayloads();

if (! is_dir(iqShowcase12BankDir()) && ! mkdir(iqShowcase12BankDir(), 0777, true) && ! is_dir(iqShowcase12BankDir())) {
    iqShowcase12BuildFail('failed to create bank dir: '.iqShowcase12BankDir(), 2);
}

try {
    foreach ($payloads as $key => $payload) {
        $targetPath = $fileMap[$key] ?? null;
        if (! is_string($targetPath) || $targetPath === '') {
            iqShowcase12BuildFail('missing target path for payload: '.$key, 3);
        }

        $encoded = iqSvgPrettyJson($payload).PHP_EOL;
        if ($checkOnly) {
            $existing = is_file($targetPath) ? file_get_contents($targetPath) : false;
            if (! is_string($existing) || $existing !== $encoded) {
                iqShowcase12BuildFail('bank artifact drift detected: '.iqSvgRelativePath($targetPath), 4);
            }

            fwrite(STDOUT, '[iq-showcase12-build] verified '.iqSvgRelativePath($targetPath).PHP_EOL);

            continue;
        }

        if (file_put_contents($targetPath, $encoded) === false) {
            iqShowcase12BuildFail('failed to write artifact: '.iqSvgRelativePath($targetPath), 5);
        }

        fwrite(STDOUT, '[iq-showcase12-build] wrote '.iqSvgRelativePath($targetPath).PHP_EOL);
    }
} catch (Throwable $throwable) {
    iqShowcase12BuildFail($throwable->getMessage(), 10);
}
