#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__.'/iq_owner_original30_image_bank_lib.php';

$sourceDir = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--source=')) {
        $sourceDir = substr($arg, strlen('--source='));
    }
}

if (! is_string($sourceDir) || trim($sourceDir) === '') {
    fwrite(STDERR, "Usage: php backend/scripts/iq/build_iq_owner_original30_image_bank.php --source=/path/to/智商测试\n");
    exit(2);
}

try {
    $payloads = iqOwner30BuildPayloadsFromSource($sourceDir, true);
    iqOwner30WritePayloads($payloads);
    iqOwner30VerifyCommittedArtifacts();
} catch (Throwable $e) {
    fwrite(STDERR, 'IQ_OWNER_ORIGINAL_30 build failed: '.$e->getMessage()."\n");
    exit(1);
}

echo "IQ_OWNER_ORIGINAL_30 image bank written and verified.\n";
