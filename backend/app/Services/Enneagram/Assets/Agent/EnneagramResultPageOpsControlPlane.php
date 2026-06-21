<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets\Agent;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class EnneagramResultPageOpsControlPlane
{
    public const SCHEMA_VERSION = 'fap.enneagram.result_page.ops_agent_control_plane.v0.1';

    public const DEFAULT_ARTIFACT_RELATIVE_DIR = 'artifacts/enneagram_result_page_ops_control_plane';

    public const DEFAULT_CONTRACT_RELATIVE_PATH = 'content_assets/enneagram/result_page/ops_agent_control_plane/control_plane_v0_1.json';

    public const EXACT_RELEASE_ID = 'enneagram_1r_a_to_1r_h_phase8b_candidate_20260427_a9fd3eb4';

    public const EXACT_CANDIDATE_MANIFEST_SHA256 = 'a9fd3eb474ea2ca0130d06ad2b1640305d9160ee1a74e559ad4f60bfc4db56c0';

    public const EXACT_RUNTIME_REGISTRY_SHA256 = 'ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f';

    private const ALLOWED_MODES = [
        'auto-to-pr',
        'auto-to-staging',
        'auto-to-report',
        'production-manual-gate',
    ];

    private const AUTO_MODES = [
        'auto-to-pr',
        'auto-to-staging',
        'auto-to-report',
    ];

    private const REQUIRED_APPROVAL_FIELDS = [
        'release_id',
        'confirm_release_id',
        'candidate_manifest_sha256',
        'runtime_registry_sha256',
        'rollback_window',
        'post_activation_smoke_plan',
    ];

    /**
     * @param  array{
     *     run_id?:string,
     *     artifact_dir?:string,
     *     contract_path?:string,
     *     mode?:string,
     *     strict?:bool,
     *     simulate_production_rollout?:bool,
     *     approval?:array<string,mixed>
     * }  $options
     * @return array<string,mixed>
     */
    public function audit(array $options = []): array
    {
        $runId = $this->sanitizeRunId((string) ($options['run_id'] ?? ''));
        $artifactDir = $this->artifactDir((string) ($options['artifact_dir'] ?? ''), $runId);
        $contractPath = $this->contractPath((string) ($options['contract_path'] ?? ''));
        $mode = trim((string) ($options['mode'] ?? 'auto-to-report'));
        $strict = ($options['strict'] ?? false) === true;
        $simulateProductionRollout = ($options['simulate_production_rollout'] ?? false) === true;
        $approval = is_array($options['approval'] ?? null) ? $options['approval'] : [];

        $this->ensureDirectory($artifactDir);

        $contract = $this->readContract($contractPath);
        $contractErrors = $this->contractErrors($contract);
        $modeDecision = $this->modeDecision($mode, $simulateProductionRollout, $approval, $contract);
        $errors = array_values(array_merge($contractErrors, $modeDecision['errors']));

        $report = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'ops_agent_control_plane_audit',
            'run_id' => $runId,
            'contract' => [
                'relative_path' => $this->relativePath($contractPath),
                'sha256' => hash_file('sha256', $contractPath) ?: '',
                'valid' => $contractErrors === [],
                'errors' => $contractErrors,
            ],
            'mode_decision' => $modeDecision,
            'allowed_modes' => self::ALLOWED_MODES,
            'auto_modes' => self::AUTO_MODES,
            'production_manual_gate' => [
                'execution_allowed_for_agent' => false,
                'manual_approval_required' => true,
                'exact_release_id' => self::EXACT_RELEASE_ID,
                'candidate_manifest_sha256' => self::EXACT_CANDIDATE_MANIFEST_SHA256,
                'runtime_registry_sha256' => self::EXACT_RUNTIME_REGISTRY_SHA256,
                'required_approval_fields' => self::REQUIRED_APPROVAL_FIELDS,
                'prepared_command_template' => (string) data_get($contract, 'production_manual_gate.prepared_command_template', ''),
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
            'error_count' => count($errors),
            'errors' => $errors,
        ];

        $artifact = $this->writeJson($artifactDir.'/ops_agent_control_plane_report.json', $report);
        $ok = $errors === [] || (! $strict && $modeDecision['status'] === 'manual_approval_required');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $ok,
            'status' => $ok ? 'success' : 'blocked',
            'run_id' => $runId,
            'artifact_dir' => $this->redactPath($artifactDir),
            'artifacts' => [
                'ops_agent_control_plane_report.json' => $artifact,
            ],
            'mode' => $mode,
            'strict' => $strict,
            'summary' => [
                'contract_valid' => $contractErrors === [],
                'mode_allowed' => (bool) $modeDecision['mode_allowed'],
                'production_execution_allowed_for_agent' => false,
                'manual_approval_required_for_production' => true,
                'production_rollout_simulated' => $simulateProductionRollout,
                'production_rollout_blocked' => (bool) $modeDecision['production_rollout_blocked'],
            ],
            'errors' => $errors,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function modeDecision(string $mode, bool $simulateProductionRollout, array $approval, array $contract): array
    {
        $errors = [];
        $modeAllowed = in_array($mode, self::ALLOWED_MODES, true);
        $productionBlocked = false;

        if (! $modeAllowed) {
            $errors[] = 'mode_not_allowed:'.$mode;
        }

        if ($simulateProductionRollout && in_array($mode, self::AUTO_MODES, true)) {
            $productionBlocked = true;
            $errors[] = 'automatic_production_rollout_blocked';
        }

        $approvalErrors = [];
        $approvalValid = false;
        if ($mode === 'production-manual-gate') {
            $approvalErrors = $this->approvalErrors($approval);
            $approvalValid = $approvalErrors === [];
            $errors = array_merge($errors, $approvalErrors);
        }

        $modeRows = collect((array) ($contract['allowed_modes'] ?? []))
            ->filter(static fn ($row): bool => is_array($row))
            ->keyBy(static fn (array $row): string => (string) ($row['mode'] ?? ''));
        $contractMode = $modeRows->get($mode, []);

        return [
            'mode' => $mode,
            'mode_allowed' => $modeAllowed,
            'status' => $mode === 'production-manual-gate' ? 'manual_approval_required' : ($errors === [] ? 'allowed' : 'blocked'),
            'may_create_pull_request' => (bool) data_get($contractMode, 'may_create_pull_request', false),
            'may_write_staging' => (bool) data_get($contractMode, 'may_write_staging', false),
            'may_write_production' => false,
            'may_activate_production' => false,
            'production_rollout_blocked' => $productionBlocked || $mode === 'production-manual-gate',
            'approval_contract_valid' => $mode === 'production-manual-gate' ? $approvalValid : null,
            'approval_errors' => $approvalErrors,
            'errors' => $errors,
        ];
    }

    /**
     * @return list<string>
     */
    private function approvalErrors(array $approval): array
    {
        $errors = [];
        foreach (self::REQUIRED_APPROVAL_FIELDS as $field) {
            if (! array_key_exists($field, $approval) || trim((string) $approval[$field]) === '') {
                $errors[] = 'missing_approval_field:'.$field;
            }
        }

        if (($approval['release_id'] ?? null) !== self::EXACT_RELEASE_ID) {
            $errors[] = 'release_id_mismatch';
        }
        if (($approval['confirm_release_id'] ?? null) !== self::EXACT_RELEASE_ID) {
            $errors[] = 'confirm_release_id_mismatch';
        }
        if (($approval['candidate_manifest_sha256'] ?? null) !== self::EXACT_CANDIDATE_MANIFEST_SHA256) {
            $errors[] = 'candidate_manifest_sha256_mismatch';
        }
        if (($approval['runtime_registry_sha256'] ?? null) !== self::EXACT_RUNTIME_REGISTRY_SHA256) {
            $errors[] = 'runtime_registry_sha256_mismatch';
        }

        return array_values(array_unique($errors));
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
        if (($contract['runtime_use'] ?? null) !== 'not_runtime') {
            $errors[] = 'runtime_use_must_be_not_runtime';
        }
        if (($contract['production_use_allowed'] ?? null) !== false) {
            $errors[] = 'production_use_allowed_must_be_false';
        }

        $modes = array_map(
            static fn (array $row): string => (string) ($row['mode'] ?? ''),
            array_filter((array) ($contract['allowed_modes'] ?? []), 'is_array')
        );
        sort($modes);
        $expectedModes = self::ALLOWED_MODES;
        sort($expectedModes);
        if ($modes !== $expectedModes) {
            $errors[] = 'allowed_modes_mismatch';
        }

        foreach ((array) ($contract['allowed_modes'] ?? []) as $modeRow) {
            if (! is_array($modeRow)) {
                $errors[] = 'allowed_mode_row_malformed';
                continue;
            }

            if (($modeRow['may_write_production'] ?? null) !== false) {
                $errors[] = 'mode_may_write_production_not_false:'.(string) ($modeRow['mode'] ?? '');
            }
            if (($modeRow['may_activate_production'] ?? null) !== false) {
                $errors[] = 'mode_may_activate_production_not_false:'.(string) ($modeRow['mode'] ?? '');
            }
        }

        if (data_get($contract, 'production_manual_gate.execution_allowed_for_agent') !== false) {
            $errors[] = 'production_execution_allowed_for_agent_must_be_false';
        }
        if (data_get($contract, 'production_manual_gate.manual_approval_required') !== true) {
            $errors[] = 'production_manual_approval_required_must_be_true';
        }
        if (data_get($contract, 'production_manual_gate.exact_release_id') !== self::EXACT_RELEASE_ID) {
            $errors[] = 'production_gate_release_id_mismatch';
        }
        if (data_get($contract, 'production_manual_gate.candidate_manifest_sha256') !== self::EXACT_CANDIDATE_MANIFEST_SHA256) {
            $errors[] = 'production_gate_candidate_hash_mismatch';
        }
        if (data_get($contract, 'production_manual_gate.runtime_registry_sha256') !== self::EXACT_RUNTIME_REGISTRY_SHA256) {
            $errors[] = 'production_gate_runtime_hash_mismatch';
        }
        if ((array) data_get($contract, 'production_manual_gate.required_approval_fields', []) !== self::REQUIRED_APPROVAL_FIELDS) {
            $errors[] = 'production_gate_required_approval_fields_mismatch';
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
            'bulk_content_generation_happened' => false,
            'candidate_import_happened' => false,
            'production_activation_happened' => false,
            'runtime_switch_happened' => false,
            'production_write_happened' => false,
            'frontend_change_happened' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readContract(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Control plane contract does not exist: '.$path);
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Control plane contract is not valid JSON: '.$path);
        }

        return $decoded;
    }

    private function contractPath(string $path): string
    {
        if ($path !== '') {
            return $path;
        }

        return base_path(self::DEFAULT_CONTRACT_RELATIVE_PATH);
    }

    private function artifactDir(string $root, string $runId): string
    {
        $artifactRoot = $root !== '' ? rtrim($root, DIRECTORY_SEPARATOR) : base_path(self::DEFAULT_ARTIFACT_RELATIVE_DIR);

        return $artifactRoot.DIRECTORY_SEPARATOR.$runId;
    }

    private function sanitizeRunId(string $runId): string
    {
        $trimmed = trim($runId);
        if ($trimmed === '') {
            return 'ops-control-plane-'.gmdate('Ymd\THis\Z');
        }

        $sanitized = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $trimmed) ?: '';

        return trim($sanitized, '-') ?: 'ops-control-plane';
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
