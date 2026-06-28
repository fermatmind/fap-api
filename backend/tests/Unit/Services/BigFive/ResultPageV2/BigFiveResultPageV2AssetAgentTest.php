<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\AssetAgent\BigFiveResultPageV2AssetAgent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BigFiveResultPageV2AssetAgentTest extends TestCase
{
    public function test_audit_command_writes_redacted_staging_reports_without_runtime_changes(): void
    {
        $artifactRoot = $this->tempDir('big5-v2-agent-command');

        try {
            $this->artisan('big5:result-page-v2-agent', [
                'action' => 'audit',
                '--run-id' => 'unit-run',
                '--artifact-dir' => $artifactRoot,
                '--json' => true,
            ])->assertExitCode(0);

            $runDir = $artifactRoot.'/unit-run';
            foreach ([
                'input_inventory.json',
                'validation_report.json',
                'safety_report.json',
                'qa_eval_summary.json',
                'ops_report_summary.json',
                'go_no_go.md',
                'readiness.json',
            ] as $filename) {
                $this->assertFileExists($runDir.'/'.$filename);
            }

            $inventory = $this->readJson($runDir.'/input_inventory.json');
            $this->assertSame('staging_only', $inventory['runtime_use'] ?? null);
            $this->assertFalse((bool) ($inventory['production_use_allowed'] ?? true));
            $this->assertSame('fap.big5.result_page_v2.selector_asset.v0.1', data_get($inventory, 'inputs.selector_asset_schema'));
            $this->assertTrue((bool) data_get($inventory, 'source_ledger.valid'));
            $this->assertStringEndsWith(
                'content_assets/big5/result_page_v2/source_ledger/source_ledger.json',
                (string) data_get($inventory, 'source_ledger.primary_ledger_path')
            );
            $this->assertSame([
                'public_domain_source',
                'citation_only',
                'structure_reference_only',
                'forbidden_copy_source',
            ], data_get($inventory, 'source_ledger.allowed_source_labels'));
            $this->assertTrue((bool) data_get($inventory, 'source_ledger.bfi_2_policy_valid'));

            $validation = $this->readJson($runDir.'/validation_report.json');
            $this->assertSame(325, (int) ($validation['asset_count'] ?? 0));
            $this->assertSame(0, (int) ($validation['error_count'] ?? -1));
            $this->assertSame([], (array) ($validation['errors'] ?? []));

            $safety = $this->readJson($runDir.'/safety_report.json');
            $this->assertSame('pass', data_get($safety, 'leak_scan.status'));
            $this->assertContains('selector_token_big5', (array) ($safety['forbidden_rendered_text_rules'] ?? []));
            $this->assertContains('broken_template_generated_by', (array) ($safety['forbidden_rendered_text_rules'] ?? []));

            $goNoGo = (string) file_get_contents($runDir.'/go_no_go.md');
            $this->assertStringContainsString('ready_for_runtime: false', $goNoGo);
            $this->assertStringContainsString('production_use_allowed: false', $goNoGo);
            $this->assertStringContainsString('## Ops Metrics', $goNoGo);
            $this->assertStringContainsString('comparison_status: no_previous_run', $goNoGo);

            $qa = $this->readJson($runDir.'/qa_eval_summary.json');
            $this->assertSame(
                'fap.big5.result_page_v2.asset_agent.ops_report.v0.1',
                $qa['ops_report_schema_version'] ?? null
            );
            $this->assertFalse((bool) data_get($qa, 'ops_metrics.ready_for_pilot', true));
            $this->assertFalse((bool) data_get($qa, 'ops_metrics.ready_for_runtime', true));
            $this->assertFalse((bool) data_get($qa, 'ops_metrics.ready_for_production', true));

            $opsReport = $this->readJson($runDir.'/ops_report_summary.json');
            $this->assertSame(
                'fap.big5.result_page_v2.asset_agent.ops_report.v0.1',
                $opsReport['schema_version'] ?? null
            );
            foreach ([
                'p0_blocker_count',
                'registry_gap_count',
                'module_gap_count',
                'scope_gap_count',
                'reading_mode_gap_count',
                'share_safety_missing_count',
                'norm_unavailable_missing',
                'low_quality_missing',
                'forbidden_leak_hit_count',
                'ready_for_pilot',
                'ready_for_runtime',
                'ready_for_production',
            ] as $metricKey) {
                $this->assertArrayHasKey($metricKey, (array) ($opsReport['metrics'] ?? []));
            }
            $this->assertSame('no_previous_run', data_get($opsReport, 'diff_summary.comparison_status'));

            $readiness = $this->readJson($runDir.'/readiness.json');
            $this->assertSame('fap.big5.result_page_agent.readiness.v0.1', $readiness['schema_version'] ?? null);
            $this->assertSame('big5_result_page_agent_readiness', $readiness['task'] ?? null);
            $this->assertSame('big_five', $readiness['scale'] ?? null);
            $this->assertSame('big5_result_page_v2', $readiness['source_contract'] ?? null);
            $this->assertSame('not_runtime', $readiness['runtime_use'] ?? null);
            $this->assertFalse((bool) ($readiness['production_use_allowed'] ?? true));
            $this->assertSame('ready_readonly', $readiness['current_readiness'] ?? null);
            $this->assertTrue((bool) ($readiness['readiness_pass'] ?? false));
            $this->assertFalse((bool) ($readiness['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($readiness['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($readiness['ready_for_production'] ?? true));
            $this->assertSame(0, (int) data_get($readiness, 'evidence_summary.share_safety_missing_count', -1));
            $this->assertSame(13, (int) data_get($readiness, 'evidence_summary.share_safe_reading_mode_count', 0));
            $this->assertSame(0, (int) data_get($readiness, 'evidence_summary.validation_error_count', -1));
            $this->assertSame(0, (int) data_get($readiness, 'evidence_summary.leak_hit_count', -1));
            $this->assertSame(0, (int) data_get($readiness, 'evidence_summary.p0_blocker_count', -1));
            $this->assertTrue((bool) data_get($readiness, 'handoff_contract.backend_authority'));
            $this->assertFalse((bool) data_get($readiness, 'handoff_contract.frontend_copy_allowed', true));
            $this->assertFalse((bool) data_get($readiness, 'handoff_contract.production_import_allowed', true));
            $this->assertFalse((bool) data_get($readiness, 'handoff_contract.rollout_allowed', true));
            $this->assertSame([], (array) ($readiness['strict_failures'] ?? ['unexpected']));
            $this->assertArrayHasKey('ops_report_summary.json', (array) ($readiness['source_artifacts'] ?? []));

            $readinessText = (string) file_get_contents($runDir.'/readiness.json');
            foreach ([
                'attempt_id',
                'private_url',
                'report_json',
                'report_full_json',
                'report_free_json',
                'raw score',
                'raw_score',
                'raw_scores',
                'percentile',
                'percentiles',
                'body_zh',
                '[object Object]',
            ] as $forbiddenToken) {
                $this->assertStringNotContainsString($forbiddenToken, $readinessText, $forbiddenToken);
            }

            $allArtifacts = implode("\n", array_map(
                static fn (string $filename): string => (string) file_get_contents($runDir.'/'.$filename),
                [
                    'input_inventory.json',
                    'validation_report.json',
                    'safety_report.json',
                    'qa_eval_summary.json',
                    'ops_report_summary.json',
                    'go_no_go.md',
                ]
            ));
            $this->assertStringContainsString('private_url', $allArtifacts);
            $this->assertStringContainsString('attempt_id', $allArtifacts);
            $this->assertStringNotContainsString(sys_get_temp_dir(), $allArtifacts);
            $this->assertStringNotContainsString('body_zh', $allArtifacts);
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_source_ledger_contract_fixes_source_labels_and_bfi2_copy_boundary(): void
    {
        $summary = app(BigFiveResultPageV2AssetAgent::class)->audit([
            'run_id' => 'source-ledger-contract',
            'artifact_dir' => $this->tempDir('big5-v2-agent-source-ledger'),
        ]);
        $artifactDir = (string) ($summary['artifact_dir'] ?? '');

        try {
            $inventory = $this->readJson($artifactDir.'/input_inventory.json');
            $sourceLedger = (array) data_get($inventory, 'source_ledger', []);

            $this->assertTrue((bool) ($sourceLedger['valid'] ?? false));
            $this->assertSame([], $sourceLedger['errors'] ?? ['unexpected']);
            $this->assertSame([
                'public_domain_source',
                'citation_only',
                'structure_reference_only',
                'forbidden_copy_source',
            ], $sourceLedger['allowed_source_labels'] ?? []);
            $this->assertSame(1, data_get($sourceLedger, 'label_counts.public_domain_source'));
            $this->assertGreaterThanOrEqual(1, (int) data_get($sourceLedger, 'label_counts.citation_only', 0));
            $this->assertGreaterThanOrEqual(1, (int) data_get($sourceLedger, 'label_counts.structure_reference_only', 0));
            $this->assertSame(1, data_get($sourceLedger, 'label_counts.forbidden_copy_source'));
            $this->assertTrue((bool) ($sourceLedger['bfi_2_policy_valid'] ?? false));

            foreach ([
                'ipip_official',
                'bfi_2_colby',
                'bigfive_web_github',
                'internal_big5_v2_formal_doc',
                'internal_big5_twenty_thousand_word_final_doc',
                'existing_big5_result_page_v2_asset_packs',
                'restricted_bfi2_item_text_and_proprietary_reports',
            ] as $sourceId) {
                $this->assertContains($sourceId, (array) ($sourceLedger['required_source_ids_present'] ?? []));
            }
        } finally {
            $this->deleteDirectory(dirname($artifactDir));
        }
    }

    public function test_audit_ops_report_compares_against_previous_run(): void
    {
        $artifactRoot = $this->tempDir('big5-v2-agent-ops-diff');

        try {
            app(BigFiveResultPageV2AssetAgent::class)->audit([
                'run_id' => 'run-a',
                'artifact_dir' => $artifactRoot,
            ]);
            app(BigFiveResultPageV2AssetAgent::class)->audit([
                'run_id' => 'run-b',
                'artifact_dir' => $artifactRoot,
            ]);

            $opsReport = $this->readJson($artifactRoot.'/run-b/ops_report_summary.json');
            $this->assertSame('compared', data_get($opsReport, 'diff_summary.comparison_status'));
            $this->assertSame('run-a', data_get($opsReport, 'diff_summary.previous_run_id'));
            $this->assertArrayHasKey(
                'p0_blocker_count',
                (array) data_get($opsReport, 'diff_summary.metric_deltas', [])
            );
            $this->assertSame(
                0,
                data_get($opsReport, 'diff_summary.metric_deltas.p0_blocker_count.delta')
            );
            $this->assertFalse((bool) data_get($opsReport, 'metrics.ready_for_production', true));
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_generate_candidates_writes_staging_only_selector_and_content_asset_drafts(): void
    {
        $artifactRoot = $this->tempDir('big5-v2-agent-candidates');

        try {
            $this->artisan('big5:result-page-v2-agent', [
                'action' => 'generate-candidates',
                '--run-id' => 'candidate-run',
                '--artifact-dir' => $artifactRoot,
                '--json' => true,
            ])->assertExitCode(0);

            $runDir = $artifactRoot.'/candidate-run';
            foreach ([
                'selector_asset_candidates.jsonl',
                'content_asset_candidates.jsonl',
                'candidate_generation_summary.json',
            ] as $filename) {
                $this->assertFileExists($runDir.'/'.$filename);
            }

            $selectorCandidates = $this->readJsonl($runDir.'/selector_asset_candidates.jsonl');
            $contentCandidates = $this->readJsonl($runDir.'/content_asset_candidates.jsonl');
            $summary = $this->readJson($runDir.'/candidate_generation_summary.json');

            $this->assertCount(1, $selectorCandidates);
            $this->assertCount(1, $contentCandidates);
            $this->assertSame('staging_only', $summary['runtime_use'] ?? null);
            $this->assertFalse((bool) ($summary['production_use_allowed'] ?? true));
            $this->assertFalse((bool) ($summary['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($summary['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($summary['ready_for_production'] ?? true));
            $this->assertSame('pass', data_get($summary, 'validation.status'));
            $this->assertSame(0, data_get($summary, 'validation.error_count'));
            $this->assertSame('pass', data_get($summary, 'leak_scan.status'));
            $this->assertSame(0, data_get($summary, 'leak_scan.hit_count'));
            $this->assertTrue((bool) data_get($summary, 'source_ledger.valid'));
            $this->assertTrue((bool) data_get($summary, 'source_ledger.bfi_2_policy_valid'));

            $selector = $selectorCandidates[0];
            $this->assertSame('draft', $selector['review_status'] ?? null);
            $this->assertFalse((bool) ($selector['shareable'] ?? true));
            $this->assertSame('staging_only', data_get($selector, 'public_payload.runtime_use'));
            $this->assertFalse((bool) data_get($selector, 'public_payload.production_use_allowed', true));

            $content = $contentCandidates[0];
            $this->assertSame('draft', $content['qa_status'] ?? null);
            $this->assertSame('staging_only', $content['runtime_use'] ?? null);
            $this->assertFalse((bool) ($content['production_use_allowed'] ?? true));
            $this->assertFalse((bool) ($content['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) data_get($content, 'source_trace.bfi_2_copy_used', true));

            $allArtifacts = implode("\n", array_map(
                static fn (string $filename): string => (string) file_get_contents($runDir.'/'.$filename),
                [
                    'selector_asset_candidates.jsonl',
                    'content_asset_candidates.jsonl',
                    'candidate_generation_summary.json',
                ]
            ));
            foreach ([
                'private_url',
                'attempt_id',
                'raw_score',
                'percentile',
                'fixed_type',
                'user_confirmed_type',
                'type_code',
            ] as $forbiddenToken) {
                $this->assertStringNotContainsString($forbiddenToken, $allArtifacts);
            }
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_plan_pr_writes_dry_run_orchestration_artifacts_without_git_or_github_mutation(): void
    {
        $artifactRoot = base_path('artifacts/big5_result_page_v2_agent/unit-orchestrator');

        $this->deleteDirectory($artifactRoot);

        try {
            app(BigFiveResultPageV2AssetAgent::class)->generateCandidates([
                'run_id' => 'candidate-source',
                'artifact_dir' => $artifactRoot,
            ]);

            $this->artisan('big5:result-page-v2-agent', [
                'action' => 'plan-pr',
                '--run-id' => 'orchestrator-plan',
                '--artifact-dir' => $artifactRoot,
                '--source-run-dir' => $artifactRoot.'/candidate-source',
                '--pr-id' => 'B5-RESULT-CANDIDATE-BATCH-DRY-RUN-01',
                '--branch' => 'codex/big5-v2-candidate-batch-dry-run',
                '--title' => 'B5-RESULT-CANDIDATE-BATCH-DRY-RUN-01: Big Five V2 candidate batch dry run',
                '--json' => true,
            ])->assertExitCode(0);

            $runDir = $artifactRoot.'/orchestrator-plan';
            foreach ([
                'auto_pr_orchestration_plan.json',
                'auto_pr_scope_validation.json',
                'auto_pr_body.md',
            ] as $filename) {
                $this->assertFileExists($runDir.'/'.$filename);
            }

            $plan = $this->readJson($runDir.'/auto_pr_orchestration_plan.json');
            $scope = $this->readJson($runDir.'/auto_pr_scope_validation.json');
            $body = (string) file_get_contents($runDir.'/auto_pr_body.md');

            $this->assertSame('auto_pr_orchestrator_plan', $plan['task'] ?? null);
            $this->assertSame('not_runtime', $plan['runtime_use'] ?? null);
            $this->assertFalse((bool) ($plan['production_use_allowed'] ?? true));
            $this->assertFalse((bool) ($plan['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($plan['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($plan['ready_for_production'] ?? true));
            $this->assertSame('dry_run_artifact_only', $plan['execution_mode'] ?? null);
            $this->assertSame('codex/big5-v2-candidate-batch-dry-run', data_get($plan, 'pr.branch'));
            $this->assertSame('candidate_generation', data_get($plan, 'source_run.artifact_kind'));
            $this->assertTrue((bool) data_get($plan, 'source_run.valid'));
            $this->assertGreaterThan(0, (int) data_get($plan, 'scope_validation.planned_changed_file_count'));
            $this->assertTrue((bool) data_get($plan, 'scope_validation.valid'));
            $this->assertTrue((bool) ($scope['valid'] ?? false));

            foreach ([
                'git_branch_created',
                'git_commit_created',
                'github_pr_created',
                'github_checks_polled',
                'auto_merge_performed',
                'runtime_flag_change',
                'production_import_gate_change',
                'rollout_gate_change',
            ] as $guarantee) {
                $this->assertFalse((bool) data_get($plan, "negative_guarantees.{$guarantee}", true), $guarantee);
            }

            $this->assertStringContainsString('No frontend copy added.', $body);
            $this->assertStringContainsString('No production import, release snapshot, rollout gate, or runtime flag changed.', $body);
            $this->assertStringContainsString('Legacy `big5_report_engine_v2` remains fallback only.', $body);

            $allArtifacts = implode("\n", [
                (string) file_get_contents($runDir.'/auto_pr_orchestration_plan.json'),
                (string) file_get_contents($runDir.'/auto_pr_scope_validation.json'),
                $body,
            ]);
            foreach (['private_url', 'attempt_id', 'raw_score', 'percentile', 'fixed_type', 'user_confirmed_type', 'type_code'] as $forbiddenToken) {
                $this->assertStringNotContainsString($forbiddenToken, $allArtifacts);
            }
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_execute_github_mutation_simulates_pr_plan_without_live_mutation(): void
    {
        $artifactRoot = base_path('artifacts/big5_result_page_v2_agent/unit-github-mutation-simulate');

        $this->deleteDirectory($artifactRoot);

        try {
            app(BigFiveResultPageV2AssetAgent::class)->generateCandidates([
                'run_id' => 'candidate-source',
                'artifact_dir' => $artifactRoot,
            ]);
            app(BigFiveResultPageV2AssetAgent::class)->planPr([
                'run_id' => 'orchestrator-plan',
                'artifact_dir' => $artifactRoot,
                'source_run_dir' => $artifactRoot.'/candidate-source',
                'pr_id' => 'B5-RESULT-CANDIDATE-BATCH-SIMULATE-01',
                'branch' => 'codex/big5-v2-candidate-batch-simulate',
                'title' => 'B5-RESULT-CANDIDATE-BATCH-SIMULATE-01: Big Five V2 candidate batch simulate',
            ]);

            $this->artisan('big5:result-page-v2-agent', [
                'action' => 'execute-github-mutation',
                '--run-id' => 'github-mutation',
                '--artifact-dir' => $artifactRoot,
                '--execution-plan-json' => $artifactRoot.'/orchestrator-plan/auto_pr_orchestration_plan.json',
                '--repo-root' => dirname(base_path()),
                '--github-repo' => 'fermatmind/fap-api',
                '--mutation-mode' => 'simulate',
                '--json' => true,
            ])->assertExitCode(0);

            $runDir = $artifactRoot.'/github-mutation';
            $this->assertFileExists($runDir.'/github_mutation_execution_report.json');
            $this->assertFileExists($runDir.'/repair_log.json');

            $report = $this->readJson($runDir.'/github_mutation_execution_report.json');
            $this->assertSame('github_mutation_execution_runner', $report['task'] ?? null);
            $this->assertSame('not_runtime', $report['runtime_use'] ?? null);
            $this->assertFalse((bool) ($report['production_use_allowed'] ?? true));
            $this->assertSame('auto_pr_orchestrator_plan', $report['source_plan_task'] ?? null);
            $this->assertSame('simulate', $report['mutation_mode'] ?? null);
            $this->assertFalse((bool) ($report['allow_github_mutation'] ?? true));
            $this->assertFalse((bool) ($report['live_execution_performed'] ?? true));
            $this->assertTrue((bool) data_get($report, 'preflight.valid'));
            $this->assertSame([], data_get($report, 'preflight.blockers'));
            $this->assertSame(8, (int) ($report['step_count'] ?? 0));
            $this->assertFalse((bool) data_get($report, 'steps.0.executed', true));

            foreach ([
                'git_branch_created',
                'git_commit_created',
                'github_pr_created',
                'github_merge_performed',
                'auto_merge_performed',
                'runtime_flag_change',
                'production_import_gate_change',
                'rollout_gate_change',
            ] as $guarantee) {
                $this->assertFalse((bool) data_get($report, "negative_guarantees.{$guarantee}", true), $guarantee);
            }

            $allArtifacts = implode("\n", [
                (string) file_get_contents($runDir.'/github_mutation_execution_report.json'),
                (string) file_get_contents($runDir.'/repair_log.json'),
            ]);
            foreach ([
                sys_get_temp_dir(),
                'private_url',
                'attempt_id',
                'raw_score',
                'percentile',
                'fixed_type',
                'user_confirmed_type',
                'type_code',
            ] as $forbiddenToken) {
                $this->assertStringNotContainsString($forbiddenToken, $allArtifacts);
            }
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_execute_github_mutation_rejects_live_mode_without_explicit_authorization(): void
    {
        $artifactRoot = base_path('artifacts/big5_result_page_v2_agent/unit-github-mutation-live-blocked');

        $this->deleteDirectory($artifactRoot);

        try {
            app(BigFiveResultPageV2AssetAgent::class)->generateCandidates([
                'run_id' => 'candidate-source',
                'artifact_dir' => $artifactRoot,
            ]);
            app(BigFiveResultPageV2AssetAgent::class)->planPr([
                'run_id' => 'orchestrator-plan',
                'artifact_dir' => $artifactRoot,
                'source_run_dir' => $artifactRoot.'/candidate-source',
                'pr_id' => 'B5-RESULT-CANDIDATE-BATCH-LIVE-BLOCKED-01',
                'branch' => 'codex/big5-v2-candidate-batch-live-blocked',
                'title' => 'B5-RESULT-CANDIDATE-BATCH-LIVE-BLOCKED-01: Big Five V2 candidate batch live blocked',
            ]);

            $this->artisan('big5:result-page-v2-agent', [
                'action' => 'execute-github-mutation',
                '--run-id' => 'github-mutation',
                '--artifact-dir' => $artifactRoot,
                '--execution-plan-json' => $artifactRoot.'/orchestrator-plan/auto_pr_orchestration_plan.json',
                '--repo-root' => dirname(base_path()),
                '--github-repo' => 'fermatmind/fap-api',
                '--mutation-mode' => 'live',
                '--json' => true,
            ])->assertExitCode(1);

            $report = $this->readJson($artifactRoot.'/github-mutation/github_mutation_execution_report.json');
            $this->assertSame('live', $report['mutation_mode'] ?? null);
            $this->assertFalse((bool) ($report['allow_github_mutation'] ?? true));
            $this->assertFalse((bool) ($report['live_execution_performed'] ?? true));
            $this->assertFalse((bool) data_get($report, 'preflight.valid', true));
            $this->assertContains('live_github_mutation_not_allowed', (array) data_get($report, 'preflight.blockers', []));
            $this->assertSame(8, (int) ($report['step_count'] ?? 0));
            $this->assertFalse((bool) data_get($report, 'steps.0.executed', true));
            $this->assertFalse((bool) data_get($report, 'negative_guarantees.github_pr_created', true));
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_inspect_ci_classifies_failures_and_only_plans_mechanical_fixes(): void
    {
        $artifactRoot = base_path('artifacts/big5_result_page_v2_agent/unit-ci-inspector');

        $this->deleteDirectory($artifactRoot);
        mkdir($artifactRoot, 0777, true);

        try {
            $checksPath = $artifactRoot.'/checks.json';
            file_put_contents($checksPath, json_encode([
                'statusCheckRollup' => [
                    [
                        'name' => 'hygiene',
                        'status' => 'COMPLETED',
                        'conclusion' => 'FAILURE',
                    ],
                    [
                        'name' => 'content-pack-build-validate',
                        'status' => 'COMPLETED',
                        'conclusion' => 'FAILURE',
                    ],
                    [
                        'name' => 'Semgrep blocking secrets',
                        'status' => 'COMPLETED',
                        'conclusion' => 'FAILURE',
                    ],
                    [
                        'name' => 'verify-bigfive',
                        'status' => 'COMPLETED',
                        'conclusion' => 'SUCCESS',
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

            $this->artisan('big5:result-page-v2-agent', [
                'action' => 'inspect-ci',
                '--run-id' => 'ci-inspection',
                '--artifact-dir' => $artifactRoot,
                '--checks-json' => $checksPath,
                '--json' => true,
            ])->assertExitCode(0);

            $runDir = $artifactRoot.'/ci-inspection';
            foreach ([
                'ci_inspection_report.json',
                'mechanical_fix_plan.json',
                'repair_log.json',
            ] as $filename) {
                $this->assertFileExists($runDir.'/'.$filename);
            }

            $report = $this->readJson($runDir.'/ci_inspection_report.json');
            $fixPlan = $this->readJson($runDir.'/mechanical_fix_plan.json');

            $this->assertSame('ci_inspector', $report['task'] ?? null);
            $this->assertSame('not_runtime', $report['runtime_use'] ?? null);
            $this->assertFalse((bool) ($report['production_use_allowed'] ?? true));
            $this->assertSame(4, (int) ($report['check_count'] ?? 0));
            $this->assertSame(3, (int) ($report['failed_check_count'] ?? 0));
            $this->assertSame(2, (int) ($report['mechanical_fix_candidate_count'] ?? 0));
            $this->assertSame(1, (int) ($report['blocked_failure_count'] ?? 0));

            $this->assertSame('mechanical_fix_plan', $fixPlan['task'] ?? null);
            $this->assertFalse((bool) ($fixPlan['apply_requested'] ?? true));
            $this->assertFalse((bool) ($fixPlan['apply_performed'] ?? true));
            $this->assertCount(2, (array) ($fixPlan['candidates'] ?? []));
            $this->assertCount(1, (array) ($fixPlan['blocked_failures'] ?? []));
            $this->assertSame('security', data_get($fixPlan, 'blocked_failures.0.failure_class'));

            foreach ([
                'github_checks_read_live',
                'git_branch_created',
                'git_commit_created',
                'github_pr_created',
                'mechanical_fix_apply_performed',
                'auto_merge_performed',
                'runtime_flag_change',
                'production_import_gate_change',
                'rollout_gate_change',
            ] as $guarantee) {
                $this->assertFalse((bool) data_get($report, "negative_guarantees.{$guarantee}", true), $guarantee);
            }

            $allArtifacts = implode("\n", [
                (string) file_get_contents($runDir.'/ci_inspection_report.json'),
                (string) file_get_contents($runDir.'/mechanical_fix_plan.json'),
                (string) file_get_contents($runDir.'/repair_log.json'),
            ]);
            foreach (['private_url', 'attempt_id', 'raw_score', 'percentile', 'fixed_type', 'user_confirmed_type', 'type_code'] as $forbiddenToken) {
                $this->assertStringNotContainsString($forbiddenToken, $allArtifacts);
            }
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_inspect_ci_applies_only_explicit_artifact_mechanical_fixes(): void
    {
        $artifactRoot = base_path('artifacts/big5_result_page_v2_agent/unit-ci-mechanical-apply');

        $this->deleteDirectory($artifactRoot);
        mkdir($artifactRoot, 0777, true);

        try {
            $targetRelativePath = 'artifacts/big5_result_page_v2_agent/unit-ci-mechanical-apply/malformed.json';
            $targetPath = base_path($targetRelativePath);
            file_put_contents($targetPath, '{"z":2,"a":1}');

            $checksPath = $artifactRoot.'/checks.json';
            file_put_contents($checksPath, json_encode([
                'statusCheckRollup' => [
                    [
                        'name' => 'hygiene format',
                        'status' => 'COMPLETED',
                        'conclusion' => 'FAILURE',
                        'mechanical_fix' => [
                            'action' => 'format_json',
                            'target_path' => $targetRelativePath,
                        ],
                    ],
                    [
                        'name' => 'Semgrep blocking secrets',
                        'status' => 'COMPLETED',
                        'conclusion' => 'FAILURE',
                        'mechanical_fix' => [
                            'action' => 'format_json',
                            'target_path' => $targetRelativePath,
                        ],
                    ],
                    [
                        'name' => 'verify-bigfive',
                        'status' => 'COMPLETED',
                        'conclusion' => 'SUCCESS',
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

            $this->artisan('big5:result-page-v2-agent', [
                'action' => 'inspect-ci',
                '--run-id' => 'mechanical-fix',
                '--artifact-dir' => $artifactRoot,
                '--checks-json' => $checksPath,
                '--apply-mechanical-fixes' => true,
                '--json' => true,
            ])->assertExitCode(0);

            $runDir = $artifactRoot.'/mechanical-fix';
            $this->assertFileExists($runDir.'/ci_inspection_report.json');
            $this->assertFileExists($runDir.'/mechanical_fix_plan.json');
            $this->assertFileExists($runDir.'/repair_log.json');

            $formatted = (string) file_get_contents($targetPath);
            $this->assertStringContainsString(PHP_EOL, $formatted);
            $this->assertSame(['z' => 2, 'a' => 1], $this->readJson($targetPath));

            $report = $this->readJson($runDir.'/ci_inspection_report.json');
            $fixPlan = $this->readJson($runDir.'/mechanical_fix_plan.json');
            $repairLog = $this->readJson($runDir.'/repair_log.json');

            $this->assertSame(3, (int) ($report['check_count'] ?? 0));
            $this->assertSame(2, (int) ($report['failed_check_count'] ?? 0));
            $this->assertSame(1, (int) ($report['mechanical_fix_candidate_count'] ?? 0));
            $this->assertSame(1, (int) ($report['blocked_failure_count'] ?? 0));
            $this->assertTrue((bool) data_get($report, 'mechanical_fix_application.apply_requested'));
            $this->assertTrue((bool) data_get($report, 'mechanical_fix_application.apply_performed'));
            $this->assertSame(1, (int) data_get($report, 'mechanical_fix_application.applied_fix_count', 0));
            $this->assertSame(0, (int) data_get($report, 'mechanical_fix_application.rejected_fix_count', -1));
            $this->assertSame('format_json', data_get($report, 'mechanical_fix_application.applied_fixes.0.action'));
            $this->assertSame([$targetRelativePath], [
                data_get($report, 'mechanical_fix_application.applied_fixes.0.target_path'),
            ]);

            $this->assertTrue((bool) ($fixPlan['apply_requested'] ?? false));
            $this->assertTrue((bool) ($fixPlan['apply_performed'] ?? false));
            $this->assertSame('security', data_get($fixPlan, 'blocked_failures.0.failure_class'));
            $this->assertStringContainsString(
                'hygiene format: applied format_json',
                implode("\n", (array) ($repairLog['entries'] ?? []))
            );

            foreach ([
                'database_write',
                'cms_write',
                'content_assets_write',
                'frontend_copy_write',
                'final_result_payload_generation',
                'runtime_flag_change',
                'production_import_gate_change',
                'rollout_gate_change',
                'github_checks_read_live',
                'git_branch_created',
                'git_commit_created',
                'github_pr_created',
                'auto_merge_performed',
            ] as $guarantee) {
                $this->assertFalse((bool) data_get($report, "negative_guarantees.{$guarantee}", true), $guarantee);
            }
            $this->assertTrue((bool) data_get($report, 'negative_guarantees.mechanical_fix_apply_requested'));
            $this->assertTrue((bool) data_get($report, 'negative_guarantees.mechanical_fix_apply_performed'));

            $allArtifacts = implode("\n", [
                (string) file_get_contents($runDir.'/ci_inspection_report.json'),
                (string) file_get_contents($runDir.'/mechanical_fix_plan.json'),
                (string) file_get_contents($runDir.'/repair_log.json'),
            ]);
            foreach (['private_url', 'attempt_id', 'raw_score', 'percentile', 'fixed_type', 'user_confirmed_type', 'type_code'] as $forbiddenToken) {
                $this->assertStringNotContainsString($forbiddenToken, $allArtifacts);
            }
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_poll_github_checks_writes_redacted_rollup_without_mutation(): void
    {
        $artifactRoot = base_path('artifacts/big5_result_page_v2_agent/unit-check-poller');

        $this->deleteDirectory($artifactRoot);
        mkdir($artifactRoot, 0777, true);

        try {
            $statePath = $artifactRoot.'/pr-state.json';
            file_put_contents($statePath, json_encode([
                'number' => 2266,
                'url' => 'https://github.com/fermatmind/fap-api/pull/2266',
                'headRefName' => 'codex/big5-v2-live-dry-run-artifact',
                'headRefOid' => 'abc123',
                'state' => 'OPEN',
                'isDraft' => false,
                'mergeStateStatus' => 'UNSTABLE',
                'reviewDecision' => '',
                'statusCheckRollup' => [
                    [
                        'name' => 'hygiene',
                        'status' => 'COMPLETED',
                        'conclusion' => 'SUCCESS',
                    ],
                    [
                        'name' => 'verify-bigfive',
                        'status' => 'IN_PROGRESS',
                        'conclusion' => '',
                    ],
                    [
                        'name' => 'Semgrep blocking secrets',
                        'status' => 'COMPLETED',
                        'conclusion' => 'FAILURE',
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

            $this->artisan('big5:result-page-v2-agent', [
                'action' => 'poll-github-checks',
                '--run-id' => 'check-poller',
                '--artifact-dir' => $artifactRoot,
                '--pr-state-json' => $statePath,
                '--json' => true,
            ])->assertExitCode(0);

            $runDir = $artifactRoot.'/check-poller';
            foreach ([
                'github_check_poll_report.json',
                'status_check_rollup.redacted.json',
                'repair_log.json',
            ] as $filename) {
                $this->assertFileExists($runDir.'/'.$filename);
            }

            $report = $this->readJson($runDir.'/github_check_poll_report.json');
            $rollup = $this->readJson($runDir.'/status_check_rollup.redacted.json');
            $repairLog = $this->readJson($runDir.'/repair_log.json');

            $this->assertSame('github_check_poller', $report['task'] ?? null);
            $this->assertSame('not_runtime', $report['runtime_use'] ?? null);
            $this->assertFalse((bool) ($report['production_use_allowed'] ?? true));
            $this->assertSame('exported_pr_state_json', $report['source'] ?? null);
            $this->assertSame('2266', data_get($report, 'pr.number'));
            $this->assertSame('UNSTABLE', data_get($report, 'pr.merge_state_status'));
            $this->assertSame(3, (int) data_get($report, 'required_check_summary.check_count', 0));
            $this->assertSame(1, (int) data_get($report, 'required_check_summary.pending_check_count', 0));
            $this->assertSame(1, (int) data_get($report, 'required_check_summary.failed_check_count', 0));
            $this->assertSame(1, (int) data_get($report, 'required_check_summary.passed_check_count', 0));
            $this->assertSame('failed_checks', data_get($report, 'required_check_summary.blocking_status'));
            $this->assertFalse((bool) data_get($report, 'required_check_summary.all_green', true));
            $this->assertSame(1, (int) data_get($report, 'sidecar_blocker_classification.sidecar_candidate_count', 0));
            $this->assertSame('security', data_get($report, 'sidecar_blocker_classification.candidates.0.failure_class'));
            $this->assertTrue((bool) data_get($report, 'sidecar_blocker_classification.candidates.0.sidecar_candidate'));

            $this->assertSame('github_check_poll_rollup', $rollup['task'] ?? null);
            $this->assertCount(3, (array) ($rollup['checks'] ?? []));
            $this->assertTrue((bool) ($repairLog['repair_required'] ?? false));

            foreach ([
                'github_checks_read_live',
                'github_checks_mutation',
                'git_branch_created',
                'git_commit_created',
                'github_pr_created',
                'github_pr_mutation',
                'github_merge_performed',
                'remote_branch_deleted',
                'local_branch_deleted',
                'local_main_synced',
                'post_merge_revalidation_run',
                'auto_merge_performed',
                'runtime_flag_change',
                'production_import_gate_change',
                'rollout_gate_change',
            ] as $guarantee) {
                $this->assertFalse((bool) data_get($report, "negative_guarantees.{$guarantee}", true), $guarantee);
            }

            $allArtifacts = implode("\n", [
                (string) file_get_contents($runDir.'/github_check_poll_report.json'),
                (string) file_get_contents($runDir.'/status_check_rollup.redacted.json'),
                (string) file_get_contents($runDir.'/repair_log.json'),
            ]);
            foreach ([
                'private_url',
                'attempt_id',
                'raw_score',
                'percentile',
                'fixed_type',
                'user_confirmed_type',
                'type_code',
            ] as $forbiddenToken) {
                $this->assertStringNotContainsString($forbiddenToken, $allArtifacts);
            }
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_plan_merge_cleanup_requires_clean_green_pr_and_does_not_execute_merge(): void
    {
        $artifactRoot = base_path('artifacts/big5_result_page_v2_agent/unit-merge-cleanup');

        $this->deleteDirectory($artifactRoot);
        mkdir($artifactRoot, 0777, true);

        try {
            $statePath = $artifactRoot.'/pr-state.json';
            file_put_contents($statePath, json_encode([
                'number' => 2250,
                'headRefName' => 'codex/big5-v2-ci-inspector-mechanical-fixer',
                'state' => 'OPEN',
                'isDraft' => false,
                'mergeStateStatus' => 'CLEAN',
                'reviewDecision' => '',
                'statusCheckRollup' => [
                    [
                        'name' => 'hygiene',
                        'status' => 'COMPLETED',
                        'conclusion' => 'SUCCESS',
                    ],
                    [
                        'name' => 'verify-bigfive',
                        'status' => 'COMPLETED',
                        'conclusion' => 'SUCCESS',
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

            $this->artisan('big5:result-page-v2-agent', [
                'action' => 'plan-merge-cleanup',
                '--run-id' => 'merge-cleanup',
                '--artifact-dir' => $artifactRoot,
                '--pr-state-json' => $statePath,
                '--json' => true,
            ])->assertExitCode(0);

            $runDir = $artifactRoot.'/merge-cleanup';
            $this->assertFileExists($runDir.'/auto_merge_cleanup_plan.json');
            $this->assertFileExists($runDir.'/repair_log.json');

            $plan = $this->readJson($runDir.'/auto_merge_cleanup_plan.json');
            $this->assertSame('auto_merge_cleanup_plan', $plan['task'] ?? null);
            $this->assertSame('not_runtime', $plan['runtime_use'] ?? null);
            $this->assertFalse((bool) ($plan['production_use_allowed'] ?? true));
            $this->assertTrue((bool) data_get($plan, 'gate.can_merge'));
            $this->assertSame([], data_get($plan, 'gate.blockers'));
            $this->assertSame(0, (int) data_get($plan, 'gate.pending_check_count', -1));
            $this->assertSame(0, (int) data_get($plan, 'gate.failed_check_count', -1));
            $this->assertContains('gh pr merge 2250 --squash --delete-branch', (array) ($plan['planned_commands'] ?? []));

            foreach ([
                'github_merge_performed',
                'remote_branch_deleted',
                'local_branch_deleted',
                'local_main_synced',
                'post_merge_revalidation_run',
                'auto_merge_performed',
                'runtime_flag_change',
                'production_import_gate_change',
                'rollout_gate_change',
            ] as $guarantee) {
                $this->assertFalse((bool) data_get($plan, "negative_guarantees.{$guarantee}", true), $guarantee);
            }
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_weekly_ops_writes_redacted_ops_and_smoke_report(): void
    {
        $artifactRoot = base_path('artifacts/big5_result_page_v2_agent/unit-weekly-ops');

        $this->deleteDirectory($artifactRoot);

        try {
            $this->artisan('big5:result-page-v2-agent', [
                'action' => 'weekly-ops',
                '--run-id' => 'weekly',
                '--artifact-dir' => $artifactRoot,
                '--ops-source' => 'contract',
                '--json' => true,
            ])->assertExitCode(0);

            $runDir = $artifactRoot.'/weekly';
            $this->assertFileExists($runDir.'/weekly_ops_report.json');
            $this->assertFileExists($runDir.'/weekly_ops_report.md');

            $report = $this->readJson($runDir.'/weekly_ops_report.json');
            $this->assertSame('weekly_ops_runner', $report['task'] ?? null);
            $this->assertSame('contract', $report['ops_source'] ?? null);
            $this->assertSame('not_runtime', $report['runtime_use'] ?? null);
            $this->assertFalse((bool) ($report['production_use_allowed'] ?? true));
            $this->assertTrue((bool) ($report['production_ops_reporting_ready'] ?? false));
            $this->assertFalse((bool) ($report['production_rollout_enabled'] ?? true));
            $this->assertSame('count_and_rate_only', data_get($report, 'metrics_contract.v2_payload_coverage_rate.redaction'));
            $this->assertSame('count_and_rate_only', data_get($report, 'metrics_contract.fallback_hit_rate.redaction'));
            $this->assertSame('enum_counts_only', data_get($report, 'metrics_contract.malformed_rejection_reasons.redaction'));
            $this->assertSame('must_not_appear', data_get($report, 'smoke_contract.pdf_private_link_check'));
            $this->assertSame('must_not_expose_internal_tokens', data_get($report, 'smoke_contract.footer_check'));
            $this->assertGreaterThanOrEqual(10, (int) data_get($report, 'smoke_contract.forbidden_public_text_token_count', 0));

            foreach ([
                'stores_real_attempt_identifier',
                'stores_private_link',
                'stores_pdf_file',
                'stores_raw_report_body',
                'stores_user_score_values',
                'runtime_flag_change',
                'production_import_gate_change',
                'rollout_gate_change',
            ] as $guarantee) {
                $this->assertFalse((bool) data_get($report, "negative_guarantees.{$guarantee}", true), $guarantee);
            }
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_weekly_ops_defaults_to_report_snapshots_metrics_without_sensitive_fields(): void
    {
        $artifactRoot = base_path('artifacts/big5_result_page_v2_agent/unit-weekly-ops-report-snapshots');
        $attemptIds = $this->seedBigFiveOpsSnapshotRows();

        $this->deleteDirectory($artifactRoot);

        try {
            $this->artisan('big5:result-page-v2-agent', [
                'action' => 'weekly-ops',
                '--run-id' => 'weekly',
                '--artifact-dir' => $artifactRoot,
                '--window-days' => 45,
                '--json' => true,
            ])->assertExitCode(0);

            $runDir = $artifactRoot.'/weekly';
            $report = $this->readJson($runDir.'/weekly_ops_report.json');
            $markdown = (string) file_get_contents($runDir.'/weekly_ops_report.md');

            $this->assertSame('report_snapshots', $report['ops_source'] ?? null);
            $this->assertSame('report_snapshots', $report['metrics_source'] ?? null);
            $this->assertSame('ready', $report['metrics_query_status'] ?? null);
            $this->assertSame(45, (int) ($report['reporting_window_days'] ?? 0));
            $this->assertSame('not_runtime', $report['runtime_use'] ?? null);
            $this->assertFalse((bool) ($report['production_use_allowed'] ?? true));
            $this->assertFalse((bool) ($report['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($report['ready_for_production'] ?? true));

            $this->assertSame(6, data_get($report, 'production_metrics.total_big5_reports'));
            $this->assertSame(2, data_get($report, 'production_metrics.attached_count'));
            $this->assertSame(2, data_get($report, 'production_metrics.fallback_count'));
            $this->assertSame(1, data_get($report, 'production_metrics.invalid_count'));
            $this->assertSame(1, data_get($report, 'production_metrics.disabled_or_not_evaluated_count'));
            $this->assertSame('33.3%', data_get($report, 'production_metrics.v2_payload_coverage_rate'));
            $this->assertSame('50.0%', data_get($report, 'production_metrics.fallback_hit_rate'));
            $this->assertSame(3, data_get($report, 'production_metrics.validation_error_count'));
            $this->assertIsString(data_get($report, 'production_metrics.latest_audited_at'));
            $this->assertSame([
                'payload_validation_failed' => 1,
            ], data_get($report, 'production_metrics.malformed_rejection_reasons'));
            $this->assertSame([
                'locked_or_free_preview' => 1,
                'production_rollout_denied' => 1,
            ], data_get($report, 'production_metrics.fallback_reasons'));
            $this->assertSame('not_returned', data_get($report, 'metric_redaction.report_body_fields'));
            $this->assertStringContainsString('total_big5_reports: 6', $markdown);
            $this->assertStringContainsString('malformed_rejection_reasons: {"payload_validation_failed":1}', $markdown);

            $allArtifacts = (string) file_get_contents($runDir.'/weekly_ops_report.json')."\n".$markdown;
            foreach ([
                'attempt_id',
                'private_url',
                'report_json',
                'report_full_json',
                'report_free_json',
                'raw scores',
                'raw_score',
                'raw_scores',
                'shareable percentiles',
                '[object Object]',
            ] as $forbiddenToken) {
                $this->assertStringNotContainsString($forbiddenToken, $allArtifacts, $forbiddenToken);
            }
        } finally {
            DB::table('report_snapshots')->whereIn('attempt_id', $attemptIds)->delete();
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_stage_candidates_fails_closed_without_human_review_manifest(): void
    {
        $artifactRoot = $this->tempDir('big5-v2-agent-stage-missing-review');
        $stagingDir = $artifactRoot.'/staging-package';

        try {
            app(BigFiveResultPageV2AssetAgent::class)->generateCandidates([
                'run_id' => 'candidate-run',
                'artifact_dir' => $artifactRoot,
            ]);

            $this->artisan('big5:result-page-v2-agent', [
                'action' => 'stage-candidates',
                '--run-id' => 'stage-run',
                '--artifact-dir' => $artifactRoot,
                '--candidate-dir' => $artifactRoot.'/candidate-run',
                '--staging-output-dir' => $stagingDir,
                '--allow-staging-write' => true,
                '--json' => true,
            ])->assertExitCode(1);

            $summary = $this->readJson($artifactRoot.'/stage-run/staging_import_summary.json');
            $this->assertFalse((bool) ($summary['ok'] ?? true));
            $this->assertFalse((bool) ($summary['staging_write_performed'] ?? true));
            $this->assertStringContainsString(
                'review_manifest.json missing',
                implode("\n", (array) data_get($summary, 'repair_log.entries', []))
            );
            $this->assertFileDoesNotExist($stagingDir.'/staging_import_manifest.json');
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_stage_candidates_imports_only_reviewed_candidates_to_staging_package(): void
    {
        $artifactRoot = $this->tempDir('big5-v2-agent-stage-reviewed');
        $candidateDir = $artifactRoot.'/candidate-run';
        $stagingDir = $artifactRoot.'/content_assets/big5/result_page_v2/staging_candidate_imports/reviewed-run';

        try {
            app(BigFiveResultPageV2AssetAgent::class)->generateCandidates([
                'run_id' => 'candidate-run',
                'artifact_dir' => $artifactRoot,
            ]);
            file_put_contents($candidateDir.'/review_manifest.json', json_encode([
                'schema_version' => 'fap.big5.result_page_v2.staging_review_manifest.v0.1',
                'human_reviewed' => true,
                'review_status' => 'approved_for_staging',
                'runtime_use' => 'staging_only',
                'production_use_allowed' => false,
                'ready_for_pilot' => false,
                'reviewed_by' => 'unit_test_editorial_gate',
                'reviewed_at' => '2026-06-21T00:00:00Z',
                'approved_candidate_files' => [
                    'selector_asset_candidates.jsonl',
                    'content_asset_candidates.jsonl',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

            $this->artisan('big5:result-page-v2-agent', [
                'action' => 'stage-candidates',
                '--run-id' => 'stage-run',
                '--artifact-dir' => $artifactRoot,
                '--candidate-dir' => $candidateDir,
                '--staging-output-dir' => $stagingDir,
                '--allow-staging-write' => true,
                '--json' => true,
            ])->assertExitCode(0);

            foreach ([
                'selector_asset_candidates.staging.jsonl',
                'content_asset_candidates.staging.jsonl',
                'staging_import_manifest.json',
                'staging_import_validation_report.json',
                'repair_log.json',
            ] as $filename) {
                $this->assertFileExists($stagingDir.'/'.$filename);
            }

            $summary = $this->readJson($artifactRoot.'/stage-run/staging_import_summary.json');
            $validation = $this->readJson($stagingDir.'/staging_import_validation_report.json');
            $manifest = $this->readJson($stagingDir.'/staging_import_manifest.json');

            $this->assertTrue((bool) ($summary['ok'] ?? false));
            $this->assertTrue((bool) ($summary['staging_write_performed'] ?? false));
            $this->assertTrue((bool) ($validation['staging_write_performed'] ?? false));
            $this->assertSame('pass', data_get($validation, 'candidate_validation.status'));
            $this->assertSame('pass', data_get($validation, 'leak_scan.status'));
            $this->assertSame('staging_only', $manifest['runtime_use'] ?? null);
            $this->assertFalse((bool) ($manifest['production_use_allowed'] ?? true));
            $this->assertFalse((bool) ($manifest['ready_for_pilot'] ?? true));

            $allStagingArtifacts = implode("\n", array_map(
                static fn (string $filename): string => (string) file_get_contents($stagingDir.'/'.$filename),
                [
                    'selector_asset_candidates.staging.jsonl',
                    'content_asset_candidates.staging.jsonl',
                    'staging_import_manifest.json',
                    'staging_import_validation_report.json',
                    'repair_log.json',
                ]
            ));
            foreach (['private_url', 'attempt_id', 'raw_score', 'percentile', 'fixed_type', 'user_confirmed_type', 'type_code'] as $forbiddenToken) {
                $this->assertStringNotContainsString($forbiddenToken, $allStagingArtifacts);
            }
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_committed_candidate_batch_001_is_reviewed_and_staging_only(): void
    {
        $agentRunDir = base_path('content_assets/big5/result_page_v2/agent_runs/candidate_batch_001');
        $stagingDir = base_path('content_assets/big5/result_page_v2/staging_candidate_imports/candidate_batch_001');

        foreach ([
            'selector_asset_candidates.jsonl',
            'content_asset_candidates.jsonl',
            'candidate_generation_summary.json',
            'review_manifest.json',
            'staging_import_summary.json',
            'staging_import_validation_report.json',
            'repair_log.json',
        ] as $filename) {
            $this->assertFileExists($agentRunDir.'/'.$filename);
        }

        foreach ([
            'selector_asset_candidates.staging.jsonl',
            'content_asset_candidates.staging.jsonl',
            'staging_import_manifest.json',
            'staging_import_validation_report.json',
            'repair_log.json',
        ] as $filename) {
            $this->assertFileExists($stagingDir.'/'.$filename);
        }

        $generation = $this->readJson($agentRunDir.'/candidate_generation_summary.json');
        $review = $this->readJson($agentRunDir.'/review_manifest.json');
        $summary = $this->readJson($agentRunDir.'/staging_import_summary.json');
        $validation = $this->readJson($stagingDir.'/staging_import_validation_report.json');
        $manifest = $this->readJson($stagingDir.'/staging_import_manifest.json');
        $repairLog = $this->readJson($stagingDir.'/repair_log.json');

        $this->assertSame('pass', data_get($generation, 'validation.status'));
        $this->assertSame(0, data_get($generation, 'validation.error_count'));
        $this->assertSame('pass', data_get($generation, 'leak_scan.status'));
        $this->assertSame(0, data_get($generation, 'leak_scan.hit_count'));

        $this->assertTrue((bool) ($review['human_reviewed'] ?? false));
        $this->assertSame('approved_for_staging', $review['review_status'] ?? null);
        $this->assertSame([
            'selector_asset_candidates.jsonl',
            'content_asset_candidates.jsonl',
        ], $review['approved_candidate_files'] ?? []);

        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertTrue((bool) ($summary['staging_write_performed'] ?? false));
        $this->assertTrue((bool) ($validation['staging_write_performed'] ?? false));
        $this->assertSame('pass', data_get($validation, 'candidate_validation.status'));
        $this->assertSame(0, data_get($validation, 'candidate_validation.error_count'));
        $this->assertSame('pass', data_get($validation, 'leak_scan.status'));
        $this->assertSame(0, data_get($validation, 'leak_scan.hit_count'));
        $this->assertSame([], $repairLog['entries'] ?? ['unexpected']);

        foreach ([$generation, $review, $manifest] as $artifact) {
            $this->assertSame('staging_only', $artifact['runtime_use'] ?? null);
            $this->assertFalse((bool) ($artifact['production_use_allowed'] ?? true));
            $this->assertFalse((bool) ($artifact['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($artifact['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($artifact['ready_for_production'] ?? true));
        }

        $allArtifacts = implode("\n", array_map(
            static fn (string $path): string => (string) file_get_contents($path),
            array_merge(
                glob($agentRunDir.'/*') ?: [],
                glob($stagingDir.'/*') ?: []
            )
        ));

        foreach (['private_url', 'attempt_id', 'raw_score', 'percentile', 'fixed_type', 'user_confirmed_type', 'type_code'] as $forbiddenToken) {
            $this->assertStringNotContainsString($forbiddenToken, $allArtifacts);
        }
    }

    public function test_committed_method_boundary_v0_5_normalized_candidates_are_reviewed_and_staging_only(): void
    {
        $artifactRoot = $this->tempDir('big5-v2-agent-method-boundary-v0-5-normalized');
        $agentRunDir = base_path('content_assets/big5/result_page_v2/agent_runs/method_boundary_v0_5_normalized');

        try {
            foreach ([
                'README.md',
                'SHA256SUMS.txt',
                'selector_asset_candidates.jsonl',
                'content_asset_candidates.jsonl',
                'normalization_manifest.json',
                'normalization_validation_summary.json',
                'review_manifest.json',
            ] as $filename) {
                $this->assertFileExists($agentRunDir.'/'.$filename);
            }

            $manifest = $this->readJson($agentRunDir.'/normalization_manifest.json');
            $validation = $this->readJson($agentRunDir.'/normalization_validation_summary.json');
            $review = $this->readJson($agentRunDir.'/review_manifest.json');

            $this->assertSame('staging_only', $manifest['runtime_use'] ?? null);
            $this->assertFalse((bool) ($manifest['production_use_allowed'] ?? true));
            $this->assertFalse((bool) ($manifest['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($manifest['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($manifest['ready_for_production'] ?? true));
            $this->assertSame(14, data_get($manifest, 'counts.selector_asset_candidates'));
            $this->assertSame(14, data_get($manifest, 'counts.content_asset_candidates'));
            $this->assertSame(0, data_get($manifest, 'source_qa_scan_summary.hit_count'));

            $this->assertTrue((bool) ($review['human_reviewed'] ?? false));
            $this->assertSame('approved_for_staging', $review['review_status'] ?? null);
            $this->assertSame('staging_only', $review['runtime_use'] ?? null);
            $this->assertFalse((bool) ($review['production_use_allowed'] ?? true));
            $this->assertFalse((bool) ($review['ready_for_pilot'] ?? true));
            $this->assertTrue((bool) data_get($review, 'review_scope.normalization_only'));
            $this->assertTrue((bool) data_get($review, 'review_scope.staging_import_deferred'));
            $this->assertFalse((bool) data_get($review, 'review_checks.staging_import_performed'));

            $this->assertTrue((bool) ($validation['ok'] ?? false));
            $this->assertFalse((bool) ($validation['staging_write_performed'] ?? true));
            $this->assertSame('pass', data_get($validation, 'candidate_validation.status'));
            $this->assertSame(0, data_get($validation, 'candidate_validation.error_count'));
            $this->assertSame('pass', data_get($validation, 'leak_scan.status'));
            $this->assertSame(0, data_get($validation, 'leak_scan.hit_count'));

            $summary = app(BigFiveResultPageV2AssetAgent::class)->stageCandidates([
                'run_id' => 'method-boundary-v0-5-normalized-test',
                'artifact_dir' => $artifactRoot,
                'candidate_dir' => $agentRunDir,
                'allow_staging_write' => false,
            ]);

            $this->assertTrue((bool) ($summary['ok'] ?? false));
            $this->assertSame(14, data_get($summary, 'summary.selector_candidate_count'));
            $this->assertSame(14, data_get($summary, 'summary.content_candidate_count'));
            $this->assertSame(0, data_get($summary, 'summary.validation_error_count'));
            $this->assertSame(0, data_get($summary, 'summary.review_error_count'));
            $this->assertSame(0, data_get($summary, 'summary.leak_hit_count'));
            $this->assertFalse((bool) data_get($summary, 'summary.staging_write_performed'));
            $this->assertSame([], $summary['staging_artifacts'] ?? ['unexpected']);

            $allArtifacts = implode("\n", array_map(
                static fn (string $path): string => (string) file_get_contents($path),
                glob($agentRunDir.'/*') ?: []
            ));

            foreach ([
                'private_url',
                'attempt_id',
                'raw_score',
                'percentile',
                'fixed_type',
                'user_confirmed_type',
                'type_code',
                'big5:',
                'band:',
                'PR3B',
                'AttemptReadController',
                'Big Five Report Engine',
                '[object Object]',
            ] as $forbiddenToken) {
                $this->assertStringNotContainsString($forbiddenToken, $allArtifacts, $forbiddenToken);
            }
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_committed_method_boundary_v0_5_staging_import_is_reviewed_and_non_runtime(): void
    {
        $stagingDir = base_path('content_assets/big5/result_page_v2/staging_candidate_imports/method_boundary_v0_5_staging_import');

        foreach ([
            'selector_asset_candidates.staging.jsonl',
            'content_asset_candidates.staging.jsonl',
            'staging_import_manifest.json',
            'staging_import_validation_report.json',
            'repair_log.json',
        ] as $filename) {
            $this->assertFileExists($stagingDir.'/'.$filename);
        }

        $manifest = $this->readJson($stagingDir.'/staging_import_manifest.json');
        $validation = $this->readJson($stagingDir.'/staging_import_validation_report.json');
        $repairLog = $this->readJson($stagingDir.'/repair_log.json');

        $this->assertSame('staging_only', $manifest['runtime_use'] ?? null);
        $this->assertFalse((bool) ($manifest['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($manifest['ready_for_pilot'] ?? true));
        $this->assertFalse((bool) ($manifest['ready_for_runtime'] ?? true));
        $this->assertFalse((bool) ($manifest['ready_for_production'] ?? true));
        $this->assertSame('content_assets/big5/result_page_v2/agent_runs/method_boundary_v0_5_normalized', $manifest['candidate_dir'] ?? null);
        $this->assertSame(14, $manifest['selector_asset_candidate_count'] ?? null);
        $this->assertSame(14, $manifest['content_asset_candidate_count'] ?? null);

        $this->assertTrue((bool) ($validation['staging_write_performed'] ?? false));
        $this->assertSame('pass', data_get($validation, 'candidate_validation.status'));
        $this->assertSame(0, data_get($validation, 'candidate_validation.error_count'));
        $this->assertSame('pass', data_get($validation, 'leak_scan.status'));
        $this->assertSame(0, data_get($validation, 'leak_scan.hit_count'));
        $this->assertSame([], $repairLog['entries'] ?? ['unexpected']);
        $this->assertFalse((bool) ($repairLog['repair_required'] ?? true));

        $selectorRows = $this->readJsonl($stagingDir.'/selector_asset_candidates.staging.jsonl');
        $contentRows = $this->readJsonl($stagingDir.'/content_asset_candidates.staging.jsonl');
        $this->assertCount(14, $selectorRows);
        $this->assertCount(14, $contentRows);

        foreach (array_merge($selectorRows, $contentRows) as $row) {
            $this->assertSame('staging_only', data_get($row, 'runtime_use', data_get($row, 'provenance.runtime_use')));
            $this->assertFalse((bool) data_get($row, 'production_use_allowed', data_get($row, 'provenance.production_use_allowed', true)));
        }

        $allArtifacts = implode("\n", array_map(
            static fn (string $path): string => (string) file_get_contents($path),
            glob($stagingDir.'/*') ?: []
        ));

        foreach ([
            'private_url',
            'attempt_id',
            'raw_score',
            'percentile',
            'fixed_type',
            'user_confirmed_type',
            'type_code',
            'big5:',
            'band:',
            'PR3B',
            'AttemptReadController',
            'Big Five Report Engine',
            '[object Object]',
        ] as $forbiddenToken) {
            $this->assertStringNotContainsString($forbiddenToken, $allArtifacts, $forbiddenToken);
        }
    }

    public function test_strict_mode_rejects_public_payload_and_shareable_score_leaks(): void
    {
        $root = $this->tempDir('big5-v2-agent-leak');
        $contentRoot = $root.'/content_assets/big5/result_page_v2';
        mkdir($contentRoot.'/selector_ready_assets/v0_3_p0_full', 0777, true);

        file_put_contents($contentRoot.'/selector_ready_assets/v0_3_p0_full/assets.jsonl', json_encode([
            'version' => 'fap.big5.result_page_v2.selector_asset.v0.1',
            'asset_key' => 'leaky_asset',
            'registry_key' => 'share_safety_registry',
            'module_key' => 'module_08_share_save',
            'block_key' => 'module_08_share_save.share_safety.leaky',
            'block_kind' => 'share_save',
            'slot_key' => 'share_save.safety_transform',
            'trigger' => [
                'score_bands' => [],
                'interpretation_scopes' => ['share_safe_summary_only'],
                'reading_mode' => ['quick'],
                'scenario' => ['share'],
            ],
            'priority' => 10,
            'mutual_exclusion_group' => 'share_safety.leaky',
            'can_stack_with' => [],
            'reading_modes' => ['quick'],
            'scenario' => 'share',
            'scope' => 'share_safe_summary_only',
            'required_evidence_level' => 'descriptive',
            'evidence_level' => 'descriptive',
            'safety_level' => 'share_safe',
            'shareable' => true,
            'shareable_policy' => 'required_for_every_shareable_true_block',
            'fallback_policy' => 'share_safe_summary_only',
            'content_source' => 'fixture',
            'provenance' => 'unit-test',
            'replacement_policy' => 'unit-test',
            'forbidden_public_fields' => [],
            'review_status' => 'fixture_only',
            'public_payload' => [
                'summary' => 'This share block exposes raw_score percentile and fixed_type.',
            ],
            'internal_metadata' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        try {
            $summary = app(BigFiveResultPageV2AssetAgent::class)->audit([
                'run_id' => 'strict-leak',
                'artifact_dir' => $root.'/artifacts',
                'content_asset_root' => $contentRoot,
                'source_ledger_dir' => $root.'/missing-source-ledger',
                'strict' => true,
            ]);

            $this->assertFalse((bool) ($summary['ok'] ?? true));
            $this->assertContains('forbidden_leak_hits', $summary['strict_failures']);

            $safety = $this->readJson($root.'/artifacts/strict-leak/safety_report.json');
            $this->assertSame('blocked', data_get($safety, 'leak_scan.status'));
            $this->assertGreaterThanOrEqual(3, (int) data_get($safety, 'leak_scan.hit_count'));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_strict_mode_rejects_rendered_hygiene_leaks_without_raw_evidence(): void
    {
        $root = $this->tempDir('big5-v2-agent-rendered-hygiene');
        $contentRoot = $root.'/content_assets/big5/result_page_v2';
        mkdir($contentRoot.'/selector_ready_assets/v0_3_p0_full', 0777, true);
        mkdir($contentRoot.'/trait_band_assets/v0_1', 0777, true);

        file_put_contents($contentRoot.'/selector_ready_assets/v0_3_p0_full/assets.jsonl', json_encode([
            'version' => 'fap.big5.result_page_v2.selector_asset.v0.1',
            'asset_key' => 'rendered_hygiene_leaky_asset',
            'registry_key' => 'share_safety_registry',
            'module_key' => 'module_08_share_save',
            'block_key' => 'module_08_share_save.share_safety.rendered_hygiene_leak',
            'block_kind' => 'share_save',
            'slot_key' => 'share_save.safety_transform',
            'trigger' => [
                'score_bands' => [],
                'interpretation_scopes' => ['share_safe_summary_only'],
                'reading_mode' => ['quick'],
                'scenario' => ['share'],
            ],
            'priority' => 10,
            'mutual_exclusion_group' => 'share_safety.rendered_hygiene_leak',
            'can_stack_with' => [],
            'reading_modes' => ['quick'],
            'scenario' => 'share',
            'scope' => 'share_safe_summary_only',
            'required_evidence_level' => 'descriptive',
            'evidence_level' => 'descriptive',
            'safety_level' => 'share_safe',
            'shareable' => true,
            'shareable_policy' => 'required_for_every_shareable_true_block',
            'fallback_policy' => 'share_safe_summary_only',
            'content_source' => 'fixture',
            'provenance' => 'unit-test',
            'replacement_policy' => 'unit-test',
            'forbidden_public_fields' => [],
            'review_status' => 'fixture_only',
            'public_payload' => [
                'title_zh' => 'Visible selector tokens big5:o_mid and band:o.mid must not render.',
                'summary_zh' => 'Visible internals mention payload registry PR3B AttemptReadController Big Five Report Engine [object Object].',
            ],
            'internal_metadata' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        file_put_contents($contentRoot.'/trait_band_assets/v0_1/rendered_hygiene_fixture.json', json_encode([
            'asset_id' => 'rendered_hygiene_fixture',
            'body_zh' => '本 由 生成； 已覆盖 30 条 与 22 条 facet，不代表生产 已接入。',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        try {
            $summary = app(BigFiveResultPageV2AssetAgent::class)->audit([
                'run_id' => 'strict-rendered-hygiene',
                'artifact_dir' => $root.'/artifacts',
                'content_asset_root' => $contentRoot,
                'source_ledger_dir' => $root.'/missing-source-ledger',
                'strict' => true,
            ]);

            $this->assertFalse((bool) ($summary['ok'] ?? true));
            $this->assertContains('forbidden_leak_hits', $summary['strict_failures']);

            $safety = $this->readJson($root.'/artifacts/strict-rendered-hygiene/safety_report.json');
            $this->assertSame('blocked', data_get($safety, 'leak_scan.status'));
            $hitValues = array_column((array) data_get($safety, 'leak_scan.hits', []), 'value');
            foreach ([
                'selector_token_big5',
                'selector_token_band',
                'internal_payload_term',
                'internal_registry_term',
                'internal_pr3b_term',
                'internal_attempt_read_controller_term',
                'internal_report_engine_term',
                'object_object_term',
                'broken_template_generated_by',
                'broken_template_facet_coverage',
                'broken_template_production_connected',
            ] as $expectedRule) {
                $this->assertContains($expectedRule, $hitValues);
            }

            $safetyText = (string) file_get_contents($root.'/artifacts/strict-rendered-hygiene/safety_report.json');
            foreach ([
                'big5:o_mid',
                'band:o.mid',
                'Visible internals mention',
                '本 由 生成',
            ] as $forbiddenEvidenceText) {
                $this->assertStringNotContainsString($forbiddenEvidenceText, $safetyText, $forbiddenEvidenceText);
            }
        } finally {
            $this->deleteDirectory($root);
        }
    }

    /**
     * @return list<string>
     */
    private function seedBigFiveOpsSnapshotRows(): array
    {
        $this->ensureReportSnapshotsTable();

        $now = now();
        $attemptIds = [];
        $rows = [
            ['status' => 'attached', 'reason' => 'v2_attached', 'errors' => 0, 'minutes_ago' => 5, 'scale' => 'BIG5_OCEAN'],
            ['status' => 'attached', 'reason' => 'v2_attached', 'errors' => 0, 'minutes_ago' => 6, 'scale' => 'BIG5_OCEAN'],
            ['status' => 'fallback', 'reason' => 'production_rollout_denied', 'errors' => 0, 'minutes_ago' => 7, 'scale' => 'BIG5_OCEAN'],
            ['status' => 'fallback', 'reason' => 'locked_or_free_preview', 'errors' => 0, 'minutes_ago' => 8, 'scale' => 'BIG5_OCEAN'],
            ['status' => 'invalid', 'reason' => 'payload_validation_failed', 'errors' => 3, 'minutes_ago' => 9, 'scale' => 'BIG5_OCEAN'],
            ['status' => 'not_evaluated', 'reason' => 'production_runtime_disabled', 'errors' => 0, 'minutes_ago' => 10, 'scale' => 'BIG5_OCEAN'],
            ['status' => 'invalid', 'reason' => 'route_input_invalid', 'errors' => 2, 'minutes_ago' => 60 * 24 * 50, 'scale' => 'BIG5_OCEAN'],
            ['status' => 'attached', 'reason' => 'v2_attached', 'errors' => 0, 'minutes_ago' => 4, 'scale' => 'MBTI_16'],
        ];

        foreach ($rows as $index => $row) {
            $attemptId = (string) Str::uuid();
            $attemptIds[] = $attemptId;

            DB::table('report_snapshots')->insert([
                'org_id' => 0,
                'attempt_id' => $attemptId,
                'order_no' => null,
                'scale_code' => $row['scale'],
                'pack_id' => $row['scale'],
                'dir_version' => 'v1',
                'scoring_spec_version' => 'big5_spec_2026Q2_form90_v1',
                'report_engine_version' => 'v1.2',
                'big5_result_page_v2_status' => $row['status'],
                'big5_result_page_v2_fallback_reason' => $row['reason'],
                'big5_result_page_v2_validation_error_count' => $row['errors'],
                'big5_result_page_v2_audited_at' => $now->copy()->subMinutes((int) $row['minutes_ago']),
                'snapshot_version' => 'v1',
                'report_json' => json_encode(['variant' => 'full', 'row' => $index], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'ready',
                'last_error' => null,
                'created_at' => $now->copy()->subMinutes((int) $row['minutes_ago']),
                'updated_at' => $now->copy()->subMinutes((int) $row['minutes_ago']),
            ]);
        }

        return $attemptIds;
    }

    private function ensureReportSnapshotsTable(): void
    {
        if (Schema::hasTable('report_snapshots')) {
            return;
        }

        Schema::create('report_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('attempt_id');
            $table->string('order_no')->nullable();
            $table->string('scale_code')->nullable();
            $table->string('pack_id')->nullable();
            $table->string('dir_version')->nullable();
            $table->string('scoring_spec_version')->nullable();
            $table->string('report_engine_version')->nullable();
            $table->string('big5_result_page_v2_status')->nullable();
            $table->string('big5_result_page_v2_fallback_reason')->nullable();
            $table->unsignedSmallInteger('big5_result_page_v2_validation_error_count')->default(0);
            $table->timestamp('big5_result_page_v2_audited_at')->nullable();
            $table->string('snapshot_version')->nullable();
            $table->json('report_json')->nullable();
            $table->string('status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    private function tempDir(string $prefix): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(4));
        mkdir($path, 0777, true);

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $path): array
    {
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded);
            $rows[] = $decoded;
        }

        return $rows;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }
}
