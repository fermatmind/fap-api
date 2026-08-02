<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Production;

use App\Models\ContentPackRelease;
use App\Services\Storage\ContentReleaseManifestCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** @review-surface big_five_v2_editorial_revision */
final class BigFiveResultPageV2ProductionImportExecutor
{
    public const SNAPSHOT_ID = 'big5_result_page_v2_v0_4';

    public const APPROVAL_ID = 'big5_result_page_v2_production_import_approval_v0_4';

    public const DEFAULT_SNAPSHOT_PATH = 'content_assets/big5/result_page_v2/releases/v0_4/big5_v2_release_snapshot_v0_4.json';

    public const DEFAULT_APPROVAL_PATH = 'content_assets/big5/result_page_v2/releases/v0_4/production_import_approval_v0_4.json';

    public const DEFAULT_CANDIDATE_PATH = 'content_assets/big5/result_page_v2/releases/v0_4/candidate_payload_set_v0_4.json';

    public const SNAPSHOT_SHA256 = 'd4e9f457f5514e01681e27723d99ede228d58e49a3abac446947671d064950a1';

    public const APPROVAL_SHA256 = 'fb8cbacb798ec75c77de37be77ca33ecf1869a0c62cc012aa2dd17f35d400557';

    public const CANDIDATE_SHA256 = 'c009b8878e1c7a4b76d63fe4cc2cf557ec576008d51669d3ed3bbe8bca658afa';

    public const RELEASE_ACTION = 'bigfive_result_page_v2_production_import';

    public const RELEASE_SCHEMA = 'fap.big5.result_page_v2.production_import_release.v0.4';

    private const PACK_ID = 'BIG5_OCEAN';

    private const PACK_VERSION = 'result_page_v2_v0_4';

    private const STORAGE_ROOT = 'private/content_releases/BIG5_OCEAN/result_page_v2/v0_4/production_import';

    private const REQUIRED_SCOPE = [
        'org_ids' => ['0'],
        'form_codes' => ['big5_90', 'big5_120'],
        'locales' => ['zh-CN'],
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
        $summary = $this->validate($options);

        if ($summary['errors'] !== []) {
            $summary['decision'] = 'fail';
            $summary['mode'] = $execute ? 'production_import_execute' : 'production_import_dry_run';
            $summary['execution'] = $this->noWrite('validation_failed');

            return $summary;
        }

        if (! $execute) {
            $summary['decision'] = 'pass';
            $summary['mode'] = 'production_import_dry_run';
            $summary['execution'] = $this->noWrite('dry_run_default_no_write');

            return $summary;
        }

        if (! hash_equals((string) $summary['expected_confirm_execute'], trim((string) ($options['confirm_execute'] ?? '')))) {
            $summary['decision'] = 'fail';
            $summary['mode'] = 'production_import_execute';
            $summary['errors'][] = 'confirm_execute_token_mismatch';
            $summary['execution'] = $this->noWrite('confirm_execute_token_mismatch');

            return $summary;
        }

        $summary['decision'] = 'pass';
        $summary['mode'] = 'production_import_execute';
        $summary['execution'] = $this->executeAudit($summary);

        return $summary;
    }

    public static function expectedConfirmExecuteToken(
        string $snapshotId,
        string $snapshotSha256,
        string $approvalId,
        string $approvalSha256,
    ): string {
        $binding = hash('sha256', implode('|', [$snapshotId, $snapshotSha256, $approvalId, $approvalSha256]));

        return 'BIG5_RESULT_PAGE_V2_PRODUCTION_IMPORT_EXECUTE:'.$binding.':NO_ROLLOUT';
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function validate(array $options): array
    {
        $snapshotPath = $this->absolutePath((string) ($options['snapshot_path'] ?? self::DEFAULT_SNAPSHOT_PATH));
        $approvalPath = $this->absolutePath((string) ($options['approval_path'] ?? self::DEFAULT_APPROVAL_PATH));
        [$snapshot, $snapshotBytes] = $this->readJson($snapshotPath);
        [$approval, $approvalBytes] = $this->readJson($approvalPath);
        $snapshotSha = hash('sha256', $snapshotBytes);
        $approvalSha = hash('sha256', $approvalBytes);
        $expectedSnapshotId = trim((string) ($options['snapshot_id'] ?? ''));
        $expectedSnapshotSha = strtolower(trim((string) ($options['snapshot_sha256'] ?? '')));
        $expectedApprovalId = trim((string) ($options['approval_id'] ?? ''));
        $expectedApprovalSha = strtolower(trim((string) ($options['approval_sha256'] ?? '')));
        $scope = [
            'org_ids' => $this->csv((string) ($options['org_ids'] ?? '')),
            'form_codes' => $this->csv((string) ($options['form_codes'] ?? '')),
            'locales' => $this->csv((string) ($options['locales'] ?? '')),
        ];
        $errors = [];

        $this->same($errors, 'snapshot_id', self::SNAPSHOT_ID, $expectedSnapshotId);
        $this->same($errors, 'snapshot_sha256', self::SNAPSHOT_SHA256, $expectedSnapshotSha);
        $this->same($errors, 'snapshot_file_sha256', self::SNAPSHOT_SHA256, $snapshotSha);
        $this->same($errors, 'approval_id', self::APPROVAL_ID, $expectedApprovalId);
        $this->same($errors, 'approval_sha256', self::APPROVAL_SHA256, $expectedApprovalSha);
        $this->same($errors, 'approval_file_sha256', self::APPROVAL_SHA256, $approvalSha);
        $this->same($errors, 'snapshot_schema', 'fap.big5.result_page_v2.release_snapshot.v0.4', (string) ($snapshot['schema_version'] ?? ''));
        $this->same($errors, 'snapshot_document_id', self::SNAPSHOT_ID, (string) ($snapshot['snapshot_id'] ?? ''));
        $this->same($errors, 'snapshot_runtime_use', 'production_import_candidate', (string) ($snapshot['runtime_use'] ?? ''));
        $this->flag($errors, 'snapshot_immutable', $snapshot['immutable'] ?? null, true);
        $this->flag($errors, 'snapshot_production_use_allowed', $snapshot['production_use_allowed'] ?? null, true);
        $this->flag($errors, 'snapshot_ready_for_import', $snapshot['ready_for_production_import'] ?? null, true);
        $this->flag($errors, 'snapshot_ready_for_rollout', $snapshot['ready_for_production_rollout'] ?? null, false);
        $this->flag($errors, 'snapshot_import_performed', $snapshot['production_import_performed'] ?? null, false);
        $this->flag($errors, 'snapshot_rollout_enabled', $snapshot['production_rollout_enabled'] ?? null, false);
        $this->same($errors, 'approval_schema', 'fap.big5.result_page_v2.production_import_approval.v0.4', (string) ($approval['schema_version'] ?? ''));
        $this->same($errors, 'approval_document_id', self::APPROVAL_ID, (string) ($approval['approval_id'] ?? ''));
        $this->same($errors, 'approval_decision', 'GO', (string) ($approval['decision'] ?? ''));
        $this->same($errors, 'approval_scope', 'production_import_only', (string) ($approval['approval_scope'] ?? ''));
        $this->same($errors, 'approval_snapshot_id', self::SNAPSHOT_ID, (string) data_get($approval, 'snapshot.snapshot_id'));
        $this->same($errors, 'approval_snapshot_sha256', self::SNAPSHOT_SHA256, (string) data_get($approval, 'snapshot.sha256'));

        foreach (self::REQUIRED_SCOPE as $key => $expected) {
            if ($scope[$key] !== $expected
                || array_values((array) data_get($snapshot, 'scope.'.$key, [])) !== $expected
                || array_values((array) data_get($approval, 'scope.'.$key, [])) !== $expected) {
                $errors[] = 'scope_'.$key.'_mismatch';
            }
        }

        $this->same($errors, 'kill_switch_ref', 'big5_result_page_v2.production_emergency_disabled', trim((string) ($options['kill_switch_ref'] ?? '')));
        $this->same($errors, 'smoke_procedure_id', 'big5_result_page_v2_post_deploy_smoke_v0_4', trim((string) ($options['post_deploy_smoke_procedure_id'] ?? '')));
        if (! (bool) ($options['rollback_kill_switch_confirmed'] ?? false)) {
            $errors[] = 'rollback_kill_switch_not_confirmed';
        }

        $this->verifyBindings($errors, $snapshot);
        $this->verifyCandidate($errors, $snapshot);
        $this->flag($errors, 'approval_import_execution_authorized', data_get($approval, 'authorization.production_import_execution_authorized'), false);
        $this->flag($errors, 'approval_rollout_authorized', data_get($approval, 'authorization.production_rollout_authorized'), false);

        return [
            'schema_version' => self::RELEASE_SCHEMA,
            'task_id' => 'BIG5-RESULT-V2-PRODUCTION-SNAPSHOT-04',
            'decision' => 'pending',
            'mode' => 'production_import_dry_run',
            'release_id' => $this->releaseId($expectedSnapshotSha, $expectedApprovalSha),
            'release_snapshot_id' => $expectedSnapshotId,
            'expected_confirm_execute' => self::expectedConfirmExecuteToken($expectedSnapshotId, $expectedSnapshotSha, $expectedApprovalId, $expectedApprovalSha),
            'inputs' => [
                'snapshot' => ['path' => $this->relativePath($snapshotPath), 'sha256' => $snapshotSha],
                'approval' => ['path' => $this->relativePath($approvalPath), 'sha256' => $approvalSha],
            ],
            'scope' => $scope,
            'safety' => [
                'rollback_kill_switch_confirmed' => (bool) ($options['rollback_kill_switch_confirmed'] ?? false),
                'kill_switch_ref' => trim((string) ($options['kill_switch_ref'] ?? '')),
                'post_deploy_smoke_procedure_id' => trim((string) ($options['post_deploy_smoke_procedure_id'] ?? '')),
                'rollout_separation_preserved' => true,
            ],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /** @param list<string> $errors */
    private function verifyBindings(array &$errors, array $snapshot): void
    {
        $bindings = (array) ($snapshot['asset_bindings'] ?? []);
        if (count($bindings) !== 7) {
            $errors[] = 'asset_binding_count_mismatch';
        }

        foreach ($bindings as $binding) {
            $path = $this->absolutePath((string) ($binding['path'] ?? ''));
            $expected = strtolower((string) ($binding['sha256'] ?? ''));
            if (! is_file($path) || hash_file('sha256', $path) !== $expected) {
                $errors[] = 'asset_binding_hash_mismatch:'.(string) ($binding['kind'] ?? 'unknown');
            }
        }

        $rollbackPath = $this->absolutePath((string) data_get($snapshot, 'rollback.path', ''));
        if (! is_file($rollbackPath) || hash_file('sha256', $rollbackPath) !== (string) data_get($snapshot, 'rollback.sha256', '')) {
            $errors[] = 'rollback_snapshot_hash_mismatch';
        }
    }

    /** @param list<string> $errors */
    private function verifyCandidate(array &$errors, array $snapshot): void
    {
        $path = $this->absolutePath((string) data_get($snapshot, 'candidate_payload_evidence.path', ''));
        if (! is_file($path) || hash_file('sha256', $path) !== self::CANDIDATE_SHA256) {
            $errors[] = 'candidate_payload_evidence_hash_mismatch';

            return;
        }

        [$candidate] = $this->readJson($path);
        $this->same($errors, 'candidate_payload_count', '325', (string) ($candidate['payload_count'] ?? ''));
        $this->same($errors, 'candidate_payload_set_sha256', 'ad573527ff3495fe31402256dd4310d5f02421391b7c299b3fa16ab703c51a4b', (string) ($candidate['payload_set_sha256'] ?? ''));
        foreach (['source_mapping_failure_count', 'missing_count', 'fallback_count', 'blocked_count', 'duplicate_selection_count', 'metadata_leak_count', 'forbidden_claim_count'] as $key) {
            if ((int) data_get($candidate, 'verification.'.$key, -1) !== 0) {
                $errors[] = 'candidate_'.$key.'_nonzero';
            }
        }
    }

    /** @param array<string,mixed> $summary */
    private function executeAudit(array $summary): array
    {
        $releaseId = (string) $summary['release_id'];
        $manifest = [
            'schema_version' => self::RELEASE_SCHEMA,
            'release_kind' => 'production_import_audit',
            'release_id' => $releaseId,
            'snapshot_id' => self::SNAPSHOT_ID,
            'pack_id' => self::PACK_ID,
            'pack_version' => self::PACK_VERSION,
            'runtime_use' => 'production_imported_not_rolled_out',
            'ready_for_production_rollout' => false,
            'production_rollout_enabled' => false,
            'inputs' => $summary['inputs'],
            'scope' => $summary['scope'],
            'safety' => $summary['safety'],
        ];
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $manifestSha = hash('sha256', $manifestJson);
        $storagePath = self::STORAGE_ROOT.'/'.$releaseId;
        $storageRoot = storage_path('app/'.$storagePath);
        File::ensureDirectoryExists($storageRoot);
        File::put($storageRoot.'/manifest.json', $manifestJson);

        $release = DB::transaction(function () use ($releaseId, $manifest, $manifestSha, $storagePath): ContentPackRelease {
            $release = ContentPackRelease::query()->updateOrCreate(['id' => $releaseId], [
                'action' => self::RELEASE_ACTION,
                'region' => 'GLOBAL',
                'locale' => 'zh-CN',
                'dir_alias' => self::PACK_VERSION,
                'to_pack_id' => self::PACK_ID,
                'status' => 'success',
                'message' => 'Big Five v0_4 production import audit recorded without rollout',
                'created_by' => 'ops',
                'manifest_hash' => $manifestSha,
                'compiled_hash' => self::CANDIDATE_SHA256,
                'content_hash' => self::SNAPSHOT_SHA256,
                'pack_version' => self::PACK_VERSION,
                'manifest_json' => $manifest,
                'storage_path' => $storagePath,
            ]);
            $this->manifestCatalogService->upsertManifest([
                'content_pack_release_id' => (string) $release->getKey(),
                'manifest_hash' => $manifestSha,
                'schema_version' => self::RELEASE_SCHEMA,
                'storage_disk' => 'local',
                'storage_path' => $storagePath,
                'pack_id' => self::PACK_ID,
                'pack_version' => self::PACK_VERSION,
                'compiled_hash' => self::CANDIDATE_SHA256,
                'content_hash' => self::SNAPSHOT_SHA256,
                'payload_json' => $manifest,
            ]);

            return $release;
        });

        $activationRows = Schema::hasTable('content_pack_activations')
            ? DB::table('content_pack_activations')->where('release_id', $releaseId)->count()
            : 0;

        return [
            'production_import_command_run' => true,
            'release_audit_written' => true,
            'production_import_performed' => true,
            'runtime_change_performed' => false,
            'environment_change_performed' => false,
            'production_rollout_performed' => false,
            'content_pack_release_id' => (string) $release->getKey(),
            'content_release_manifest_hash' => $manifestSha,
            'activation_rows_created' => $activationRows,
        ];
    }

    /** @return array<string,mixed> */
    private function noWrite(string $reason): array
    {
        return [
            'production_import_command_run' => false,
            'release_audit_written' => false,
            'production_import_performed' => false,
            'runtime_change_performed' => false,
            'environment_change_performed' => false,
            'production_rollout_performed' => false,
            'write_skipped_reason' => $reason,
        ];
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Required JSON file is missing: '.$path);
        }
        $bytes = (string) file_get_contents($path);
        $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Required JSON file is not an object: '.$path);
        }

        return [$decoded, $bytes];
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    private function relativePath(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR);
    }

    /** @return list<string> */
    private function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
    }

    /** @param list<string> $errors */
    private function same(array &$errors, string $key, string $expected, string $actual): void
    {
        if ($expected !== $actual) {
            $errors[] = $key.'_mismatch';
        }
    }

    /** @param list<string> $errors */
    private function flag(array &$errors, string $key, mixed $actual, bool $expected): void
    {
        if ($actual !== $expected) {
            $errors[] = $key.'_mismatch';
        }
    }

    private function releaseId(string $snapshotSha, string $approvalSha): string
    {
        $hex = substr(hash('sha256', self::SNAPSHOT_ID.'|'.$snapshotSha.'|'.$approvalSha), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
