<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use DomainException;

final readonly class PromotionContext
{
    public function __construct(
        public string $packageDirectory,
        public string $packageSha256,
        public string $lane,
        public ?string $subscope,
        public string $sourceCommit,
        public string $executorReleaseSha256,
        public string $releasePolicySha256,
        public string $workflowRunId,
        public int $workflowRunAttempt,
        public string $workflowSignature,
        public int $expectedRowCount,
        public string $idempotencyKey,
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/', $packageSha256) !== 1) {
            throw new DomainException('package_sha256_invalid');
        }
        if (preg_match('/\A(?:W[1-8]|TOP100)\z/', $lane) !== 1) {
            throw new DomainException('lane_invalid');
        }
        if ($subscope !== null && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $subscope) !== 1) {
            throw new DomainException('subscope_invalid');
        }
        if (preg_match('/\A[a-f0-9]{40}\z/', $sourceCommit) !== 1) {
            throw new DomainException('source_commit_invalid');
        }
        foreach ([$executorReleaseSha256, $releasePolicySha256, $workflowSignature, $idempotencyKey] as $sha256) {
            if (preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1) {
                throw new DomainException('integrity_sha256_invalid');
            }
        }
        if (preg_match('/\A[1-9][0-9]{0,19}\z/', $workflowRunId) !== 1 || $workflowRunAttempt < 1) {
            throw new DomainException('workflow_identity_invalid');
        }
        if ($expectedRowCount < 1 || $expectedRowCount > 100000) {
            throw new DomainException('expected_row_count_invalid');
        }
    }
}
