<?php

declare(strict_types=1);

namespace FermatMind\Operations;

use App\Domain\Career\Publish\CareerGenerationAuthorityLoader;
use App\Models\IndexState;
use App\Models\Occupation;
use DateTimeInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

final class CareerBaselineIndexStateAuthorityRepairFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

final class CareerBaselineIndexStateAuthorityRepair
{
    public const CONTRACT_VERSION = 'career.baseline_index_state_authority_repair.v1';

    public const MANIFEST_SHA256 = 'b570ec0cdda65278aa543431886b3529d072de8d67a8e79f1cafbb1c4c8dfc0e';

    public const BASELINE_SET_SHA256 = '39cc766fb18c85d385b83f0ac1f56a8b97d46481d3e9a12de0588abbaf640060';

    public const BASELINE_LOCALE_ROW_SET_SHA256 = 'a42b2c69562ee7ea463d8190572f3b9f8244a633e1616d73b2122c3119ecfbee';

    public const DELTA_SET_SHA256 = '09ec67befe967e1619a40578c47b862743883717b048da802ee7ef3551a0747f';

    public const TARGET_SET_SHA256 = '3b101fb76b5666200c73519c650beb1a5b0b35f47f7592453bf5671920571a18';

    public const EXPECTED_DELTA_STATE_SHA256 = '53ea7077bc46eb8e1da6df1da911000f805c8245b5d575de86e3263aea3220f4';

    public const EMPTY_SET_SHA256 = '01ba4719c80b6fe911b091a7c05124b64eeece964e09c058ef8f9805daca546b';

    public const GENERATION_ID = 'career-current-342-30-bootstrap-v1';

    public const POINTER_SHA256 = '1ebfd2826be9d3b63d810d33050034e3d424c95b3db81fa49b0822c5e6b2ec08';

    public const PROJECTION_SHA256 = '397f2a4ec284e9c0a6cd610447541ad4773fa7a7f3045008fab5efb334ec85c6';

    public const LEDGER_SHA256 = '975b311bb346a090f1add678d5a6d9f1be230f87b223e2c3c829f4c7fd7aac6e';

    private const MANIFEST_PATH = 'docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json';

    private const PROMOTION_REASON = 'canonical_rollout_batch_promotion';

    private const REPAIR_REASON = 'career_baseline_30_index_state_authority_repair';

    /** @param list<string> $values */
    public static function setHash(array $values): string
    {
        return hash('sha256', implode("\n", self::normalizedList($values))."\n");
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<array<string, mixed>>  $occupations
     * @param  list<array<string, mixed>>  $indexStates
     * @return array<string, mixed>
     */
    public static function analyze(array $manifest, array $occupations, array $indexStates): array
    {
        $baselineSlugs = self::normalizedList($manifest['baseline_slugs'] ?? null);
        $deltaSlugs = self::normalizedList($manifest['delta_slugs'] ?? null);
        $targetSlugs = self::normalizedList([...$baselineSlugs, ...$deltaSlugs]);
        self::assertFrozenSets($baselineSlugs, $deltaSlugs, $targetSlugs);

        $occupationBySlug = [];
        $slugByOccupation = [];
        foreach ($occupations as $occupation) {
            $slug = self::normalizedSlug($occupation['canonical_slug'] ?? null);
            $id = self::stringValue($occupation['id'] ?? null);
            if ($slug === null || $id === '' || isset($occupationBySlug[$slug]) || isset($slugByOccupation[$id])) {
                throw new CareerBaselineIndexStateAuthorityRepairFailure('OCCUPATION_IDENTITY_INVALID_OR_DUPLICATE');
            }
            if (! in_array($slug, $targetSlugs, true)) {
                throw new CareerBaselineIndexStateAuthorityRepairFailure('OCCUPATION_OUTSIDE_TARGET');
            }
            $occupationBySlug[$slug] = $id;
            $slugByOccupation[$id] = $slug;
        }
        if (self::difference($targetSlugs, array_keys($occupationBySlug)) !== []) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure('TARGET_OCCUPATION_MISSING');
        }

        $statesByOccupation = [];
        foreach ($indexStates as $state) {
            $occupationId = self::stringValue($state['occupation_id'] ?? null);
            $id = self::stringValue($state['id'] ?? null);
            if ($occupationId === '' || $id === '' || ! isset($slugByOccupation[$occupationId])) {
                throw new CareerBaselineIndexStateAuthorityRepairFailure('INDEX_STATE_ROW_INVALID_OR_OUTSIDE_TARGET');
            }
            $statesByOccupation[$occupationId][] = $state;
        }

        $baselineRows = [];
        $deltaRows = [];
        $baselinePresent = [];
        $baselineMatching = [];
        $baselineRepair = [];
        $missing = [];
        $stateMismatch = [];
        $eligibilityMismatch = [];
        $pathMismatch = [];
        $targetMismatch = [];
        $reasonMismatch = [];
        $ties = [];
        $deltaMatching = [];
        $deltaMismatch = [];
        $deltaMissing = [];

        foreach ($targetSlugs as $slug) {
            $occupationId = $occupationBySlug[$slug];
            $states = $statesByOccupation[$occupationId] ?? [];
            usort($states, self::compareStates(...));
            if (isset($states[1])
                && self::stringValue($states[0]['changed_at'] ?? null) === self::stringValue($states[1]['changed_at'] ?? null)
                && self::stringValue($states[0]['created_at'] ?? null) === self::stringValue($states[1]['created_at'] ?? null)) {
                $ties[] = $slug;
            }

            $latest = $states[0] ?? null;
            $isBaseline = in_array($slug, $baselineSlugs, true);
            if ($latest === null) {
                if ($isBaseline) {
                    $baselineRows[] = 'slug='.$slug.'|occupation_id='.$occupationId.'|latest_index_state=missing';
                    $baselineRepair[] = $slug;
                    $missing[] = $slug;
                } else {
                    $deltaRows[] = $slug.'|occupation='.$occupationId.'|latest_index_state=missing';
                    $deltaMismatch[] = $slug;
                    $deltaMissing[] = $slug;
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
            $matches = $indexState === 'indexed'
                && $eligible
                && $canonicalPath === '/career/jobs/'.$slug
                && $canonicalTarget === ''
                && in_array(self::PROMOTION_REASON, $reasonCodes, true);

            if ($isBaseline) {
                $baselinePresent[] = $slug;
                $baselineRows[] = $row;
                if ($matches) {
                    $baselineMatching[] = $slug;
                } else {
                    $baselineRepair[] = $slug;
                    if ($indexState !== 'indexed') {
                        $stateMismatch[] = $slug;
                    }
                    if (! $eligible) {
                        $eligibilityMismatch[] = $slug;
                    }
                    if ($canonicalPath !== '/career/jobs/'.$slug) {
                        $pathMismatch[] = $slug;
                    }
                    if ($canonicalTarget !== '') {
                        $targetMismatch[] = $slug;
                    }
                    if (! in_array(self::PROMOTION_REASON, $reasonCodes, true)) {
                        $reasonMismatch[] = $slug;
                    }
                }
            } else {
                $deltaRows[] = $row;
                if ($matches) {
                    $deltaMatching[] = $slug;
                } else {
                    $deltaMismatch[] = $slug;
                }
            }
        }

        $sets = [
            'baseline_present' => $baselinePresent,
            'baseline_matching' => $baselineMatching,
            'repair' => $baselineRepair,
            'missing' => $missing,
            'state_mismatch' => $stateMismatch,
            'eligibility_mismatch' => $eligibilityMismatch,
            'path_mismatch' => $pathMismatch,
            'target_mismatch' => $targetMismatch,
            'reason_mismatch' => $reasonMismatch,
            'ties' => $ties,
            'delta_matching' => $deltaMatching,
            'delta_mismatch' => $deltaMismatch,
            'delta_missing' => $deltaMissing,
        ];
        foreach ($sets as $key => $values) {
            $sets[$key] = self::normalizedList($values);
        }

        return [
            'manifest' => [
                'baseline_count' => 30,
                'baseline_set_sha256' => self::setHash($baselineSlugs),
                'delta_count' => 1016,
                'delta_set_sha256' => self::setHash($deltaSlugs),
                'target_count' => 1046,
                'target_set_sha256' => self::setHash($targetSlugs),
            ],
            'baseline' => [
                'current_state_row_count' => count($baselineRows),
                'current_state_sha256' => self::setHash($baselineRows),
                'preserved_count' => count($sets['baseline_present']),
                'preserved_set_sha256' => self::setHash($sets['baseline_present']),
                'matching_count' => count($sets['baseline_matching']),
                'matching_set_sha256' => self::setHash($sets['baseline_matching']),
                'repair_target_count' => count($sets['repair']),
                'repair_target_set_sha256' => self::setHash($sets['repair']),
                'missing_count' => count($sets['missing']),
                'missing_set_sha256' => self::setHash($sets['missing']),
                'state_mismatch_count' => count($sets['state_mismatch']),
                'state_mismatch_set_sha256' => self::setHash($sets['state_mismatch']),
                'eligibility_mismatch_count' => count($sets['eligibility_mismatch']),
                'eligibility_mismatch_set_sha256' => self::setHash($sets['eligibility_mismatch']),
                'canonical_path_mismatch_count' => count($sets['path_mismatch']),
                'canonical_path_mismatch_set_sha256' => self::setHash($sets['path_mismatch']),
                'canonical_target_mismatch_count' => count($sets['target_mismatch']),
                'canonical_target_mismatch_set_sha256' => self::setHash($sets['target_mismatch']),
                'promotion_reason_mismatch_count' => count($sets['reason_mismatch']),
                'promotion_reason_mismatch_set_sha256' => self::setHash($sets['reason_mismatch']),
                'latest_state_tie_count' => count(self::intersection($sets['ties'], $baselineSlugs)),
                'latest_state_tie_set_sha256' => self::setHash(self::intersection($sets['ties'], $baselineSlugs)),
                'repair_slugs' => $sets['repair'],
            ],
            'delta' => [
                'current_state_row_count' => count($deltaRows),
                'current_state_sha256' => self::setHash($deltaRows),
                'matching_count' => count($sets['delta_matching']),
                'matching_set_sha256' => self::setHash($sets['delta_matching']),
                'missing_or_mismatching_count' => count($sets['delta_mismatch']),
                'missing_or_mismatching_set_sha256' => self::setHash($sets['delta_mismatch']),
                'latest_state_missing_count' => count($sets['delta_missing']),
                'latest_state_missing_set_sha256' => self::setHash($sets['delta_missing']),
                'latest_state_tie_count' => count(self::intersection($sets['ties'], $deltaSlugs)),
                'latest_state_tie_set_sha256' => self::setHash(self::intersection($sets['ties'], $deltaSlugs)),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function main(array $argv): int
    {
        $mode = trim((string) getenv('CAREER_BASELINE_REPAIR_MODE'));
        $transactionCommitted = false;

        try {
            if (! in_array($mode, ['preflight', 'apply', 'readback'], true)
                || (($argv[1] ?? '') !== ($mode === 'apply' ? 'apply' : 'inspect'))) {
                throw new CareerBaselineIndexStateAuthorityRepairFailure('MODE_INVALID');
            }
            $expected = self::expectedEnvironment($mode);
            $backendRoot = self::backendRoot();
            require $backendRoot.'/vendor/autoload.php';
            $app = require $backendRoot.'/bootstrap/app.php';
            $app->make(Kernel::class)->bootstrap();
            self::assertNoPendingMigrations($backendRoot);
            $authority = self::validatePointerBoundAuthority($backendRoot);
            $manifest = self::manifest($backendRoot);

            if ($mode === 'apply') {
                $result = DB::transaction(function () use ($manifest, $expected): array {
                    $snapshot = self::snapshot($manifest, true);
                    $before = self::analyze($manifest, $snapshot['occupations'], $snapshot['index_states']);
                    self::assertExpectedPreflightState($before, $expected);
                    $repairSlugs = $before['baseline']['repair_slugs'];
                    if ($repairSlugs === [] || count($repairSlugs) > 30) {
                        throw new CareerBaselineIndexStateAuthorityRepairFailure('REPAIR_TARGET_COUNT_INVALID');
                    }

                    $now = now();
                    self::assertTimestampStrictlyLater($now, $snapshot['index_states'], $repairSlugs, $snapshot['occupations']);
                    $occupationBySlug = [];
                    foreach ($snapshot['occupations'] as $occupation) {
                        $occupationBySlug[(string) $occupation['canonical_slug']] = (string) $occupation['id'];
                    }
                    $createdIds = [];
                    foreach ($repairSlugs as $slug) {
                        $reasonCodes = [
                            self::PROMOTION_REASON,
                            self::REPAIR_REASON,
                            'baseline_set_sha256:'.self::BASELINE_SET_SHA256,
                            'failed_task_3b_run:31651231779',
                        ];
                        $fingerprint = hash('sha256', json_encode([
                            'source' => self::REPAIR_REASON,
                            'slug' => $slug,
                            'occupation_id' => $occupationBySlug[$slug],
                            'canonical_path' => '/career/jobs/'.$slug,
                            'reason_codes' => $reasonCodes,
                            'preflight_baseline_state_sha256' => $expected['baseline_state_sha256'],
                            'preflight_delta_state_sha256' => $expected['delta_state_sha256'],
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                        $created = IndexState::query()->create([
                            'occupation_id' => $occupationBySlug[$slug],
                            'index_state' => 'indexed',
                            'index_eligible' => true,
                            'canonical_path' => '/career/jobs/'.$slug,
                            'canonical_target' => null,
                            'reason_codes' => $reasonCodes,
                            'changed_at' => $now,
                            'import_run_id' => null,
                            'row_fingerprint' => $fingerprint,
                        ]);
                        $createdIds[] = (string) $created->id;
                    }

                    $afterSnapshot = self::snapshot($manifest, true);
                    $after = self::analyze($manifest, $afterSnapshot['occupations'], $afterSnapshot['index_states']);
                    if ($after['baseline']['preserved_count'] !== 30
                        || $after['baseline']['matching_count'] !== 30
                        || $after['baseline']['repair_target_count'] !== 0
                        || $after['baseline']['latest_state_tie_count'] !== 0
                        || ! hash_equals(self::BASELINE_SET_SHA256, $after['baseline']['matching_set_sha256'])
                        || ! hash_equals(self::EXPECTED_DELTA_STATE_SHA256, $after['delta']['current_state_sha256'])
                        || $after['delta']['matching_count'] !== 0
                        || $after['delta']['missing_or_mismatching_count'] !== 1016
                        || $after['delta']['latest_state_tie_count'] !== 0
                        || count($createdIds) !== (int) $expected['repair_target_count']) {
                        throw new CareerBaselineIndexStateAuthorityRepairFailure('POSTWRITE_READBACK_NOT_EXACT');
                    }

                    return [
                        'before' => $before,
                        'after' => $after,
                        'created_count' => count($createdIds),
                        'created_id_set_sha256' => self::setHash($createdIds),
                    ];
                }, 1);
                $transactionCommitted = true;
                self::emit(self::successReceipt($mode, $expected, $authority, $result['after'], [
                    'database_insert_count' => $result['created_count'],
                    'database_insert_id_set_sha256' => $result['created_id_set_sha256'],
                    'database_transaction_committed' => true,
                    'writes_committed' => true,
                ]));

                return 0;
            }

            $observed = [];
            DB::listen(static function (QueryExecuted $query) use (&$observed): void {
                $observed[] = strtolower((string) strtok(ltrim($query->sql), " \t\r\n"));
            });
            $snapshot = self::snapshot($manifest, false);
            $analysis = self::analyze($manifest, $snapshot['occupations'], $snapshot['index_states']);
            if ($observed === [] || array_values(array_unique($observed)) !== ['select']) {
                throw new CareerBaselineIndexStateAuthorityRepairFailure('DATABASE_NOT_SELECT_ONLY');
            }
            if (! hash_equals(self::EXPECTED_DELTA_STATE_SHA256, $analysis['delta']['current_state_sha256'])
                || $analysis['delta']['matching_count'] !== 0
                || $analysis['delta']['missing_or_mismatching_count'] !== 1016
                || $analysis['delta']['latest_state_tie_count'] !== 0
                || $analysis['baseline']['latest_state_tie_count'] !== 0) {
                throw new CareerBaselineIndexStateAuthorityRepairFailure('DATABASE_AUTHORITY_NOT_REPAIRABLE');
            }
            if ($mode === 'readback' && $analysis['baseline']['repair_target_count'] !== 0) {
                throw new CareerBaselineIndexStateAuthorityRepairFailure('READBACK_BASELINE_NOT_EXACT');
            }
            self::emit(self::successReceipt($mode, $expected, $authority, $analysis, [
                'observed_database_query_count' => count($observed),
                'database_insert_count' => 0,
                'database_transaction_committed' => false,
                'writes_committed' => false,
            ]));

            return 0;
        } catch (CareerBaselineIndexStateAuthorityRepairFailure $failure) {
            self::emit(self::failureReceipt($mode, $failure->safeCode, $transactionCommitted));

            return 1;
        } catch (Throwable) {
            self::emit(self::failureReceipt($mode, 'UNEXPECTED_CONTROL_FAILURE', $transactionCommitted));

            return 1;
        }
    }

    /** @return array<string, mixed> */
    private static function successReceipt(string $mode, array $expected, array $authority, array $analysis, array $write): array
    {
        $status = match ($mode) {
            'preflight' => $analysis['baseline']['repair_target_count'] === 0
                ? 'PASS_BASELINE_ALREADY_EXACT'
                : 'PASS_BASELINE_REPAIR_REQUIRED',
            'apply' => 'PASS_BASELINE_REPAIR_APPLIED',
            'readback' => 'PASS_BASELINE_AUTHORITY_EXACT',
        };
        $sanitizedBaseline = $analysis['baseline'];
        unset($sanitizedBaseline['repair_slugs']);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => $mode,
            'status' => $status,
            'safe_code' => null,
            'control_plane_sha' => $expected['control_plane_sha'],
            'active_revision' => $expected['active_revision'],
            'active_release_name_sha256' => hash('sha256', $expected['release_name']),
            'workflow_run_id' => $expected['run_id'],
            'workflow_run_attempt' => $expected['run_attempt'],
            'preflight_lineage' => $expected['preflight_lineage'],
            'apply_lineage' => $expected['apply_lineage'],
            'authority' => $authority,
            'manifest' => $analysis['manifest'],
            'baseline' => $sanitizedBaseline,
            'delta' => $analysis['delta'],
            'deploy_lock_absent' => true,
            'deploy_process_absent' => true,
            'migration_file_delta_count' => 0,
            'database_select_only' => $mode !== 'apply',
            'database_insert_count' => $write['database_insert_count'],
            'database_insert_id_set_sha256' => $write['database_insert_id_set_sha256'] ?? self::EMPTY_SET_SHA256,
            'database_update_count' => 0,
            'database_delete_count' => 0,
            'database_transaction_committed' => $write['database_transaction_committed'],
            'cms_write_count' => 0,
            'cache_write_count' => 0,
            'pointer_write_count' => 0,
            'artifact_write_count' => 0,
            'publication_write_count' => 0,
            'discoverability_write_count' => 0,
            'migration_count' => 0,
            'deployment_count' => 0,
            'restart_count' => 0,
            'sitemap_write_count' => 0,
            'llms_write_count' => 0,
            'search_submission_count' => 0,
            'writes_committed' => $write['writes_committed'],
            'automatic_retry_allowed' => false,
            'automatic_rollback_allowed' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function failureReceipt(string $mode, string $safeCode, bool $transactionCommitted): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => in_array($mode, ['preflight', 'apply', 'readback'], true) ? $mode : 'invalid',
            'status' => 'HOLD_BASELINE_AUTHORITY_REPAIR',
            'safe_code' => $safeCode,
            'database_insert_count' => $transactionCommitted ? null : 0,
            'database_update_count' => 0,
            'database_delete_count' => 0,
            'database_transaction_committed' => $transactionCommitted,
            'cms_write_count' => 0,
            'cache_write_count' => 0,
            'pointer_write_count' => 0,
            'artifact_write_count' => 0,
            'publication_write_count' => 0,
            'discoverability_write_count' => 0,
            'migration_count' => 0,
            'deployment_count' => 0,
            'restart_count' => 0,
            'sitemap_write_count' => 0,
            'llms_write_count' => 0,
            'search_submission_count' => 0,
            'writes_committed' => $transactionCommitted,
            'automatic_retry_allowed' => false,
            'automatic_rollback_allowed' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function expectedEnvironment(string $mode): array
    {
        $releaseName = trim((string) getenv('CAREER_BASELINE_REPAIR_RELEASE_NAME'));
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $releaseName)) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure('RELEASE_NAME_INVALID');
        }

        return [
            'control_plane_sha' => self::shaEnv('CAREER_BASELINE_REPAIR_CONTROL_PLANE_SHA', 40),
            'active_revision' => self::shaEnv('CAREER_BASELINE_REPAIR_ACTIVE_REVISION', 40),
            'release_name' => $releaseName,
            'run_id' => self::positiveIntEnv('CAREER_BASELINE_REPAIR_RUN_ID'),
            'run_attempt' => self::positiveIntEnv('CAREER_BASELINE_REPAIR_RUN_ATTEMPT'),
            'baseline_state_sha256' => $mode === 'apply' ? self::shaEnv('CAREER_BASELINE_REPAIR_EXPECTED_BASELINE_STATE_SHA256') : '',
            'delta_state_sha256' => $mode === 'apply' ? self::shaEnv('CAREER_BASELINE_REPAIR_EXPECTED_DELTA_STATE_SHA256') : self::EXPECTED_DELTA_STATE_SHA256,
            'repair_target_count' => $mode === 'apply' ? self::positiveIntEnv('CAREER_BASELINE_REPAIR_EXPECTED_TARGET_COUNT') : 0,
            'repair_target_set_sha256' => $mode === 'apply' ? self::shaEnv('CAREER_BASELINE_REPAIR_EXPECTED_TARGET_SET_SHA256') : '',
            'preflight_lineage' => [
                'run_id' => self::optionalPositiveIntEnv('CAREER_BASELINE_REPAIR_PREFLIGHT_RUN_ID'),
                'run_attempt' => self::optionalPositiveIntEnv('CAREER_BASELINE_REPAIR_PREFLIGHT_RUN_ATTEMPT'),
                'receipt_sha256' => self::optionalShaEnv('CAREER_BASELINE_REPAIR_PREFLIGHT_RECEIPT_SHA256'),
                'artifact_digest' => self::optionalDigestEnv('CAREER_BASELINE_REPAIR_PREFLIGHT_ARTIFACT_DIGEST'),
            ],
            'apply_lineage' => [
                'run_id' => self::optionalPositiveIntEnv('CAREER_BASELINE_REPAIR_APPLY_RUN_ID'),
                'run_attempt' => self::optionalPositiveIntEnv('CAREER_BASELINE_REPAIR_APPLY_RUN_ATTEMPT'),
                'receipt_sha256' => self::optionalShaEnv('CAREER_BASELINE_REPAIR_APPLY_RECEIPT_SHA256'),
                'artifact_digest' => self::optionalDigestEnv('CAREER_BASELINE_REPAIR_APPLY_ARTIFACT_DIGEST'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function validatePointerBoundAuthority(string $backendRoot): array
    {
        $privateRoot = $backendRoot.'/storage/app/private';
        $authorityRoot = $privateRoot.'/career_generation_authority';
        $activePath = $authorityRoot.'/active-generation.json';
        $immutablePath = $authorityRoot.'/generations/'.self::GENERATION_ID.'/generation-pointer.json';
        $active = self::readExactJson($authorityRoot, $activePath, self::POINTER_SHA256, 'ACTIVE_POINTER');
        $immutable = self::readExactJson($authorityRoot, $immutablePath, self::POINTER_SHA256, 'IMMUTABLE_POINTER');
        if ($active !== $immutable
            || ($active['schema_version'] ?? null) !== 'career.generation_pointer.v1'
            || ($active['payload']['generation_id'] ?? null) !== self::GENERATION_ID) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure('POINTER_CONTRACT_INVALID');
        }
        $payload = $active['payload'];
        foreach ([
            'projection' => ['career_runtime_publish_projection', 'career-runtime-publish-projection.json', self::PROJECTION_SHA256],
            'ledger' => ['career_release_ledger', 'career-full-release-ledger.json', self::LEDGER_SHA256],
        ] as $key => [$family, $filename, $sha]) {
            $descriptor = $payload['artifacts'][$key] ?? null;
            $relative = is_array($descriptor) ? ($descriptor['path'] ?? null) : null;
            if (! is_string($relative)
                || ($descriptor['sha256'] ?? null) !== $sha
                || preg_match('#^'.preg_quote($family, '#').'/[A-Za-z0-9][A-Za-z0-9._-]{0,127}/'.preg_quote($filename, '#').'$#D', $relative) !== 1) {
                throw new CareerBaselineIndexStateAuthorityRepairFailure(strtoupper($key).'_DESCRIPTOR_INVALID');
            }
            self::readExactJson($privateRoot, $privateRoot.'/'.$relative, $sha, strtoupper($key));
        }

        $app = app();
        $loaded = $app->make(CareerGenerationAuthorityLoader::class)->loadStrict();
        $items = $loaded['projection']['items'] ?? null;
        if (! is_array($items)) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure('PROJECTION_ITEMS_INVALID');
        }
        $slugs = [];
        $rows = [];
        $publishedSlugs = [];
        $publishedRows = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new CareerBaselineIndexStateAuthorityRepairFailure('PROJECTION_ITEM_INVALID');
            }
            $slug = self::normalizedSlug($item['slug'] ?? null);
            $rawLocale = $item['locale'] ?? null;
            if ($slug === null || ! in_array($rawLocale, ['en', 'zh'], true)) {
                throw new CareerBaselineIndexStateAuthorityRepairFailure('PROJECTION_ROW_IDENTITY_INVALID');
            }
            $locale = $rawLocale === 'zh' ? 'zh-CN' : 'en';
            if (isset($rows[$slug.'|'.$locale])) {
                throw new CareerBaselineIndexStateAuthorityRepairFailure('PROJECTION_DUPLICATE_ROW');
            }
            $slugs[$slug] = true;
            $rows[$slug.'|'.$locale] = true;
            if (($item['runtime_publish_state'] ?? null) === 'published') {
                if (($item['public_resolution_type'] ?? null) !== 'public_canonical_job'
                    || ($item['release_gate_pass'] ?? false) !== true) {
                    throw new CareerBaselineIndexStateAuthorityRepairFailure('PUBLISHED_ROW_AUTHORITY_INVALID');
                }
                $publishedSlugs[$slug] = true;
                $publishedRows[$slug.'|'.$locale] = true;
            }
        }
        $slugList = array_keys($slugs);
        $rowList = array_keys($rows);
        $publishedSlugList = array_keys($publishedSlugs);
        $publishedRowList = array_keys($publishedRows);
        foreach ($slugList as $slug) {
            if (! isset($rows[$slug.'|en'], $rows[$slug.'|zh-CN'])) {
                throw new CareerBaselineIndexStateAuthorityRepairFailure('PROJECTION_LOCALE_PAIR_INCOMPLETE');
            }
        }
        foreach ($publishedSlugList as $slug) {
            if (! isset($publishedRows[$slug.'|en'], $publishedRows[$slug.'|zh-CN'])) {
                throw new CareerBaselineIndexStateAuthorityRepairFailure('PUBLISHED_LOCALE_PAIR_INCOMPLETE');
            }
        }
        if (count($slugList) !== 342 || count($rowList) !== 684
            || count($publishedSlugList) !== 30 || count($publishedRowList) !== 60
            || ! hash_equals(self::BASELINE_SET_SHA256, self::setHash($publishedSlugList))
            || ! hash_equals(self::BASELINE_LOCALE_ROW_SET_SHA256, self::setHash($publishedRowList))) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure('POINTER_BOUND_PUBLIC_AUTHORITY_INVALID');
        }

        return [
            'generation_id' => self::GENERATION_ID,
            'pointer_document_sha256' => self::POINTER_SHA256,
            'projection_sha256' => self::PROJECTION_SHA256,
            'ledger_sha256' => self::LEDGER_SHA256,
            'slug_count' => 342,
            'locale_row_count' => 684,
            'published_slug_count' => 30,
            'published_slug_set_sha256' => self::BASELINE_SET_SHA256,
            'published_locale_row_count' => 60,
            'published_locale_row_set_sha256' => self::BASELINE_LOCALE_ROW_SET_SHA256,
        ];
    }

    /** @return array<string, mixed> */
    private static function readExactJson(string $root, string $path, string $sha, string $stage): array
    {
        self::assertNoSymlinkPath($root, $path, $stage);
        $rootReal = realpath($root);
        $pathReal = realpath($path);
        $stat = $pathReal !== false ? @lstat($pathReal) : false;
        if (! is_string($rootReal) || ! is_string($pathReal) || ! is_array($stat)
            || is_link($path) || ! str_starts_with($pathReal, $rootReal.'/')
            || ! is_file($pathReal) || (int) ($stat['nlink'] ?? 0) !== 1) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure($stage.'_PATH_INVALID');
        }
        $bytes = file_get_contents($pathReal);
        if (! is_string($bytes) || ! hash_equals($sha, hash('sha256', $bytes))) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure($stage.'_HASH_INVALID');
        }
        try {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure($stage.'_JSON_INVALID');
        }
        if (! is_array($decoded)) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure($stage.'_JSON_INVALID');
        }

        return $decoded;
    }

    private static function assertNoSymlinkPath(string $root, string $path, string $stage): void
    {
        $prefix = rtrim($root, '/').'/';
        if (! str_starts_with($path, $prefix)) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure($stage.'_PATH_INVALID');
        }
        $current = rtrim($root, '/');
        foreach (explode('/', substr($path, strlen($prefix))) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new CareerBaselineIndexStateAuthorityRepairFailure($stage.'_PATH_INVALID');
            }
            $current .= '/'.$segment;
            if (is_link($current)) {
                throw new CareerBaselineIndexStateAuthorityRepairFailure($stage.'_PATH_INVALID');
            }
        }
    }

    private static function assertNoPendingMigrations(string $backendRoot): void
    {
        $migrationFiles = glob($backendRoot.'/database/migrations/*.php');
        if (! is_array($migrationFiles) || $migrationFiles === []) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure('MIGRATION_FILES_INVALID');
        }
        $expected = array_map(
            static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            $migrationFiles,
        );
        $applied = DB::table('migrations')->pluck('migration')->map(
            static fn (mixed $value): string => trim((string) $value),
        )->all();
        if (array_values(array_diff($expected, $applied)) !== []) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure('PENDING_MIGRATION_PRESENT');
        }
    }

    /** @return array<string, mixed> */
    private static function manifest(string $backendRoot): array
    {
        $bytes = file_get_contents($backendRoot.'/'.self::MANIFEST_PATH);
        $manifest = is_string($bytes) ? json_decode($bytes, true) : null;
        if (! is_string($bytes) || ! is_array($manifest)
            || ! hash_equals(self::MANIFEST_SHA256, hash('sha256', $bytes))) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure('MANIFEST_IDENTITY_INVALID');
        }

        return $manifest;
    }

    /** @return array{occupations:list<array<string,mixed>>,index_states:list<array<string,mixed>>} */
    private static function snapshot(array $manifest, bool $locked): array
    {
        $target = self::normalizedList([...(array) $manifest['baseline_slugs'], ...(array) $manifest['delta_slugs']]);
        $occupationQuery = Occupation::query()->whereIn('canonical_slug', $target)->orderBy('canonical_slug');
        if ($locked) {
            $occupationQuery->lockForUpdate();
        }
        $occupations = $occupationQuery->get(['id', 'canonical_slug'])->map(static fn (Occupation $row): array => [
            'id' => (string) $row->id,
            'canonical_slug' => strtolower(trim((string) $row->canonical_slug)),
        ])->all();
        $stateQuery = IndexState::query()->whereIn('occupation_id', array_column($occupations, 'id'))
            ->orderBy('occupation_id')->orderBy('changed_at')->orderBy('created_at')->orderBy('id');
        if ($locked) {
            $stateQuery->lockForUpdate();
        }
        $states = $stateQuery->get([
            'id', 'occupation_id', 'index_state', 'index_eligible', 'canonical_path', 'canonical_target',
            'reason_codes', 'changed_at', 'created_at',
        ])->map(static fn (IndexState $row): array => [
            'id' => (string) $row->id,
            'occupation_id' => (string) $row->occupation_id,
            'index_state' => (string) $row->index_state,
            'index_eligible' => (bool) $row->index_eligible,
            'canonical_path' => (string) $row->canonical_path,
            'canonical_target' => $row->canonical_target === null ? '' : (string) $row->canonical_target,
            'reason_codes' => is_array($row->reason_codes) ? $row->reason_codes : [],
            'changed_at' => self::stringValue($row->changed_at),
            'created_at' => self::stringValue($row->created_at),
        ])->all();

        return ['occupations' => $occupations, 'index_states' => $states];
    }

    private static function assertExpectedPreflightState(array $analysis, array $expected): void
    {
        if (! hash_equals($expected['baseline_state_sha256'], $analysis['baseline']['current_state_sha256'])
            || ! hash_equals($expected['delta_state_sha256'], $analysis['delta']['current_state_sha256'])
            || (int) $expected['repair_target_count'] !== $analysis['baseline']['repair_target_count']
            || ! hash_equals($expected['repair_target_set_sha256'], $analysis['baseline']['repair_target_set_sha256'])
            || $analysis['baseline']['latest_state_tie_count'] !== 0
            || $analysis['delta']['latest_state_tie_count'] !== 0
            || $analysis['delta']['matching_count'] !== 0
            || $analysis['delta']['missing_or_mismatching_count'] !== 1016) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure('PREFLIGHT_DATABASE_STATE_DRIFT');
        }
    }

    private static function assertTimestampStrictlyLater(DateTimeInterface $now, array $states, array $repairSlugs, array $occupations): void
    {
        $repairIds = [];
        foreach ($occupations as $occupation) {
            if (in_array($occupation['canonical_slug'], $repairSlugs, true)) {
                $repairIds[(string) $occupation['id']] = true;
            }
        }
        foreach ($states as $state) {
            if (! isset($repairIds[(string) $state['occupation_id']])) {
                continue;
            }
            foreach (['changed_at', 'created_at'] as $field) {
                $value = self::stringValue($state[$field] ?? null);
                if ($value !== '' && new \DateTimeImmutable($value) >= $now) {
                    throw new CareerBaselineIndexStateAuthorityRepairFailure('NEW_TIMESTAMP_NOT_STRICTLY_LATEST');
                }
            }
        }
    }

    private static function compareStates(array $left, array $right): int
    {
        foreach (['changed_at', 'created_at', 'id'] as $field) {
            $comparison = strcmp(self::stringValue($right[$field] ?? null), self::stringValue($left[$field] ?? null));
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    private static function assertFrozenSets(array $baseline, array $delta, array $target): void
    {
        if (count($baseline) !== 30 || count($delta) !== 1016 || count($target) !== 1046
            || ! hash_equals(self::BASELINE_SET_SHA256, self::setHash($baseline))
            || ! hash_equals(self::DELTA_SET_SHA256, self::setHash($delta))
            || ! hash_equals(self::TARGET_SET_SHA256, self::setHash($target))) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure('FROZEN_MANIFEST_SET_DRIFT');
        }
    }

    /** @return list<string> */
    private static function normalizedList(mixed $values): array
    {
        if (! is_array($values)) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure('SET_INPUT_INVALID');
        }
        $normalized = [];
        foreach ($values as $value) {
            $item = is_string($value) ? strtolower(trim($value)) : '';
            if ($item !== '') {
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
        $slug = strtolower(trim($value));

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) === 1 ? $slug : null;
    }

    /** @return list<string> */
    private static function difference(array $left, array $right): array
    {
        return self::normalizedList(array_values(array_diff($left, $right)));
    }

    /** @return list<string> */
    private static function intersection(array $left, array $right): array
    {
        return self::normalizedList(array_values(array_intersect($left, $right)));
    }

    private static function stringValue(mixed $value): string
    {
        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d\TH:i:s.uP')
            : trim((string) ($value ?? ''));
    }

    private static function backendRoot(): string
    {
        $value = trim((string) getenv('CAREER_BASELINE_REPAIR_BACKEND_ROOT'));
        $real = $value !== '' ? realpath($value) : false;
        if (! is_string($real) || ! is_dir($real) || ! str_ends_with($real, '/backend')) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure('BACKEND_ROOT_INVALID');
        }

        return $real;
    }

    private static function shaEnv(string $name, int $length = 64): string
    {
        $value = strtolower(trim((string) getenv($name)));
        if (! preg_match('/^[0-9a-f]{'.$length.'}$/D', $value)) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure('IDENTITY_ENV_INVALID');
        }

        return $value;
    }

    private static function positiveIntEnv(string $name): int
    {
        $value = trim((string) getenv($name));
        if (! preg_match('/^[1-9][0-9]*$/D', $value)) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure('INTEGER_ENV_INVALID');
        }

        return (int) $value;
    }

    private static function optionalPositiveIntEnv(string $name): ?int
    {
        $value = trim((string) getenv($name));

        return $value === '' ? null : self::positiveIntEnv($name);
    }

    private static function optionalShaEnv(string $name): ?string
    {
        return trim((string) getenv($name)) === '' ? null : self::shaEnv($name);
    }

    private static function optionalDigestEnv(string $name): ?string
    {
        $value = strtolower(trim((string) getenv($name)));
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^sha256:[0-9a-f]{64}$/D', $value)) {
            throw new CareerBaselineIndexStateAuthorityRepairFailure('DIGEST_ENV_INVALID');
        }

        return $value;
    }

    private static function emit(array $receipt): void
    {
        echo json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__ || __FILE__ === '/dev/stdin') {
    exit(CareerBaselineIndexStateAuthorityRepair::main($argv));
}
