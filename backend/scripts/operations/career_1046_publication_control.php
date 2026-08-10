<?php

declare(strict_types=1);

namespace FermatMind\Operations;

use App\Domain\Career\Publish\CareerFullReleaseLedgerProjectionService;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionExporter;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionLookup;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

final class Career1046PublicationControlFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

final class Career1046PublicationControl
{
    public const CONTRACT_VERSION = 'career.1046.publication_control.runner.v1';

    private const MANIFEST_FILENAME = 'detail-ready-1046-rollout-manifest.v1.json';

    private const CANARY_SLUG = 'accountants-and-auditors';

    private const PUBLIC_CANONICAL_JOB = 'public_canonical_job';

    /** @var list<int> */
    private const ALLOWED_BATCH_SIZES = [10, 25, 50, 100];

    public static function main(array $argv): int
    {
        $mode = strtolower(trim((string) ($argv[1] ?? '')));

        try {
            return match ($mode) {
                'inspect' => self::inspect(),
                'apply' => self::apply(),
                'assess' => self::assess(),
                default => throw new Career1046PublicationControlFailure('MODE_INVALID'),
            };
        } catch (Career1046PublicationControlFailure $failure) {
            self::emit(self::holdReceipt($mode, $failure->safeCode));

            return 1;
        } catch (Throwable) {
            self::emit(self::holdReceipt($mode, 'UNEXPECTED_CONTROL_FAILURE'));

            return 1;
        }
    }

    private static function inspect(): int
    {
        $app = self::bootstrapApplication();
        $context = self::verifiedBatchContext($app, runDryRun: true);

        self::emit([
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'inspect',
            'status' => $context['batch_kind'] === 'canary'
                ? 'PASS_CANARY_VERIFY'
                : 'PASS_BATCH_VERIFY',
            'manifest_sha256' => $context['manifest_sha256'],
            'before_projection_sha256' => $context['artifact_sha256'],
            'live_projection_sha256' => $context['live_projection_sha256'],
            'target_set_sha256' => $context['target_set_sha256'],
            'before_published_slug_set_sha256' => $context['published_slug_set_sha256'],
            'before_published_row_set_sha256' => $context['published_row_set_sha256'],
            'before_published_slug_count' => $context['published_slug_count'],
            'before_published_row_count' => $context['published_row_count'],
            'batch_kind' => $context['batch_kind'],
            'batch_id' => $context['batch_id'],
            'batch_size' => $context['batch_size'],
            'batch_slug_set_sha256' => $context['batch_slug_set_sha256'],
            'rollback_group_sha256' => $context['rollback_group_sha256'],
            'failure_policy' => $context['failure_policy'],
            'expected_after_published_slug_count' => $context['expected_after_published_slug_count'],
            'expected_after_published_row_count' => $context['expected_after_published_row_count'],
            'dry_run' => $context['dry_run'],
            'loader_matches_materialized_projection' => true,
            'database_write_count' => 0,
            'publication_write_count' => 0,
            'artifact_write_count' => 0,
            'cache_write_count' => 0,
            'deploy_count' => 0,
            'migration_count' => 0,
            'sitemap_submission_count' => 0,
            'llms_submission_count' => 0,
            'search_submission_count' => 0,
            'automatic_retry_allowed' => false,
        ]);

        return 0;
    }

    private static function apply(): int
    {
        if (self::env('CAREER_1046_PUBLICATION_APPLY_AUTHORIZED') !== '1') {
            throw new Career1046PublicationControlFailure('APPLY_NOT_AUTHORIZED');
        }

        $app = self::bootstrapApplication();
        $before = self::verifiedBatchContext($app, runDryRun: true);
        $expectedBefore = self::shaEnv('CAREER_1046_EXPECTED_BEFORE_PROJECTION_SHA256');
        if (! hash_equals($expectedBefore, $before['artifact_sha256'])) {
            throw new Career1046PublicationControlFailure('BEFORE_PROJECTION_BYTES_DRIFT');
        }

        $arguments = [
            '--batch-id' => $before['batch_id'],
            '--slugs' => implode(',', $before['batch_slugs']),
            '--locales' => 'en,zh',
            '--rollback-group' => implode(',', $before['batch_slugs']),
            '--apply' => true,
            '--projection' => $before['artifact_path'],
            '--json' => true,
            '--no-interaction' => true,
            '--no-ansi' => true,
        ];
        if ($before['failure_policy'] === 'quarantine') {
            $arguments['--quarantine-on-failure'] = true;
        }

        $exitCode = Artisan::call('career:execute-canonical-rollout-batch', $arguments);
        $result = json_decode(Artisan::output(), true);
        if (! is_array($result)) {
            self::emit(self::ambiguousApplyReceipt($before, 'APPLY_RESULT_NON_JSON'));

            return 2;
        }
        if ($exitCode !== 0 || ($result['status'] ?? null) !== 'promoted_success') {
            self::emit(self::failedApplyReceipt($before, $result));

            return 1;
        }
        if (($result['writes_database'] ?? false) !== true
            || ($result['write_verified'] ?? false) !== true
            || ($result['rollback_required'] ?? true) !== false
            || ($result['quarantine_required'] ?? true) !== false
            || count((array) ($result['promoted_slugs'] ?? [])) !== $before['batch_size']
            || (int) ($result['promoted_locale_rows'] ?? -1) !== $before['batch_size'] * 2) {
            self::emit(self::ambiguousApplyReceipt($before, 'APPLY_RESULT_CONTRACT_DRIFT'));

            return 2;
        }

        $timestamp = self::timestampEnv('CAREER_1046_PUBLICATION_TIMESTAMP');
        $ledgerExit = Artisan::call('career:export-full-release-ledger', [
            '--timestamp' => $timestamp,
            '--json' => true,
            '--no-interaction' => true,
            '--no-ansi' => true,
        ]);
        $ledgerResult = json_decode(Artisan::output(), true);
        $ledgerPath = is_array($ledgerResult)
            ? data_get($ledgerResult, 'artifacts.'.CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME)
            : null;
        if ($ledgerExit !== 0 || ! is_string($ledgerPath) || ! is_file($ledgerPath) || is_link($ledgerPath)) {
            self::emit(self::ambiguousApplyReceipt($before, 'POST_COMMIT_LEDGER_MATERIALIZATION_FAILED'));

            return 2;
        }

        $projectionExit = Artisan::call('career:export-runtime-publish-projection', [
            '--timestamp' => $timestamp,
            '--ledger' => $ledgerPath,
            '--json' => true,
            '--no-interaction' => true,
            '--no-ansi' => true,
        ]);
        $projectionResult = json_decode(Artisan::output(), true);
        $projectionPath = is_array($projectionResult)
            ? data_get($projectionResult, 'artifacts.'.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME)
            : null;
        if ($projectionExit !== 0 || ! is_string($projectionPath) || ! is_file($projectionPath) || is_link($projectionPath)) {
            self::emit(self::ambiguousApplyReceipt($before, 'POST_COMMIT_PROJECTION_MATERIALIZATION_FAILED'));

            return 2;
        }

        clearstatcache(true, $ledgerPath);
        clearstatcache(true, $projectionPath);
        $ledgerSha256 = hash_file('sha256', $ledgerPath);
        $projectionSha256 = hash_file('sha256', $projectionPath);
        if (! is_string($ledgerSha256) || ! is_string($projectionSha256)) {
            self::emit(self::ambiguousApplyReceipt($before, 'POST_COMMIT_ARTIFACT_HASH_FAILED'));

            return 2;
        }

        $after = self::stateContext($app);
        $expectedAfterSlugs = array_values(array_unique(array_merge(
            $before['published_slugs'],
            $before['batch_slugs'],
        )));
        sort($expectedAfterSlugs, SORT_STRING);
        if ($after['artifact_sha256'] !== $projectionSha256
            || $after['published_slugs'] !== $expectedAfterSlugs
            || $after['published_slug_count'] !== $before['expected_after_published_slug_count']
            || $after['published_row_count'] !== $before['expected_after_published_row_count']
            || $after['artifact_published_slugs'] !== $after['live_published_slugs']
            || $after['loader_published_slugs'] !== $after['artifact_published_slugs']) {
            self::emit(self::ambiguousApplyReceipt($before, 'POST_COMMIT_RUNTIME_READBACK_DRIFT'));

            return 2;
        }

        self::emit([
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'apply',
            'status' => 'PASS_BATCH_PROMOTED',
            'manifest_sha256' => $before['manifest_sha256'],
            'before_projection_sha256' => $before['artifact_sha256'],
            'after_projection_sha256' => $projectionSha256,
            'after_ledger_sha256' => $ledgerSha256,
            'target_set_sha256' => $before['target_set_sha256'],
            'before_published_slug_set_sha256' => $before['published_slug_set_sha256'],
            'after_published_slug_set_sha256' => $after['published_slug_set_sha256'],
            'after_published_row_set_sha256' => $after['published_row_set_sha256'],
            'before_published_slug_count' => $before['published_slug_count'],
            'after_published_slug_count' => $after['published_slug_count'],
            'after_published_row_count' => $after['published_row_count'],
            'batch_kind' => $before['batch_kind'],
            'batch_id' => $before['batch_id'],
            'batch_size' => $before['batch_size'],
            'batch_slug_set_sha256' => $before['batch_slug_set_sha256'],
            'rollback_group_sha256' => $before['rollback_group_sha256'],
            'failure_policy' => $before['failure_policy'],
            'promoted_slug_count' => count((array) ($result['promoted_slugs'] ?? [])),
            'promoted_locale_row_count' => (int) ($result['promoted_locale_rows'] ?? 0),
            'loader_matches_materialized_projection' => true,
            'database_write_count' => $before['batch_size'],
            'publication_write_count' => $before['batch_size'],
            'artifact_write_count' => 2,
            'rollback_executed' => false,
            'quarantine_executed' => false,
            'deploy_count' => 0,
            'migration_count' => 0,
            'sitemap_submission_count' => 0,
            'llms_submission_count' => 0,
            'search_submission_count' => 0,
            'automatic_retry_allowed' => false,
        ]);

        return 0;
    }

    private static function assess(): int
    {
        $app = self::bootstrapApplication();
        $state = self::stateContext($app);
        $batch = self::batchInput(self::manifest());
        $expectedBefore = self::shaEnv('CAREER_1046_EXPECTED_BEFORE_PROJECTION_SHA256');
        $published = array_flip($state['published_slugs']);
        $publishedBatchCount = count(array_filter(
            $batch['slugs'],
            static fn (string $slug): bool => isset($published[$slug]),
        ));

        if ($publishedBatchCount === count($batch['slugs'])
            && ! hash_equals($expectedBefore, $state['artifact_sha256'])) {
            $status = 'PASS_APPLY_OBSERVED';
            $exit = 0;
        } elseif ($publishedBatchCount === 0
            && hash_equals($expectedBefore, $state['artifact_sha256'])) {
            $status = 'PASS_NO_APPLY_OBSERVED';
            $exit = 0;
        } else {
            $status = 'HOLD_PARTIAL_OR_ARTIFACT_DRIFT';
            $exit = 2;
        }

        self::emit([
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'assess',
            'status' => $status,
            'manifest_sha256' => $state['manifest_sha256'],
            'expected_before_projection_sha256' => $expectedBefore,
            'observed_projection_sha256' => $state['artifact_sha256'],
            'batch_id' => $batch['batch_id'],
            'batch_size' => count($batch['slugs']),
            'batch_slug_set_sha256' => $batch['set_sha256'],
            'published_batch_slug_count' => $publishedBatchCount,
            'published_slug_count' => $state['published_slug_count'],
            'published_row_count' => $state['published_row_count'],
            'loader_matches_materialized_projection' => $state['loader_published_slugs'] === $state['artifact_published_slugs'],
            'database_write_count' => 0,
            'publication_write_count' => 0,
            'artifact_write_count' => 0,
            'cache_write_count' => 0,
            'deploy_count' => 0,
            'migration_count' => 0,
            'sitemap_submission_count' => 0,
            'llms_submission_count' => 0,
            'search_submission_count' => 0,
            'automatic_retry_allowed' => false,
        ]);

        return $exit;
    }

    /** @return array<string, mixed> */
    private static function verifiedBatchContext(object $app, bool $runDryRun): array
    {
        $state = self::stateContext($app);
        $manifest = $state['manifest'];
        $batch = self::batchInput($manifest);
        $baseline = $manifest['baseline_slugs'];
        $delta = $manifest['delta_slugs'];
        $publishedSet = array_flip($state['published_slugs']);

        foreach ($baseline as $slug) {
            if (! isset($publishedSet[$slug])) {
                throw new Career1046PublicationControlFailure('BASELINE_PUBLICATION_DRIFT');
            }
        }

        $publishedDelta = array_values(array_filter(
            $delta,
            static fn (string $slug): bool => isset($publishedSet[$slug]),
        ));
        $expectedPublishedDelta = array_slice($delta, 0, count($publishedDelta));
        if ($publishedDelta !== $expectedPublishedDelta) {
            throw new Career1046PublicationControlFailure('DELTA_SEQUENCE_DRIFT');
        }

        $next = array_slice($delta, count($publishedDelta), count($batch['slugs']));
        if ($next !== $batch['slugs']) {
            throw new Career1046PublicationControlFailure('BATCH_NOT_NEXT_CONTIGUOUS_DELTA');
        }
        if ($batch['kind'] === 'canary') {
            if (count($publishedDelta) !== 0 || $batch['slugs'] !== [self::CANARY_SLUG]) {
                throw new Career1046PublicationControlFailure('AA_CANARY_SEQUENCE_INVALID');
            }
        } elseif (! isset($publishedSet[self::CANARY_SLUG])) {
            throw new Career1046PublicationControlFailure('AA_CANARY_NOT_PUBLISHED');
        }

        $dryRun = null;
        if ($runDryRun) {
            $exitCode = Artisan::call('career:execute-canonical-rollout-batch', [
                '--batch-id' => $batch['batch_id'],
                '--slugs' => implode(',', $batch['slugs']),
                '--locales' => 'en,zh',
                '--rollback-group' => implode(',', $batch['slugs']),
                '--dry-run' => true,
                '--no-audit-write' => true,
                '--projection' => $state['artifact_path'],
                '--json' => true,
                '--no-interaction' => true,
                '--no-ansi' => true,
            ]);
            $payload = json_decode(Artisan::output(), true);
            if ($exitCode !== 0 || ! is_array($payload)
                || ($payload['status'] ?? null) !== 'planned'
                || ($payload['writes_database'] ?? true) !== false
                || (int) ($payload['promoted_locale_rows'] ?? -1) !== count($batch['slugs']) * 2
                || (array) ($payload['promoted_slugs'] ?? []) !== $batch['slugs']
                || (array) data_get($payload, 'plan_validation.failures', []) !== []) {
                throw new Career1046PublicationControlFailure('BATCH_DRY_RUN_GATE_FAILED');
            }
            $dryRun = [
                'status' => 'planned',
                'writes_database' => false,
                'occupation_count' => (int) ($payload['occupation_count'] ?? 0),
                'promoted_locale_rows' => (int) ($payload['promoted_locale_rows'] ?? 0),
                'failure_count' => 0,
            ];
        }

        return array_merge($state, [
            'batch_kind' => $batch['kind'],
            'batch_id' => $batch['batch_id'],
            'batch_slugs' => $batch['slugs'],
            'batch_size' => count($batch['slugs']),
            'batch_slug_set_sha256' => $batch['set_sha256'],
            'rollback_group_sha256' => $batch['rollback_group_sha256'],
            'failure_policy' => $batch['failure_policy'],
            'expected_after_published_slug_count' => $state['published_slug_count'] + count($batch['slugs']),
            'expected_after_published_row_count' => $state['published_row_count'] + (count($batch['slugs']) * 2),
            'dry_run' => $dryRun,
        ]);
    }

    /** @return array<string, mixed> */
    private static function stateContext(object $app): array
    {
        $manifest = self::manifest();
        $artifact = self::latestProjectionArtifact();
        $liveProjection = $app->make(CareerRuntimePublishProjectionExporter::class)->build();
        $artifactSnapshot = self::projectionSnapshot($artifact['payload'], $manifest['target_slugs']);
        $liveSnapshot = self::projectionSnapshot($liveProjection, $manifest['target_slugs']);
        $loaderItems = array_values($app->make(CareerRuntimePublishProjectionLookup::class)
            ->jobDetailCoverageItems(['en', 'zh-CN']));
        $loaderSnapshot = self::projectionSnapshot(['items' => $loaderItems], $manifest['target_slugs']);

        if ($liveSnapshot['target_row_count'] !== 2092
            || $liveSnapshot['target_slug_count'] !== 1046
            || $liveSnapshot['missing_target_row_count'] !== 0) {
            throw new Career1046PublicationControlFailure('LIVE_1046_AUTHORITY_INCOMPLETE');
        }
        if ($artifactSnapshot['published_slugs'] !== $liveSnapshot['published_slugs']) {
            throw new Career1046PublicationControlFailure('ARTIFACT_LIVE_PUBLISHED_SET_DRIFT');
        }
        if ($loaderSnapshot['published_slugs'] !== $artifactSnapshot['published_slugs']) {
            throw new Career1046PublicationControlFailure('LOADER_ARTIFACT_PUBLISHED_SET_DRIFT');
        }
        foreach ([$liveSnapshot, $artifactSnapshot, $loaderSnapshot] as $snapshot) {
            if ($snapshot['published_row_count'] !== count($snapshot['published_slugs']) * 2) {
                throw new Career1046PublicationControlFailure('PUBLISHED_LOCALE_ROW_PARITY_DRIFT');
            }
        }
        if ($liveSnapshot['outside_target_published_slug_count'] !== 0
            || $artifactSnapshot['outside_target_published_slug_count'] !== 0) {
            throw new Career1046PublicationControlFailure('EXTRA_PUBLISHED_CANONICAL_SLUGS');
        }

        $liveEncoded = json_encode($liveProjection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($liveEncoded)) {
            throw new Career1046PublicationControlFailure('LIVE_PROJECTION_ENCODE_FAILED');
        }

        return [
            'manifest' => $manifest,
            'manifest_sha256' => $manifest['sha256'],
            'target_set_sha256' => self::setHash($manifest['target_slugs']),
            'artifact_path' => $artifact['path'],
            'artifact_sha256' => $artifact['sha256'],
            'live_projection_sha256' => hash('sha256', $liveEncoded),
            'published_slugs' => $liveSnapshot['published_slugs'],
            'artifact_published_slugs' => $artifactSnapshot['published_slugs'],
            'live_published_slugs' => $liveSnapshot['published_slugs'],
            'loader_published_slugs' => $loaderSnapshot['published_slugs'],
            'published_slug_count' => count($liveSnapshot['published_slugs']),
            'published_row_count' => $liveSnapshot['published_row_count'],
            'published_slug_set_sha256' => self::setHash($liveSnapshot['published_slugs']),
            'published_row_set_sha256' => $liveSnapshot['published_row_set_sha256'],
        ];
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        $path = self::backendRoot().'/docs/seo/generated/'.self::MANIFEST_FILENAME;
        if (! is_file($path) || is_link($path)) {
            throw new Career1046PublicationControlFailure('MANIFEST_MISSING_OR_SYMLINKED');
        }
        $bytes = file_get_contents($path);
        $payload = is_string($bytes) ? json_decode($bytes, true) : null;
        if (! is_array($payload)) {
            throw new Career1046PublicationControlFailure('MANIFEST_JSON_INVALID');
        }
        $sha256 = hash('sha256', $bytes);
        if (! hash_equals(self::shaEnv('CAREER_1046_EXPECTED_MANIFEST_SHA256'), $sha256)) {
            throw new Career1046PublicationControlFailure('MANIFEST_BYTES_DRIFT');
        }

        $baseline = self::slugList($payload['baseline_slugs'] ?? null, 'BASELINE_SLUGS_INVALID');
        $delta = self::slugList($payload['delta_slugs'] ?? null, 'DELTA_SLUGS_INVALID');
        $rollbackGroup = self::slugList($payload['rollback_group'] ?? null, 'MANIFEST_ROLLBACK_GROUP_INVALID');
        $target = array_values(array_merge($baseline, $delta));
        if (($payload['schema_version'] ?? null) !== 'detail_ready_1046_rollout_manifest.v1'
            || ($payload['manifest_safe'] ?? false) !== true
            || ($payload['read_only'] ?? false) !== true
            || ($payload['writes_database'] ?? true) !== false
            || ($payload['apply_allowed'] ?? true) !== false
            || ($payload['rollout_apply_allowed'] ?? true) !== false
            || count($baseline) !== 30
            || count($delta) !== 1016
            || count($target) !== 1046
            || count(array_unique($target)) !== 1046
            || $rollbackGroup !== $delta
            || $delta[0] !== self::CANARY_SLUG
            || in_array('software-developers', $target, true)
            || in_array('digital-forensics-analysts', $target, true)
            || in_array('computer-occupations-all-other', $target, true)) {
            throw new Career1046PublicationControlFailure('MANIFEST_1046_CONTRACT_INVALID');
        }

        return [
            'sha256' => $sha256,
            'baseline_slugs' => $baseline,
            'delta_slugs' => $delta,
            'target_slugs' => $target,
        ];
    }

    /** @return array{kind: string, batch_id: string, slugs: list<string>, set_sha256: string, rollback_group_sha256: string, failure_policy: string} */
    private static function batchInput(array $manifest): array
    {
        $kind = strtolower(self::env('CAREER_1046_BATCH_KIND'));
        if (! in_array($kind, ['canary', 'batch'], true)) {
            throw new Career1046PublicationControlFailure('BATCH_KIND_INVALID');
        }
        $batchId = self::env('CAREER_1046_BATCH_ID');
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', $batchId) !== 1) {
            throw new Career1046PublicationControlFailure('BATCH_ID_INVALID');
        }
        $slugs = self::slugList(explode(',', self::env('CAREER_1046_BATCH_SLUGS')), 'BATCH_SLUGS_INVALID');
        if (count($slugs) !== count(array_unique($slugs))) {
            throw new Career1046PublicationControlFailure('BATCH_SLUGS_DUPLICATED');
        }
        if ($kind === 'canary' && $slugs !== [self::CANARY_SLUG]) {
            throw new Career1046PublicationControlFailure('CANARY_BATCH_INVALID');
        }
        if ($kind === 'batch' && ! in_array(count($slugs), self::ALLOWED_BATCH_SIZES, true)) {
            throw new Career1046PublicationControlFailure('BATCH_SIZE_INVALID');
        }
        foreach ($slugs as $slug) {
            if (! in_array($slug, $manifest['delta_slugs'], true)) {
                throw new Career1046PublicationControlFailure('BATCH_SLUG_OUTSIDE_DELTA');
            }
        }
        $setSha = self::setHash($slugs);
        if (! hash_equals(self::shaEnv('CAREER_1046_EXPECTED_BATCH_SLUG_SET_SHA256'), $setSha)) {
            throw new Career1046PublicationControlFailure('BATCH_SLUG_SET_SHA_DRIFT');
        }
        $rollbackSha = self::shaEnv('CAREER_1046_EXPECTED_ROLLBACK_GROUP_SHA256');
        if (! hash_equals($rollbackSha, $setSha)) {
            throw new Career1046PublicationControlFailure('ROLLBACK_GROUP_MUST_EQUAL_BATCH');
        }
        $failurePolicy = strtolower(self::env('CAREER_1046_FAILURE_POLICY'));
        if (! in_array($failurePolicy, ['rollback', 'quarantine'], true)) {
            throw new Career1046PublicationControlFailure('FAILURE_POLICY_INVALID');
        }

        return [
            'kind' => $kind,
            'batch_id' => $batchId,
            'slugs' => $slugs,
            'set_sha256' => $setSha,
            'rollback_group_sha256' => $rollbackSha,
            'failure_policy' => $failurePolicy,
        ];
    }

    /** @return array<string, mixed> */
    private static function projectionSnapshot(array $projection, array $targetSlugs): array
    {
        $targetSet = array_flip($targetSlugs);
        $targetRows = [];
        $publishedRows = [];
        $publishedBySlug = [];
        $outsidePublished = [];
        foreach ((array) ($projection['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $slug = strtolower(trim((string) ($item['slug'] ?? '')));
            $locale = self::normalizeLocale((string) ($item['locale'] ?? ''));
            if ($slug === '' || $locale === null) {
                continue;
            }
            $isCanonical = ($item['public_resolution_type'] ?? null) === self::PUBLIC_CANONICAL_JOB;
            $isPublished = $isCanonical
                && ($item['runtime_publish_state'] ?? null) === CareerRuntimePublishProjectionService::STATE_PUBLISHED
                && ($item['release_gate_pass'] ?? false) === true;
            if ($isPublished && ! isset($targetSet[$slug])) {
                $outsidePublished[$slug] = true;
            }
            if (! isset($targetSet[$slug])) {
                continue;
            }
            $key = $slug.'|'.$locale;
            $targetRows[$key] = true;
            if ($isPublished) {
                $publishedRows[$key] = true;
                $publishedBySlug[$slug][$locale] = true;
            }
        }

        $publishedSlugs = [];
        foreach ($publishedBySlug as $slug => $locales) {
            if (isset($locales['en'], $locales['zh'])) {
                $publishedSlugs[] = $slug;
            }
        }
        sort($publishedSlugs, SORT_STRING);
        $publishedRowKeys = array_keys($publishedRows);
        sort($publishedRowKeys, SORT_STRING);

        return [
            'target_slug_count' => count(array_unique(array_map(
                static fn (string $row): string => explode('|', $row, 2)[0],
                array_keys($targetRows),
            ))),
            'target_row_count' => count($targetRows),
            'missing_target_row_count' => max(0, count($targetSlugs) * 2 - count($targetRows)),
            'published_slugs' => $publishedSlugs,
            'published_row_count' => count($publishedRowKeys),
            'published_row_set_sha256' => self::setHash($publishedRowKeys),
            'outside_target_published_slug_count' => count($outsidePublished),
        ];
    }

    /** @return array{path: string, sha256: string, payload: array<string, mixed>} */
    private static function latestProjectionArtifact(): array
    {
        $root = self::backendRoot().'/storage/app/private/career_runtime_publish_projection';
        $directories = is_dir($root) ? glob($root.'/*', GLOB_ONLYDIR) : false;
        if (! is_array($directories) || $directories === []) {
            throw new Career1046PublicationControlFailure('PROJECTION_ARTIFACT_MISSING');
        }
        $candidates = [];
        foreach ($directories as $directory) {
            $path = $directory.'/'.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME;
            clearstatcache(true, $path);
            $candidates[] = [
                'path' => $path,
                'mtime' => is_file($path) ? ((int) (@filemtime($path) ?: 0)) : ((int) (@filemtime($directory) ?: 0)),
            ];
        }
        usort($candidates, static fn (array $left, array $right): int => ($right['mtime'] <=> $left['mtime']) ?: strcmp($right['path'], $left['path'])
        );
        $path = (string) ($candidates[0]['path'] ?? '');
        if (! is_file($path) || ! is_readable($path) || is_link($path)) {
            throw new Career1046PublicationControlFailure('LATEST_PROJECTION_ARTIFACT_UNREADABLE');
        }
        $bytes = file_get_contents($path);
        $payload = is_string($bytes) ? json_decode($bytes, true) : null;
        if (! is_array($payload)
            || ($payload['projection_kind'] ?? null) !== CareerRuntimePublishProjectionService::PROJECTION_KIND) {
            throw new Career1046PublicationControlFailure('LATEST_PROJECTION_ARTIFACT_INVALID');
        }

        return ['path' => $path, 'sha256' => hash('sha256', $bytes), 'payload' => $payload];
    }

    private static function bootstrapApplication(): object
    {
        $root = self::backendRoot();
        require_once $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    private static function backendRoot(): string
    {
        $root = rtrim(self::env('CAREER_1046_BACKEND_ROOT'), '/');
        if ($root === '' || ! is_dir($root) || is_link($root)) {
            throw new Career1046PublicationControlFailure('BACKEND_ROOT_INVALID');
        }

        return $root;
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        if (! is_string($value) || trim($value) === '') {
            throw new Career1046PublicationControlFailure($name.'_MISSING');
        }

        return trim($value);
    }

    private static function shaEnv(string $name): string
    {
        $value = strtolower(self::env($name));
        if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new Career1046PublicationControlFailure($name.'_INVALID');
        }

        return $value;
    }

    private static function timestampEnv(string $name): string
    {
        $value = self::env($name);
        if (preg_match('/^[0-9]{8}T[0-9]{6}Z-career1046-[1-9][0-9]*-[1-9][0-9]*$/', $value) !== 1) {
            throw new Career1046PublicationControlFailure('PUBLICATION_TIMESTAMP_INVALID');
        }

        return $value;
    }

    /** @return list<string> */
    private static function slugList(mixed $value, string $failure): array
    {
        if (! is_array($value)) {
            throw new Career1046PublicationControlFailure($failure);
        }
        $slugs = [];
        foreach ($value as $slug) {
            $normalized = strtolower(trim((string) $slug));
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $normalized) !== 1) {
                throw new Career1046PublicationControlFailure($failure);
            }
            $slugs[] = $normalized;
        }

        return array_values($slugs);
    }

    private static function normalizeLocale(string $locale): ?string
    {
        $normalized = strtolower(trim($locale));

        return match (true) {
            $normalized === 'en' => 'en',
            str_starts_with($normalized, 'zh') => 'zh',
            default => null,
        };
    }

    /** @param list<string> $values */
    private static function setHash(array $values): string
    {
        $normalized = array_values(array_unique($values));
        sort($normalized, SORT_STRING);

        return hash('sha256', implode("\n", $normalized)."\n");
    }

    /** @return array<string, mixed> */
    private static function failedApplyReceipt(array $before, array $result): array
    {
        $rollback = (bool) ($result['promotion_rolled_back'] ?? false);
        $quarantine = (bool) ($result['promotion_quarantined'] ?? false);
        $status = $rollback
            ? 'HOLD_APPLY_ROLLED_BACK'
            : ($quarantine ? 'HOLD_APPLY_QUARANTINED' : 'HOLD_APPLY_FAILED');

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'apply',
            'status' => $status,
            'safe_failure_code' => (string) ($result['status'] ?? 'APPLY_FAILED'),
            'before_projection_sha256' => $before['artifact_sha256'],
            'batch_id' => $before['batch_id'],
            'batch_size' => $before['batch_size'],
            'batch_slug_set_sha256' => $before['batch_slug_set_sha256'],
            'rollback_group_sha256' => $before['rollback_group_sha256'],
            'failure_policy' => $before['failure_policy'],
            'database_commit_succeeded' => (bool) ($result['database_commit_succeeded'] ?? false),
            'database_write_count' => (bool) ($result['writes_database'] ?? false) ? $before['batch_size'] : 0,
            'publication_write_count' => (bool) ($result['write_verified'] ?? false) ? $before['batch_size'] : 0,
            'rollback_executed' => $rollback,
            'quarantine_executed' => $quarantine,
            'remediation_status' => (string) data_get($result, 'remediation.status', 'not_observed'),
            'artifact_write_count' => 0,
            'deploy_count' => 0,
            'migration_count' => 0,
            'sitemap_submission_count' => 0,
            'llms_submission_count' => 0,
            'search_submission_count' => 0,
            'automatic_retry_allowed' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function ambiguousApplyReceipt(array $before, string $failure): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'apply',
            'status' => 'HOLD_AMBIGUOUS_APPLY',
            'safe_failure_code' => $failure,
            'before_projection_sha256' => $before['artifact_sha256'],
            'batch_id' => $before['batch_id'],
            'batch_size' => $before['batch_size'],
            'batch_slug_set_sha256' => $before['batch_slug_set_sha256'],
            'rollback_group_sha256' => $before['rollback_group_sha256'],
            'failure_policy' => $before['failure_policy'],
            'database_write_count' => null,
            'publication_write_count' => null,
            'artifact_write_count' => null,
            'deploy_count' => 0,
            'migration_count' => 0,
            'sitemap_submission_count' => 0,
            'llms_submission_count' => 0,
            'search_submission_count' => 0,
            'automatic_retry_allowed' => false,
            'required_next_action' => 'incident_assessment_only',
        ];
    }

    /** @return array<string, mixed> */
    private static function holdReceipt(string $mode, string $failure): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => $mode,
            'status' => 'HOLD_CONTROL_FAILED',
            'safe_failure_code' => $failure,
            'database_write_count' => 0,
            'publication_write_count' => 0,
            'artifact_write_count' => 0,
            'cache_write_count' => 0,
            'deploy_count' => 0,
            'migration_count' => 0,
            'sitemap_submission_count' => 0,
            'llms_submission_count' => 0,
            'search_submission_count' => 0,
            'automatic_retry_allowed' => false,
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function emit(array $payload): void
    {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
    }
}

exit(Career1046PublicationControl::main($argv));
