#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__.'/iq_owner_original30_image_bank_lib.php';

try {
    iqOwner30VerifyPrivateScoringArtifacts();
} catch (Throwable $e) {
    fwrite(STDERR, 'IQ_OWNER_ORIGINAL_30 private scoring verification failed: '.$e->getMessage()."\n");
    exit(1);
}

echo "IQ_OWNER_ORIGINAL_30 private scoring verification passed.\n";
