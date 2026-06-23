#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__.'/iq_owner_original30_image_bank_lib.php';

try {
    iqOwner30VerifyCommittedArtifacts();
} catch (Throwable $e) {
    fwrite(STDERR, 'IQ_OWNER_ORIGINAL_30 verification failed: '.$e->getMessage()."\n");
    exit(1);
}

echo "IQ_OWNER_ORIGINAL_30 image bank verification passed.\n";
