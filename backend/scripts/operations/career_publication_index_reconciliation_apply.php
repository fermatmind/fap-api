<?php

declare(strict_types=1);

namespace FermatMind\Operations;

use App\Domain\Career\Publish\CareerVerifiedRolloutBatchSlugAuthority;
use App\Models\IndexState;
use App\Models\Occupation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CareerPublicationIndexReconciliationApplyFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

final class CareerPublicationIndexReconciliationApply
{
    public const CONTRACT_VERSION = 'career.publication_index_reconciliation_apply.v1';

    public const MANIFEST_SHA256 = 'b570ec0cdda65278aa543431886b3529d072de8d67a8e79f1cafbb1c4c8dfc0e';

    public const BASELINE_SET_SHA256 = '39cc766fb18c85d385b83f0ac1f56a8b97d46481d3e9a12de0588abbaf640060';

    public const RECEIPT_SET_SHA256 = '09ec67befe967e1619a40578c47b862743883717b048da802ee7ef3551a0747f';

    public const TARGET_SET_SHA256 = '3b101fb76b5666200c73519c650beb1a5b0b35f47f7592453bf5671920571a18';

    public const EMPTY_SET_SHA256 = '01ba4719c80b6fe911b091a7c05124b64eeece964e09c058ef8f9805daca546b';

    private const MANIFEST_PATH = 'docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json';

    private const TARGET_INDEX_STATE = 'indexed';

    private const PROMOTION_REASON = 'canonical_rollout_batch_promotion';

    private const RECONCILIATION_REASON = 'career_1046_exact_publication_index_reconciliation';

    private const ALLOWED_WRITE_TABLE = 'index_states';

    /** @var list<string> */
    private const ALLOWED_WRITE_COLUMNS = [
        'id',
        'occupation_id',
        'index_state',
        'index_eligible',
        'canonical_path',
        'canonical_target',
        'reason_codes',
        'changed_at',
        'import_run_id',
        'row_fingerprint',
        'created_at',
        'updated_at',
    ];

    /** @param list<string> $values */
    public static function setHash(array $values): string
    {
        return hash('sha256', implode("\n", self::normalizedList($values))."\n");
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $receiptSlugs
     * @param  list<array<string, mixed>>  $occupations
     * @param  list<array<string, mixed>>  $indexStates
     * @return array<string, mixed>
     */
    public static function analyze(
        array $manifest,
        array $receiptSlugs,
        array $occupations,
        array $indexStates,
    ): array {
        $baselineSlugs = self::normalizedList($manifest['baseline_slugs'] ?? null);
        $deltaSlugs = self::normalizedList($manifest['delta_slugs'] ?? null);
        $targetSlugs = self::normalizedList([...$baselineSlugs, ...$deltaSlugs]);
        self::assertFrozenSets($baselineSlugs, $deltaSlugs, $targetSlugs);

        $receipts = self::normalizedList($receiptSlugs);
        $missingReceipts = self::difference($deltaSlugs, $receipts);
        $outsideTargetReceipts = self::difference($receipts, $targetSlugs);
        $baselineReceiptOverlap = self::intersection($receipts, $baselineSlugs);
        if ($receipts !== $deltaSlugs
            || $missingReceipts !== []
            || $outsideTargetReceipts !== []
            || $baselineReceiptOverlap !== []) {
            throw new CareerPublicationIndexReconciliationApplyFailure('RECEIPT_AUTHORITY_NOT_EXACT');
        }

        $occupationBySlug = [];
        $slugByOccupationId = [];
        foreach ($occupations as $occupation) {
            $slug = self::normalizedSlug($occupation['canonical_slug'] ?? null);
            $id = self::stringValue($occupation['id'] ?? null);
            if ($slug === null || $id === '' || isset($occupationBySlug[$slug]) || isset($slugByOccupationId[$id])) {
                throw new CareerPublicationIndexReconciliationApplyFailure('OCCUPATION_IDENTITY_INVALID_OR_DUPLICATE');
            }
            if (! in_array($slug, $targetSlugs, true)) {
                throw new CareerPublicationIndexReconciliationApplyFailure('OCCUPATION_OUTSIDE_TARGET');
            }
            $occupationBySlug[$slug] = $id;
            $slugByOccupationId[$id] = $slug;
        }

        $missingOccupations = self::difference($targetSlugs, array_keys($occupationBySlug));
        if ($missingOccupations !== []) {
            throw new CareerPublicationIndexReconciliationApplyFailure('TARGET_OCCUPATION_MISSING');
        }

        $statesByOccupation = [];
        foreach ($indexStates as $state) {
            $occupationId = self::stringValue($state['occupation_id'] ?? null);
            $id = self::stringValue($state['id'] ?? null);
            if ($occupationId === '' || $id === '' || ! isset($slugByOccupationId[$occupationId])) {
                throw new CareerPublicationIndexReconciliationApplyFailure('INDEX_STATE_ROW_INVALID_OR_OUTSIDE_TARGET');
            }
            $statesByOccupation[$occupationId][] = $state;
        }

        $deltaStateRows = [];
        $baselineStateRows = [];
        $matchingDelta = [];
        $mismatchingDelta = [];
        $missingLatestDelta = [];
        $latestStateTies = [];
        $baselinePresent = [];
        $baselineMatching = [];

        foreach ($targetSlugs as $slug) {
            $occupationId = $occupationBySlug[$slug];
            $states = $statesByOccupation[$occupationId] ?? [];
            usort($states, static function (array $left, array $right): int {
                foreach (['changed_at', 'created_at', 'id'] as $field) {
                    $comparison = strcmp(
                        self::stringValue($right[$field] ?? null),
                        self::stringValue($left[$field] ?? null),
                    );
                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                return 0;
            });

            if (isset($states[1])
                && self::stringValue($states[0]['changed_at'] ?? null) === self::stringValue($states[1]['changed_at'] ?? null)
                && self::stringValue($states[0]['created_at'] ?? null) === self::stringValue($states[1]['created_at'] ?? null)) {
                $latestStateTies[] = $slug;
            }

            $latest = $states[0] ?? null;
            $isBaseline = in_array($slug, $baselineSlugs, true);
            if ($latest === null) {
                if ($isBaseline) {
                    $baselineStateRows[] = 'slug='.$slug.'|occupation_id='.$occupationId.'|latest_index_state=missing';
                } else {
                    // Keep this byte-identical to the Task 3A preflight current-state hash contract.
                    $deltaStateRows[] = $slug.'|occupation='.$occupationId.'|latest_index_state=missing';
                    $mismatchingDelta[] = $slug;
                    $missingLatestDelta[] = $slug;
                }

                continue;
            }

            $reasonCodes = self::normalizedList($latest['reason_codes'] ?? []);
            $indexState = strtolower(self::stringValue($latest['index_state'] ?? null));
            $eligible = filter_var($latest['index_eligible'] ?? false, FILTER_VALIDATE_BOOL);
            $canonicalPath = self::stringValue($latest['canonical_path'] ?? null);
            $canonicalTarget = self::stringValue($latest['canonical_target'] ?? null);
            $row = implode('|', [
                'slug='.$slug,
                'occupation_id='.$occupationId,
                'index_state_id='.self::stringValue($latest['id'] ?? null),
                'index_state='.$indexState,
                'index_eligible='.($eligible ? '1' : '0'),
                'canonical_path='.$canonicalPath,
                'canonical_target='.$canonicalTarget,
                'reason_codes='.implode(',', $reasonCodes),
                'changed_at='.self::stringValue($latest['changed_at'] ?? null),
                'created_at='.self::stringValue($latest['created_at'] ?? null),
            ]);
            $matches = $indexState === self::TARGET_INDEX_STATE
                && $eligible
                && $canonicalPath === '/career/jobs/'.$slug
                && $canonicalTarget === ''
                && in_array(self::PROMOTION_REASON, $reasonCodes, true);

            if ($isBaseline) {
                $baselinePresent[] = $slug;
                $baselineStateRows[] = $row;
                if ($matches) {
                    $baselineMatching[] = $slug;
                }
            } else {
                $deltaStateRows[] = $row;
                if ($matches) {
                    $matchingDelta[] = $slug;
                } else {
                    $mismatchingDelta[] = $slug;
                }
            }
        }

        $matchingDelta = self::normalizedList($matchingDelta);
        $mismatchingDelta = self::normalizedList($mismatchingDelta);
        $missingLatestDelta = self::normalizedList($missingLatestDelta);
        $latestStateTies = self::normalizedList($latestStateTies);
        $baselinePresent = self::normalizedList($baselinePresent);
        $baselineMatching = self::normalizedList($baselineMatching);

        return [
            'manifest' => [
                'baseline_count' => count($baselineSlugs),
                'baseline_set_sha256' => self::setHash($baselineSlugs),
                'delta_count' => count($deltaSlugs),
                'delta_set_sha256' => self::setHash($deltaSlugs),
                'target_count' => count($targetSlugs),
                'target_set_sha256' => self::setHash($targetSlugs),
            ],
            'receipt_authority' => [
                'receipt_covered_count' => count($receipts),
                'receipt_covered_set_sha256' => self::setHash($receipts),
                'missing_receipt_count' => count($missingReceipts),
                'missing_receipt_set_sha256' => self::setHash($missingReceipts),
                'outside_target_count' => count($outsideTargetReceipts),
                'outside_target_set_sha256' => self::setHash($outsideTargetReceipts),
                'baseline_overlap_count' => count($baselineReceiptOverlap),
                'baseline_overlap_set_sha256' => self::setHash($baselineReceiptOverlap),
            ],
            'database_latest_index_state' => [
                'current_state_row_count' => count($deltaStateRows),
                'current_state_sha256' => self::setHash($deltaStateRows),
                'matching_count' => count($matchingDelta),
                'matching_set_sha256' => self::setHash($matchingDelta),
                'missing_or_mismatching_count' => count($mismatchingDelta),
                'missing_or_mismatching_set_sha256' => self::setHash($mismatchingDelta),
                'latest_state_missing_count' => count($missingLatestDelta),
                'latest_state_missing_set_sha256' => self::setHash($missingLatestDelta),
                'latest_state_tie_count' => count($latestStateTies),
                'latest_state_tie_set_sha256' => self::setHash($latestStateTies),
                'full_delta_match' => $matchingDelta === $deltaSlugs
                    && $mismatchingDelta === []
                    && $latestStateTies === [],
            ],
            'baseline_latest_index_state' => [
                'preserved_count' => count($baselinePresent),
                'preserved_set_sha256' => self::setHash($baselinePresent),
                'matching_count' => count($baselineMatching),
                'matching_set_sha256' => self::setHash($baselineMatching),
                'current_state_row_count' => count($baselineStateRows),
                'current_state_sha256' => self::setHash($baselineStateRows),
            ],
            'write_plan' => [
                'insert_count' => count($mismatchingDelta),
                'insert_slug_set_sha256' => self::setHash($mismatchingDelta),
                'insert_slugs' => $mismatchingDelta,
                'update_count' => 0,
                'delete_count' => 0,
                'outside_target_count' => 0,
                'outside_target_set_sha256' => self::EMPTY_SET_SHA256,
            ],
        ];
    }

    /** @param list<string> $baseline @param list<string> $delta @param list<string> $target */
    private static function assertFrozenSets(array $baseline, array $delta, array $target): void
    {
        if (count($baseline) !== 30
            || count($delta) !== 1016
            || count($target) !== 1046
            || ! hash_equals(self::BASELINE_SET_SHA256, self::setHash($baseline))
            || ! hash_equals(self::RECEIPT_SET_SHA256, self::setHash($delta))
            || ! hash_equals(self::TARGET_SET_SHA256, self::setHash($target))) {
            throw new CareerPublicationIndexReconciliationApplyFailure('FROZEN_MANIFEST_SET_DRIFT');
        }
    }

    /** @return list<string> */
    private static function normalizedList(mixed $values): array
    {
        if (! is_array($values)) {
            throw new CareerPublicationIndexReconciliationApplyFailure('SET_INPUT_INVALID');
        }

        $normalized = [];
        foreach ($values as $value) {
            $item = self::normalizedSlug($value);
            if ($item !== null) {
                $normalized[$item] = true;
            }
        }
        $result = array_keys($normalized);
        sort($result, SORT_STRING);

        return $result;
    }

    private static function normalizedSlug(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $normalized = strtolower(trim($value));

        return $normalized === '' ? null : $normalized;
    }

    private static function stringValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }

        return trim((string) ($value ?? ''));
    }

    /** @param list<string> $left @param list<string> $right @return list<string> */
    private static function difference(array $left, array $right): array
    {
        return self::normalizedList(array_values(array_diff($left, $right)));
    }

    /** @param list<string> $left @param list<string> $right @return list<string> */
    private static function intersection(array $left, array $right): array
    {
        return self::normalizedList(array_values(array_intersect($left, $right)));
    }

    /** @return array<string, mixed> */
    private static function failureReceipt(string $safeCode, bool $transactionCommitted): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'apply',
            'status' => 'HOLD_EXACT_RECONCILIATION_APPLY',
            'safe_code' => $safeCode,
            'production_apply_execution' => true,
            'writes_committed' => $transactionCommitted,
            'database_transaction_committed' => $transactionCommitted,
            'automatic_retry_allowed' => false,
            'cms_write_count' => 0,
            'cache_write_count' => 0,
            'artifact_write_count' => 0,
            'projection_write_count' => 0,
            'ledger_write_count' => 0,
            'deployment_count' => 0,
            'migration_count' => 0,
            'sitemap_write_count' => 0,
            'llms_write_count' => 0,
            'search_submission_count' => 0,
        ];
    }

    public static function main(array $argv): int
    {
        $transactionCommitted = false;

        try {
            if (($argv[1] ?? '') !== 'apply') {
                throw new CareerPublicationIndexReconciliationApplyFailure('MODE_INVALID');
            }

            $backendRoot = realpath((string) getenv('CAREER_RECONCILIATION_BACKEND_ROOT'));
            if (! is_string($backendRoot) || ! is_dir($backendRoot)) {
                throw new CareerPublicationIndexReconciliationApplyFailure('BACKEND_ROOT_INVALID');
            }
            require $backendRoot.'/vendor/autoload.php';
            $app = require $backendRoot.'/bootstrap/app.php';
            $app->make(Kernel::class)->bootstrap();

            $manifestPath = $backendRoot.'/'.self::MANIFEST_PATH;
            $manifestBytes = file_get_contents($manifestPath);
            $manifest = is_string($manifestBytes) ? json_decode($manifestBytes, true) : null;
            if (! is_string($manifestBytes)
                || ! is_array($manifest)
                || ! hash_equals(self::MANIFEST_SHA256, hash('sha256', $manifestBytes))) {
                throw new CareerPublicationIndexReconciliationApplyFailure('MANIFEST_IDENTITY_INVALID');
            }

            $receiptSlugs = $app->make(CareerVerifiedRolloutBatchSlugAuthority::class)->slugs();
            $controlPlaneSha = self::shaEnv('CAREER_RECONCILIATION_CONTROL_PLANE_SHA', 40);
            $releaseSha = self::shaEnv('CAREER_RECONCILIATION_RELEASE_SHA', 40);
            $releaseName = (string) getenv('CAREER_RECONCILIATION_RELEASE_NAME');
            $preflightRunId = self::positiveIntEnv('CAREER_RECONCILIATION_PREFLIGHT_RUN_ID');
            $preflightRunAttempt = self::positiveIntEnv('CAREER_RECONCILIATION_PREFLIGHT_RUN_ATTEMPT');
            $preflightReceiptSha = self::shaEnv('CAREER_RECONCILIATION_PREFLIGHT_RECEIPT_SHA256', 64);
            $preflightArtifactDigest = self::digestEnv('CAREER_RECONCILIATION_PREFLIGHT_ARTIFACT_DIGEST');
            $expectedCurrentStateSha = self::shaEnv('CAREER_RECONCILIATION_EXPECTED_CURRENT_STATE_SHA256', 64);
            $runId = self::positiveIntEnv('CAREER_RECONCILIATION_RUN_ID');
            $runAttempt = self::positiveIntEnv('CAREER_RECONCILIATION_RUN_ATTEMPT');
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $releaseName)) {
                throw new CareerPublicationIndexReconciliationApplyFailure('RELEASE_NAME_INVALID');
            }

            $result = DB::transaction(function () use ($manifest, $receiptSlugs, $expectedCurrentStateSha): array {
                $baselineSlugs = self::normalizedList($manifest['baseline_slugs'] ?? null);
                $deltaSlugs = self::normalizedList($manifest['delta_slugs'] ?? null);
                $targetSlugs = self::normalizedList([...$baselineSlugs, ...$deltaSlugs]);
                $beforeRows = self::lockedSnapshot($targetSlugs);
                $before = self::analyze($manifest, $receiptSlugs, $beforeRows['occupations'], $beforeRows['index_states']);
                if (! hash_equals($expectedCurrentStateSha, $before['database_latest_index_state']['current_state_sha256'])) {
                    throw new CareerPublicationIndexReconciliationApplyFailure('PREFLIGHT_CURRENT_STATE_DRIFT');
                }
                if ($before['database_latest_index_state']['latest_state_tie_count'] !== 0
                    || $before['baseline_latest_index_state']['preserved_count'] !== 30
                    || $before['baseline_latest_index_state']['matching_count'] !== 30) {
                    throw new CareerPublicationIndexReconciliationApplyFailure('PREWRITE_AUTHORITY_NOT_EXACT');
                }

                $baselineBeforeSha = $before['baseline_latest_index_state']['current_state_sha256'];
                $writeSlugs = $before['write_plan']['insert_slugs'];
                $occupationBySlug = [];
                foreach ($beforeRows['occupations'] as $occupation) {
                    $occupationBySlug[(string) $occupation['canonical_slug']] = (string) $occupation['id'];
                }
                $now = now();
                $createdIds = [];
                foreach ($writeSlugs as $slug) {
                    if (! in_array($slug, $deltaSlugs, true)
                        || ! isset($occupationBySlug[$slug])) {
                        throw new CareerPublicationIndexReconciliationApplyFailure('NON_TARGET_WRITE_REFUSED');
                    }
                    $reasonCodes = [
                        self::PROMOTION_REASON,
                        self::RECONCILIATION_REASON,
                        'receipt_set_sha256:'.self::RECEIPT_SET_SHA256,
                    ];
                    $rowFingerprint = hash('sha256', json_encode([
                        'source' => self::RECONCILIATION_REASON,
                        'canonical_slug' => $slug,
                        'occupation_id' => $occupationBySlug[$slug],
                        'index_state' => self::TARGET_INDEX_STATE,
                        'index_eligible' => true,
                        'canonical_path' => '/career/jobs/'.$slug,
                        'canonical_target' => null,
                        'reason_codes' => $reasonCodes,
                        'preflight_current_state_sha256' => $expectedCurrentStateSha,
                    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                    $created = IndexState::query()->create([
                        'occupation_id' => $occupationBySlug[$slug],
                        'index_state' => self::TARGET_INDEX_STATE,
                        'index_eligible' => true,
                        'canonical_path' => '/career/jobs/'.$slug,
                        'canonical_target' => null,
                        'reason_codes' => $reasonCodes,
                        'changed_at' => $now,
                        'import_run_id' => null,
                        'row_fingerprint' => $rowFingerprint,
                    ]);
                    $createdIds[] = (string) $created->id;
                }

                $afterRows = self::lockedSnapshot($targetSlugs);
                $after = self::analyze($manifest, $receiptSlugs, $afterRows['occupations'], $afterRows['index_states']);
                if ($after['database_latest_index_state']['matching_count'] !== 1016
                    || $after['database_latest_index_state']['missing_or_mismatching_count'] !== 0
                    || $after['database_latest_index_state']['latest_state_missing_count'] !== 0
                    || $after['database_latest_index_state']['latest_state_tie_count'] !== 0
                    || $after['receipt_authority']['outside_target_count'] !== 0
                    || $after['baseline_latest_index_state']['preserved_count'] !== 30
                    || $after['baseline_latest_index_state']['matching_count'] !== 30
                    || ! hash_equals($baselineBeforeSha, $after['baseline_latest_index_state']['current_state_sha256'])) {
                    throw new CareerPublicationIndexReconciliationApplyFailure('POSTWRITE_READBACK_NOT_EXACT');
                }

                return [
                    'before' => $before,
                    'after' => $after,
                    'created_count' => count($createdIds),
                    'created_id_set_sha256' => self::setHash($createdIds),
                    'baseline_before_sha256' => $baselineBeforeSha,
                ];
            }, 1);
            $transactionCommitted = true;

            $receipt = [
                'contract_version' => self::CONTRACT_VERSION,
                'mode' => 'apply',
                'status' => 'PASS_EXACT_RECONCILIATION_APPLY',
                'control_plane_sha' => $controlPlaneSha,
                'release_sha' => $releaseSha,
                'release_name_sha256' => hash('sha256', $releaseName),
                'workflow_run_id' => $runId,
                'workflow_run_attempt' => $runAttempt,
                'preflight_run_id' => $preflightRunId,
                'preflight_run_attempt' => $preflightRunAttempt,
                'preflight_receipt_sha256' => $preflightReceiptSha,
                'preflight_artifact_digest' => $preflightArtifactDigest,
                'preflight_current_state_sha256' => $expectedCurrentStateSha,
                'manifest_sha256' => self::MANIFEST_SHA256,
                'manifest' => $result['after']['manifest'],
                'receipt_authority' => $result['after']['receipt_authority'],
                'before_database_latest_index_state' => [
                    'current_state_row_count' => $result['before']['database_latest_index_state']['current_state_row_count'],
                    'current_state_sha256' => $result['before']['database_latest_index_state']['current_state_sha256'],
                    'matching_count' => $result['before']['database_latest_index_state']['matching_count'],
                    'matching_set_sha256' => $result['before']['database_latest_index_state']['matching_set_sha256'],
                    'missing_or_mismatching_count' => $result['before']['database_latest_index_state']['missing_or_mismatching_count'],
                    'missing_or_mismatching_set_sha256' => $result['before']['database_latest_index_state']['missing_or_mismatching_set_sha256'],
                ],
                'postwrite_readback' => [
                    'receipt_covered_count' => $result['after']['receipt_authority']['receipt_covered_count'],
                    'receipt_covered_set_sha256' => $result['after']['receipt_authority']['receipt_covered_set_sha256'],
                    'matching_latest_state_count' => $result['after']['database_latest_index_state']['matching_count'],
                    'matching_latest_state_set_sha256' => $result['after']['database_latest_index_state']['matching_set_sha256'],
                    'missing_latest_state_count' => $result['after']['database_latest_index_state']['latest_state_missing_count'],
                    'missing_latest_state_set_sha256' => $result['after']['database_latest_index_state']['latest_state_missing_set_sha256'],
                    'outside_target_count' => $result['after']['receipt_authority']['outside_target_count'],
                    'outside_target_set_sha256' => $result['after']['receipt_authority']['outside_target_set_sha256'],
                    'baseline_preserved_count' => $result['after']['baseline_latest_index_state']['preserved_count'],
                    'baseline_preserved_set_sha256' => $result['after']['baseline_latest_index_state']['preserved_set_sha256'],
                    'baseline_state_sha256' => $result['after']['baseline_latest_index_state']['current_state_sha256'],
                    'current_state_sha256' => $result['after']['database_latest_index_state']['current_state_sha256'],
                ],
                'allowed_database_write' => [
                    'table' => self::ALLOWED_WRITE_TABLE,
                    'columns' => self::ALLOWED_WRITE_COLUMNS,
                    'index_state_values' => [self::TARGET_INDEX_STATE],
                    'index_eligible_values' => [true],
                    'canonical_path_template' => '/career/jobs/{exact_receipt_slug}',
                    'canonical_target_values' => [null],
                    'receipt_slug_set_sha256' => self::RECEIPT_SET_SHA256,
                ],
                'database_insert_count' => $result['created_count'],
                'database_insert_id_set_sha256' => $result['created_id_set_sha256'],
                'database_update_count' => 0,
                'database_delete_count' => 0,
                'database_transaction_committed' => true,
                'writes_committed' => true,
                'production_apply_execution' => true,
                'cms_write_count' => 0,
                'cache_write_count' => 0,
                'artifact_write_count' => 0,
                'projection_write_count' => 0,
                'ledger_write_count' => 0,
                'deployment_count' => 0,
                'migration_count' => 0,
                'sitemap_write_count' => 0,
                'llms_write_count' => 0,
                'search_submission_count' => 0,
                'automatic_retry_allowed' => false,
            ];
            echo json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;

            return 0;
        } catch (CareerPublicationIndexReconciliationApplyFailure $failure) {
            echo json_encode(self::failureReceipt($failure->safeCode, $transactionCommitted), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;

            return 1;
        } catch (Throwable) {
            echo json_encode(self::failureReceipt('UNEXPECTED_APPLY_FAILURE', $transactionCommitted), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;

            return 1;
        }
    }

    /** @param list<string> $targetSlugs @return array{occupations:list<array<string,mixed>>,index_states:list<array<string,mixed>>} */
    private static function lockedSnapshot(array $targetSlugs): array
    {
        $occupations = Occupation::query()
            ->whereIn('canonical_slug', $targetSlugs)
            ->orderBy('canonical_slug')
            ->lockForUpdate()
            ->get(['id', 'canonical_slug'])
            ->map(static fn (Occupation $occupation): array => [
                'id' => (string) $occupation->id,
                'canonical_slug' => strtolower(trim((string) $occupation->canonical_slug)),
            ])
            ->all();
        $occupationIds = array_column($occupations, 'id');
        $indexStates = IndexState::query()
            ->whereIn('occupation_id', $occupationIds)
            ->orderBy('occupation_id')
            ->orderBy('changed_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get([
                'id',
                'occupation_id',
                'index_state',
                'index_eligible',
                'canonical_path',
                'canonical_target',
                'reason_codes',
                'changed_at',
                'created_at',
            ])
            ->map(static fn (IndexState $state): array => [
                'id' => (string) $state->id,
                'occupation_id' => (string) $state->occupation_id,
                'index_state' => (string) $state->index_state,
                'index_eligible' => (bool) $state->index_eligible,
                'canonical_path' => (string) $state->canonical_path,
                'canonical_target' => $state->canonical_target === null ? '' : (string) $state->canonical_target,
                'reason_codes' => is_array($state->reason_codes) ? $state->reason_codes : [],
                'changed_at' => self::stringValue($state->changed_at),
                'created_at' => self::stringValue($state->created_at),
            ])
            ->all();

        return ['occupations' => $occupations, 'index_states' => $indexStates];
    }

    private static function shaEnv(string $name, int $length): string
    {
        $value = strtolower(trim((string) getenv($name)));
        if (! preg_match('/^[0-9a-f]{'.$length.'}$/D', $value)) {
            throw new CareerPublicationIndexReconciliationApplyFailure('IDENTITY_ENV_INVALID');
        }

        return $value;
    }

    private static function digestEnv(string $name): string
    {
        $value = strtolower(trim((string) getenv($name)));
        if (! preg_match('/^sha256:[0-9a-f]{64}$/D', $value)) {
            throw new CareerPublicationIndexReconciliationApplyFailure('ARTIFACT_DIGEST_INVALID');
        }

        return $value;
    }

    private static function positiveIntEnv(string $name): int
    {
        $value = trim((string) getenv($name));
        if (! preg_match('/^[1-9][0-9]*$/D', $value)) {
            throw new CareerPublicationIndexReconciliationApplyFailure('RUN_IDENTITY_INVALID');
        }

        return (int) $value;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__ || __FILE__ === '/dev/stdin') {
    exit(CareerPublicationIndexReconciliationApply::main($argv));
}
