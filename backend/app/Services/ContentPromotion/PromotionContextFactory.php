<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use DomainException;

final class PromotionContextFactory
{
    public function __construct(private readonly ExactPackagePathGuard $pathGuard) {}

    public function make(
        string $package,
        string $packageSha256,
        string $lane,
        ?string $subscope,
    ): PromotionContext {
        $resolved = $this->pathGuard->resolve($package);
        $sourceCommit = strtolower(trim((string) env('CONTENT_PROMOTION_SOURCE_COMMIT', '')));
        $workflowRunId = trim((string) env('CONTENT_PROMOTION_WORKFLOW_RUN_ID', ''));
        $workflowRunAttempt = (int) env('CONTENT_PROMOTION_WORKFLOW_RUN_ATTEMPT', 0);
        $expectedRowCount = (int) env('CONTENT_PROMOTION_EXPECTED_ROW_COUNT', 0);
        $executorReleaseSha256 = strtolower(trim((string) env('CONTENT_PROMOTION_EXECUTOR_RELEASE_SHA256', '')));
        $releasePolicySha256 = strtolower(trim((string) env('CONTENT_PROMOTION_RELEASE_POLICY_SHA256', '')));
        $workflowSignature = strtolower(trim((string) env('CONTENT_PROMOTION_WORKFLOW_SIGNATURE', '')));

        $actualPolicySha256 = hash('sha256', self::canonicalJson((array) config('content_promotion.release_policy', [])));
        if (! hash_equals($actualPolicySha256, $releasePolicySha256)) {
            throw new DomainException('release_policy_sha256_mismatch');
        }

        $packageSha256 = strtolower(trim($packageSha256));
        $lane = strtoupper(trim($lane));
        $subscope = $subscope === null || trim($subscope) === '' ? null : trim($subscope);
        $workflowIdentityKey = (string) config('content_promotion.workflow_identity_key', '');
        $signatureMaterial = implode('|', [
            'content-promotion-v2',
            $sourceCommit,
            $workflowRunId,
            (string) $workflowRunAttempt,
            $lane,
            $subscope ?? '-',
            $packageSha256,
            $releasePolicySha256,
            (string) $expectedRowCount,
        ]);
        if (strlen($workflowIdentityKey) < 32
            || preg_match('/\A[a-f0-9]{64}\z/', $workflowSignature) !== 1
            || ! hash_equals(hash_hmac('sha256', $signatureMaterial, $workflowIdentityKey), $workflowSignature)) {
            throw new DomainException('workflow_identity_signature_invalid');
        }
        $idempotencyKey = hash('sha256', implode('|', [
            'content-promotion-v2',
            $lane,
            $subscope ?? '-',
            $packageSha256,
            $sourceCommit,
            $releasePolicySha256,
        ]));

        return new PromotionContext(
            packageDirectory: $resolved['path'],
            packageSha256: $packageSha256,
            lane: $lane,
            subscope: $subscope,
            sourceCommit: $sourceCommit,
            executorReleaseSha256: $executorReleaseSha256,
            releasePolicySha256: $releasePolicySha256,
            workflowRunId: $workflowRunId,
            workflowRunAttempt: $workflowRunAttempt,
            workflowSignature: $workflowSignature,
            expectedRowCount: $expectedRowCount,
            idempotencyKey: $idempotencyKey,
        );
    }

    /** @param array<string, mixed> $value */
    public static function canonicalJson(array $value): string
    {
        $canonicalize = static function (mixed $nested) use (&$canonicalize): mixed {
            if (! is_array($nested)) {
                return $nested;
            }
            if (array_is_list($nested)) {
                return array_map($canonicalize, $nested);
            }
            ksort($nested, SORT_STRING);

            return array_map($canonicalize, $nested);
        };

        return (string) json_encode($canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
