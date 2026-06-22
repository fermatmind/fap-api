<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets\Agent;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class EnneagramResultPageContentBatchAutomation
{
    public const SCHEMA_VERSION = 'fap.enneagram.result_page.content_batch_automation.v0.1';

    public const DEFAULT_ARTIFACT_RELATIVE_DIR = 'artifacts/enneagram_result_page_content_batch_automation';

    public const DEFAULT_CONTRACT_RELATIVE_PATH = 'content_assets/enneagram/result_page/content_batch_automation/content_batch_automation_contract_v0_1.json';

    public const DEFAULT_SOURCE_LEDGER_RELATIVE_PATH = 'content_assets/enneagram/result_page/source_ledger/source_ledger.json';

    private const FORBIDDEN_CLAIM_FAMILIES = [
        'diagnosis_clinical_treatment',
        'hiring_employment_screening',
        'final_typing_you_are_this_type',
        'fixed_type_certainty',
        'e105_fc144_score_comparison',
        'fc144_more_accurate_or_replacement_result',
        'success_salary_performance_prediction',
        'private_result_identifier_path_or_vector_leakage',
    ];

    public function __construct(private readonly EnneagramResultPageAgentBatchRunner $runner) {}

    /**
     * @param  array{
     *     run_id?:string,
     *     artifact_dir?:string,
     *     contract_path?:string,
     *     source_ledger_path?:string,
     *     source_id?:string,
     *     module_key?:string,
     *     result_type?:string,
     *     scope?:string,
     *     public_payload?:array<string,mixed>,
     *     previous_payload?:array<string,mixed>|null,
     *     strict?:bool
     * }  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $options = []): array
    {
        $runId = $this->runId((string) ($options['run_id'] ?? ''), $options);
        $artifactDir = $this->artifactDir((string) ($options['artifact_dir'] ?? ''), $runId);
        $contractPath = $this->contractPath((string) ($options['contract_path'] ?? ''));
        $sourceLedgerPath = $this->sourceLedgerPath((string) ($options['source_ledger_path'] ?? ''));
        $sourceId = trim((string) ($options['source_id'] ?? 'batch_1r_a_asset_stream'));
        $moduleKey = trim((string) ($options['module_key'] ?? 'pilot_baseline_reflection'));
        $resultType = trim((string) ($options['result_type'] ?? 'type_1'));
        $scope = trim((string) ($options['scope'] ?? 'pilot'));
        $publicPayload = is_array($options['public_payload'] ?? null)
            ? (array) $options['public_payload']
            : $this->defaultPilotPayload();
        $previousPayload = is_array($options['previous_payload'] ?? null) ? (array) $options['previous_payload'] : null;
        $strict = ($options['strict'] ?? false) === true;

        $this->ensureDirectory($artifactDir);

        $contract = $this->readJson($contractPath, 'Content batch automation contract');
        $contractErrors = $this->contractErrors($contract);
        $sourceLedgerRow = $this->sourceLedgerRow($sourceLedgerPath, $sourceId);

        $runnerResult = $this->runner->run([
            'source_ledger_row' => $sourceLedgerRow,
            'target_module' => [
                'module_key' => $moduleKey,
                'result_type' => $resultType,
                'scope' => $scope,
                'additional_source_ids' => [
                    'phase8b_candidate_baseline_a9fd',
                    'forbidden_claim_policy',
                ],
            ],
            'public_payload' => $publicPayload,
            'previous_payload' => $previousPayload,
            'forbidden_claim_policy' => [
                'families' => self::FORBIDDEN_CLAIM_FAMILIES,
            ],
        ]);

        $errors = array_values(array_unique(array_merge(
            $contractErrors,
            (array) ($runnerResult['errors'] ?? [])
        )));

        $report = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'content_batch_automation_evaluate',
            'run_id' => $runId,
            'contract' => [
                'relative_path' => $this->relativePath($contractPath),
                'sha256' => hash_file('sha256', $contractPath) ?: '',
                'valid' => $contractErrors === [],
                'errors' => $contractErrors,
            ],
            'input' => [
                'source_id' => $sourceId,
                'module_key' => $moduleKey,
                'result_type' => $resultType,
                'scope' => $scope,
                'payload_count' => 1,
                'bulk_generation_allowed' => false,
            ],
            'runner_status' => [
                'ok' => (bool) ($runnerResult['ok'] ?? false),
                'status' => (string) ($runnerResult['status'] ?? 'unknown'),
                'payload_hash_sha256' => (string) ($runnerResult['payload_hash_sha256'] ?? ''),
            ],
            'output_files' => [
                'payload.json',
                'source_mapping_report.json',
                'safety_report.json',
                'diff_report.json',
                'rollback_report.json',
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
            'error_count' => count($errors),
            'errors' => $errors,
        ];

        $artifacts = [
            'payload.json' => $this->writeJson($artifactDir.'/payload.json', (array) ($runnerResult['payload'] ?? [])),
            'source_mapping_report.json' => $this->writeJson($artifactDir.'/source_mapping_report.json', (array) ($runnerResult['source_mapping_report'] ?? [])),
            'safety_report.json' => $this->writeJson($artifactDir.'/safety_report.json', (array) ($runnerResult['safety_report'] ?? [])),
            'diff_report.json' => $this->writeJson($artifactDir.'/diff_report.json', (array) ($runnerResult['diff_report'] ?? [])),
            'rollback_report.json' => $this->writeJson($artifactDir.'/rollback_report.json', (array) ($runnerResult['rollback_report'] ?? [])),
            'batch_automation_report.json' => $this->writeJson($artifactDir.'/batch_automation_report.json', $report),
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
                'payload_count' => 1,
                'bulk_generation_allowed' => false,
                'source_mapping_zero_failures' => (int) data_get($runnerResult, 'source_mapping_report.source_mapping_failure_count', 1) === 0,
                'metadata_leakage_zero' => (int) data_get($runnerResult, 'safety_report.metadata_leakage_hit_count', 1) === 0,
                'forbidden_claim_zero' => (int) data_get($runnerResult, 'safety_report.forbidden_claim_hit_count', 1) === 0,
                'fc144_boundary_zero' => (int) data_get($runnerResult, 'safety_report.fc144_boundary_violation_count', 1) === 0,
                'production_execution_allowed_for_agent' => false,
            ],
            'errors' => $errors,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultPilotPayload(): array
    {
        return [
            'heading' => 'Turn one observation into a next step',
            'body' => 'Name one situation to review, then choose one small action that can be completed today.',
        ];
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
        if (($contract['runner_schema_version'] ?? null) !== EnneagramResultPageAgentBatchRunner::SCHEMA_VERSION) {
            $errors[] = 'runner_schema_version_mismatch';
        }
        if (($contract['runtime_use'] ?? null) !== 'not_runtime') {
            $errors[] = 'runtime_use_must_be_not_runtime';
        }
        if (($contract['production_use_allowed'] ?? null) !== false) {
            $errors[] = 'production_use_allowed_must_be_false';
        }
        if (data_get($contract, 'batch_size_policy.bulk_generation_allowed') !== false) {
            $errors[] = 'bulk_generation_must_be_false';
        }
        if ((int) data_get($contract, 'batch_size_policy.max_fixture_payloads_in_this_pr') > 1) {
            $errors[] = 'max_fixture_payloads_exceeds_one';
        }
        if (data_get($contract, 'production_guard.agent_may_import_candidate') !== false) {
            $errors[] = 'agent_import_candidate_must_be_false';
        }
        if (data_get($contract, 'production_guard.agent_may_execute_production_rollout') !== false) {
            $errors[] = 'agent_production_rollout_must_be_false';
        }

        foreach ($this->negativeGuarantees() as $key => $expected) {
            if (data_get($contract, 'negative_guarantees.'.$key) !== $expected) {
                $errors[] = 'negative_guarantee_mismatch:'.$key;
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceLedgerRow(string $sourceLedgerPath, string $sourceId): array
    {
        $ledger = $this->readJson($sourceLedgerPath, 'Source ledger');
        foreach ((array) ($ledger['sources'] ?? []) as $source) {
            if (is_array($source) && ($source['source_id'] ?? null) === $sourceId) {
                return $source;
            }
        }

        throw new RuntimeException('Missing source ledger row: '.$sourceId);
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path, string $label): array
    {
        if (! is_file($path)) {
            throw new RuntimeException($label.' does not exist: '.$path);
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException($label.' is not valid JSON: '.$path);
        }

        return $decoded;
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

    private function contractPath(string $path): string
    {
        return $path !== '' ? $path : base_path(self::DEFAULT_CONTRACT_RELATIVE_PATH);
    }

    private function sourceLedgerPath(string $path): string
    {
        return $path !== '' ? $path : base_path(self::DEFAULT_SOURCE_LEDGER_RELATIVE_PATH);
    }

    private function artifactDir(string $root, string $runId): string
    {
        $artifactRoot = $root !== '' ? rtrim($root, DIRECTORY_SEPARATOR) : base_path(self::DEFAULT_ARTIFACT_RELATIVE_DIR);

        return $artifactRoot.DIRECTORY_SEPARATOR.$runId;
    }

    private function runId(string $provided, array $options): string
    {
        $provided = trim($provided);
        if ($provided !== '') {
            return $this->sanitizeSlug($provided);
        }

        $seed = implode('|', [
            (string) ($options['source_id'] ?? 'batch_1r_a_asset_stream'),
            (string) ($options['module_key'] ?? 'pilot_baseline_reflection'),
            (string) ($options['result_type'] ?? 'type_1'),
            (string) ($options['scope'] ?? 'pilot'),
        ]);

        return 'enneagram-batch-'.substr(hash('sha256', $seed), 0, 12);
    }

    private function sanitizeSlug(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($value)) ?: '';

        return trim($sanitized, '-') ?: 'content-batch';
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
