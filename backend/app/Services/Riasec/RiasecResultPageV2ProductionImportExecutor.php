<?php

declare(strict_types=1);

namespace App\Services\Riasec;

use App\Models\ContentPackRelease;
use App\Services\Storage\ContentReleaseManifestCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class RiasecResultPageV2ProductionImportExecutor
{
    public const PACK_ID = 'RIASEC';

    public const PACK_VERSION = 'result_page_v2';

    public const RELEASE_ACTION = 'riasec_result_page_v2_production_import';

    public const RELEASE_SCHEMA_VERSION = 'fap.riasec.result_page_v2.production_import_release.v0.1';

    public const DEFAULT_APPROVED_SNAPSHOT_PATH = 'content_assets/riasec/result_page_v2/releases/production_approved/v0_1/riasec_result_page_v2_prod_approved_2026_06_22_01.json';

    public const DEFAULT_APPROVAL_EVIDENCE_PATH = 'content_assets/riasec/result_page_v2/governance/production_approval_evidence/v0_1/riasec_result_page_v2_production_import_approval_2026_06_22_01.json';

    public const DEFAULT_DRY_RUN_ARTIFACT_PATH = 'content_assets/riasec/result_page_v2/qa/production_import_gate_dry_run_authorized_snapshot/v0_1/riasec_result_page_v2_production_import_gate_dry_run_authorized_snapshot_v0_1.json';

    public const DEFAULT_RELEASE_STORAGE_ROOT = 'private/content_releases/RIASEC/result_page_v2/production_import';

    /**
     * @var array<string,list<string>>
     */
    private const REQUIRED_SCOPE = [
        'tenant_ids' => ['single_owner_global'],
        'form_codes' => ['riasec_60', 'riasec_140'],
        'locales' => ['zh-CN'],
        'allowlist' => ['owner_manual_import_only'],
    ];

    /**
     * @var list<string>
     */
    private const FORBIDDEN_PUBLIC_PAYLOAD_KEYS = [
        '"attempt_id"',
        '"user_id"',
        '"raw_score"',
        '"raw_scores"',
        '"score_vector"',
        '"dimension_vector"',
        '"percentile"',
        '"selector_trace"',
        '"share_block"',
        '"token"',
        '"secret"',
    ];

    public function __construct(
        private readonly ContentReleaseManifestCatalogService $manifestCatalogService,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function run(array $options): array
    {
        $execute = (bool) ($options['execute'] ?? false);
        $outputDir = trim((string) ($options['output_dir'] ?? ''));
        $summary = $this->buildValidationSummary($options);

        if ($summary['errors'] !== []) {
            $summary['decision'] = 'fail';
            $summary['mode'] = $execute ? 'production_import_execute' : 'production_import_dry_run';
            $summary['execution'] = $this->nonExecutionRecord('validation_failed');
            $this->writeSummaryIfRequested($outputDir, $summary);

            return $summary;
        }

        if (! $execute) {
            $summary['decision'] = 'pass';
            $summary['mode'] = 'production_import_dry_run';
            $summary['execution'] = $this->nonExecutionRecord('dry_run_default_no_write');
            $summary['go_no_go']['ready_for_execute_with_exact_confirm_token'] = true;
            $this->writeSummaryIfRequested($outputDir, $summary);

            return $summary;
        }

        $confirm = trim((string) ($options['confirm_execute'] ?? ''));
        $expectedConfirm = (string) data_get($summary, 'expected_confirm_execute');
        if ($confirm === '' || ! hash_equals($expectedConfirm, $confirm)) {
            $summary['decision'] = 'fail';
            $summary['mode'] = 'production_import_execute';
            $summary['errors'][] = 'confirm_execute_token_mismatch';
            $summary['execution'] = $this->nonExecutionRecord('confirm_execute_token_mismatch');
            $this->writeSummaryIfRequested($outputDir, $summary);

            return $summary;
        }

        $execution = $this->executeImport($summary);
        $summary['decision'] = 'pass';
        $summary['mode'] = 'production_import_execute';
        $summary['execution'] = $execution;
        $summary['go_no_go']['production_import_execution_completed'] = true;
        $summary['go_no_go']['production_rollout_allowed_now'] = false;
        $this->writeSummaryIfRequested($outputDir, $summary);

        return $summary;
    }

    public static function expectedConfirmExecuteToken(string $snapshotId, string $snapshotSha256): string
    {
        return 'RIASEC_RESULT_PAGE_V2_PRODUCTION_IMPORT_EXECUTE:'.$snapshotId.':'.$snapshotSha256.':NO_ROLLOUT';
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function buildValidationSummary(array $options): array
    {
        $snapshotPath = $this->absolutePath((string) ($options['approved_snapshot_path'] ?? self::DEFAULT_APPROVED_SNAPSHOT_PATH));
        $approvalPath = $this->absolutePath((string) ($options['approval_evidence_path'] ?? self::DEFAULT_APPROVAL_EVIDENCE_PATH));
        $dryRunPath = $this->absolutePath((string) ($options['dry_run_artifact_path'] ?? self::DEFAULT_DRY_RUN_ARTIFACT_PATH));

        $snapshot = $this->decodeJsonFile($snapshotPath);
        $approval = $this->decodeJsonFile($approvalPath);
        $dryRun = $this->decodeJsonFile($dryRunPath);

        $expectedSnapshotId = trim((string) ($options['approved_snapshot_id'] ?? ''));
        $expectedSnapshotSha = $this->normalizeSha256((string) ($options['approved_snapshot_sha256'] ?? ''));
        $expectedApprovalId = trim((string) ($options['approval_evidence_id'] ?? ''));
        $expectedApprovalSha = $this->normalizeSha256((string) ($options['approval_evidence_sha256'] ?? ''));
        $expectedDryRunSha = $this->normalizeSha256((string) ($options['dry_run_artifact_sha256'] ?? ''));

        $snapshotSha = hash_file('sha256', $snapshotPath) ?: '';
        $approvalSha = hash_file('sha256', $approvalPath) ?: '';
        $dryRunSha = hash_file('sha256', $dryRunPath) ?: '';

        $errors = [];
        $this->requireEquals($errors, 'approved_snapshot_id', $expectedSnapshotId, (string) ($snapshot['snapshot_id'] ?? ''));
        $this->requireEquals($errors, 'approved_snapshot_sha256', $expectedSnapshotSha, $snapshotSha);
        $this->requireEquals($errors, 'approval_evidence_id', $expectedApprovalId, (string) ($approval['approval_id'] ?? ''));
        $this->requireEquals($errors, 'approval_evidence_sha256', $expectedApprovalSha, $approvalSha);
        $this->requireEquals($errors, 'dry_run_artifact_sha256', $expectedDryRunSha, $dryRunSha);

        $this->assertSnapshot($errors, $snapshot, $expectedSnapshotId, $expectedSnapshotSha);
        $this->assertApproval($errors, $approval, $expectedApprovalId, $expectedSnapshotId, $expectedSnapshotSha);
        $this->assertDryRun($errors, $dryRun, $expectedSnapshotId, $expectedSnapshotSha);
        $this->assertScope($errors, $options, $snapshot, $approval);
        $this->assertSafety($errors, $options, $snapshot, $approval, $dryRun);
        $this->assertNoForbiddenKeys($errors, [
            'approved_snapshot' => $snapshotPath,
            'approval_evidence' => $approvalPath,
            'dry_run_artifact' => $dryRunPath,
        ]);

        $releaseId = $this->deterministicReleaseUuid($expectedSnapshotId, $expectedSnapshotSha, $expectedDryRunSha);

        return [
            'schema_version' => self::RELEASE_SCHEMA_VERSION,
            'task_id' => 'RIASEC-RESULT-V2-PRODUCTION-IMPORT-COMMAND-IMPLEMENTATION-01',
            'decision' => 'pending',
            'mode' => 'production_import_dry_run',
            'release_id' => $releaseId,
            'release_snapshot_id' => $expectedSnapshotId,
            'expected_confirm_execute' => self::expectedConfirmExecuteToken($expectedSnapshotId, $expectedSnapshotSha),
            'inputs' => [
                'approved_snapshot' => [
                    'path' => $this->relativeToBasePath($snapshotPath),
                    'expected_sha256' => $expectedSnapshotSha,
                    'actual_sha256' => $snapshotSha,
                    'sha256_match' => $expectedSnapshotSha !== '' && $expectedSnapshotSha === $snapshotSha,
                ],
                'approval_evidence' => [
                    'path' => $this->relativeToBasePath($approvalPath),
                    'expected_sha256' => $expectedApprovalSha,
                    'actual_sha256' => $approvalSha,
                    'sha256_match' => $expectedApprovalSha !== '' && $expectedApprovalSha === $approvalSha,
                ],
                'dry_run_artifact' => [
                    'path' => $this->relativeToBasePath($dryRunPath),
                    'expected_sha256' => $expectedDryRunSha,
                    'actual_sha256' => $dryRunSha,
                    'sha256_match' => $expectedDryRunSha !== '' && $expectedDryRunSha === $dryRunSha,
                ],
            ],
            'scope' => $this->normalizedOptionScope($options),
            'safety' => [
                'rollback_kill_switch_confirmed' => (bool) ($options['rollback_kill_switch_confirmed'] ?? false),
                'kill_switch_ref' => trim((string) ($options['kill_switch_ref'] ?? '')),
                'post_deploy_smoke_procedure_id' => trim((string) ($options['post_deploy_smoke_procedure_id'] ?? '')),
                'rollout_separation_preserved' => true,
            ],
            'storage' => [
                'storage_disk' => 'local',
                'storage_path' => self::DEFAULT_RELEASE_STORAGE_ROOT.'/'.$releaseId,
            ],
            'go_no_go' => [
                'production_import_execution_completed' => false,
                'production_import_command_available' => true,
                'cms_write_allowed_only_with_execute_and_confirm_token' => true,
                'runtime_production_enablement_allowed_now' => false,
                'production_rollout_allowed_now' => false,
                'import_approval_counts_as_rollout_approval' => false,
            ],
            'negative_guarantees' => [
                'runtime_change_performed' => false,
                'environment_change_performed' => false,
                'production_rollout_opened' => false,
                'production_rollout_performed' => false,
                'approved_snapshot_modified' => false,
                'source_rc_snapshot_modified' => false,
                'staging_only_assets_marked_production_ready' => false,
            ],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    private function executeImport(array $summary): array
    {
        $storagePath = (string) data_get($summary, 'storage.storage_path');
        $storageRoot = storage_path('app/'.$storagePath);
        $releaseManifest = $this->releaseManifest($summary);
        $releaseManifestJson = $this->encodeJson($releaseManifest);
        $releaseManifestSha = hash('sha256', $releaseManifestJson);

        $this->resetDirectory($storageRoot);
        File::put($storageRoot.DIRECTORY_SEPARATOR.'manifest.json', $releaseManifestJson);
        File::copy(
            base_path(self::DEFAULT_APPROVED_SNAPSHOT_PATH),
            $storageRoot.DIRECTORY_SEPARATOR.'approved_snapshot.json'
        );
        File::copy(
            base_path(self::DEFAULT_APPROVAL_EVIDENCE_PATH),
            $storageRoot.DIRECTORY_SEPARATOR.'approval_evidence.json'
        );
        File::copy(
            base_path(self::DEFAULT_DRY_RUN_ARTIFACT_PATH),
            $storageRoot.DIRECTORY_SEPARATOR.'import_gate_dry_run.json'
        );

        $releaseId = (string) ($summary['release_id'] ?? '');
        $now = now();
        $release = DB::transaction(function () use ($releaseId, $releaseManifest, $releaseManifestSha, $storagePath, $summary, $now): ContentPackRelease {
            $release = ContentPackRelease::query()->updateOrCreate(
                ['id' => $releaseId],
                [
                    'action' => self::RELEASE_ACTION,
                    'region' => 'GLOBAL',
                    'locale' => 'zh-CN',
                    'dir_alias' => self::PACK_VERSION,
                    'from_version_id' => null,
                    'to_version_id' => null,
                    'from_pack_id' => null,
                    'to_pack_id' => self::PACK_ID,
                    'status' => 'success',
                    'message' => 'RIASEC Result Page V2 production import materialized without runtime rollout',
                    'created_by' => 'ops',
                    'manifest_hash' => $releaseManifestSha,
                    'compiled_hash' => (string) data_get($summary, 'inputs.dry_run_artifact.actual_sha256'),
                    'content_hash' => (string) data_get($summary, 'inputs.approved_snapshot.actual_sha256'),
                    'pack_version' => self::PACK_VERSION,
                    'manifest_json' => $releaseManifest,
                    'storage_path' => $storagePath,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $this->manifestCatalogService->upsertManifest([
                'content_pack_release_id' => (string) $release->getKey(),
                'manifest_hash' => $releaseManifestSha,
                'schema_version' => self::RELEASE_SCHEMA_VERSION,
                'storage_disk' => 'local',
                'storage_path' => $storagePath,
                'pack_id' => self::PACK_ID,
                'pack_version' => self::PACK_VERSION,
                'compiled_hash' => (string) data_get($summary, 'inputs.dry_run_artifact.actual_sha256'),
                'content_hash' => (string) data_get($summary, 'inputs.approved_snapshot.actual_sha256'),
                'payload_json' => $releaseManifest,
            ]);

            return $release;
        });

        $activationRows = Schema::hasTable('content_pack_activations')
            ? DB::table('content_pack_activations')
                ->where('pack_id', self::PACK_ID)
                ->where('pack_version', self::PACK_VERSION)
                ->where('release_id', $releaseId)
                ->count()
            : 0;

        return [
            'production_import_command_run' => true,
            'cms_write_performed' => true,
            'production_import_performed' => true,
            'runtime_change_performed' => false,
            'environment_change_performed' => false,
            'production_rollout_opened' => false,
            'production_rollout_performed' => false,
            'content_pack_release_id' => (string) $release->getKey(),
            'content_pack_release_action' => self::RELEASE_ACTION,
            'content_release_manifest_hash' => $releaseManifestSha,
            'storage_path' => $storagePath,
            'readback' => [
                'content_pack_release_exists' => ContentPackRelease::query()->whereKey($releaseId)->exists(),
                'content_release_manifest_exists' => $this->manifestCatalogService->findByManifestHash($releaseManifestSha) !== null,
                'activation_rows_created' => $activationRows,
                'runtime_rollout_rows_created' => 0,
            ],
            'rollback_boundary' => 'Delete or supersede content_pack_releases/content_release_manifests rows for this release_id/manifest_hash; runtime remains disabled separately.',
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    private function releaseManifest(array $summary): array
    {
        return [
            'schema_version' => self::RELEASE_SCHEMA_VERSION,
            'release_kind' => 'production_import',
            'release_id' => (string) ($summary['release_id'] ?? ''),
            'release_snapshot_id' => (string) ($summary['release_snapshot_id'] ?? ''),
            'pack_id' => self::PACK_ID,
            'pack_version' => self::PACK_VERSION,
            'runtime_use' => 'production_imported_not_rolled_out',
            'production_import_performed' => true,
            'ready_for_production_rollout' => false,
            'production_rollout_enabled' => false,
            'inputs' => $summary['inputs'] ?? [],
            'scope' => $summary['scope'] ?? [],
            'safety' => $summary['safety'] ?? [],
            'negative_guarantees' => $summary['negative_guarantees'] ?? [],
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function nonExecutionRecord(string $reason): array
    {
        return [
            'production_import_command_run' => false,
            'cms_write_performed' => false,
            'production_import_performed' => false,
            'runtime_change_performed' => false,
            'environment_change_performed' => false,
            'production_rollout_opened' => false,
            'production_rollout_performed' => false,
            'write_skipped_reason' => $reason,
        ];
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string,mixed>  $snapshot
     */
    private function assertSnapshot(array &$errors, array $snapshot, string $expectedSnapshotId, string $expectedSnapshotSha): void
    {
        $this->requireEquals($errors, 'snapshot.schema_version', 'fap.riasec.result_page_v2.production_approved_snapshot.v0.1', (string) ($snapshot['schema_version'] ?? ''));
        $this->requireEquals($errors, 'snapshot.snapshot_id', $expectedSnapshotId, (string) ($snapshot['snapshot_id'] ?? ''));
        $this->requireTrue($errors, 'snapshot.production_use_allowed', (bool) ($snapshot['production_use_allowed'] ?? false));
        $this->requireTrue($errors, 'snapshot.ready_for_production_import', (bool) ($snapshot['ready_for_production_import'] ?? false));
        $this->requireFalse($errors, 'snapshot.ready_for_production_rollout', (bool) ($snapshot['ready_for_production_rollout'] ?? true));
        $this->requireFalse($errors, 'snapshot.production_rollout_enabled', (bool) ($snapshot['production_rollout_enabled'] ?? true));
        $this->requireFalse($errors, 'snapshot.cms_write_performed', (bool) ($snapshot['cms_write_performed'] ?? true));
        $this->requireFalse($errors, 'snapshot.production_import_performed', (bool) ($snapshot['production_import_performed'] ?? true));
        $this->requireFalse($errors, 'snapshot.runtime_change_performed', (bool) ($snapshot['runtime_change_performed'] ?? true));
        $this->requireFalse($errors, 'snapshot.environment_change_performed', (bool) ($snapshot['environment_change_performed'] ?? true));
        $this->requireEquals($errors, 'snapshot.runtime_use', 'production_import_candidate', (string) ($snapshot['runtime_use'] ?? ''));
        $this->requireEquals($errors, 'snapshot.approval_summary.approval_type', 'production_import', (string) data_get($snapshot, 'approval_summary.approval_type', ''));
        $this->requireEquals($errors, 'snapshot.approval_summary.decision', 'GO', (string) data_get($snapshot, 'approval_summary.decision', ''));
        $this->requireEquals($errors, 'snapshot.source_snapshot_sha256', '4e5b7a3c356324bbd854ad2a3c8586caf07f0e05fee6bb26ab56af5c29f4b853', (string) ($snapshot['source_snapshot_sha256'] ?? ''));
        $this->requireEquals($errors, 'snapshot.expected_sha256_self_reference', $expectedSnapshotSha, hash_file('sha256', base_path(self::DEFAULT_APPROVED_SNAPSHOT_PATH)) ?: '');
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string,mixed>  $approval
     */
    private function assertApproval(array &$errors, array $approval, string $expectedApprovalId, string $expectedSnapshotId, string $expectedSnapshotSha): void
    {
        $this->requireEquals($errors, 'approval.schema_version', 'fap.riasec.result_page_v2.production_import_human_approval.v0.1', (string) ($approval['schema_version'] ?? ''));
        $this->requireEquals($errors, 'approval.approval_id', $expectedApprovalId, (string) ($approval['approval_id'] ?? ''));
        $this->requireEquals($errors, 'approval.approval_type', 'production_import', (string) ($approval['approval_type'] ?? ''));
        $this->requireEquals($errors, 'approval.decision', 'GO', (string) ($approval['decision'] ?? ''));
        $this->requireEquals($errors, 'approval.approved_release_snapshot_id', $expectedSnapshotId, (string) ($approval['approved_release_snapshot_id'] ?? ''));
        $this->requireEquals($errors, 'approval.approved_release_snapshot_sha256', $expectedSnapshotSha, (string) ($approval['approved_release_snapshot_sha256'] ?? ''));
        $this->requireTrue($errors, 'approval.rollback_kill_switch_confirmed', (bool) ($approval['rollback_kill_switch_confirmed'] ?? false));
        $this->requireTrue($errors, 'approval.private_payload_leak_ack', (bool) ($approval['private_payload_leak_ack'] ?? false));
        $this->requireTrue($errors, 'approval.staging_only_rejection_ack', (bool) ($approval['staging_only_rejection_ack'] ?? false));
        $this->requireTrue($errors, 'approval.rollout_remains_separately_gated_ack', (bool) ($approval['rollout_remains_separately_gated_ack'] ?? false));
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string,mixed>  $dryRun
     */
    private function assertDryRun(array &$errors, array $dryRun, string $expectedSnapshotId, string $expectedSnapshotSha): void
    {
        $this->requireEquals($errors, 'dry_run.decision', 'GO_FOR_IMPORT_EXECUTION_AUTHORIZATION_ONLY', (string) ($dryRun['decision'] ?? ''));
        $this->requireEquals($errors, 'dry_run.dry_run_status', 'pass_read_only', (string) ($dryRun['dry_run_status'] ?? ''));
        $this->requireEquals($errors, 'dry_run.approved_snapshot.snapshot_id', $expectedSnapshotId, (string) data_get($dryRun, 'approved_snapshot.snapshot_id', ''));
        $this->requireEquals($errors, 'dry_run.approved_snapshot.sha256', $expectedSnapshotSha, (string) data_get($dryRun, 'approved_snapshot.sha256', ''));
        $this->requireTrue($errors, 'dry_run.go_no_go.import_gate_dry_run_passed_for_authorized_snapshot', (bool) data_get($dryRun, 'go_no_go.import_gate_dry_run_passed_for_authorized_snapshot', false));
        $this->requireTrue($errors, 'dry_run.go_no_go.ready_for_separate_import_execution_authorization', (bool) data_get($dryRun, 'go_no_go.ready_for_separate_import_execution_authorization', false));
        $this->requireFalse($errors, 'dry_run.go_no_go.production_rollout_allowed_now', (bool) data_get($dryRun, 'go_no_go.production_rollout_allowed_now', true));
        $this->requireFalse($errors, 'dry_run.explicit_non_actions.production_import_performed', (bool) data_get($dryRun, 'explicit_non_actions.production_import_performed', true));
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,mixed>  $approval
     */
    private function assertScope(array &$errors, array $options, array $snapshot, array $approval): void
    {
        $providedScope = $this->normalizedOptionScope($options);
        foreach (self::REQUIRED_SCOPE as $key => $requiredValues) {
            $this->requireEquals($errors, 'scope.option.'.$key, implode(',', $requiredValues), implode(',', $providedScope[$key] ?? []));
            $this->requireEquals($errors, 'scope.snapshot.'.$key, implode(',', $requiredValues), implode(',', (array) data_get($snapshot, 'approved_scope.'.$key, [])));
            $this->requireEquals($errors, 'scope.approval.'.$key, implode(',', $requiredValues), implode(',', (array) data_get($approval, 'scope.'.$key, [])));
        }
        $this->requireEquals($errors, 'scope.snapshot.percentage', '0', (string) data_get($snapshot, 'approved_scope.percentage', ''));
        $this->requireEquals($errors, 'scope.snapshot.max_percentage', '0', (string) data_get($snapshot, 'approved_scope.max_percentage', ''));
        $this->requireEquals($errors, 'scope.approval.percentage', '0', (string) data_get($approval, 'scope.percentage', ''));
        $this->requireEquals($errors, 'scope.approval.max_percentage', '0', (string) data_get($approval, 'scope.max_percentage', ''));
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,mixed>  $approval
     * @param  array<string,mixed>  $dryRun
     */
    private function assertSafety(array &$errors, array $options, array $snapshot, array $approval, array $dryRun): void
    {
        $this->requireTrue($errors, 'option.rollback_kill_switch_confirmed', (bool) ($options['rollback_kill_switch_confirmed'] ?? false));
        $this->requireEquals($errors, 'option.kill_switch_ref', 'riasec_result_page_v2.production_emergency_disabled', trim((string) ($options['kill_switch_ref'] ?? '')));
        $this->requireEquals($errors, 'option.post_deploy_smoke_procedure_id', 'riasec_result_page_v2_post_deploy_smoke_v0_1', trim((string) ($options['post_deploy_smoke_procedure_id'] ?? '')));

        foreach ([
            'snapshot' => $snapshot,
            'approval' => $approval,
            'dry_run' => $dryRun,
        ] as $label => $payload) {
            $this->requireFalse($errors, $label.'.production_rollout_enabled', (bool) data_get($payload, 'production_rollout_enabled', data_get($payload, 'approved_snapshot.production_rollout_enabled', false)));
            $this->requireFalse($errors, $label.'.runtime_change_performed', (bool) data_get($payload, 'runtime_change_performed', data_get($payload, 'explicit_non_actions.runtime_change_performed', false)));
            $this->requireFalse($errors, $label.'.environment_change_performed', (bool) data_get($payload, 'environment_change_performed', data_get($payload, 'explicit_non_actions.environment_change_performed', false)));
        }
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string,string>  $paths
     */
    private function assertNoForbiddenKeys(array &$errors, array $paths): void
    {
        foreach ($paths as $label => $path) {
            $raw = (string) File::get($path);
            foreach (self::FORBIDDEN_PUBLIC_PAYLOAD_KEYS as $needle) {
                if (str_contains($raw, $needle)) {
                    $errors[] = $label.'_forbidden_key_detected:'.$needle;
                }
            }
        }
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,list<string>>
     */
    private function normalizedOptionScope(array $options): array
    {
        return [
            'tenant_ids' => $this->csvList((string) ($options['tenant_ids'] ?? '')),
            'form_codes' => $this->csvList((string) ($options['form_codes'] ?? '')),
            'locales' => $this->csvList((string) ($options['locales'] ?? '')),
            'allowlist' => $this->csvList((string) ($options['allowlist'] ?? '')),
        ];
    }

    /**
     * @return list<string>
     */
    private function csvList(string $value): array
    {
        return array_values(array_filter(
            array_map(static fn (string $part): string => trim($part), explode(',', $value)),
            static fn (string $part): bool => $part !== ''
        ));
    }

    /**
     * @param  list<string>  $errors
     */
    private function requireEquals(array &$errors, string $field, string $expected, string $actual): void
    {
        if ($expected === '' || ! hash_equals($expected, $actual)) {
            $errors[] = $field.'_mismatch';
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function requireTrue(array &$errors, string $field, bool $actual): void
    {
        if (! $actual) {
            $errors[] = $field.'_must_be_true';
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function requireFalse(array &$errors, string $field, bool $actual): void
    {
        if ($actual) {
            $errors[] = $field.'_must_be_false';
        }
    }

    private function normalizeSha256(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : '';
    }

    private function deterministicReleaseUuid(string $snapshotId, string $snapshotSha, string $dryRunSha): string
    {
        $hex = substr(hash('sha256', $snapshotId.'|'.$snapshotSha.'|'.$dryRunSha), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    private function absolutePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('Path is required.');
        }
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }
        if (str_starts_with($path, 'backend/')) {
            $path = substr($path, strlen('backend/'));
        }

        return base_path($path);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonFile(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('JSON file does not exist: '.$path);
        }
        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid JSON file: '.$path);
        }

        return $decoded;
    }

    private function resetDirectory(string $path): void
    {
        File::deleteDirectory($path);
        File::ensureDirectoryExists($path);
    }

    /**
     * @param  array<string,mixed>|list<mixed>  $value
     */
    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function writeSummaryIfRequested(string $outputDir, array $summary): void
    {
        if ($outputDir === '') {
            return;
        }
        File::ensureDirectoryExists($outputDir);
        File::put($outputDir.DIRECTORY_SEPARATOR.'riasec_production_import_summary.json', $this->encodeJson($summary));
    }

    private function relativeToBasePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
