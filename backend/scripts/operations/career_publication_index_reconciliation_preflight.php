<?php

declare(strict_types=1);

namespace FermatMind\Operations;

use App\Domain\Career\Publish\CareerVerifiedRolloutBatchSlugAuthority;
use App\Models\IndexState;
use App\Models\Occupation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CareerPublicationIndexReconciliationPreflightFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

final class CareerPublicationIndexReconciliationPreflight
{
    public const CONTRACT_VERSION = 'career.publication_index_reconciliation_preflight.v1';

    public const MANIFEST_SHA256 = 'b570ec0cdda65278aa543431886b3529d072de8d67a8e79f1cafbb1c4c8dfc0e';

    public const BASELINE_SET_SHA256 = '39cc766fb18c85d385b83f0ac1f56a8b97d46481d3e9a12de0588abbaf640060';

    public const RECEIPT_SET_SHA256 = '09ec67befe967e1619a40578c47b862743883717b048da802ee7ef3551a0747f';

    public const TARGET_SET_SHA256 = '3b101fb76b5666200c73519c650beb1a5b0b35f47f7592453bf5671920571a18';

    public const EMPTY_SET_SHA256 = '01ba4719c80b6fe911b091a7c05124b64eeece964e09c058ef8f9805daca546b';

    private const PROMOTION_REASON = 'canonical_rollout_batch_promotion';

    private const MANIFEST_PATH = 'docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json';

    /** @param list<string> $values */
    public static function setHash(array $values): string
    {
        $normalized = self::slugList($values);

        return hash('sha256', implode("\n", $normalized)."\n");
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
        $baselineSlugs = self::slugList($manifest['baseline_slugs'] ?? null);
        $deltaSlugs = self::slugList($manifest['delta_slugs'] ?? null);
        $targetSlugs = self::slugList([...$baselineSlugs, ...$deltaSlugs]);
        self::assertFrozenSets($baselineSlugs, $deltaSlugs, $targetSlugs);

        $receipts = self::slugList($receiptSlugs);
        $missingReceipts = self::difference($deltaSlugs, $receipts);
        $outsideTargetReceipts = self::difference($receipts, $targetSlugs);
        $baselineReceiptOverlap = self::intersection($receipts, $baselineSlugs);
        $coveredDelta = self::intersection($receipts, $deltaSlugs);

        $occupationBySlug = [];
        $duplicateOccupationSlugs = [];
        foreach ($occupations as $occupation) {
            $slug = self::normalizeSlug($occupation['canonical_slug'] ?? null);
            $id = self::stringValue($occupation['id'] ?? null);
            if ($slug === null || $id === '') {
                throw new CareerPublicationIndexReconciliationPreflightFailure('OCCUPATION_ROW_INVALID');
            }
            if (isset($occupationBySlug[$slug])) {
                $duplicateOccupationSlugs[] = $slug;

                continue;
            }
            $occupationBySlug[$slug] = $id;
        }
        $duplicateOccupationSlugs = self::slugList($duplicateOccupationSlugs);
        if ($duplicateOccupationSlugs !== []) {
            throw new CareerPublicationIndexReconciliationPreflightFailure('OCCUPATION_SLUG_DUPLICATE');
        }

        $statesByOccupation = [];
        foreach ($indexStates as $state) {
            $occupationId = self::stringValue($state['occupation_id'] ?? null);
            $id = self::stringValue($state['id'] ?? null);
            if ($occupationId === '' || $id === '') {
                throw new CareerPublicationIndexReconciliationPreflightFailure('INDEX_STATE_ROW_INVALID');
            }
            $statesByOccupation[$occupationId][] = $state;
        }

        $databaseStateRows = [];
        $matchingSlugs = [];
        $mismatchingSlugs = [];
        $occupationMissingSlugs = [];
        $latestStateMissingSlugs = [];
        $latestStateTieSlugs = [];

        foreach ($deltaSlugs as $slug) {
            $occupationId = $occupationBySlug[$slug] ?? null;
            if ($occupationId === null) {
                $occupationMissingSlugs[] = $slug;
                $mismatchingSlugs[] = $slug;
                $databaseStateRows[] = $slug.'|occupation=missing|latest_index_state=missing';

                continue;
            }

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

            if ($states === []) {
                $latestStateMissingSlugs[] = $slug;
                $mismatchingSlugs[] = $slug;
                $databaseStateRows[] = $slug.'|occupation='.$occupationId.'|latest_index_state=missing';

                continue;
            }

            if (isset($states[1])
                && self::stringValue($states[0]['changed_at'] ?? null) === self::stringValue($states[1]['changed_at'] ?? null)
                && self::stringValue($states[0]['created_at'] ?? null) === self::stringValue($states[1]['created_at'] ?? null)) {
                $latestStateTieSlugs[] = $slug;
            }

            $latest = $states[0];
            $reasonCodes = self::slugList($latest['reason_codes'] ?? []);
            $eligible = filter_var($latest['index_eligible'] ?? false, FILTER_VALIDATE_BOOL);
            $indexState = strtolower(self::stringValue($latest['index_state'] ?? null));
            $databaseStateRows[] = implode('|', [
                'slug='.$slug,
                'occupation_id='.$occupationId,
                'index_state_id='.self::stringValue($latest['id'] ?? null),
                'index_state='.$indexState,
                'index_eligible='.($eligible ? '1' : '0'),
                'canonical_path='.self::stringValue($latest['canonical_path'] ?? null),
                'canonical_target='.self::stringValue($latest['canonical_target'] ?? null),
                'reason_codes='.implode(',', $reasonCodes),
                'changed_at='.self::stringValue($latest['changed_at'] ?? null),
                'created_at='.self::stringValue($latest['created_at'] ?? null),
            ]);

            if ($indexState === 'indexed'
                && $eligible
                && in_array(self::PROMOTION_REASON, $reasonCodes, true)) {
                $matchingSlugs[] = $slug;
            } else {
                $mismatchingSlugs[] = $slug;
            }
        }

        $matchingSlugs = self::slugList($matchingSlugs);
        $mismatchingSlugs = self::slugList($mismatchingSlugs);
        $occupationMissingSlugs = self::slugList($occupationMissingSlugs);
        $latestStateMissingSlugs = self::slugList($latestStateMissingSlugs);
        $latestStateTieSlugs = self::slugList($latestStateTieSlugs);

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
                'authentic_receipt_count' => count($receipts),
                'authentic_receipt_set_sha256' => self::setHash($receipts),
                'covered_delta_count' => count($coveredDelta),
                'covered_delta_set_sha256' => self::setHash($coveredDelta),
                'missing_receipt_count' => count($missingReceipts),
                'missing_receipt_set_sha256' => self::setHash($missingReceipts),
                'outside_target_count' => count($outsideTargetReceipts),
                'outside_target_set_sha256' => self::setHash($outsideTargetReceipts),
                'baseline_overlap_count' => count($baselineReceiptOverlap),
                'baseline_overlap_set_sha256' => self::setHash($baselineReceiptOverlap),
                'exact_delta_receipt_authority' => $receipts === $deltaSlugs
                    && $missingReceipts === []
                    && $outsideTargetReceipts === []
                    && $baselineReceiptOverlap === [],
            ],
            'database_latest_index_state' => [
                'receipt_covered_count' => count($coveredDelta),
                'current_state_row_count' => count($databaseStateRows),
                'current_state_sha256' => self::setHash($databaseStateRows),
                'matching_count' => count($matchingSlugs),
                'matching_set_sha256' => self::setHash($matchingSlugs),
                'missing_or_mismatching_count' => count($mismatchingSlugs),
                'missing_or_mismatching_set_sha256' => self::setHash($mismatchingSlugs),
                'occupation_missing_count' => count($occupationMissingSlugs),
                'occupation_missing_set_sha256' => self::setHash($occupationMissingSlugs),
                'latest_state_missing_count' => count($latestStateMissingSlugs),
                'latest_state_missing_set_sha256' => self::setHash($latestStateMissingSlugs),
                'latest_state_tie_count' => count($latestStateTieSlugs),
                'latest_state_tie_set_sha256' => self::setHash($latestStateTieSlugs),
                'full_delta_match' => $matchingSlugs === $deltaSlugs
                    && $mismatchingSlugs === []
                    && $latestStateTieSlugs === [],
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
            throw new CareerPublicationIndexReconciliationPreflightFailure('FROZEN_MANIFEST_SET_DRIFT');
        }
    }

    /** @return list<string> */
    private static function slugList(mixed $values): array
    {
        if (! is_array($values)) {
            throw new CareerPublicationIndexReconciliationPreflightFailure('SET_INPUT_INVALID');
        }

        $normalized = [];
        foreach ($values as $value) {
            $item = self::normalizeSlug($value);
            if ($item !== null) {
                $normalized[$item] = true;
            }
        }
        $result = array_keys($normalized);
        sort($result, SORT_STRING);

        return $result;
    }

    private static function normalizeSlug(mixed $value): ?string
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
        return self::slugList(array_values(array_diff($left, $right)));
    }

    /** @param list<string> $left @param list<string> $right @return list<string> */
    private static function intersection(array $left, array $right): array
    {
        return self::slugList(array_values(array_intersect($left, $right)));
    }

    /** @return array<string, mixed> */
    private static function failureReceipt(string $safeCode): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'preflight',
            'status' => 'HOLD_SELECT_ONLY_RECONCILIATION_PREFLIGHT',
            'safe_code' => $safeCode,
            'production_read_execution' => true,
            'database_select_only' => true,
            'database_write_count' => 0,
            'cms_write_count' => 0,
            'cache_write_count' => 0,
            'artifact_write_count' => 0,
            'publication_write_count' => 0,
            'deployment_count' => 0,
            'migration_count' => 0,
            'sitemap_write_count' => 0,
            'llms_write_count' => 0,
            'search_submission_count' => 0,
            'writes_committed' => false,
            'automatic_retry_allowed' => false,
        ];
    }

    public static function main(array $argv): int
    {
        try {
            if (($argv[1] ?? '') !== 'inspect') {
                throw new CareerPublicationIndexReconciliationPreflightFailure('MODE_INVALID');
            }

            $backendRoot = realpath((string) getenv('CAREER_RECONCILIATION_BACKEND_ROOT'));
            if (! is_string($backendRoot) || ! is_dir($backendRoot)) {
                throw new CareerPublicationIndexReconciliationPreflightFailure('BACKEND_ROOT_INVALID');
            }
            require $backendRoot.'/vendor/autoload.php';
            $app = require $backendRoot.'/bootstrap/app.php';
            $app->make(Kernel::class)->bootstrap();

            $observedSqlVerbs = [];
            DB::listen(static function (QueryExecuted $query) use (&$observedSqlVerbs): void {
                $sql = ltrim($query->sql);
                $verb = strtolower((string) strtok($sql, " \t\r\n"));
                $observedSqlVerbs[] = $verb;
            });

            $manifestPath = $backendRoot.'/'.self::MANIFEST_PATH;
            $manifestBytes = file_get_contents($manifestPath);
            $manifest = is_string($manifestBytes) ? json_decode($manifestBytes, true) : null;
            if (! is_string($manifestBytes)
                || ! is_array($manifest)
                || ! hash_equals(self::MANIFEST_SHA256, hash('sha256', $manifestBytes))) {
                throw new CareerPublicationIndexReconciliationPreflightFailure('MANIFEST_IDENTITY_INVALID');
            }

            $receiptSlugs = $app->make(CareerVerifiedRolloutBatchSlugAuthority::class)->slugs();
            $deltaSlugs = self::slugList($manifest['delta_slugs'] ?? null);
            $occupations = Occupation::query()
                ->whereIn('canonical_slug', $deltaSlugs)
                ->get(['id', 'canonical_slug'])
                ->map(static fn (Occupation $occupation): array => [
                    'id' => (string) $occupation->id,
                    'canonical_slug' => (string) $occupation->canonical_slug,
                ])
                ->all();
            $occupationIds = array_column($occupations, 'id');
            $indexStates = IndexState::query()
                ->whereIn('occupation_id', $occupationIds)
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

            if ($observedSqlVerbs === []
                || array_values(array_unique($observedSqlVerbs)) !== ['select']) {
                throw new CareerPublicationIndexReconciliationPreflightFailure('DATABASE_NOT_SELECT_ONLY');
            }

            $analysis = self::analyze($manifest, $receiptSlugs, $occupations, $indexStates);
            if ($analysis['receipt_authority']['exact_delta_receipt_authority'] !== true
                || $analysis['database_latest_index_state']['latest_state_tie_count'] !== 0) {
                throw new CareerPublicationIndexReconciliationPreflightFailure('RECONCILIATION_INPUT_NOT_EXACT');
            }

            $controlPlaneSha = self::shaEnv('CAREER_RECONCILIATION_CONTROL_PLANE_SHA', 40);
            $releaseSha = self::shaEnv('CAREER_RECONCILIATION_RELEASE_SHA', 40);
            $releaseName = (string) getenv('CAREER_RECONCILIATION_RELEASE_NAME');
            $runId = self::positiveIntEnv('CAREER_RECONCILIATION_RUN_ID');
            $runAttempt = self::positiveIntEnv('CAREER_RECONCILIATION_RUN_ATTEMPT');
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $releaseName)) {
                throw new CareerPublicationIndexReconciliationPreflightFailure('RELEASE_NAME_INVALID');
            }

            $receipt = [
                'contract_version' => self::CONTRACT_VERSION,
                'mode' => 'preflight',
                'status' => 'PASS_SELECT_ONLY_RECONCILIATION_PREFLIGHT',
                'control_plane_sha' => $controlPlaneSha,
                'release_sha' => $releaseSha,
                'release_name_sha256' => hash('sha256', $releaseName),
                'workflow_run_id' => $runId,
                'workflow_run_attempt' => $runAttempt,
                'manifest_sha256' => self::MANIFEST_SHA256,
                ...$analysis,
                'observed_database_query_count' => count($observedSqlVerbs),
                'observed_database_query_verb_set_sha256' => self::setHash($observedSqlVerbs),
                'deploy_lock_absent' => true,
                'deploy_process_absent' => true,
                'production_read_execution' => true,
                'database_select_only' => true,
                'database_write_count' => 0,
                'cms_write_count' => 0,
                'cache_write_count' => 0,
                'artifact_write_count' => 0,
                'publication_write_count' => 0,
                'deployment_count' => 0,
                'migration_count' => 0,
                'sitemap_write_count' => 0,
                'llms_write_count' => 0,
                'search_submission_count' => 0,
                'writes_committed' => false,
                'zero_write_guarantee' => true,
                'automatic_retry_allowed' => false,
            ];
            echo json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;

            return 0;
        } catch (CareerPublicationIndexReconciliationPreflightFailure $failure) {
            echo json_encode(self::failureReceipt($failure->safeCode), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;

            return 1;
        } catch (Throwable) {
            echo json_encode(self::failureReceipt('UNEXPECTED_PREFLIGHT_FAILURE'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;

            return 1;
        }
    }

    private static function shaEnv(string $name, int $length): string
    {
        $value = strtolower(trim((string) getenv($name)));
        if (! preg_match('/^[0-9a-f]{'.$length.'}$/D', $value)) {
            throw new CareerPublicationIndexReconciliationPreflightFailure('IDENTITY_ENV_INVALID');
        }

        return $value;
    }

    private static function positiveIntEnv(string $name): int
    {
        $value = trim((string) getenv($name));
        if (! preg_match('/^[1-9][0-9]*$/D', $value)) {
            throw new CareerPublicationIndexReconciliationPreflightFailure('RUN_IDENTITY_INVALID');
        }

        return (int) $value;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__ || __FILE__ === '/dev/stdin') {
    exit(CareerPublicationIndexReconciliationPreflight::main($argv));
}
