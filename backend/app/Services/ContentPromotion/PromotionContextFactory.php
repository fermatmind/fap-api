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
        // Workflow runtime context is passed through SSH process env at
        // promotion time, not through the deployed shared `.env`. Reading it
        // via `config()` would return the value that was frozen into
        // `bootstrap/cache/config.php` by `artisan config:cache` at deploy
        // time (typically null), causing `release_policy_sha256_mismatch`
        // even when the workflow correctly exports the variable. Bypass the
        // config cache and read the process env directly so the values the
        // workflow computed at dispatch time are honoured.
        $sourceCommit = strtolower(trim(self::runtimeEnv('CONTENT_PROMOTION_SOURCE_COMMIT', 'content_promotion.execution.source_commit')));
        $workflowRunId = trim(self::runtimeEnv('CONTENT_PROMOTION_WORKFLOW_RUN_ID', 'content_promotion.execution.workflow_run_id'));
        $workflowRunAttempt = (int) self::runtimeEnv('CONTENT_PROMOTION_WORKFLOW_RUN_ATTEMPT', 'content_promotion.execution.workflow_run_attempt', '0');
        $expectedRowCount = (int) self::runtimeEnv('CONTENT_PROMOTION_EXPECTED_ROW_COUNT', 'content_promotion.execution.expected_row_count', '0');
        $executorReleaseSha256 = strtolower(trim(self::runtimeEnv('CONTENT_PROMOTION_EXECUTOR_RELEASE_SHA256', 'content_promotion.execution.executor_release_sha256')));
        $releasePolicySha256 = strtolower(trim(self::runtimeEnv('CONTENT_PROMOTION_RELEASE_POLICY_SHA256', 'content_promotion.execution.release_policy_sha256')));
        $workflowSignature = strtolower(trim(self::runtimeEnv('CONTENT_PROMOTION_WORKFLOW_SIGNATURE', 'content_promotion.execution.workflow_signature')));

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

    /**
     * Read a workflow runtime environment variable. The promotion workflow
     * passes its runtime context (run id, signature, release-policy hash,
     * previous-receipt path, ...) through process env at dispatch time.
     * Production runs `artisan config:cache` at deploy time, so any read of
     * `config('content_promotion.execution.*')` returns the value frozen
     * into `bootstrap/cache/config.php` (typically null) instead of the
     * value the workflow just exported.
     *
     * Resolution order:
     *   1. `$_SERVER[$name]` — primary source for runtime-dispatched values.
     *   2. `$_ENV[$name]` — secondary, matches `Env::get` ordering.
     *   3. `getenv($name)` — last env-tier lookup.
     *   4. `config($configKey)` — fallback so non-cached environments
     *      (tests, local dev without `config:cache`) keep working through
     *      the legacy config path. In production with `config:cache` this
     *      returns the deploy-time frozen value, which is `null` for
     *      runtime-dispatched variables, so the fallback is inert there.
     *   5. `$default`.
     */
    public static function runtimeEnv(string $name, string $configKey, string $default = ''): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }
        $fromGetenv = getenv($name);
        if (is_string($fromGetenv) && $fromGetenv !== '') {
            return $fromGetenv;
        }
        $fromConfig = config($configKey);
        if (is_string($fromConfig) && $fromConfig !== '') {
            return $fromConfig;
        }
        if (is_int($fromConfig)) {
            return (string) $fromConfig;
        }

        return $default;
    }
}
