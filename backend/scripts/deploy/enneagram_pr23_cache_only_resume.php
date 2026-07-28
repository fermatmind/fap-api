<?php

declare(strict_types=1);

namespace FermatMind\Deploy;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Models\PersonalityPublicContentAssetRevisionReview;
use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV224CacheCoordinator;
use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV224RuntimeManifest;
use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV224RuntimeReadback;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class EnneagramPr23CacheOnlyResumeRunner
{
    public const CONTRACT_VERSION = 'enneagram.pr23.cache_only_resume.v1';

    public const AUTHORIZATION_VERSION = 'enneagram.pr23.cache_only_resume.authorization.v1';

    public const TARGET_COUNT = 116;

    public const RUNTIME_CONFIG_APPLY_RUN_ID = 30333691762;

    public const RUNTIME_CONFIG_APPLY_RUN_ATTEMPT = 1;

    /** @var list<string> */
    private const BATCH_NAMES = [
        'canary-00',
        'readback-01',
        'readback-02',
        'readback-03',
        'readback-04',
        'readback-05',
        'readback-06',
        'readback-07',
        'readback-08',
        'readback-09',
    ];

    private static bool $frontendRevalidationAttempted = false;

    private static bool $frontendRevalidationCommitted = false;

    private static string $failureStage = 'runner_startup';

    public static function main(): int
    {
        self::$frontendRevalidationAttempted = false;
        self::$frontendRevalidationCommitted = false;
        self::$failureStage = 'runner_startup';

        try {
            self::emit(self::execute(self::environment()));

            return 0;
        } catch (Throwable $throwable) {
            self::emit([
                'contract_version' => self::CONTRACT_VERSION,
                'ok' => false,
                'status' => 'FAIL_CLOSED',
                'failure_stage' => self::$failureStage,
                'safe_error_code' => self::safeErrorCode($throwable),
                'writes_committed' => self::$frontendRevalidationCommitted,
                'frontend_revalidation_attempted' => self::$frontendRevalidationAttempted,
                'frontend_revalidation_committed' => self::$frontendRevalidationCommitted,
                'import_committed' => false,
                'review_bind_committed' => false,
                'promotion_committed' => false,
                'rollback_committed' => false,
                'backend_cache_invalidation_committed' => false,
                'deployment_committed' => false,
                'pr23_rerun' => false,
                'secret_output' => false,
                'nonce_output' => false,
                'signature_output' => false,
            ]);

            return 1;
        }
    }

    /** @param array<string,string> $environment @return array<string,mixed> */
    public static function execute(array $environment): array
    {
        self::$failureStage = 'validate_inputs';
        $mode = self::required($environment, 'FM_ENNEAGRAM_RESUME_MODE');
        if (! in_array($mode, ['preflight', 'execute', 'post_readback_only'], true)) {
            throw new RuntimeException('INVALID_MODE');
        }

        self::$failureStage = 'bootstrap_active_runtime';
        $activeBackend = self::managedBackendPath(
            self::required($environment, 'FM_ENNEAGRAM_ACTIVE_BACKEND'),
            self::required($environment, 'FM_ENNEAGRAM_MANAGED_DEPLOY_ROOT'),
        );
        self::bootstrapApplication($activeBackend);

        self::$failureStage = 'bind_runtime_identity';
        $bindings = self::bindings($environment, $activeBackend);
        self::$failureStage = 'validate_release_evidence';
        $releaseReport = self::releaseReport($activeBackend, $bindings);
        $services = self::services();
        self::$failureStage = 'validate_promotion_state';
        $state = self::productionState($releaseReport, $bindings, $services['manifest']);

        if ($mode === 'preflight') {
            self::$failureStage = 'read_runtime_snapshot';
            $snapshot = self::retryTransientRead(
                static fn (): array => $services['readback']->snapshot(
                    $releaseReport,
                    $bindings['frontend_origin'],
                ),
            );
            $safeState = $state;
            unset($safeState['private_reviewer_names']);
            $stateFingerprint = self::fingerprint([
                'bindings' => $bindings,
                'state' => $safeState,
                'snapshot' => $snapshot,
            ]);
            self::$failureStage = 'build_preflight_authorization';
            $phrase = self::authorizationPhrase(
                (int) $bindings['preflight_run_id'],
                (int) $bindings['preflight_run_attempt'],
                (string) $bindings['control_plane_sha'],
                (string) $bindings['runner_sha256'],
                (string) $bindings['backend_sha'],
                (string) $bindings['frontend_sha'],
                (string) $bindings['package_sha256'],
                (string) $bindings['release_report_sha256'],
                (int) $bindings['runtime_config_apply_run_id'],
                (int) $bindings['runtime_config_apply_run_attempt'],
                (string) $bindings['runtime_config_receipt_sha256'],
                (string) $bindings['rollback_token_sha256'],
                $stateFingerprint,
            );

            return self::preflightReceipt($bindings, $state, $snapshot, $stateFingerprint, $phrase);
        }

        if ($mode === 'post_readback_only') {
            self::$failureStage = 'validate_post_readback_source';
            $stateFingerprint = self::hash(self::required(
                $environment,
                'FM_ENNEAGRAM_EXPECTED_STATE_FINGERPRINT',
            ));
            $authorizedProjectionFingerprint = self::hash(self::required(
                $environment,
                'FM_ENNEAGRAM_AUTHORIZED_PUBLIC_PROJECTION_FINGERPRINT',
            ));
            $authorizedDiscoverabilityFingerprint = self::hash(self::required(
                $environment,
                'FM_ENNEAGRAM_AUTHORIZED_DISCOVERABILITY_FINGERPRINT',
            ));
            $authorizedUrlSetsSha256 = self::hash(self::required(
                $environment,
                'FM_ENNEAGRAM_AUTHORIZED_URL_SETS_SHA256',
            ));
            $sourceExecuteRunId = self::positiveInteger(self::required(
                $environment,
                'FM_ENNEAGRAM_SOURCE_EXECUTE_RUN_ID',
            ));
            $sourceExecuteRunAttempt = self::positiveInteger(self::required(
                $environment,
                'FM_ENNEAGRAM_SOURCE_EXECUTE_RUN_ATTEMPT',
            ));
            $sourceExecuteControlPlaneSha = self::gitSha(self::required(
                $environment,
                'FM_ENNEAGRAM_SOURCE_EXECUTE_CONTROL_PLANE_SHA',
            ));
            $sourceExecuteRunnerSha256 = self::hash(self::required(
                $environment,
                'FM_ENNEAGRAM_SOURCE_EXECUTE_RUNNER_SHA256',
            ));
            $sourceExecuteReceiptSha256 = self::hash(self::required(
                $environment,
                'FM_ENNEAGRAM_SOURCE_EXECUTE_RECEIPT_SHA256',
            ));

            $batchReceipts = self::runPostReadbackBatches(
                $services['readback'],
                $releaseReport,
                $bindings,
                $state['private_reviewer_names'],
            );
            $postSnapshot = self::postReadbackSnapshot(
                $services['readback'],
                $releaseReport,
                $bindings['frontend_origin'],
                $authorizedProjectionFingerprint,
                $authorizedDiscoverabilityFingerprint,
                $authorizedUrlSetsSha256,
            );

            return [
                'contract_version' => self::CONTRACT_VERSION,
                'ok' => true,
                'status' => 'PASS_POST_READBACK_ONLY',
                'control_plane_sha' => $bindings['control_plane_sha'],
                'runner_sha256' => $bindings['runner_sha256'],
                'backend_sha' => $bindings['backend_sha'],
                'frontend_sha' => $bindings['frontend_sha'],
                'package_sha256' => $bindings['package_sha256'],
                'state_fingerprint_sha256' => $stateFingerprint,
                'source_execute_run_id' => $sourceExecuteRunId,
                'source_execute_run_attempt' => $sourceExecuteRunAttempt,
                'source_execute_control_plane_sha' => $sourceExecuteControlPlaneSha,
                'source_execute_runner_sha256' => $sourceExecuteRunnerSha256,
                'source_execute_receipt_sha256' => $sourceExecuteReceiptSha256,
                'source_frontend_revalidation_committed' => true,
                'readback_batch_count' => count($batchReceipts),
                'api_read_count' => array_sum(array_column($batchReceipts, 'api_read_count')),
                'html_read_count' => array_sum(array_column($batchReceipts, 'html_read_count')),
                'batch_receipts' => $batchReceipts,
                'public_projection_fingerprint' => $postSnapshot['public_projection_fingerprint'],
                'stable_identity_discoverability_fingerprint' => $postSnapshot['stable_identity_discoverability_fingerprint'],
                'url_sets_sha256' => self::fingerprint($postSnapshot['url_sets']),
                'writes_committed' => false,
                'frontend_revalidation_attempted' => false,
                'frontend_revalidation_committed' => false,
                'import_committed' => false,
                'review_bind_committed' => false,
                'promotion_committed' => false,
                'rollback_committed' => false,
                'backend_cache_invalidation_committed' => false,
                'deployment_committed' => false,
                'pr23_rerun' => false,
                'automatic_rollback' => false,
                'secret_output' => false,
                'nonce_output' => false,
                'signature_output' => false,
            ];
        }

        self::$failureStage = 'validate_execute_authorization';
        self::assertHash(
            self::required($environment, 'FM_ENNEAGRAM_EXPECTED_STATE_FINGERPRINT'),
            'INVALID_EXPECTED_STATE_FINGERPRINT',
        );
        $stateFingerprint = self::required(
            $environment,
            'FM_ENNEAGRAM_EXPECTED_STATE_FINGERPRINT',
        );
        $authorizedProjectionFingerprint = self::hash(self::required(
            $environment,
            'FM_ENNEAGRAM_AUTHORIZED_PUBLIC_PROJECTION_FINGERPRINT',
        ));
        $authorizedDiscoverabilityFingerprint = self::hash(self::required(
            $environment,
            'FM_ENNEAGRAM_AUTHORIZED_DISCOVERABILITY_FINGERPRINT',
        ));
        $authorizedUrlSetsSha256 = self::hash(self::required(
            $environment,
            'FM_ENNEAGRAM_AUTHORIZED_URL_SETS_SHA256',
        ));
        $expectedPhrase = self::authorizationPhrase(
            (int) $bindings['preflight_run_id'],
            (int) $bindings['preflight_run_attempt'],
            (string) $bindings['control_plane_sha'],
            (string) $bindings['runner_sha256'],
            (string) $bindings['backend_sha'],
            (string) $bindings['frontend_sha'],
            (string) $bindings['package_sha256'],
            (string) $bindings['release_report_sha256'],
            (int) $bindings['runtime_config_apply_run_id'],
            (int) $bindings['runtime_config_apply_run_attempt'],
            (string) $bindings['runtime_config_receipt_sha256'],
            (string) $bindings['rollback_token_sha256'],
            $stateFingerprint,
        );
        if (! hash_equals($expectedPhrase, self::required($environment, 'FM_ENNEAGRAM_AUTHORIZATION_PHRASE'))) {
            throw new RuntimeException('AUTHORIZATION_PHRASE_MISMATCH');
        }

        self::$failureStage = 'validate_revalidation_runtime_config';
        $secret = (string) config('ops.content_release_observability.hmac_revalidation_secret', '');
        $endpoint = (string) config('ops.content_release_observability.hmac_revalidation_url', '');
        if (strlen($secret) < 24 || ! self::isHttpsUrl($endpoint)) {
            throw new RuntimeException('RUNTIME_REVALIDATION_CONFIG_UNAVAILABLE');
        }

        self::$failureStage = 'frontend_hmac_revalidation';
        self::$frontendRevalidationAttempted = true;
        $revalidation = $services['cache']->revalidateFrontend($releaseReport, $endpoint, $secret);
        if (($revalidation['ok'] ?? false) !== true
            || (int) ($revalidation['accepted_count'] ?? 0) !== self::TARGET_COUNT
            || (int) ($revalidation['rejected_count'] ?? -1) !== 0) {
            throw new RuntimeException('FRONTEND_REVALIDATION_INCOMPLETE');
        }
        self::$frontendRevalidationCommitted = true;

        $batchReceipts = self::runPostReadbackBatches(
            $services['readback'],
            $releaseReport,
            $bindings,
            $state['private_reviewer_names'],
        );
        $postSnapshot = self::postReadbackSnapshot(
            $services['readback'],
            $releaseReport,
            $bindings['frontend_origin'],
            $authorizedProjectionFingerprint,
            $authorizedDiscoverabilityFingerprint,
            $authorizedUrlSetsSha256,
        );

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'ok' => true,
            'status' => 'PASS_CACHE_ONLY_REVALIDATION_AND_POST_READBACK',
            'control_plane_sha' => $bindings['control_plane_sha'],
            'runner_sha256' => $bindings['runner_sha256'],
            'backend_sha' => $bindings['backend_sha'],
            'frontend_sha' => $bindings['frontend_sha'],
            'package_sha256' => $bindings['package_sha256'],
            'runtime_config_apply_run_id' => $bindings['runtime_config_apply_run_id'],
            'runtime_config_apply_run_attempt' => $bindings['runtime_config_apply_run_attempt'],
            'runtime_config_receipt_sha256' => $bindings['runtime_config_receipt_sha256'],
            'rollback_token_sha256' => $bindings['rollback_token_sha256'],
            'state_fingerprint_sha256' => $stateFingerprint,
            'accepted_revalidation_path_count' => 116,
            'rejected_revalidation_path_count' => 0,
            'readback_batch_count' => count($batchReceipts),
            'api_read_count' => array_sum(array_column($batchReceipts, 'api_read_count')),
            'html_read_count' => array_sum(array_column($batchReceipts, 'html_read_count')),
            'batch_receipts' => $batchReceipts,
            'public_projection_fingerprint' => $postSnapshot['public_projection_fingerprint'],
            'stable_identity_discoverability_fingerprint' => $postSnapshot['stable_identity_discoverability_fingerprint'],
            'url_sets_sha256' => self::fingerprint($postSnapshot['url_sets']),
            'writes_committed' => true,
            'frontend_revalidation_attempted' => true,
            'frontend_revalidation_committed' => true,
            'import_committed' => false,
            'review_bind_committed' => false,
            'promotion_committed' => false,
            'rollback_committed' => false,
            'backend_cache_invalidation_committed' => false,
            'deployment_committed' => false,
            'pr23_rerun' => false,
            'automatic_rollback' => false,
            'secret_output' => false,
            'nonce_output' => false,
            'signature_output' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $bindings
     * @param  array<string,mixed>  $state
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    public static function preflightReceipt(
        array $bindings,
        array $state,
        array $snapshot,
        string $stateFingerprint,
        string $phrase,
    ): array {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'authorization_contract_version' => self::AUTHORIZATION_VERSION,
            'ok' => true,
            'status' => 'PASS_CACHE_ONLY_RESUME_PREFLIGHT_AUTHORIZATION_REQUIRED',
            'preflight_run_id' => $bindings['preflight_run_id'],
            'preflight_run_attempt' => $bindings['preflight_run_attempt'],
            'control_plane_sha' => $bindings['control_plane_sha'],
            'runner_sha256' => $bindings['runner_sha256'],
            'backend_sha' => $bindings['backend_sha'],
            'frontend_sha' => $bindings['frontend_sha'],
            'package_sha256' => $bindings['package_sha256'],
            'release_report_sha256' => $bindings['release_report_sha256'],
            'runtime_config_apply_run_id' => $bindings['runtime_config_apply_run_id'],
            'runtime_config_apply_run_attempt' => $bindings['runtime_config_apply_run_attempt'],
            'runtime_config_receipt_sha256' => $bindings['runtime_config_receipt_sha256'],
            'rollback_token_sha256' => $bindings['rollback_token_sha256'],
            'published_count' => $state['published_count'],
            'working_count' => $state['working_count'],
            'approved_review_count' => $state['approved_review_count'],
            'non_empty_media_count' => 0,
            'revalidation_path_count' => self::TARGET_COUNT,
            'readback_plan' => '8+9x12',
            'public_projection_fingerprint' => $snapshot['public_projection_fingerprint'],
            'stable_identity_discoverability_fingerprint' => $snapshot['stable_identity_discoverability_fingerprint'],
            'url_sets_sha256' => self::fingerprint($snapshot['url_sets']),
            'state_fingerprint_sha256' => $stateFingerprint,
            'authorization_phrase' => $phrase,
            'authorization_phrase_sha256' => hash('sha256', $phrase),
            'writes_committed' => false,
            'frontend_revalidation_attempted' => false,
            'frontend_revalidation_committed' => false,
            'import_committed' => false,
            'review_bind_committed' => false,
            'promotion_committed' => false,
            'rollback_committed' => false,
            'backend_cache_invalidation_committed' => false,
            'deployment_committed' => false,
            'pr23_rerun' => false,
            'automatic_rollback' => false,
            'secret_output' => false,
            'nonce_output' => false,
            'signature_output' => false,
        ];
    }

    public static function authorizationPhrase(
        int $preflightRunId,
        int $preflightRunAttempt,
        string $controlPlaneSha,
        string $runnerSha256,
        string $backendSha,
        string $frontendSha,
        string $packageSha256,
        string $releaseReportSha256,
        int $runtimeConfigApplyRunId,
        int $runtimeConfigApplyRunAttempt,
        string $runtimeConfigReceiptSha256,
        string $rollbackTokenSha256,
        string $stateFingerprint,
    ): string {
        return "I explicitly approve production Enneagram PR23 cache-only resume from preflight run {$preflightRunId} attempt {$preflightRunAttempt} with control-plane SHA {$controlPlaneSha} runner SHA256 {$runnerSha256} backend SHA {$backendSha} frontend SHA {$frontendSha} package SHA256 {$packageSha256} release-report SHA256 {$releaseReportSha256} runtime-config apply run {$runtimeConfigApplyRunId} attempt {$runtimeConfigApplyRunAttempt} receipt SHA256 {$runtimeConfigReceiptSha256} rollback-token SHA256 {$rollbackTokenSha256} state fingerprint {$stateFingerprint}; revalidate exactly 116 frontend paths by HMAC, then run post-readback canary 8 plus 9x12, no import/review-bind/promotion/rollback/backend-cache-invalidation/deploy/symlink/migration/CMS/database-authority/queue/restart/publication/sitemap/llms/search/PR23-rerun/automatic-rollback.";
    }

    public static function retryTransientRead(callable $operation, ?callable $pause = null): mixed
    {
        $pause ??= static function (): void {
            usleep(500_000);
        };

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return $operation();
            } catch (Throwable $exception) {
                if (! self::isTransientReadFailure($exception) || $attempt === 3) {
                    throw $exception;
                }
                $pause();
            }
        }

        throw new RuntimeException('TRANSIENT_READ_RETRY_EXHAUSTED');
    }

    public static function retryPostReadbackBatch(
        callable $operation,
        ?callable $pause = null,
        int $maxAttempts = 2,
    ): mixed {
        if ($maxAttempts < 1 || $maxAttempts > 13) {
            throw new RuntimeException('INVALID_POST_READBACK_MAX_ATTEMPTS');
        }
        $pause ??= static function (): void {
            usleep(1_000_000);
        };

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $operation();
            } catch (Throwable $exception) {
                $isRetryable = $exception instanceof ConnectionException
                    || ($exception instanceof RuntimeException
                        && str_starts_with($exception->getMessage(), 'Runtime readback mismatch:'));
                if (! $isRetryable || $attempt === $maxAttempts) {
                    throw $exception;
                }
                $pause();
            }
        }

        throw new RuntimeException('POST_READBACK_RETRY_EXHAUSTED');
    }

    public static function retryPostReadbackSnapshot(
        callable $operation,
        ?callable $pause = null,
    ): mixed {
        $pause ??= static function (): void {
            usleep(1_000_000);
        };

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return $operation();
            } catch (Throwable $exception) {
                $isRetryable = $exception instanceof ConnectionException
                    || ($exception instanceof RuntimeException
                        && preg_match(
                            '/^POST_READBACK_(PUBLIC_PROJECTION|DISCOVERABILITY|URL_SETS)_DRIFT$/D',
                            $exception->getMessage(),
                        ) === 1);
                if (! $isRetryable || $attempt === 3) {
                    throw $exception;
                }
                $pause();
            }
        }

        throw new RuntimeException('POST_READBACK_SNAPSHOT_RETRY_EXHAUSTED');
    }

    private static function isTransientReadFailure(Throwable $throwable): bool
    {
        if ($throwable instanceof ConnectionException) {
            return true;
        }

        return $throwable instanceof RuntimeException
            && preg_match(
                '/URL-set readback failed with HTTP (?:408|425|429|5[0-9]{2})\./D',
                $throwable->getMessage(),
            ) === 1;
    }

    /** @return array{manifest:EnneagramPublicAuthorityV224RuntimeManifest,cache:EnneagramPublicAuthorityV224CacheCoordinator,readback:EnneagramPublicAuthorityV224RuntimeReadback} */
    private static function services(): array
    {
        return [
            'manifest' => app(EnneagramPublicAuthorityV224RuntimeManifest::class),
            'cache' => app(EnneagramPublicAuthorityV224CacheCoordinator::class),
            'readback' => app(EnneagramPublicAuthorityV224RuntimeReadback::class),
        ];
    }

    /** @param array<string,mixed> $bindings @return array<string,mixed> */
    private static function releaseReport(string $activeBackend, array $bindings): array
    {
        $path = $activeBackend.'/docs/seo/personality/enneagram-authority-v2/'
            .'enneagram-public-authority-v2-release-gate-22/release-gate-report.json';
        if (! is_file($path) || ! hash_equals(hash_file('sha256', $path) ?: '', $bindings['release_report_sha256'])) {
            throw new RuntimeException('RELEASE_REPORT_SHA_DRIFT');
        }
        $report = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($report)
            || ! hash_equals((string) ($report['package_sha256'] ?? ''), $bindings['package_sha256'])
            || count($report['asset_records'] ?? []) !== self::TARGET_COUNT) {
            throw new RuntimeException('RELEASE_REPORT_CONTRACT_DRIFT');
        }

        return $report;
    }

    /**
     * @param  array<string,mixed>  $releaseReport
     * @return array{published_count:int,working_count:int,approved_review_count:int,private_reviewer_names:list<string>}
     */
    private static function productionState(
        array $releaseReport,
        array $bindings,
        EnneagramPublicAuthorityV224RuntimeManifest $manifest,
    ): array {
        $published = [];
        $working = PersonalityPublicContentAssetRevision::query()
            ->where('authority_package_sha256', $bindings['package_sha256'])
            ->pluck('id')
            ->mapWithKeys(static fn (mixed $id): array => [(int) $id => true])
            ->all();
        $reviews = [];
        $reviewerNames = [];
        foreach ($manifest->readbackBatches($releaseReport) as $targets) {
            foreach ($targets as $target) {
                $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
                    ->where('org_id', 0)
                    ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM)
                    ->where('entity_type', (string) $target['entity_type'])
                    ->where('entity_key', (string) $target['code'])
                    ->where('locale', (string) $target['locale'])
                    ->first();
                $publishedRevision = $asset instanceof PersonalityPublicContentAsset && $asset->published_revision_id
                    ? PersonalityPublicContentAssetRevision::query()->find((int) $asset->published_revision_id)
                    : null;
                $review = $publishedRevision instanceof PersonalityPublicContentAssetRevision
                    ? PersonalityPublicContentAssetRevisionReview::query()
                        ->where('revision_id', (int) $publishedRevision->id)
                        ->first()
                    : null;
                if (! $publishedRevision instanceof PersonalityPublicContentAssetRevision
                    || ! $review instanceof PersonalityPublicContentAssetRevisionReview
                    || ! hash_equals((string) $publishedRevision->authority_package_sha256, $bindings['package_sha256'])
                    || ! isset($working[(int) $publishedRevision->id])
                    || $asset->working_revision_id !== null
                    || ! hash_equals((string) $review->authority_package_sha256, $bindings['package_sha256'])
                    || (string) $review->decision !== PersonalityPublicContentAssetRevisionReview::DECISION_APPROVED
                    || trim((string) $review->reviewer_name) === '') {
                    throw new RuntimeException('PROMOTION_STATE_DRIFT');
                }
                $published[(int) $publishedRevision->id] = true;
                $reviews[(int) $review->id] = true;
                $reviewerNames[trim((string) $review->reviewer_name)] = true;
            }
        }
        if (count($published) !== self::TARGET_COUNT
            || count($working) !== self::TARGET_COUNT
            || count($reviews) !== self::TARGET_COUNT) {
            throw new RuntimeException('PROMOTION_COUNTS_DRIFT');
        }

        return [
            'published_count' => count($published),
            'working_count' => count($working),
            'approved_review_count' => count($reviews),
            'private_reviewer_names' => array_keys($reviewerNames),
        ];
    }

    /** @param array<string,string> $environment @return array<string,mixed> */
    private static function bindings(array $environment, string $activeBackend): array
    {
        $bindings = [
            'preflight_run_id' => self::positiveInteger(self::required($environment, 'FM_ENNEAGRAM_PREFLIGHT_RUN_ID')),
            'preflight_run_attempt' => self::positiveInteger(self::required($environment, 'FM_ENNEAGRAM_PREFLIGHT_RUN_ATTEMPT')),
            'control_plane_sha' => self::gitSha(self::required($environment, 'FM_ENNEAGRAM_CONTROL_PLANE_SHA')),
            'runner_sha256' => self::hash(self::required($environment, 'FM_ENNEAGRAM_RUNNER_SHA256')),
            'backend_sha' => self::gitSha(self::required($environment, 'FM_ENNEAGRAM_BACKEND_SHA')),
            'frontend_sha' => self::gitSha(self::required($environment, 'FM_ENNEAGRAM_FRONTEND_SHA')),
            'package_sha256' => self::hash(self::required($environment, 'FM_ENNEAGRAM_PACKAGE_SHA256')),
            'release_report_sha256' => self::hash(self::required($environment, 'FM_ENNEAGRAM_RELEASE_REPORT_SHA256')),
            'runtime_config_apply_run_id' => self::positiveInteger(self::required($environment, 'FM_ENNEAGRAM_RUNTIME_CONFIG_APPLY_RUN_ID')),
            'runtime_config_apply_run_attempt' => self::positiveInteger(self::required($environment, 'FM_ENNEAGRAM_RUNTIME_CONFIG_APPLY_RUN_ATTEMPT')),
            'runtime_config_receipt_sha256' => self::hash(self::required($environment, 'FM_ENNEAGRAM_RUNTIME_CONFIG_RECEIPT_SHA256')),
            'rollback_token_sha256' => self::hash(self::required($environment, 'FM_ENNEAGRAM_ROLLBACK_TOKEN_SHA256')),
            'api_origin' => self::httpsOrigin(self::required($environment, 'FM_ENNEAGRAM_API_ORIGIN')),
            'frontend_origin' => self::httpsOrigin(self::required($environment, 'FM_ENNEAGRAM_FRONTEND_ORIGIN')),
        ];
        if ($bindings['runtime_config_apply_run_id'] !== self::RUNTIME_CONFIG_APPLY_RUN_ID
            || $bindings['runtime_config_apply_run_attempt'] !== self::RUNTIME_CONFIG_APPLY_RUN_ATTEMPT) {
            throw new RuntimeException('RUNTIME_CONFIG_APPLY_RUN_DRIFT');
        }
        $revision = trim((string) file_get_contents(dirname($activeBackend).'/REVISION'));
        if (! hash_equals($revision, $bindings['backend_sha'])) {
            throw new RuntimeException('BACKEND_REVISION_DRIFT');
        }
        $frontend = Http::acceptJson()->withoutRedirecting()->timeout(20)
            ->get($bindings['frontend_origin'].'/revision');
        if (! $frontend->successful()
            || ! hash_equals((string) $frontend->json('revision'), $bindings['frontend_sha'])) {
            throw new RuntimeException('FRONTEND_REVISION_DRIFT');
        }

        return $bindings;
    }

    /** @param array<string,mixed> $readback @return array<string,mixed> */
    private static function safeReadbackReceipt(array $readback): array
    {
        if (($readback['ok'] ?? false) !== true
            || (int) ($readback['private_data_exposed_count'] ?? -1) !== 0
            || (int) ($readback['non_empty_media_count'] ?? -1) !== 0
            || ($readback['writes_committed'] ?? true) !== false) {
            throw new RuntimeException('POST_READBACK_FAILED');
        }

        return [
            'batch' => $readback['batch'],
            'target_count' => $readback['target_count'],
            'api_read_count' => $readback['api_read_count'],
            'html_read_count' => $readback['html_read_count'],
            'private_data_exposed_count' => 0,
            'non_empty_media_count' => 0,
            'public_projection_fingerprint' => $readback['public_projection_fingerprint'],
            'stable_identity_discoverability_fingerprint' => $readback['stable_identity_discoverability_fingerprint'],
            'ok' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $releaseReport
     * @param  array<string,mixed>  $bindings
     * @param  list<string>  $privateReviewerNames
     * @return list<array<string,mixed>>
     */
    private static function runPostReadbackBatches(
        EnneagramPublicAuthorityV224RuntimeReadback $readbackService,
        array $releaseReport,
        array $bindings,
        array $privateReviewerNames,
    ): array {
        $batchReceipts = [];
        foreach (self::BATCH_NAMES as $batchName) {
            self::$failureStage = 'post_readback_'.$batchName;
            $readback = self::retryPostReadbackBatch(
                static fn (): array => $readbackService->run(
                    'post',
                    $batchName,
                    $releaseReport,
                    $bindings['api_origin'],
                    $bindings['frontend_origin'],
                    $bindings['backend_sha'],
                    $bindings['frontend_sha'],
                    false,
                    $privateReviewerNames,
                ),
                maxAttempts: $batchName === 'canary-00' ? 9 : 13,
            );
            $batchReceipts[] = self::safeReadbackReceipt($readback);
        }

        return $batchReceipts;
    }

    /**
     * @param  array<string,mixed>  $releaseReport
     * @return array<string,mixed>
     */
    private static function postReadbackSnapshot(
        EnneagramPublicAuthorityV224RuntimeReadback $readbackService,
        array $releaseReport,
        string $frontendOrigin,
        string $authorizedProjectionFingerprint,
        string $authorizedDiscoverabilityFingerprint,
        string $authorizedUrlSetsSha256,
    ): array {
        self::$failureStage = 'post_readback_snapshot';

        return self::retryPostReadbackSnapshot(
            static function () use (
                $readbackService,
                $releaseReport,
                $frontendOrigin,
                $authorizedProjectionFingerprint,
                $authorizedDiscoverabilityFingerprint,
                $authorizedUrlSetsSha256,
            ): array {
                $postSnapshot = $readbackService->snapshot(
                    $releaseReport,
                    $frontendOrigin,
                );
                if (! hash_equals(
                    $authorizedProjectionFingerprint,
                    (string) $postSnapshot['public_projection_fingerprint'],
                )) {
                    throw new RuntimeException('POST_READBACK_PUBLIC_PROJECTION_DRIFT');
                }
                if (! hash_equals(
                    $authorizedDiscoverabilityFingerprint,
                    (string) $postSnapshot['stable_identity_discoverability_fingerprint'],
                )) {
                    throw new RuntimeException('POST_READBACK_DISCOVERABILITY_DRIFT');
                }
                if (! hash_equals(
                    $authorizedUrlSetsSha256,
                    self::fingerprint($postSnapshot['url_sets']),
                )) {
                    throw new RuntimeException('POST_READBACK_URL_SETS_DRIFT');
                }

                return $postSnapshot;
            },
        );
    }

    private static function bootstrapApplication(string $backend): void
    {
        require_once $backend.'/vendor/autoload.php';
        $application = require $backend.'/bootstrap/app.php';
        $application->make(Kernel::class)->bootstrap();
        if ((string) app()->environment() !== 'production') {
            throw new RuntimeException('NON_PRODUCTION_RUNTIME');
        }
    }

    private static function managedBackendPath(string $path, string $root): string
    {
        $realPath = realpath($path);
        $realRoot = realpath($root);
        if (! is_string($realPath)
            || ! is_string($realRoot)
            || ! str_starts_with($realPath.'/', rtrim($realRoot, '/').'/')
            || basename($realPath) !== 'backend'
            || ! is_file($realPath.'/bootstrap/app.php')
            || ! is_file($realPath.'/vendor/autoload.php')
            || ! is_file(dirname($realPath).'/REVISION')) {
            throw new RuntimeException('UNMANAGED_ACTIVE_BACKEND');
        }

        return $realPath;
    }

    /** @return array<string,string> */
    private static function environment(): array
    {
        $names = [
            'FM_ENNEAGRAM_RESUME_MODE',
            'FM_ENNEAGRAM_ACTIVE_BACKEND',
            'FM_ENNEAGRAM_MANAGED_DEPLOY_ROOT',
            'FM_ENNEAGRAM_PREFLIGHT_RUN_ID',
            'FM_ENNEAGRAM_PREFLIGHT_RUN_ATTEMPT',
            'FM_ENNEAGRAM_CONTROL_PLANE_SHA',
            'FM_ENNEAGRAM_RUNNER_SHA256',
            'FM_ENNEAGRAM_BACKEND_SHA',
            'FM_ENNEAGRAM_FRONTEND_SHA',
            'FM_ENNEAGRAM_PACKAGE_SHA256',
            'FM_ENNEAGRAM_RELEASE_REPORT_SHA256',
            'FM_ENNEAGRAM_RUNTIME_CONFIG_APPLY_RUN_ID',
            'FM_ENNEAGRAM_RUNTIME_CONFIG_APPLY_RUN_ATTEMPT',
            'FM_ENNEAGRAM_RUNTIME_CONFIG_RECEIPT_SHA256',
            'FM_ENNEAGRAM_ROLLBACK_TOKEN_SHA256',
            'FM_ENNEAGRAM_API_ORIGIN',
            'FM_ENNEAGRAM_FRONTEND_ORIGIN',
            'FM_ENNEAGRAM_EXPECTED_STATE_FINGERPRINT',
            'FM_ENNEAGRAM_AUTHORIZED_PUBLIC_PROJECTION_FINGERPRINT',
            'FM_ENNEAGRAM_AUTHORIZED_DISCOVERABILITY_FINGERPRINT',
            'FM_ENNEAGRAM_AUTHORIZED_URL_SETS_SHA256',
            'FM_ENNEAGRAM_AUTHORIZATION_PHRASE',
            'FM_ENNEAGRAM_SOURCE_EXECUTE_RUN_ID',
            'FM_ENNEAGRAM_SOURCE_EXECUTE_RUN_ATTEMPT',
            'FM_ENNEAGRAM_SOURCE_EXECUTE_CONTROL_PLANE_SHA',
            'FM_ENNEAGRAM_SOURCE_EXECUTE_RUNNER_SHA256',
            'FM_ENNEAGRAM_SOURCE_EXECUTE_RECEIPT_SHA256',
        ];
        $environment = [];
        foreach ($names as $name) {
            $value = getenv($name);
            $environment[$name] = is_string($value) ? $value : '';
        }

        return $environment;
    }

    /** @param array<string,string> $environment */
    private static function required(array $environment, string $name): string
    {
        $value = trim((string) ($environment[$name] ?? ''));
        if ($value === '') {
            throw new RuntimeException('MISSING_REQUIRED_INPUT');
        }

        return $value;
    }

    private static function gitSha(string $value): string
    {
        if (preg_match('/^[0-9a-f]{40}$/D', $value) !== 1) {
            throw new RuntimeException('INVALID_GIT_SHA');
        }

        return $value;
    }

    private static function hash(string $value): string
    {
        self::assertHash($value, 'INVALID_SHA256');

        return $value;
    }

    private static function assertHash(string $value, string $code): void
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new RuntimeException($code);
        }
    }

    private static function positiveInteger(string $value): int
    {
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new RuntimeException('INVALID_POSITIVE_INTEGER');
        }

        return (int) $value;
    }

    private static function httpsOrigin(string $value): string
    {
        if (! self::isHttpsUrl($value)
            || parse_url($value, PHP_URL_PATH) !== null
            || parse_url($value, PHP_URL_QUERY) !== null
            || parse_url($value, PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException('INVALID_HTTPS_ORIGIN');
        }

        return rtrim($value, '/');
    }

    private static function isHttpsUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https';
    }

    private static function fingerprint(mixed $value): string
    {
        return hash('sha256', json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private static function safeErrorCode(Throwable $throwable): string
    {
        if ($throwable instanceof ConnectionException) {
            return 'TRANSIENT_HTTP_READ_FAILURE';
        }

        $code = strtoupper(trim($throwable->getMessage()));
        if (preg_match('/^[A-Z_]+ URL-SET READBACK FAILED WITH HTTP [0-9]{3}\\.$/D', $code) === 1) {
            return 'URL_SET_HTTP_READ_FAILURE';
        }
        if (str_contains($code, 'ENNEAGRAM URL SUBSET DOES NOT MATCH')) {
            return 'URL_SET_SUBSET_DRIFT';
        }
        if (str_contains($code, 'DISCOVERABILITY SET IS NOT EXACTLY 116 PATHS')) {
            return 'EXPECTED_URL_SET_COUNT_DRIFT';
        }

        if (preg_match('/^[A-Z0-9_]{3,80}$/D', $code) === 1) {
            return $code;
        }

        $stage = preg_replace('/[^A-Z0-9_]+/', '_', strtoupper(self::$failureStage));

        return ($stage !== null && $stage !== '' ? $stage : 'UNEXPECTED').'_FAILED';
    }

    /** @param array<string,mixed> $receipt */
    private static function emit(array $receipt): void
    {
        fwrite(STDOUT, json_encode(
            $receipt,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ).PHP_EOL);
    }
}

if (getenv('FM_ENNEAGRAM_RUNNER_EXECUTE') === '1') {
    exit(EnneagramPr23CacheOnlyResumeRunner::main());
}
