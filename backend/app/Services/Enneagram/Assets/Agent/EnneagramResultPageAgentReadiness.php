<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets\Agent;

use Illuminate\Support\Facades\File;
use RuntimeException;
use SplFileInfo;

final class EnneagramResultPageAgentReadiness
{
    public const SCHEMA_VERSION = 'fap.enneagram.result_page.agent_readiness.v1';

    public const SOURCE_LEDGER_SCHEMA_VERSION = 'fap.enneagram.result_page.source_ledger.v0.1';

    public const DEFAULT_ARTIFACT_RELATIVE_DIR = 'artifacts/enneagram_result_page_agent';

    private const SOURCE_LEDGER_RELATIVE_DIR = 'content_assets/enneagram/result_page/source_ledger';

    private const SOURCE_LEDGER_PRIMARY_FILENAME = 'source_ledger.json';

    private const EXPECTED_CANDIDATE_MANIFEST_SHA256 = 'a9fd3eb474ea2ca0130d06ad2b1640305d9160ee1a74e559ad4f60bfc4db56c0';

    private const EXPECTED_RUNTIME_REGISTRY_MANIFEST_SHA256 = 'ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f';

    private const EXPECTED_PAYLOAD_COUNT = 630;

    private const LAUNCH_SCOPE_BATCHES = ['1R-A', '1R-B', '1R-C', '1R-D', '1R-E', '1R-F', '1R-G', '1R-H'];

    private const OUT_OF_LAUNCH_SCOPE_BATCHES = ['1R-I', '1R-J'];

    private const REQUIRED_CANDIDATE_FILES = [
        'candidate_manifest.json',
        'candidate_hashes.json',
        'rollback_plan.md',
        'import_diff_summary.json',
        'replacement_additive_map.json',
        'source_mapping_report.json',
        'legacy_residual_scan.json',
        'fc144_boundary_report.json',
        'phase8b_summary.json',
        'candidate_payloads_manifest.json',
        'candidate_payload_hashes.json',
        'candidate_payload_source_mapping.json',
        'candidate_payloads/',
    ];

    private const SOURCE_LEDGER_ALLOWED_LABELS = [
        'runtime_contract',
        'compiled_question_contract',
        'candidate_baseline',
        'asset_stream_contract',
        'policy_contract',
    ];

    private const SOURCE_LEDGER_REQUIRED_SOURCE_IDS = [
        'enneagram_v2_runtime_registry',
        'enneagram_e105_compiled_questions',
        'enneagram_fc144_compiled_questions',
        'phase8b_candidate_baseline_a9fd',
        'batch_1r_a_asset_stream',
        'batch_1r_b_asset_stream',
        'batch_1r_c_asset_stream',
        'batch_1r_d_asset_stream',
        'batch_1r_e_asset_stream',
        'batch_1r_f_asset_stream',
        'batch_1r_g_asset_stream',
        'batch_1r_h_asset_stream',
        'fc144_boundary_policy',
        'forbidden_claim_policy',
    ];

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

    /**
     * Field strings are intentionally not emitted into default artifacts.
     *
     * @var array<string,string>
     */
    private const FORBIDDEN_PUBLIC_FIELD_FAMILY_BY_FIELD = [
        'attempt_id' => 'private_result_identifier_path_or_vector_leakage',
        'attempt_uuid' => 'private_result_identifier_path_or_vector_leakage',
        'private_url' => 'private_result_identifier_path_or_vector_leakage',
        'private_path' => 'private_result_identifier_path_or_vector_leakage',
        'raw_score' => 'private_result_identifier_path_or_vector_leakage',
        'raw_scores' => 'private_result_identifier_path_or_vector_leakage',
        'raw_score_vector' => 'private_result_identifier_path_or_vector_leakage',
        'domain_vector' => 'private_result_identifier_path_or_vector_leakage',
        'facet_vector' => 'private_result_identifier_path_or_vector_leakage',
        'editor_notes' => 'private_result_identifier_path_or_vector_leakage',
        'qa_notes' => 'private_result_identifier_path_or_vector_leakage',
        'source_selection_notes' => 'private_result_identifier_path_or_vector_leakage',
        'internal_metadata' => 'private_result_identifier_path_or_vector_leakage',
    ];

    /**
     * @var array<string,string>
     */
    private const FORBIDDEN_TERM_FAMILY_BY_TERM = [
        'diagnosis' => 'diagnosis_clinical_treatment',
        'clinical' => 'diagnosis_clinical_treatment',
        'therapy' => 'diagnosis_clinical_treatment',
        'treatment' => 'diagnosis_clinical_treatment',
        'mental health advice' => 'diagnosis_clinical_treatment',
        'hiring' => 'hiring_employment_screening',
        'employment screening' => 'hiring_employment_screening',
        'you are this type' => 'final_typing_you_are_this_type',
        'you are type' => 'final_typing_you_are_this_type',
        'final type' => 'final_typing_you_are_this_type',
        'fixed type' => 'fixed_type_certainty',
        'certainly type' => 'fixed_type_certainty',
        'compare e105 and fc144 scores' => 'e105_fc144_score_comparison',
        'e105 score is higher than fc144' => 'e105_fc144_score_comparison',
        'fc144 is more accurate' => 'fc144_more_accurate_or_replacement_result',
        'fc144 replaces your result' => 'fc144_more_accurate_or_replacement_result',
        'salary prediction' => 'success_salary_performance_prediction',
        'performance prediction' => 'success_salary_performance_prediction',
        'success prediction' => 'success_salary_performance_prediction',
        '诊断' => 'diagnosis_clinical_treatment',
        '治疗' => 'diagnosis_clinical_treatment',
        '招聘' => 'hiring_employment_screening',
        '雇佣筛选' => 'hiring_employment_screening',
        '你就是这个类型' => 'final_typing_you_are_this_type',
        '最终类型' => 'final_typing_you_are_this_type',
        '固定类型' => 'fixed_type_certainty',
        '分数直接比较' => 'e105_fc144_score_comparison',
        'fc144 更准确' => 'fc144_more_accurate_or_replacement_result',
        '成功预测' => 'success_salary_performance_prediction',
        '薪资预测' => 'success_salary_performance_prediction',
    ];

    private const AGENT_BATCHES = [
        [
            'batch_id' => 'ENNEAGRAM-RESULT-AGENT-CONTROL-PACKET-01',
            'state' => 'merged_ready',
            'allowed_output' => 'docs, control packet, readiness artifacts',
            'generation_allowed' => false,
        ],
        [
            'batch_id' => 'ENNEAGRAM-RESULT-SOURCE-LEDGER-01',
            'state' => 'scaffold_only',
            'allowed_output' => 'source ledger and validator harness artifacts',
            'generation_allowed' => false,
        ],
        [
            'batch_id' => 'ENNEAGRAM-RESULT-PILOT-ASSET-BATCH-01',
            'state' => 'blocked_until_validator_and_ledger_pass',
            'allowed_output' => 'small candidate draft only after explicit PR authorization',
            'generation_allowed' => false,
        ],
        [
            'batch_id' => 'ENNEAGRAM-RESULT-CANDIDATE-EXPORT-QA-01',
            'state' => 'blocked_until_candidate_dir_is_provided',
            'allowed_output' => 'Phase8B/Phase8D2B reports only',
            'generation_allowed' => false,
        ],
    ];

    /**
     * @param  array{run_id?:string,artifact_dir?:string,strict?:bool,candidate_dir?:string,source_ledger_dir?:string}  $options
     * @return array<string,mixed>
     */
    public function audit(array $options = []): array
    {
        $runId = $this->sanitizeRunId((string) ($options['run_id'] ?? ''));
        $artifactDir = $this->artifactDir((string) ($options['artifact_dir'] ?? ''), $runId);
        $strict = ($options['strict'] ?? false) === true;
        $candidateDir = trim((string) ($options['candidate_dir'] ?? ''));
        $sourceLedgerDir = $this->sourceLedgerDir((string) ($options['source_ledger_dir'] ?? ''));

        $this->ensureDirectory($artifactDir);

        $controlPacket = $this->controlPacket();
        $readinessInventory = $this->readinessInventory($candidateDir);
        $sourceLedgerInventory = $this->sourceLedgerInventory($sourceLedgerDir);
        $sourceMappingContractReport = $this->sourceMappingContractReport($candidateDir);
        $metadataLeakageReport = $this->metadataLeakageReport($candidateDir);
        $forbiddenClaimReport = $this->forbiddenClaimReport($candidateDir);
        $validatorHarnessReport = $this->validatorHarnessReport(
            $candidateDir,
            $readinessInventory,
            $sourceLedgerInventory,
            $sourceMappingContractReport,
            $metadataLeakageReport,
            $forbiddenClaimReport
        );
        $validationCommands = $this->validationCommands();
        $safetyPolicy = $this->safetyPolicy();
        $goNoGo = $this->goNoGo($readinessInventory, $sourceLedgerInventory, $validatorHarnessReport);
        $strictFailures = $this->strictFailures($strict, $sourceLedgerInventory, $validatorHarnessReport);

        $artifacts = [
            'control_packet.json' => $this->writeJson($artifactDir.'/control_packet.json', $controlPacket),
            'readiness_inventory.json' => $this->writeJson($artifactDir.'/readiness_inventory.json', $readinessInventory),
            'source_ledger_inventory.json' => $this->writeJson($artifactDir.'/source_ledger_inventory.json', $sourceLedgerInventory),
            'validator_harness_report.json' => $this->writeJson($artifactDir.'/validator_harness_report.json', $validatorHarnessReport),
            'source_mapping_contract_report.json' => $this->writeJson($artifactDir.'/source_mapping_contract_report.json', $sourceMappingContractReport),
            'metadata_leakage_report.json' => $this->writeJson($artifactDir.'/metadata_leakage_report.json', $metadataLeakageReport),
            'forbidden_claim_report.json' => $this->writeJson($artifactDir.'/forbidden_claim_report.json', $forbiddenClaimReport),
            'validation_commands.json' => $this->writeJson($artifactDir.'/validation_commands.json', $validationCommands),
            'safety_policy.json' => $this->writeJson($artifactDir.'/safety_policy.json', $safetyPolicy),
            'go_no_go.md' => $this->writeText($artifactDir.'/go_no_go.md', $goNoGo),
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $strictFailures === [],
            'status' => $strictFailures === [] ? 'success' : 'blocked',
            'run_id' => $runId,
            'artifact_dir' => $this->redactPath($artifactDir),
            'artifacts' => $artifacts,
            'strict' => $strict,
            'strict_failures' => $strictFailures,
            'summary' => [
                'candidate_generation_allowed' => false,
                'candidate_payload_count_expected' => self::EXPECTED_PAYLOAD_COUNT,
                'expected_candidate_manifest_sha256' => self::EXPECTED_CANDIDATE_MANIFEST_SHA256,
                'expected_runtime_registry_manifest_sha256' => self::EXPECTED_RUNTIME_REGISTRY_MANIFEST_SHA256,
                'source_ledger_valid' => (bool) data_get($sourceLedgerInventory, 'source_ledger.valid', false),
                'candidate_dir_provided' => (bool) data_get($validatorHarnessReport, 'candidate_dir.provided', false),
                'candidate_contract_valid' => (bool) data_get($validatorHarnessReport, 'candidate_contract.valid', false),
                'ready_for_generation' => false,
                'ready_for_import' => false,
                'ready_for_activation' => false,
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function controlPacket(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'control_packet',
            'content_authority' => 'backend',
            'scale' => 'ENNEAGRAM',
            'surface' => 'private_result_page',
            'candidate_contract' => [
                'baseline_candidate_manifest_sha256' => self::EXPECTED_CANDIDATE_MANIFEST_SHA256,
                'runtime_registry_manifest_sha256' => self::EXPECTED_RUNTIME_REGISTRY_MANIFEST_SHA256,
                'payload_count' => self::EXPECTED_PAYLOAD_COUNT,
                'out_of_launch_scope_must_equal' => self::OUT_OF_LAUNCH_SCOPE_BATCHES,
                'launch_scope_batches' => self::LAUNCH_SCOPE_BATCHES,
            ],
            'source_ledger_contract' => [
                'schema_version' => self::SOURCE_LEDGER_SCHEMA_VERSION,
                'default_relative_dir' => self::SOURCE_LEDGER_RELATIVE_DIR,
                'required_source_ids' => self::SOURCE_LEDGER_REQUIRED_SOURCE_IDS,
                'allowed_source_labels' => self::SOURCE_LEDGER_ALLOWED_LABELS,
            ],
            'agent_batches' => self::AGENT_BATCHES,
            'allowed_actions' => [
                'read existing docs and code',
                'write run-scoped readiness artifacts',
                'write source ledger templates',
                'validate a provided Phase8B candidate directory without import or activation',
                'prepare small candidate drafts only in a future explicitly authorized PR',
            ],
            'forbidden_actions' => [
                'bulk content generation in this PR',
                'candidate payload creation in this PR',
                'production activation',
                'content_pack_activations write',
                'runtime registry switch',
                'production import',
                'frontend-side result copy fallback',
                'fap-web runtime changes',
                'sitemap or llms exposure',
                'public SEO profile generation',
                'private result identifier export',
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readinessInventory(string $candidateDir): array
    {
        $candidate = [
            'provided' => $candidateDir !== '',
            'path' => $candidateDir === '' ? null : $this->redactPath($candidateDir),
            'required_files' => self::REQUIRED_CANDIDATE_FILES,
            'missing_required_files' => [],
        ];

        if ($candidateDir !== '') {
            if (! is_dir($candidateDir)) {
                throw new RuntimeException('Candidate directory does not exist: '.$candidateDir);
            }

            foreach (self::REQUIRED_CANDIDATE_FILES as $file) {
                $path = rtrim($candidateDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.rtrim($file, '/');
                $exists = str_ends_with($file, '/') ? is_dir($path) : is_file($path);
                if (! $exists) {
                    $candidate['missing_required_files'][] = $file;
                }
            }
        }

        $runtimeManifest = base_path('content_packs/ENNEAGRAM/v2/registry/manifest.json');
        $runtimeHash = is_file($runtimeManifest) ? (hash_file('sha256', $runtimeManifest) ?: '') : '';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'readiness_inventory',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'candidate_dir' => $candidate,
            'repo_contracts' => [
                'export_command' => 'php artisan enneagram:export-production-equivalent-candidate-payloads --candidate-dir=<candidate> --output-dir=<tmp> --json',
                'inactive_import_command' => 'php artisan enneagram:import-inactive-candidate-release --candidate-dir=<candidate> --output-dir=<tmp> --json',
                'activation_command' => 'php artisan enneagram:activate-registry-release --release-id=<inactive_release_id>',
                'activation_allowed_in_this_program' => false,
            ],
            'runtime_registry' => [
                'manifest_path' => 'content_packs/ENNEAGRAM/v2/registry/manifest.json',
                'actual_sha256' => $runtimeHash,
                'expected_sha256' => self::EXPECTED_RUNTIME_REGISTRY_MANIFEST_SHA256,
                'matches_expected' => $runtimeHash === self::EXPECTED_RUNTIME_REGISTRY_MANIFEST_SHA256,
            ],
            'expected_candidate_manifest_sha256' => self::EXPECTED_CANDIDATE_MANIFEST_SHA256,
            'expected_payload_count' => self::EXPECTED_PAYLOAD_COUNT,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceLedgerInventory(string $sourceLedgerDir): array
    {
        $errors = [];
        $files = [];
        $primaryLedgerPath = rtrim($sourceLedgerDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.self::SOURCE_LEDGER_PRIMARY_FILENAME;
        $primaryLedger = null;

        if (! is_dir($sourceLedgerDir)) {
            $errors[] = 'source_ledger_dir missing';
        } else {
            foreach (File::files($sourceLedgerDir) as $file) {
                if (! $file instanceof SplFileInfo) {
                    continue;
                }

                $relativePath = self::SOURCE_LEDGER_RELATIVE_DIR.'/'.$file->getBasename();
                $files[] = [
                    'relative_path' => $relativePath,
                    'sha256' => hash_file('sha256', $file->getPathname()) ?: '',
                    'size' => filesize($file->getPathname()) ?: 0,
                    'json_valid' => pathinfo($file->getBasename(), PATHINFO_EXTENSION) !== 'json'
                        || is_array(json_decode((string) file_get_contents($file->getPathname()), true)),
                ];
            }

            if (! is_file($primaryLedgerPath)) {
                $errors[] = self::SOURCE_LEDGER_PRIMARY_FILENAME.' missing';
            } else {
                $decoded = json_decode((string) file_get_contents($primaryLedgerPath), true);
                if (! is_array($decoded)) {
                    $errors[] = self::SOURCE_LEDGER_PRIMARY_FILENAME.' is not valid JSON';
                } else {
                    $primaryLedger = $decoded;
                    $errors = array_merge($errors, $this->sourceLedgerContractErrors($primaryLedger));
                }
            }
        }

        $sources = is_array($primaryLedger) ? (array) ($primaryLedger['sources'] ?? []) : [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'source_ledger_inventory',
            'source_ledger' => [
                'schema_version' => is_array($primaryLedger) ? (string) ($primaryLedger['schema_version'] ?? '') : null,
                'exists' => is_dir($sourceLedgerDir),
                'valid' => $errors === [] && is_array($primaryLedger),
                'source_ledger_dir' => $this->redactPath($sourceLedgerDir),
                'primary_ledger_path' => is_file($primaryLedgerPath) ? $this->redactPath($primaryLedgerPath) : null,
                'allowed_source_labels' => self::SOURCE_LEDGER_ALLOWED_LABELS,
                'required_source_ids' => self::SOURCE_LEDGER_REQUIRED_SOURCE_IDS,
                'required_source_ids_present' => $this->presentSourceIds($sources),
                'label_counts' => $this->sourceLabelCounts($sources),
                'candidate_baseline_hash' => data_get($primaryLedger, 'candidate_contract.baseline_candidate_manifest_sha256'),
                'runtime_registry_hash' => data_get($primaryLedger, 'candidate_contract.runtime_registry_manifest_sha256'),
                'expected_payload_count' => data_get($primaryLedger, 'candidate_contract.expected_payload_count'),
                'launch_scope' => data_get($primaryLedger, 'candidate_contract.launch_scope'),
                'out_of_launch_scope' => data_get($primaryLedger, 'candidate_contract.out_of_launch_scope'),
                'negative_guarantees' => [
                    'cms_write_performed' => data_get($primaryLedger, 'cms_write_performed'),
                    'runtime_change_performed' => data_get($primaryLedger, 'runtime_change_performed'),
                    'frontend_fallback_allowed' => data_get($primaryLedger, 'frontend_fallback_allowed'),
                    'activation_happened' => data_get($primaryLedger, 'activation_happened'),
                ],
                'errors' => $errors,
                'files' => $files,
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validatorHarnessReport(
        string $candidateDir,
        array $readinessInventory,
        array $sourceLedgerInventory,
        array $sourceMappingContractReport,
        array $metadataLeakageReport,
        array $forbiddenClaimReport
    ): array {
        $provided = $candidateDir !== '';
        $errors = [];
        $checks = [
            'source_ledger_valid' => (bool) data_get($sourceLedgerInventory, 'source_ledger.valid', false),
            'candidate_dir_provided' => $provided,
            'candidate_required_artifacts_present' => true,
            'candidate_manifest_hash_matches' => null,
            'runtime_registry_hash_matches' => null,
            'candidate_payload_count_matches' => null,
            'out_of_launch_scope_matches' => null,
            'source_mapping_zero_failures' => (bool) data_get($sourceMappingContractReport, 'status.source_mapping_zero_failures', true),
            'metadata_leakage_zero' => (bool) data_get($metadataLeakageReport, 'status.leakage_zero', true),
            'forbidden_claim_zero' => (bool) data_get($forbiddenClaimReport, 'status.forbidden_claim_zero', true),
            'legacy_residual_zero' => null,
            'fc144_boundary_zero' => null,
        ];

        if (! $checks['source_ledger_valid']) {
            $errors[] = 'source_ledger_invalid';
        }

        if ($provided) {
            foreach ((array) data_get($readinessInventory, 'candidate_dir.missing_required_files', []) as $missing) {
                $errors[] = 'missing_candidate_artifact:'.$missing;
            }
            $checks['candidate_required_artifacts_present'] = ((array) data_get($readinessInventory, 'candidate_dir.missing_required_files', [])) === [];

            $candidateRoot = rtrim($candidateDir, DIRECTORY_SEPARATOR);
            $candidateManifestPath = $candidateRoot.'/candidate_manifest.json';
            $candidateHashesPath = $candidateRoot.'/candidate_hashes.json';
            $candidateManifest = $this->readJsonFileIfPresent($candidateManifestPath);
            $candidateHashes = $this->readJsonFileIfPresent($candidateHashesPath);
            $actualManifestHash = is_file($candidateManifestPath) ? (hash_file('sha256', $candidateManifestPath) ?: '') : null;
            $actualPayloadCount = $this->candidatePayloadCount($candidateRoot.'/candidate_payloads');
            $outOfLaunchScope = is_array($candidateManifest) ? (array) ($candidateManifest['out_of_launch_scope'] ?? []) : [];

            $checks['candidate_manifest_hash_matches'] = $actualManifestHash === self::EXPECTED_CANDIDATE_MANIFEST_SHA256;
            $checks['runtime_registry_hash_matches'] = data_get($candidateHashes, 'runtime_registry_manifest_sha256') === self::EXPECTED_RUNTIME_REGISTRY_MANIFEST_SHA256;
            $checks['candidate_payload_count_matches'] = $actualPayloadCount === self::EXPECTED_PAYLOAD_COUNT;
            $checks['out_of_launch_scope_matches'] = $this->normalizedList($outOfLaunchScope) === self::OUT_OF_LAUNCH_SCOPE_BATCHES;
            $checks['legacy_residual_zero'] = $this->reportCounterIsZero($candidateRoot.'/legacy_residual_scan.json', [
                'legacy_deep_core_residual_count',
                'legacy_residual_count',
                'residual_count',
            ]);
            $checks['fc144_boundary_zero'] = $this->reportCounterIsZero($candidateRoot.'/fc144_boundary_report.json', [
                'violation_count',
                'fc144_boundary_violation_count',
            ]);

            if (! $checks['candidate_manifest_hash_matches']) {
                $errors[] = 'candidate_manifest_hash_mismatch';
            }
            if (! $checks['runtime_registry_hash_matches']) {
                $errors[] = 'runtime_registry_hash_mismatch';
            }
            if (! $checks['candidate_payload_count_matches']) {
                $errors[] = 'candidate_payload_count_mismatch';
            }
            if (! $checks['out_of_launch_scope_matches']) {
                $errors[] = 'out_of_launch_scope_mismatch';
            }
            if (! $checks['source_mapping_zero_failures']) {
                $errors[] = 'source_mapping_failures';
            }
            if (! $checks['metadata_leakage_zero']) {
                $errors[] = 'metadata_leakage_hits';
            }
            if (! $checks['forbidden_claim_zero']) {
                $errors[] = 'forbidden_claim_hits';
            }
            if ($checks['legacy_residual_zero'] !== true) {
                $errors[] = 'legacy_residual_hits';
            }
            if ($checks['fc144_boundary_zero'] !== true) {
                $errors[] = 'fc144_boundary_violations';
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'validator_harness_report',
            'runtime_use' => 'not_runtime',
            'production_use_allowed' => false,
            'candidate_dir' => [
                'provided' => $provided,
                'path' => $provided ? $this->redactPath($candidateDir) : null,
                'missing_candidate_dir_is_failure' => false,
            ],
            'candidate_contract' => [
                'valid' => ! $provided || $errors === [],
                'expected_candidate_manifest_sha256' => self::EXPECTED_CANDIDATE_MANIFEST_SHA256,
                'expected_runtime_registry_manifest_sha256' => self::EXPECTED_RUNTIME_REGISTRY_MANIFEST_SHA256,
                'expected_payload_count' => self::EXPECTED_PAYLOAD_COUNT,
                'launch_scope' => self::LAUNCH_SCOPE_BATCHES,
                'out_of_launch_scope_must_equal' => self::OUT_OF_LAUNCH_SCOPE_BATCHES,
            ],
            'checks' => $checks,
            'error_count' => count($errors),
            'errors' => $errors,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceMappingContractReport(string $candidateDir): array
    {
        $provided = $candidateDir !== '';
        $issues = [];
        $status = [
            'source_mapping_zero_failures' => true,
            'payload_source_mapping_present' => null,
        ];

        if ($provided) {
            $candidateRoot = rtrim($candidateDir, DIRECTORY_SEPARATOR);
            $sourceMapping = $this->readJsonFileIfPresent($candidateRoot.'/source_mapping_report.json');
            $payloadMapping = $this->readJsonFileIfPresent($candidateRoot.'/candidate_payload_source_mapping.json');

            $status['payload_source_mapping_present'] = is_array($payloadMapping);

            foreach ([
                'source_mapping_failure_count',
                'fallback_source_count',
                'blocked_source_count',
                'duplicate_selection_count',
                'branch_provenance_mismatch_count',
            ] as $counter) {
                $value = data_get($sourceMapping, $counter);
                if ($value !== null && (int) $value !== 0) {
                    $issues[] = [
                        'counter' => $counter,
                        'count' => (int) $value,
                    ];
                }
            }

            if (! is_array($payloadMapping)) {
                $issues[] = [
                    'counter' => 'candidate_payload_source_mapping_missing',
                    'count' => 1,
                ];
            }
        }

        $status['source_mapping_zero_failures'] = $issues === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'source_mapping_contract_report',
            'runtime_use' => 'not_runtime',
            'production_use_allowed' => false,
            'candidate_dir' => [
                'provided' => $provided,
                'path' => $provided ? $this->redactPath($candidateDir) : null,
            ],
            'status' => $status,
            'issue_count' => count($issues),
            'issues' => $issues,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function metadataLeakageReport(string $candidateDir): array
    {
        $provided = $candidateDir !== '';
        $hits = [];

        if ($provided) {
            $candidateRoot = rtrim($candidateDir, DIRECTORY_SEPARATOR);
            foreach ($this->candidatePayloadFiles($candidateRoot.'/candidate_payloads') as $file) {
                $payload = json_decode((string) file_get_contents($file->getPathname()), true);
                if (is_array($payload)) {
                    $hits = array_merge($hits, $this->scanPayloadFields($payload, $file->getBasename()));
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'metadata_leakage_report',
            'runtime_use' => 'not_runtime',
            'production_use_allowed' => false,
            'candidate_dir' => [
                'provided' => $provided,
                'path' => $provided ? $this->redactPath($candidateDir) : null,
            ],
            'blocked_field_families' => [
                'private_result_identifier_path_or_vector_leakage',
                'editorial_or_qa_working_notes',
                'internal_source_selection_metadata',
            ],
            'status' => [
                'leakage_zero' => $hits === [],
            ],
            'hit_count' => count($hits),
            'hits' => array_slice($hits, 0, 100),
            'truncated' => count($hits) > 100,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function forbiddenClaimReport(string $candidateDir): array
    {
        $provided = $candidateDir !== '';
        $hits = [];

        if ($provided) {
            $candidateRoot = rtrim($candidateDir, DIRECTORY_SEPARATOR);
            foreach ($this->candidatePayloadFiles($candidateRoot.'/candidate_payloads') as $file) {
                $payload = json_decode((string) file_get_contents($file->getPathname()), true);
                if (is_array($payload)) {
                    $hits = array_merge($hits, $this->scanPayloadTerms($payload, $file->getBasename()));
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'forbidden_claim_report',
            'runtime_use' => 'not_runtime',
            'production_use_allowed' => false,
            'candidate_dir' => [
                'provided' => $provided,
                'path' => $provided ? $this->redactPath($candidateDir) : null,
            ],
            'forbidden_claim_families' => self::FORBIDDEN_CLAIM_FAMILIES,
            'status' => [
                'forbidden_claim_zero' => $hits === [],
            ],
            'hit_count' => count($hits),
            'hits' => array_slice($hits, 0, 100),
            'truncated' => count($hits) > 100,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validationCommands(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'validation_commands',
            'local_pr_validation' => [
                'php -l backend/app/Console/Commands/EnneagramResultPageAgentReadinessCommand.php',
                'php -l backend/app/Services/Enneagram/Assets/Agent/EnneagramResultPageAgentReadiness.php',
                'cd backend && php artisan test tests/Unit/Services/Enneagram/Assets/EnneagramResultPageAgentReadinessTest.php --no-ansi',
                'cd backend && php artisan enneagram:result-page-agent audit --run-id=smoke --artifact-dir=<tmpdir> --json',
                'cd backend && php artisan enneagram:result-page-agent audit --run-id=smoke-strict --artifact-dir=<tmpdir> --strict --json',
                'git diff --check',
            ],
            'future_candidate_export_gate' => [
                'cd backend && PHASE8B_CANDIDATE_DIR=<candidate-dir> PHASE8B1_OUTPUT_DIR=<tmp-output> php artisan enneagram:export-production-equivalent-candidate-payloads --json',
                'assert phase8b1_summary.json verdict starts with PASS_',
                'assert total_payload_count == 630',
                'assert metadata_leak_count == 0',
                'assert source_mapping_failure_count == 0',
                'assert fc144_boundary_violation_count == 0',
            ],
            'future_inactive_import_gate' => [
                'cd backend && PHASE8B_CANDIDATE_DIR=<candidate-dir> PHASE8D2B_OUTPUT_DIR=<tmp-output> php artisan enneagram:import-inactive-candidate-release --json',
                'assert phase8d2b_summary.json verdict == PASS_FOR_PHASE_8D_3_ACTIVATION_ROLLBACK_GATE',
                'assert activation_happened == false',
                'assert production_import_happened == false',
                'assert candidate_payload_count == 630',
            ],
            'deferred' => [
                'production activation',
                'runtime registry switch',
                'fap-web rendered QA',
                'bulk asset generation',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function safetyPolicy(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'safety_policy',
            'forbidden_claim_families' => self::FORBIDDEN_CLAIM_FAMILIES,
            'blocked_public_payload_field_families' => [
                'private_result_identifier_path_or_vector_leakage',
                'editorial_or_qa_working_notes',
                'internal_source_selection_metadata',
            ],
            'fc144_boundary_rules' => [
                'FC144 may be suggested as a second lens, not a more accurate final type.',
                'E105 and FC144 scores must not be directly compared.',
                'No final typing, retyping, diagnostic, hiring, or certainty claims.',
            ],
            'source_mapping_rules' => [
                'Every candidate payload must map to one approved source batch and source asset checksum.',
                'Fallback, blocked, duplicate selection, and branch provenance mismatch counts must be zero.',
                'Machine-local private paths must be redacted from public reports.',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $readinessInventory
     * @param  array<string,mixed>  $sourceLedgerInventory
     * @param  array<string,mixed>  $validatorHarnessReport
     */
    private function goNoGo(array $readinessInventory, array $sourceLedgerInventory, array $validatorHarnessReport): string
    {
        return implode("\n", [
            '# Enneagram Result Page Agent Go / No-Go',
            '',
            '- verdict: SOURCE_LEDGER_VALIDATOR_SCAFFOLD_ONLY',
            '- candidate_generation_allowed: false',
            '- ready_for_generation: false',
            '- ready_for_import: false',
            '- ready_for_activation: false',
            '- source_ledger_valid: '.(((bool) data_get($sourceLedgerInventory, 'source_ledger.valid', false)) ? 'true' : 'false'),
            '- candidate_dir_provided: '.(((bool) data_get($validatorHarnessReport, 'candidate_dir.provided', false)) ? 'true' : 'false'),
            '- candidate_contract_valid: '.(((bool) data_get($validatorHarnessReport, 'candidate_contract.valid', false)) ? 'true' : 'false'),
            '- expected_candidate_manifest_sha256: `'.self::EXPECTED_CANDIDATE_MANIFEST_SHA256.'`',
            '- expected_runtime_registry_manifest_sha256: `'.self::EXPECTED_RUNTIME_REGISTRY_MANIFEST_SHA256.'`',
            '- expected_payload_count: '.self::EXPECTED_PAYLOAD_COUNT,
            '- runtime_registry_matches_expected: '.(((bool) data_get($readinessInventory, 'runtime_registry.matches_expected', false)) ? 'true' : 'false'),
            '- no bulk content generated',
            '- no candidate payloads created',
            '- no import',
            '- no production write',
            '- no activation',
            '- no runtime switch',
            '- no frontend fallback',
            '',
        ]);
    }

    /**
     * @return array<string,bool|string>
     */
    private function negativeGuarantees(): array
    {
        return [
            'runtime_use' => 'not_runtime',
            'production_use_allowed' => false,
            'ready_for_generation' => false,
            'ready_for_import' => false,
            'ready_for_runtime' => false,
            'ready_for_production' => false,
            'cms_write_performed' => false,
            'runtime_change_performed' => false,
            'activation_happened' => false,
            'bulk_content_generation_happened' => false,
            'candidate_payload_creation_happened' => false,
            'frontend_fallback_allowed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $ledger
     * @return list<string>
     */
    private function sourceLedgerContractErrors(array $ledger): array
    {
        $errors = [];

        if (($ledger['schema_version'] ?? null) !== self::SOURCE_LEDGER_SCHEMA_VERSION) {
            $errors[] = 'source_ledger schema_version mismatch';
        }
        if (($ledger['runtime_use'] ?? null) !== 'not_runtime') {
            $errors[] = 'source_ledger runtime_use must be not_runtime';
        }
        if (($ledger['production_use_allowed'] ?? null) !== false) {
            $errors[] = 'source_ledger production_use_allowed must be false';
        }
        foreach (['cms_write_performed', 'runtime_change_performed', 'frontend_fallback_allowed', 'activation_happened'] as $flag) {
            if (($ledger[$flag] ?? null) !== false) {
                $errors[] = "source_ledger {$flag} must be false";
            }
        }

        $labels = array_values((array) ($ledger['allowed_source_labels'] ?? []));
        sort($labels);
        $expectedLabels = self::SOURCE_LEDGER_ALLOWED_LABELS;
        sort($expectedLabels);
        if ($labels !== $expectedLabels) {
            $errors[] = 'source_ledger allowed_source_labels must match the fixed source label contract';
        }

        if (data_get($ledger, 'candidate_contract.baseline_candidate_manifest_sha256') !== self::EXPECTED_CANDIDATE_MANIFEST_SHA256) {
            $errors[] = 'source_ledger candidate baseline hash mismatch';
        }
        if (data_get($ledger, 'candidate_contract.runtime_registry_manifest_sha256') !== self::EXPECTED_RUNTIME_REGISTRY_MANIFEST_SHA256) {
            $errors[] = 'source_ledger runtime registry hash mismatch';
        }
        if ((int) data_get($ledger, 'candidate_contract.expected_payload_count', 0) !== self::EXPECTED_PAYLOAD_COUNT) {
            $errors[] = 'source_ledger expected payload count mismatch';
        }
        if ($this->normalizedList((array) data_get($ledger, 'candidate_contract.launch_scope', [])) !== self::LAUNCH_SCOPE_BATCHES) {
            $errors[] = 'source_ledger launch scope mismatch';
        }
        if ($this->normalizedList((array) data_get($ledger, 'candidate_contract.out_of_launch_scope', [])) !== self::OUT_OF_LAUNCH_SCOPE_BATCHES) {
            $errors[] = 'source_ledger out_of_launch_scope mismatch';
        }

        $sources = (array) ($ledger['sources'] ?? []);
        if ($sources === []) {
            $errors[] = 'source_ledger sources missing';
        }

        $presentIds = $this->presentSourceIds($sources);
        foreach (self::SOURCE_LEDGER_REQUIRED_SOURCE_IDS as $sourceId) {
            if (! in_array($sourceId, $presentIds, true)) {
                $errors[] = "source_ledger missing required source_id {$sourceId}";
            }
        }

        foreach ($sources as $index => $source) {
            if (! is_array($source)) {
                $errors[] = "source_ledger source row {$index} must be an object";

                continue;
            }

            $sourceId = (string) ($source['source_id'] ?? 'unknown');
            $label = (string) ($source['source_label'] ?? '');
            if (! in_array($label, self::SOURCE_LEDGER_ALLOWED_LABELS, true)) {
                $errors[] = "source_ledger {$sourceId} has invalid source_label {$label}";
            }
            if (! is_array($source['copy_policy'] ?? null)) {
                $errors[] = "source_ledger {$sourceId} missing copy_policy";
            }
            if ($this->containsAbsoluteLocalPath($source)) {
                $errors[] = "source_ledger {$sourceId} contains an absolute local path";
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function strictFailures(bool $strict, array $sourceLedgerInventory, array $validatorHarnessReport): array
    {
        if (! $strict) {
            return [];
        }

        $failures = [];
        if ((bool) data_get($sourceLedgerInventory, 'source_ledger.valid', false) !== true) {
            $failures[] = 'source_ledger_invalid';
        }

        foreach ((array) data_get($validatorHarnessReport, 'errors', []) as $error) {
            $failures[] = (string) $error;
        }

        return array_values(array_unique($failures));
    }

    /**
     * @param  list<mixed>|array<int,mixed>  $sources
     * @return list<string>
     */
    private function presentSourceIds(array $sources): array
    {
        $ids = [];
        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $sourceId = (string) ($source['source_id'] ?? '');
            if ($sourceId !== '') {
                $ids[] = $sourceId;
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<mixed>|array<int,mixed>  $sources
     * @return array<string,int>
     */
    private function sourceLabelCounts(array $sources): array
    {
        $counts = array_fill_keys(self::SOURCE_LEDGER_ALLOWED_LABELS, 0);
        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $label = (string) ($source['source_label'] ?? '');
            if (array_key_exists($label, $counts)) {
                $counts[$label]++;
            }
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function normalizedList(array $values): array
    {
        $normalized = array_values(array_map(static fn ($value): string => (string) $value, $values));
        sort($normalized);

        return $normalized;
    }

    private function reportCounterIsZero(string $path, array $keys): bool
    {
        $report = $this->readJsonFileIfPresent($path);
        if (! is_array($report)) {
            return false;
        }

        foreach ($keys as $key) {
            $value = data_get($report, $key);
            if ($value !== null) {
                return (int) $value === 0;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJsonFileIfPresent(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function candidatePayloadCount(string $payloadDir): int
    {
        return count($this->candidatePayloadFiles($payloadDir));
    }

    /**
     * @return list<SplFileInfo>
     */
    private function candidatePayloadFiles(string $payloadDir): array
    {
        if (! is_dir($payloadDir)) {
            return [];
        }

        $files = [];
        foreach (File::files($payloadDir) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'json') {
                $files[] = $file;
            }
        }

        usort($files, static fn (SplFileInfo $a, SplFileInfo $b): int => strcmp($a->getBasename(), $b->getBasename()));

        return $files;
    }

    /**
     * @return list<array<string,string>>
     */
    private function scanPayloadFields(array $payload, string $sourceFile): array
    {
        $hits = [];
        foreach ($payload as $key => $value) {
            $keyString = strtolower((string) $key);
            if (array_key_exists($keyString, self::FORBIDDEN_PUBLIC_FIELD_FAMILY_BY_FIELD)) {
                $hits[] = [
                    'source_file' => $sourceFile,
                    'hit_type' => 'field_family',
                    'family' => self::FORBIDDEN_PUBLIC_FIELD_FAMILY_BY_FIELD[$keyString],
                    'location' => 'json_key',
                ];
            }

            if (is_array($value)) {
                $hits = array_merge($hits, $this->scanPayloadFields($value, $sourceFile));
            }
        }

        return $hits;
    }

    /**
     * @return list<array<string,string>>
     */
    private function scanPayloadTerms(array $payload, string $sourceFile): array
    {
        $text = mb_strtolower(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $hits = [];

        foreach (self::FORBIDDEN_TERM_FAMILY_BY_TERM as $term => $family) {
            if (str_contains($text, mb_strtolower($term))) {
                $hits[] = [
                    'source_file' => $sourceFile,
                    'hit_type' => 'claim_family',
                    'family' => $family,
                    'location' => 'payload_text',
                ];
            }
        }

        return $hits;
    }

    private function containsAbsoluteLocalPath(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $child) {
                if ($this->containsAbsoluteLocalPath($child)) {
                    return true;
                }
            }

            return false;
        }

        if (! is_string($value)) {
            return false;
        }

        return str_contains($value, '/Users/')
            || str_contains($value, '/private/tmp/')
            || preg_match('#^/tmp/#', $value) === 1;
    }

    private function sanitizeRunId(string $runId): string
    {
        $runId = strtolower(trim($runId));
        $runId = preg_replace('/[^a-z0-9._-]+/', '-', $runId) ?: '';
        $runId = trim($runId, '-._');

        return $runId !== '' ? $runId : now()->format('Ymd-His');
    }

    private function artifactDir(string $artifactDir, string $runId): string
    {
        $root = $artifactDir !== '' ? rtrim($artifactDir, DIRECTORY_SEPARATOR) : base_path(self::DEFAULT_ARTIFACT_RELATIVE_DIR);

        return $root.DIRECTORY_SEPARATOR.$runId;
    }

    private function sourceLedgerDir(string $sourceLedgerDir): string
    {
        return $sourceLedgerDir !== ''
            ? rtrim($sourceLedgerDir, DIRECTORY_SEPARATOR)
            : base_path(self::SOURCE_LEDGER_RELATIVE_DIR);
    }

    /**
     * @return array{path:string,relative_path:string,sha256:string}
     */
    private function writeJson(string $path, array $payload): array
    {
        $this->ensureDirectory(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $this->artifactMeta($path);
    }

    /**
     * @return array{path:string,relative_path:string,sha256:string}
     */
    private function writeText(string $path, string $contents): array
    {
        $this->ensureDirectory(dirname($path));
        File::put($path, $contents);

        return $this->artifactMeta($path);
    }

    /**
     * @return array{path:string,relative_path:string,sha256:string}
     */
    private function artifactMeta(string $path): array
    {
        return [
            'path' => $this->redactPath($path),
            'relative_path' => $this->repoRelativePath($path),
            'sha256' => hash_file('sha256', $path) ?: '',
        ];
    }

    private function ensureDirectory(string $path): void
    {
        File::ensureDirectoryExists($path);
    }

    private function repoRelativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $normalized = str_replace('\\', '/', $path);
        $baseNormalized = str_replace('\\', '/', $base);

        return str_starts_with($normalized, $baseNormalized) ? substr($normalized, strlen($baseNormalized)) : basename($path);
    }

    private function redactPath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR);
        if (str_starts_with($path, $base)) {
            return '<backend>/'.ltrim(substr($path, strlen($base)), DIRECTORY_SEPARATOR);
        }

        $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        if ($tmp !== '' && str_starts_with($path, $tmp)) {
            return '<tmp>/'.ltrim(substr($path, strlen($tmp)), DIRECTORY_SEPARATOR);
        }

        $path = preg_replace('#/Users/[^/]+/#', '/Users/<redacted>/', $path) ?: $path;
        $path = preg_replace('#/private/tmp/#', '<tmp>/', $path) ?: $path;

        return preg_replace('#^/tmp/#', '<tmp>/', $path) ?: $path;
    }
}
