<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets\Agent;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class EnneagramResultPageRenderedQaSmokeHarness
{
    public const SCHEMA_VERSION = 'fap.enneagram.result_page.rendered_qa_smoke_harness.v0.1';

    public const DEFAULT_ARTIFACT_RELATIVE_DIR = 'artifacts/enneagram_result_page_rendered_qa_smoke_harness';

    public const DEFAULT_CONTRACT_RELATIVE_PATH = 'content_assets/enneagram/result_page/rendered_qa_smoke_harness/rendered_qa_smoke_harness_contract_v0_1.json';

    private const REQUIRED_EVIDENCE = [
        'web_rendered_qa',
        'api_smoke',
        'rollback_simulation',
    ];

    /**
     * @param  array{
     *     run_id?:string,
     *     artifact_dir?:string,
     *     contract_path?:string,
     *     candidate_dir?:string,
     *     web_repo_dir?:string,
     *     evidence_dir?:string,
     *     release_id?:string,
     *     mode?:string,
     *     strict?:bool
     * }  $options
     * @return array<string,mixed>
     */
    public function run(array $options = []): array
    {
        $runId = $this->sanitizeSlug((string) ($options['run_id'] ?? 'rendered-qa-smoke'));
        $artifactDir = $this->artifactDir((string) ($options['artifact_dir'] ?? ''), $runId);
        $contractPath = $this->contractPath((string) ($options['contract_path'] ?? ''));
        $candidateDir = rtrim(trim((string) ($options['candidate_dir'] ?? '')), DIRECTORY_SEPARATOR);
        $webRepoDir = rtrim(trim((string) ($options['web_repo_dir'] ?? '')), DIRECTORY_SEPARATOR);
        $evidenceDir = rtrim(trim((string) ($options['evidence_dir'] ?? '')), DIRECTORY_SEPARATOR);
        $releaseId = trim((string) ($options['release_id'] ?? ''));
        $mode = trim((string) ($options['mode'] ?? 'auto-to-report'));
        $strict = ($options['strict'] ?? false) === true;

        $this->ensureDirectory($artifactDir);

        $contract = $this->readJson($contractPath, 'Rendered QA smoke harness contract');
        $contractErrors = $this->contractErrors($contract);
        $candidateReport = $this->candidateReport($candidateDir);
        $evidenceReport = $this->evidenceReport($evidenceDir);
        $webCommand = $this->webRenderedQaCommand($webRepoDir, $candidateDir);
        $apiCommand = $this->apiSmokeCommand($candidateDir, $artifactDir);
        $rollbackPlan = $this->rollbackSimulationPlan($releaseId, $artifactDir);

        $errors = array_values(array_unique(array_merge(
            $contractErrors,
            $strict ? (array) ($candidateReport['errors'] ?? []) : [],
            (array) ($evidenceReport['errors'] ?? []),
        )));

        if (! in_array($mode, ['auto-to-staging', 'auto-to-report'], true)) {
            $errors[] = 'mode_not_allowed:'.$mode;
        }

        $bundle = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'rendered_qa_smoke_evidence_bundle',
            'run_id' => $runId,
            'mode' => $mode,
            'contract' => [
                'relative_path' => $this->relativePath($contractPath),
                'sha256' => hash_file('sha256', $contractPath) ?: '',
                'valid' => $contractErrors === [],
                'errors' => $contractErrors,
            ],
            'candidate_dir' => $candidateReport,
            'web_rendered_qa' => $webCommand,
            'api_smoke' => $apiCommand,
            'rollback_simulation' => $rollbackPlan,
            'evidence' => $evidenceReport,
            'negative_guarantees' => $this->negativeGuarantees(),
            'error_count' => count($errors),
            'errors' => $errors,
        ];

        $artifacts = [
            'rendered_qa_smoke_harness_report.json' => $this->writeJson($artifactDir.'/rendered_qa_smoke_harness_report.json', $bundle),
            'web_rendered_qa_command.json' => $this->writeJson($artifactDir.'/web_rendered_qa_command.json', $webCommand),
            'api_smoke_command.json' => $this->writeJson($artifactDir.'/api_smoke_command.json', $apiCommand),
            'rollback_simulation_plan.json' => $this->writeJson($artifactDir.'/rollback_simulation_plan.json', $rollbackPlan),
            'evidence_bundle_manifest.json' => $this->writeJson($artifactDir.'/evidence_bundle_manifest.json', [
                'schema_version' => self::SCHEMA_VERSION,
                'run_id' => $runId,
                'required_evidence' => self::REQUIRED_EVIDENCE,
                'evidence_dir_provided' => $evidenceDir !== '',
                'evidence_valid' => (bool) ($evidenceReport['valid'] ?? false),
                'production_execution_allowed_for_agent' => false,
                'production_manual_gate_required' => true,
            ]),
        ];

        $ok = $errors === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $ok,
            'status' => $ok ? 'success' : 'blocked',
            'run_id' => $runId,
            'artifact_dir' => $this->redactPath($artifactDir),
            'artifacts' => $artifacts,
            'mode' => $mode,
            'strict' => $strict,
            'summary' => [
                'contract_valid' => $contractErrors === [],
                'candidate_dir_valid' => (bool) ($candidateReport['valid'] ?? false),
                'evidence_valid' => (bool) ($evidenceReport['valid'] ?? false),
                'web_rendered_qa_command_ready' => true,
                'api_smoke_command_ready' => true,
                'rollback_simulation_plan_ready' => true,
                'production_execution_allowed_for_agent' => false,
                'production_manual_gate_required' => true,
            ],
            'errors' => $errors,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function candidateReport(string $candidateDir): array
    {
        $errors = [];
        if ($candidateDir === '') {
            $errors[] = 'candidate_dir_missing';
        } elseif (! is_dir($candidateDir)) {
            $errors[] = 'candidate_dir_not_found';
        }

        return [
            'provided' => $candidateDir !== '',
            'path' => $candidateDir === '' ? null : $this->redactPath($candidateDir),
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function webRenderedQaCommand(string $webRepoDir, string $candidateDir): array
    {
        return [
            'task' => 'web_rendered_qa',
            'repo' => 'fap-web',
            'working_directory' => $webRepoDir === '' ? '<fap-web-repo>' : $this->redactPath($webRepoDir),
            'environment' => [
                'PHASE8B_CANDIDATE_DIR' => $candidateDir === '' ? '<candidate-dir>' : $this->redactPath($candidateDir),
            ],
            'command' => 'pnpm exec playwright test tests/e2e/enneagram-phase8c-production-equivalent-candidate-e2e.spec.ts',
            'execution_allowed_for_agent' => true,
            'production_execution_allowed_for_agent' => false,
            'frontend_change_allowed' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function apiSmokeCommand(string $candidateDir, string $artifactDir): array
    {
        return [
            'task' => 'api_smoke',
            'repo' => 'fap-api',
            'working_directory' => 'backend',
            'environment' => [
                'PHASE8B_CANDIDATE_DIR' => $candidateDir === '' ? '<candidate-dir>' : $this->redactPath($candidateDir),
            ],
            'command' => 'php artisan enneagram:result-page-candidate-staging-harness audit --candidate-dir=<candidate-dir> --artifact-dir=<artifact-dir> --strict --json',
            'artifact_dir' => $this->redactPath($artifactDir),
            'execution_allowed_for_agent' => true,
            'production_execution_allowed_for_agent' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function rollbackSimulationPlan(string $releaseId, string $artifactDir): array
    {
        return [
            'task' => 'rollback_simulation',
            'release_id' => $releaseId === '' ? '<inactive-release-id>' : $releaseId,
            'simulation_policy' => 'plan_only_until_manual_production_gate',
            'manual_gate_required_before_production_rollback' => true,
            'command_template' => 'php artisan enneagram:rollback-inactive-candidate-release --release-id=<release-id> --confirm-release-id=<release-id> --output-dir=<output-dir> --json',
            'output_dir' => $this->redactPath($artifactDir.'/rollback_simulation'),
            'execution_allowed_for_agent' => false,
            'production_rollback_allowed_for_agent' => false,
            'production_execution_allowed_for_agent' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function evidenceReport(string $evidenceDir): array
    {
        if ($evidenceDir === '') {
            return [
                'provided' => false,
                'valid' => true,
                'reports' => [],
                'errors' => [],
            ];
        }

        if (! is_dir($evidenceDir)) {
            return [
                'provided' => true,
                'valid' => false,
                'reports' => [],
                'errors' => ['evidence_dir_not_found'],
            ];
        }

        $reports = [];
        $errors = [];
        foreach (self::REQUIRED_EVIDENCE as $evidenceKey) {
            $path = $evidenceDir.'/'.$evidenceKey.'_report.json';
            if (! is_file($path)) {
                $errors[] = 'evidence_report_missing:'.$evidenceKey;
                continue;
            }

            $payload = $this->readJson($path, $evidenceKey.' evidence report');
            $status = strtolower((string) ($payload['status'] ?? $payload['verdict'] ?? ''));
            $ok = (bool) ($payload['ok'] ?? in_array($status, ['pass', 'passed', 'success'], true));
            if (! $ok) {
                $errors[] = 'evidence_report_failed:'.$evidenceKey;
            }

            $reports[$evidenceKey] = [
                'relative_path' => $this->relativePath($path),
                'sha256' => hash_file('sha256', $path) ?: '',
                'ok' => $ok,
            ];
        }

        return [
            'provided' => true,
            'valid' => $errors === [],
            'reports' => $reports,
            'errors' => array_values(array_unique($errors)),
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
        if (($contract['runtime_use'] ?? null) !== 'not_runtime') {
            $errors[] = 'runtime_use_must_be_not_runtime';
        }
        if (($contract['production_use_allowed'] ?? null) !== false) {
            $errors[] = 'production_use_allowed_must_be_false';
        }
        foreach (self::REQUIRED_EVIDENCE as $key) {
            if (! in_array($key, (array) ($contract['required_evidence'] ?? []), true)) {
                $errors[] = 'required_evidence_missing:'.$key;
            }
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

        return trim($sanitized, '-') ?: 'rendered-qa-smoke';
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
