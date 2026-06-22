<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets\Agent;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class EnneagramResultPageReportSidecarIssueHarness
{
    public const SCHEMA_VERSION = 'fap.enneagram.result_page.report_sidecar_issue.v0.1';

    public const DEFAULT_ARTIFACT_RELATIVE_DIR = 'artifacts/enneagram_result_page_report_sidecar_issue';

    public const DEFAULT_CONTRACT_RELATIVE_PATH = 'content_assets/enneagram/result_page/report_sidecar_issue/report_sidecar_issue_contract_v0_1.json';

    private const READINESS_GATES = [
        'candidate_export_staging_import',
        'web_rendered_qa',
        'api_smoke',
        'rollback_simulation',
        'production_manual_gate',
    ];

    /**
     * @param  array{
     *     run_id?:string,
     *     artifact_dir?:string,
     *     contract_path?:string,
     *     evidence_dir?:string,
     *     blocker_source?:string,
     *     blocker_reason?:string,
     *     github_checks_green?:bool,
     *     scope_validation_green?:bool,
     *     strict?:bool
     * }  $options
     * @return array<string,mixed>
     */
    public function run(array $options = []): array
    {
        $runId = $this->sanitizeSlug((string) ($options['run_id'] ?? 'report-sidecar'));
        $artifactDir = $this->artifactDir((string) ($options['artifact_dir'] ?? ''), $runId);
        $contractPath = $this->contractPath((string) ($options['contract_path'] ?? ''));
        $evidenceDir = rtrim(trim((string) ($options['evidence_dir'] ?? '')), DIRECTORY_SEPARATOR);
        $blockerSource = trim((string) ($options['blocker_source'] ?? 'none'));
        $blockerReason = trim((string) ($options['blocker_reason'] ?? ''));
        $githubChecksGreen = ($options['github_checks_green'] ?? true) === true;
        $scopeValidationGreen = ($options['scope_validation_green'] ?? true) === true;
        $strict = ($options['strict'] ?? false) === true;

        $this->ensureDirectory($artifactDir);

        $contract = $this->readJson($contractPath, 'Report sidecar issue contract');
        $contractErrors = $this->contractErrors($contract);
        $evidence = $this->evidenceInventory($evidenceDir);
        $failureClassification = $this->failureClassification(
            $blockerSource,
            $blockerReason,
            $githubChecksGreen,
            $scopeValidationGreen,
        );
        $readiness = $this->releaseReadinessSummary($evidence, $failureClassification);
        $sidecarIssue = $this->sidecarIssuePayload($runId, $failureClassification, $readiness);
        $opsReport = $this->opsReportMarkdown($runId, $failureClassification, $readiness);

        $errors = array_values(array_unique(array_merge(
            $contractErrors,
            (array) ($failureClassification['blocking_errors'] ?? []),
            $strict ? (array) ($evidence['errors'] ?? []) : [],
        )));

        $report = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'report_sidecar_issue_harness',
            'run_id' => $runId,
            'contract' => [
                'relative_path' => $this->relativePath($contractPath),
                'sha256' => hash_file('sha256', $contractPath) ?: '',
                'valid' => $contractErrors === [],
                'errors' => $contractErrors,
            ],
            'evidence_inventory' => $evidence,
            'failure_classification' => $failureClassification,
            'release_readiness_summary' => $readiness,
            'sidecar_issue_payload' => $sidecarIssue,
            'negative_guarantees' => $this->negativeGuarantees(),
            'error_count' => count($errors),
            'errors' => $errors,
        ];

        $artifacts = [
            'report_sidecar_issue_harness_report.json' => $this->writeJson($artifactDir.'/report_sidecar_issue_harness_report.json', $report),
            'failure_classification_report.json' => $this->writeJson($artifactDir.'/failure_classification_report.json', $failureClassification),
            'sidecar_issue_payload.json' => $this->writeJson($artifactDir.'/sidecar_issue_payload.json', $sidecarIssue),
            'release_readiness_summary.json' => $this->writeJson($artifactDir.'/release_readiness_summary.json', $readiness),
            'ops_report.md' => $this->writeText($artifactDir.'/ops_report.md', $opsReport),
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
                'train_can_continue' => (bool) ($failureClassification['train_can_continue'] ?? false),
                'sidecar_issue_payload_created' => true,
                'release_ready_for_manual_production_gate' => (bool) ($readiness['ready_for_manual_production_gate'] ?? false),
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
    private function failureClassification(string $blockerSource, string $blockerReason, bool $githubChecksGreen, bool $scopeValidationGreen): array
    {
        $allowed = ['none', 'external', 'current_pr'];
        $errors = [];
        if (! in_array($blockerSource, $allowed, true)) {
            $errors[] = 'blocker_source_not_allowed:'.$blockerSource;
        }

        $currentPrBlocker = $blockerSource === 'current_pr' || ! $githubChecksGreen || ! $scopeValidationGreen;
        $externalBlocker = $blockerSource === 'external';
        $trainCanContinue = ! $currentPrBlocker && ($blockerSource === 'none' || ($externalBlocker && $githubChecksGreen && $scopeValidationGreen));
        if ($currentPrBlocker) {
            $errors[] = 'current_pr_or_required_gate_blocker';
        }

        return [
            'blocker_source' => $blockerSource,
            'blocker_reason' => $blockerReason === '' ? null : $blockerReason,
            'github_checks_green' => $githubChecksGreen,
            'scope_validation_green' => $scopeValidationGreen,
            'external_blocker' => $externalBlocker,
            'current_pr_blocker' => $currentPrBlocker,
            'train_can_continue' => $trainCanContinue,
            'sidecar_issue_required' => $externalBlocker,
            'blocking_errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function releaseReadinessSummary(array $evidence, array $failureClassification): array
    {
        $evidenceByGate = (array) ($evidence['gate_status'] ?? []);
        $gates = [];
        foreach (self::READINESS_GATES as $gate) {
            $gates[$gate] = [
                'status' => $gate === 'production_manual_gate'
                    ? 'manual_required'
                    : (string) ($evidenceByGate[$gate] ?? 'pending'),
                'production_execution_allowed_for_agent' => false,
            ];
        }

        $nonManualReady = collect($gates)
            ->except('production_manual_gate')
            ->every(static fn (array $gate): bool => $gate['status'] === 'pass');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'gates' => $gates,
            'non_manual_gates_passed' => $nonManualReady,
            'ready_for_manual_production_gate' => $nonManualReady && (bool) ($failureClassification['train_can_continue'] ?? false),
            'production_manual_gate_required' => true,
            'production_execution_allowed_for_agent' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sidecarIssuePayload(string $runId, array $failureClassification, array $readiness): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'create_issue_directly' => false,
            'payload_only' => true,
            'title' => 'Enneagram result page external blocker: '.$runId,
            'labels' => ['enneagram-result-page', 'ops-agent', 'sidecar'],
            'body' => [
                'blocker_source' => $failureClassification['blocker_source'] ?? 'unknown',
                'blocker_reason' => $failureClassification['blocker_reason'] ?? null,
                'train_can_continue' => (bool) ($failureClassification['train_can_continue'] ?? false),
                'ready_for_manual_production_gate' => (bool) ($readiness['ready_for_manual_production_gate'] ?? false),
                'production_execution_allowed_for_agent' => false,
            ],
        ];
    }

    private function opsReportMarkdown(string $runId, array $failureClassification, array $readiness): string
    {
        return implode(PHP_EOL, [
            '# Enneagram Result Page Ops Report',
            '',
            '- run_id: '.$runId,
            '- train_can_continue: '.(((bool) ($failureClassification['train_can_continue'] ?? false)) ? 'true' : 'false'),
            '- blocker_source: '.(string) ($failureClassification['blocker_source'] ?? 'unknown'),
            '- ready_for_manual_production_gate: '.(((bool) ($readiness['ready_for_manual_production_gate'] ?? false)) ? 'true' : 'false'),
            '- production_execution_allowed_for_agent: false',
            '- production_manual_gate_required: true',
            '',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function evidenceInventory(string $evidenceDir): array
    {
        if ($evidenceDir === '') {
            return [
                'provided' => false,
                'relative_path' => null,
                'gate_status' => [],
                'reports' => [],
                'errors' => [],
            ];
        }
        if (! is_dir($evidenceDir)) {
            return [
                'provided' => true,
                'relative_path' => $this->redactPath($evidenceDir),
                'gate_status' => [],
                'reports' => [],
                'errors' => ['evidence_dir_not_found'],
            ];
        }

        $reports = [];
        $gateStatus = [];
        $errors = [];
        foreach (File::glob($evidenceDir.'/*.json') ?: [] as $path) {
            $payload = $this->readJson($path, basename($path));
            $gate = (string) ($payload['gate'] ?? pathinfo($path, PATHINFO_FILENAME));
            $ok = (bool) ($payload['ok'] ?? false);
            $gateStatus[$gate] = $ok ? 'pass' : 'fail';
            if (! $ok) {
                $errors[] = 'evidence_gate_failed:'.$gate;
            }
            $reports[] = [
                'relative_path' => $this->relativePath($path),
                'sha256' => hash_file('sha256', $path) ?: '',
                'gate' => $gate,
                'ok' => $ok,
            ];
        }

        return [
            'provided' => true,
            'relative_path' => $this->redactPath($evidenceDir),
            'gate_status' => $gateStatus,
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
        if (data_get($contract, 'sidecar_issue.create_issue_directly') !== false) {
            $errors[] = 'sidecar_issue_direct_write_must_be_false';
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
            'github_issue_created' => false,
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

        return trim($sanitized, '-') ?: 'report-sidecar';
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
