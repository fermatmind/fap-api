<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets\Agent;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class EnneagramResultPageProductionManualGateRunbook
{
    public const SCHEMA_VERSION = 'fap.enneagram.result_page.production_manual_gate.v0.1';

    public const DEFAULT_ARTIFACT_RELATIVE_DIR = 'artifacts/enneagram_result_page_production_manual_gate';

    public const DEFAULT_CONTRACT_RELATIVE_PATH = 'content_assets/enneagram/result_page/production_manual_gate/production_manual_gate_contract_v0_1.json';

    public const EXPECTED_RELEASE_ID = 'enneagram_1r_a_to_1r_h_phase8b_candidate_20260427_a9fd3eb4';

    public const EXPECTED_CANDIDATE_MANIFEST_SHA256 = 'a9fd3eb474ea2ca0130d06ad2b1640305d9160ee1a74e559ad4f60bfc4db56c0';

    public const EXPECTED_RUNTIME_REGISTRY_SHA256 = 'ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f';

    public function __construct(
        private readonly EnneagramResultPagePendingProductionGateStore $pendingGateStore,
    ) {}

    /**
     * @param  array{
     *     run_id?:string,
     *     artifact_dir?:string,
     *     contract_path?:string,
     *     evidence_dir?:string,
     *     write_pending_gate?:bool,
     *     pending_gate_ttl_minutes?:int,
     *     release_id?:string,
     *     confirm_release_id?:string,
     *     candidate_manifest_sha256?:string,
     *     runtime_registry_sha256?:string,
     *     rollback_window?:string,
     *     post_activation_smoke_acknowledged?:bool,
     *     strict?:bool
     * }  $options
     * @return array<string,mixed>
     */
    public function run(array $options = []): array
    {
        $runId = $this->sanitizeSlug((string) ($options['run_id'] ?? 'production-manual-gate'));
        $artifactDir = $this->artifactDir((string) ($options['artifact_dir'] ?? ''), $runId);
        $contractPath = $this->contractPath((string) ($options['contract_path'] ?? ''));
        $evidenceDir = trim((string) ($options['evidence_dir'] ?? ''));
        $writePendingGate = ($options['write_pending_gate'] ?? false) === true;
        $pendingGateTtlMinutes = (int) ($options['pending_gate_ttl_minutes'] ?? EnneagramResultPagePendingProductionGateStore::DEFAULT_TTL_MINUTES);
        $releaseId = trim((string) ($options['release_id'] ?? ''));
        $confirmReleaseId = trim((string) ($options['confirm_release_id'] ?? ''));
        $candidateManifestSha256 = trim((string) ($options['candidate_manifest_sha256'] ?? ''));
        $runtimeRegistrySha256 = trim((string) ($options['runtime_registry_sha256'] ?? ''));
        $rollbackWindow = trim((string) ($options['rollback_window'] ?? ''));
        $postActivationSmokeAcknowledged = ($options['post_activation_smoke_acknowledged'] ?? false) === true;
        $strict = ($options['strict'] ?? false) === true;

        $this->ensureDirectory($artifactDir);

        $contract = $this->readJson($contractPath, 'Production manual gate contract');
        $contractErrors = $this->contractErrors($contract);
        $approvalPacket = $this->approvalPacket(
            $releaseId,
            $confirmReleaseId,
            $candidateManifestSha256,
            $runtimeRegistrySha256,
            $rollbackWindow,
            $postActivationSmokeAcknowledged,
        );
        $rollbackWindowPlan = $this->rollbackWindowPlan($releaseId, $rollbackWindow, $artifactDir);
        $postActivationSmokePlan = $this->postActivationSmokePlan($releaseId, $artifactDir);

        $errors = array_values(array_unique(array_merge(
            $contractErrors,
            $strict ? (array) ($approvalPacket['errors'] ?? []) : [],
        )));
        $pendingGate = [
            'requested' => $writePendingGate,
            'written' => false,
            'approval_phrase' => EnneagramResultPagePendingProductionGateStore::APPROVAL_PHRASE,
            'ttl_minutes' => $pendingGateTtlMinutes,
        ];
        if ($writePendingGate) {
            try {
                $pendingGate = array_merge($pendingGate, $this->pendingGateStore->write(
                    $approvalPacket,
                    $runId,
                    $evidenceDir,
                    $pendingGateTtlMinutes,
                ));
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();
                $pendingGate['error'] = $exception->getMessage();
            }
        }

        $report = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'production_manual_gate_runbook',
            'run_id' => $runId,
            'contract' => [
                'relative_path' => $this->relativePath($contractPath),
                'sha256' => hash_file('sha256', $contractPath) ?: '',
                'valid' => $contractErrors === [],
                'errors' => $contractErrors,
            ],
            'manual_approval_packet' => $approvalPacket,
            'pending_production_activation_gate' => $pendingGate,
            'rollback_window' => $rollbackWindowPlan,
            'post_activation_smoke_plan' => $postActivationSmokePlan,
            'negative_guarantees' => $this->negativeGuarantees(),
            'error_count' => count($errors),
            'errors' => $errors,
        ];

        $artifacts = [
            'production_manual_gate_report.json' => $this->writeJson($artifactDir.'/production_manual_gate_report.json', $report),
            'manual_approval_packet.json' => $this->writeJson($artifactDir.'/manual_approval_packet.json', $approvalPacket),
            'post_activation_smoke_plan.json' => $this->writeJson($artifactDir.'/post_activation_smoke_plan.json', $postActivationSmokePlan),
            'rollback_window.md' => $this->writeText($artifactDir.'/rollback_window.md', $this->rollbackWindowMarkdown($rollbackWindowPlan)),
        ];

        $ok = $errors === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $ok,
            'status' => $ok ? 'success' : 'blocked',
            'run_id' => $runId,
            'artifact_dir' => $this->redactPath($artifactDir),
            'artifacts' => $artifacts,
            'strict' => $strict,
            'summary' => [
                'manual_approval_packet_valid' => (bool) ($approvalPacket['valid'] ?? false),
                'release_id' => $releaseId === '' ? self::EXPECTED_RELEASE_ID : $releaseId,
                'production_execution_allowed_for_agent' => false,
                'production_manual_gate_required' => true,
                'post_activation_smoke_plan_ready' => true,
                'rollback_window_recorded' => $rollbackWindow !== '',
                'pending_gate_written' => (bool) ($pendingGate['written'] ?? false),
                'pending_gate_expires_at' => $pendingGate['expires_at'] ?? null,
                'approval_phrase_required' => EnneagramResultPagePendingProductionGateStore::APPROVAL_PHRASE,
            ],
            'errors' => $errors,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function approvalPacket(
        string $releaseId,
        string $confirmReleaseId,
        string $candidateManifestSha256,
        string $runtimeRegistrySha256,
        string $rollbackWindow,
        bool $postActivationSmokeAcknowledged,
    ): array {
        $errors = [];
        if ($releaseId !== self::EXPECTED_RELEASE_ID) {
            $errors[] = 'release_id_mismatch';
        }
        if ($confirmReleaseId !== $releaseId || $confirmReleaseId !== self::EXPECTED_RELEASE_ID) {
            $errors[] = 'confirm_release_id_mismatch';
        }
        if ($candidateManifestSha256 !== self::EXPECTED_CANDIDATE_MANIFEST_SHA256) {
            $errors[] = 'candidate_manifest_sha256_mismatch';
        }
        if ($runtimeRegistrySha256 !== self::EXPECTED_RUNTIME_REGISTRY_SHA256) {
            $errors[] = 'runtime_registry_sha256_mismatch';
        }
        if ($rollbackWindow === '') {
            $errors[] = 'rollback_window_missing';
        }
        if (! $postActivationSmokeAcknowledged) {
            $errors[] = 'post_activation_smoke_acknowledgement_missing';
        }

        return [
            'valid' => $errors === [],
            'release_id' => $releaseId,
            'confirm_release_id' => $confirmReleaseId,
            'expected_release_id' => self::EXPECTED_RELEASE_ID,
            'candidate_manifest_sha256' => $candidateManifestSha256,
            'expected_candidate_manifest_sha256' => self::EXPECTED_CANDIDATE_MANIFEST_SHA256,
            'runtime_registry_sha256' => $runtimeRegistrySha256,
            'expected_runtime_registry_sha256' => self::EXPECTED_RUNTIME_REGISTRY_SHA256,
            'rollback_window' => $rollbackWindow,
            'post_activation_smoke_acknowledged' => $postActivationSmokeAcknowledged,
            'production_execution_allowed_for_agent' => false,
            'manual_human_approval_required' => true,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function rollbackWindowPlan(string $releaseId, string $rollbackWindow, string $artifactDir): array
    {
        return [
            'release_id' => $releaseId === '' ? self::EXPECTED_RELEASE_ID : $releaseId,
            'rollback_window' => $rollbackWindow === '' ? '<required-window>' : $rollbackWindow,
            'rollback_command_template' => 'php artisan enneagram:rollback-inactive-candidate-release --release-id=<exact-release-id> --confirm-release-id=<same-exact-release-id> --output-dir=<output-dir> --json',
            'output_dir' => $this->redactPath($artifactDir.'/rollback'),
            'agent_execution_allowed' => false,
            'production_rollback_allowed_for_agent' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function postActivationSmokePlan(string $releaseId, string $artifactDir): array
    {
        return [
            'release_id' => $releaseId === '' ? self::EXPECTED_RELEASE_ID : $releaseId,
            'required_smoke_steps' => [
                'verify_active_release_id_matches_exact_manual_approval',
                'run_api_result_page_smoke',
                'run_web_rendered_result_page_smoke',
                'verify_rollback_target_snapshot_exists',
            ],
            'artifact_dir' => $this->redactPath($artifactDir.'/post_activation_smoke'),
            'agent_execution_allowed_after_human_activation' => true,
            'agent_production_activation_allowed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $rollbackWindowPlan
     */
    private function rollbackWindowMarkdown(array $rollbackWindowPlan): string
    {
        return implode(PHP_EOL, [
            '# Enneagram Production Rollback Window',
            '',
            '- release_id: '.(string) ($rollbackWindowPlan['release_id'] ?? ''),
            '- rollback_window: '.(string) ($rollbackWindowPlan['rollback_window'] ?? ''),
            '- agent_execution_allowed: false',
            '- production_rollback_allowed_for_agent: false',
            '',
        ]);
    }

    /**
     * @return list<string>
     */
    private function contractErrors(array $contract): array
    {
        $errors = [];
        if (($contract['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $errors[] = 'schema_version_mismatch';
        }
        if (($contract['manual_approval_required'] ?? null) !== true) {
            $errors[] = 'manual_approval_required_must_be_true';
        }
        if (($contract['production_use_allowed_for_agent'] ?? null) !== false) {
            $errors[] = 'production_use_allowed_for_agent_must_be_false';
        }
        if (($contract['expected_release_id'] ?? null) !== self::EXPECTED_RELEASE_ID) {
            $errors[] = 'expected_release_id_mismatch';
        }
        if (($contract['expected_candidate_manifest_sha256'] ?? null) !== self::EXPECTED_CANDIDATE_MANIFEST_SHA256) {
            $errors[] = 'expected_candidate_manifest_sha256_mismatch';
        }
        if (($contract['expected_runtime_registry_sha256'] ?? null) !== self::EXPECTED_RUNTIME_REGISTRY_SHA256) {
            $errors[] = 'expected_runtime_registry_sha256_mismatch';
        }
        foreach ($this->negativeGuarantees() as $key => $expected) {
            if (data_get($contract, 'negative_guarantees.'.$key) !== $expected) {
                $errors[] = 'negative_guarantee_mismatch:'.$key;
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @return array<string,bool>
     */
    private function negativeGuarantees(): array
    {
        return [
            'agent_production_activation_allowed' => false,
            'agent_production_rollback_allowed' => false,
            'bulk_content_generation_happened' => false,
            'production_import_happened' => false,
            'production_activation_happened' => false,
            'production_rollback_happened' => false,
            'runtime_switch_happened' => false,
            'production_write_happened' => false,
            'frontend_change_happened' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path, string $label): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException($label.' is not valid JSON: '.$path);
        }

        return $decoded;
    }

    private function contractPath(string $path): string
    {
        return $path !== '' ? $path : base_path(self::DEFAULT_CONTRACT_RELATIVE_PATH);
    }

    private function artifactDir(string $root, string $runId): string
    {
        $artifactRoot = $root !== '' ? rtrim($root, DIRECTORY_SEPARATOR) : base_path(self::DEFAULT_ARTIFACT_RELATIVE_DIR);

        return $artifactRoot.DIRECTORY_SEPARATOR.$runId;
    }

    private function sanitizeSlug(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($value)) ?: '';

        return trim($sanitized, '-') ?: 'production-manual-gate';
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            File::makeDirectory($path, 0777, true);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,string>
     */
    private function writeJson(string $path, array $payload): array
    {
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return [
            'relative_path' => $this->relativePath($path),
            'sha256' => hash_file('sha256', $path) ?: '',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function writeText(string $path, string $payload): array
    {
        file_put_contents($path, $payload);

        return [
            'relative_path' => $this->relativePath($path),
            'sha256' => hash_file('sha256', $path) ?: '',
        ];
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }

        return $this->redactPath($path);
    }

    private function redactPath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR);
        if (str_starts_with($path, $base)) {
            return 'backend'.substr($path, strlen($base));
        }

        return basename($path);
    }
}
