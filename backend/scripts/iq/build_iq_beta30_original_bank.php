#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__.'/iq_beta30_original_bank_lib.php';

$check = in_array('--check', $argv, true);
$files = iqBeta30FileMap();
$payloads = iqBeta30BankPayloads();
$drift = [];

foreach ($payloads as $key => $payload) {
    $path = $files[$key];
    $expected = iqBeta30PrettyJson($payload);

    if ($check) {
        $actual = is_file($path) ? (string) file_get_contents($path) : '';
        if ($actual !== $expected) {
            $drift[] = $path;
        }

        continue;
    }

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($path, $expected);
}

if ($check && $drift !== []) {
    fwrite(STDERR, "IQ_BETA_30_ORIGINAL artifacts are stale:\n - ".implode("\n - ", $drift)."\n");
    exit(1);
}

echo $check ? "IQ_BETA_30_ORIGINAL artifacts are current.\n" : "IQ_BETA_30_ORIGINAL artifacts written.\n";
