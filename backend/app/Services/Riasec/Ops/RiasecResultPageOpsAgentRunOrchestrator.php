<?php

declare(strict_types=1);

namespace App\Services\Riasec\Ops;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class RiasecResultPageOpsAgentRunOrchestrator
{
    public const SCHEMA_VERSION = 'fap.riasec.result_page_v2.ops_pr_train_orchestrator.v0.1';

    public const DEFAULT_ARTIFACT_RELATIVE_DIR = 'artifacts/riasec_result_page_ops_agent_runner';

    public const DEFAULT_PERMISSION_MODEL_RELATIVE_PATH = 'content_assets/riasec/result_page_v2/governance/ops_agent_permission_model.v0_1.json';

    private const ALLOWED_MODES = [
        'auto-to-pr',
        'auto-to-staging',
        'auto-to-report',
    ];

    private const RUN_STATES = [
        'planned',
        'branch_prepared',
        'local_validation',
        'scope_validation',
        'pr_created',
        'checks_polling',
        'ready_to_merge',
        'merged',
        'post_merge_revalidated',
    ];

    private const ALLOWED_CHANGED_FILE_PREFIXES = [
        'backend/app/Console/Commands/RiasecResultPageOpsRunnerCommand.php',
        'backend/app/Services/Riasec/Ops/',
        'backend/tests/Feature/Console/RiasecOpsAgent',
        'backend/tests/Unit/Services/Riasec/Ops/RiasecOpsAgent',
        'docs/codex/pr-train-state.json',
    ];

    /**
     * @param  array{
     *     run_id?:string,
     *     artifact_dir?:string,
     *     permission_model_path?:string,
     *     mode?:string,
     *     scope_id?:string,
     *     pr_title?:string,
     *     base_branch?:string,
     *     changed_files?:list<string>,
     *     strict?:bool,
     *     simulate_external_blocker?:bool,
     *     simulate_current_scope_failure?:bool
     * }  $options
     * @return array<string,mixed>
     */
    public function plan(array $options = []): array
    {
        $mode = trim((string) ($options['mode'] ?? 'auto-to-pr'));
        $scopeId = $this->sanitizeSlug((string) ($options['scope_id'] ?? 'ops-agent-pr-train-orchestrator'));
        $baseBranch = $this->sanitizeBranchSegment((string) ($options['base_branch'] ?? 'main'));
        $prTitle = trim((string) ($options['pr_title'] ?? 'RIASEC: add result page ops agent PR train orchestrator'));
        $runId = $this->runId((string) ($options['run_id'] ?? ''), $mode, $scopeId, $baseBranch, $prTitle);
        $artifactDir = $this->artifactDir((string) ($options['artifact_dir'] ?? ''), $runId);
        $permissionModelPath = $this->permissionModelPath((string) ($options['permission_model_path'] ?? ''));
        $strict = ($options['strict'] ?? false) === true;
        $changedFiles = $this->normalizeChangedFiles((array) ($options['changed_files'] ?? []));
        $simulateExternalBlocker = ($options['simulate_external_blocker'] ?? false) === true;
        $simulateCurrentScopeFailure = ($options['simulate_current_scope_failure'] ?? false) === true;

        $this->ensureDirectory($artifactDir);

        $permissionModel = $this->readJson($permissionModelPath);
        $permissionErrors = $this->permissionModelErrors($permissionModel);
        $scopeReport = $this->scopeValidationReport($changedFiles, $simulateCurrentScopeFailure);
        $failureReport = $this->failureClassificationReport($simulateExternalBlocker, $simulateCurrentScopeFailure);
        $queueItem = $this->queueItem($runId, $mode, $scopeId, $baseBranch, $prTitle);
        $branchPlan = $this->branchPlan($scopeId, $baseBranch);
        $validationPlan = $this->validationPlan();
        $prContract = $this->pullRequestContract($prTitle, $branchPlan['branch_name'], $baseBranch);
        $checksContract = $this->githubChecksContract();
        $sidecarPayload = $this->sidecarIssuePayload($runId, $scopeId, $failureReport);

        $errors = array_merge($permissionErrors, (array) $scopeReport['errors'], (array) $failureReport['blocking_errors']);
        if (! in_array($mode, self::ALLOWED_MODES, true)) {
            $errors[] = 'mode_not_allowed:'.$mode;
        }

        $report = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'ops_agent_pr_train_orchestrator_plan',
            'run_id' => $runId,
            'mode' => $mode,
            'scope_id' => $scopeId,
            'permission_model' => [
                'relative_path' => $this->relativePath($permissionModelPath),
                'sha256' => hash_file('sha256', $permissionModelPath) ?: '',
                'valid' => $permissionErrors === [],
                'errors' => $permissionErrors,
            ],
            'queue_item' => $queueItem,
            'branch_plan' => $branchPlan,
            'scope_validation' => $scopeReport,
            'local_validation_plan' => $validationPlan,
            'pull_request_contract' => $prContract,
            'github_checks_contract' => $checksContract,
            'failure_classification' => $failureReport,
            'sidecar_issue_payload' => $sidecarPayload,
            'negative_guarantees' => $this->negativeGuarantees(),
            'error_count' => count(array_unique($errors)),
            'errors' => array_values(array_unique($errors)),
        ];

        $artifacts = [
            'ops_agent_pr_train_orchestrator_plan.json' => $this->writeJson($artifactDir.'/ops_agent_pr_train_orchestrator_plan.json', $report),
            'sidecar_issue_payload.json' => $this->writeJson($artifactDir.'/sidecar_issue_payload.json', $sidecarPayload),
        ];

        $ok = $errors === [] || (! $strict && $failureReport['train_can_continue'] === true && $scopeReport['valid'] === true);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $ok,
            'status' => $ok ? 'success' : 'blocked',
            'run_id' => $runId,
            'artifact_dir' => $this->redactPath($artifactDir),
            'artifacts' => $artifacts,
            'mode' => $mode,
            'scope_id' => $scopeId,
            'strict' => $strict,
            'summary' => [
                'permission_model_valid' => $permissionErrors === [],
                'scope_valid' => (bool) $scopeReport['valid'],
                'train_can_continue' => (bool) $failureReport['train_can_continue'] && $scopeReport['valid'] === true,
                'sidecar_issue_payload_created' => true,
                'production_execution_allowed_for_agent' => false,
                'production_manual_gate_required' => true,
            ],
            'errors' => array_values(array_unique($errors)),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function queueItem(string $runId, string $mode, string $scopeId, string $baseBranch, string $prTitle): array
    {
        return [
            'queue_backend' => 'file_backed_manifest',
            'run_id' => $runId,
            'mode' => $mode,
            'scope_id' => $scopeId,
            'base_branch' => $baseBranch,
            'pr_title' => $prTitle,
            'current_state' => 'planned',
            'allowed_states' => self::RUN_STATES,
            'state_transitions' => [
                ['from' => 'planned', 'to' => 'branch_prepared'],
                ['from' => 'branch_prepared', 'to' => 'local_validation'],
                ['from' => 'local_validation', 'to' => 'scope_validation'],
                ['from' => 'scope_validation', 'to' => 'pr_created'],
                ['from' => 'pr_created', 'to' => 'checks_polling'],
                ['from' => 'checks_polling', 'to' => 'ready_to_merge'],
                ['from' => 'ready_to_merge', 'to' => 'merged'],
                ['from' => 'merged', 'to' => 'post_merge_revalidated'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function branchPlan(string $scopeId, string $baseBranch): array
    {
        $branchName = 'codex/riasec-'.$scopeId.'-01';
        $branchSlug = str_replace('/', '-', $branchName);

        return [
            'base_branch' => $baseBranch,
            'branch_name' => $branchName,
            'worktree_path_template' => '<tmp>/fap-api-'.$branchSlug,
            'start_from_latest_origin_main' => true,
            'requires_clean_worktree' => true,
            'delete_branch_after_merge' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function scopeValidationReport(array $changedFiles, bool $simulateCurrentScopeFailure): array
    {
        $outOfScope = [];
        foreach ($changedFiles as $file) {
            if (! $this->fileIsAllowed($file)) {
                $outOfScope[] = $file;
            }
        }

        if ($simulateCurrentScopeFailure) {
            $outOfScope[] = 'backend/routes/api.php';
        }

        return [
            'valid' => $outOfScope === [],
            'changed_files' => $changedFiles,
            'allowed_prefixes' => self::ALLOWED_CHANGED_FILE_PREFIXES,
            'out_of_scope_files' => array_values(array_unique($outOfScope)),
            'errors' => $outOfScope === [] ? [] : ['scope_validation_failed'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validationPlan(): array
    {
        return [
            'commands' => [
                'php -l app/Services/Riasec/Ops/RiasecResultPageOpsAgentRunOrchestrator.php',
                'php -l app/Console/Commands/RiasecResultPageOpsRunnerCommand.php',
                'php artisan test --filter=RiasecOpsAgent --no-ansi',
                'php artisan riasec:result-page-ops-runner plan --run-id=<run-id> --scope-id=<scope-id> --mode=auto-to-pr --strict --json',
                'git diff --check',
            ],
            'scope_validation_required' => true,
            'github_required_checks_required' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function pullRequestContract(string $title, string $headBranch, string $baseBranch): array
    {
        return [
            'auto_create_pr_allowed' => true,
            'title' => $title,
            'head_branch' => $headBranch,
            'base_branch' => $baseBranch,
            'body_required_sections' => [
                'What changed',
                'Why',
                'Validation',
                'Intentionally deferred',
            ],
            'must_state_no_production_activation' => true,
            'must_state_no_production_writes' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function githubChecksContract(): array
    {
        return [
            'poll_checks_allowed' => true,
            'required_checks_must_be_green_before_merge' => true,
            'inspect_logs_before_fixing_failures' => true,
            'fix_only_current_pr_scope' => true,
            'auto_merge_when_repo_policy_allows' => true,
            'squash_merge_default' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function failureClassificationReport(bool $simulateExternalBlocker, bool $simulateCurrentScopeFailure): array
    {
        $events = [];
        $blockingErrors = [];
        if ($simulateExternalBlocker) {
            $events[] = [
                'class' => 'external_ci_flaky',
                'train_blocking' => false,
                'sidecar_issue_required' => true,
            ];
        }

        if ($simulateCurrentScopeFailure) {
            $events[] = [
                'class' => 'current_pr_scope',
                'train_blocking' => true,
                'sidecar_issue_required' => false,
            ];
            $blockingErrors[] = 'current_pr_scope_failure';
        }

        return [
            'events' => $events,
            'blocking_errors' => $blockingErrors,
            'external_blockers_recorded_as_sidecar' => $simulateExternalBlocker,
            'train_can_continue' => $blockingErrors === [],
            'policy' => [
                'external_blocker_does_not_stop_train_when_required_checks_green' => true,
                'current_pr_scope_failure_stops_train' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sidecarIssuePayload(string $runId, string $scopeId, array $failureReport): array
    {
        return [
            'dry_run' => true,
            'run_id' => $runId,
            'scope_id' => $scopeId,
            'title' => 'RIASEC ops agent external blocker: '.$scopeId,
            'labels' => ['ops-agent', 'riasec', 'external-blocker'],
            'body' => [
                'external_blockers_recorded' => (bool) $failureReport['external_blockers_recorded_as_sidecar'],
                'train_can_continue' => (bool) $failureReport['train_can_continue'],
                'production_activation_executed' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function permissionModelErrors(array $permissionModel): array
    {
        $errors = [];
        if (($permissionModel['schema'] ?? null) !== 'fap.riasec.result_page_v2.ops_agent_permission_model.v0.1') {
            $errors[] = 'permission_model_schema_mismatch';
        }
        if (($permissionModel['runtime_use'] ?? null) !== 'staging_only') {
            $errors[] = 'runtime_use_must_be_staging_only';
        }
        foreach ([
            'production_use_allowed',
            'ready_for_runtime',
            'ready_for_production',
            'cms_write_allowed',
            'production_rollout_allowed',
            'production_gate_auto_enable_allowed',
        ] as $field) {
            if (($permissionModel[$field] ?? null) !== false) {
                $errors[] = $field.'_must_be_false';
            }
        }
        if ((array) data_get($permissionModel, 'sidecar_policy.current_pr_blocker_stops_train') !== [true]) {
            $errors[] = 'current_pr_blocker_policy_missing';
        }
        foreach (['auto_to_pr', 'auto_to_staging', 'auto_to_report'] as $tier) {
            if (! $this->permissionTierExists($permissionModel, $tier)) {
                $errors[] = 'permission_tier_missing:'.$tier;
            }
        }

        return array_values(array_unique($errors));
    }

    private function permissionTierExists(array $permissionModel, string $tier): bool
    {
        foreach ((array) ($permissionModel['permission_tiers'] ?? []) as $item) {
            if (is_array($item) && ($item['id'] ?? null) === $tier) {
                return true;
            }
        }

        return false;
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

    private function fileIsAllowed(string $file): bool
    {
        foreach (self::ALLOWED_CHANGED_FILE_PREFIXES as $allowed) {
            if ($file === $allowed || str_starts_with($file, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function normalizeChangedFiles(array $changedFiles): array
    {
        $normalized = [];
        foreach ($changedFiles as $file) {
            $file = trim((string) $file);
            if ($file !== '') {
                $normalized[] = $file;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Required JSON file does not exist: '.$path);
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Required JSON file is not valid JSON: '.$path);
        }

        return $decoded;
    }

    private function permissionModelPath(string $path): string
    {
        return $path !== '' ? $path : base_path(self::DEFAULT_PERMISSION_MODEL_RELATIVE_PATH);
    }

    private function artifactDir(string $root, string $runId): string
    {
        $artifactRoot = $root !== '' ? rtrim($root, DIRECTORY_SEPARATOR) : base_path(self::DEFAULT_ARTIFACT_RELATIVE_DIR);

        return $artifactRoot.DIRECTORY_SEPARATOR.$runId;
    }

    private function runId(string $provided, string $mode, string $scopeId, string $baseBranch, string $prTitle): string
    {
        $provided = trim($provided);
        if ($provided !== '') {
            return $this->sanitizeSlug($provided);
        }

        $seed = implode('|', [$mode, $scopeId, $baseBranch, $prTitle]);

        return 'riasec-ops-'.substr(hash('sha256', $seed), 0, 12);
    }

    private function sanitizeSlug(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($value)) ?: '';

        return trim($sanitized, '-') ?: 'ops-agent-pr-train-orchestrator';
    }

    private function sanitizeBranchSegment(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_.\\/-]+/', '-', trim($value)) ?: '';

        return trim($sanitized, '-') ?: 'main';
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
