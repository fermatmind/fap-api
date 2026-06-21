<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets\Agent;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class EnneagramResultPageAgentReadiness
{
    public const SCHEMA_VERSION = 'fap.enneagram.result_page.agent_readiness.v1';

    public const DEFAULT_ARTIFACT_RELATIVE_DIR = 'artifacts/enneagram_result_page_agent';

    private const EXPECTED_CANDIDATE_MANIFEST_SHA256 = 'a9fd3eb474ea2ca0130d06ad2b1640305d9160ee1a74e559ad4f60bfc4db56c0';

    private const EXPECTED_RUNTIME_REGISTRY_MANIFEST_SHA256 = 'ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f';

    private const EXPECTED_PAYLOAD_COUNT = 630;

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

    private const FORBIDDEN_CLAIM_FAMILIES = [
        'diagnosis_or_treatment',
        'clinical_condition',
        'hiring_or_employment_screening',
        'fixed_type_certainty',
        'you_are_this_type',
        'final_typing_or_retyping',
        'score_comparison_between_e105_and_fc144',
        'fc144_more_accurate_claim',
        'ability_success_salary_or_performance_prediction',
        'medical_therapy_or_mental_health_advice',
    ];

    private const AGENT_BATCHES = [
        [
            'batch_id' => 'ENNEAGRAM-RESULT-AGENT-CONTROL-PACKET-01',
            'state' => 'ready_for_review',
            'allowed_output' => 'docs, control packet, readiness artifacts',
            'generation_allowed' => false,
        ],
        [
            'batch_id' => 'ENNEAGRAM-RESULT-SOURCE-LEDGER-01',
            'state' => 'planned',
            'allowed_output' => 'source ledger only',
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
     * @param  array{run_id?:string,artifact_dir?:string,strict?:bool,candidate_dir?:string}  $options
     * @return array<string,mixed>
     */
    public function audit(array $options = []): array
    {
        $runId = $this->sanitizeRunId((string) ($options['run_id'] ?? ''));
        $artifactDir = $this->artifactDir((string) ($options['artifact_dir'] ?? ''), $runId);
        $strict = ($options['strict'] ?? false) === true;
        $candidateDir = trim((string) ($options['candidate_dir'] ?? ''));

        $this->ensureDirectory($artifactDir);

        $controlPacket = $this->controlPacket();
        $readinessInventory = $this->readinessInventory($candidateDir);
        $validationCommands = $this->validationCommands();
        $safetyPolicy = $this->safetyPolicy();
        $goNoGo = $this->goNoGo($readinessInventory);

        $artifacts = [
            'control_packet.json' => $this->writeJson($artifactDir.'/control_packet.json', $controlPacket),
            'readiness_inventory.json' => $this->writeJson($artifactDir.'/readiness_inventory.json', $readinessInventory),
            'validation_commands.json' => $this->writeJson($artifactDir.'/validation_commands.json', $validationCommands),
            'safety_policy.json' => $this->writeJson($artifactDir.'/safety_policy.json', $safetyPolicy),
            'go_no_go.md' => $this->writeText($artifactDir.'/go_no_go.md', $goNoGo),
        ];

        $strictFailures = [];
        if ($strict && (bool) data_get($readinessInventory, 'candidate_dir.provided', false) === true) {
            foreach ((array) data_get($readinessInventory, 'candidate_dir.missing_required_files', []) as $missing) {
                $strictFailures[] = 'missing_candidate_artifact:'.$missing;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $strictFailures === [],
            'status' => $strictFailures === [] ? 'success' : 'blocked',
            'run_id' => $runId,
            'artifact_dir' => $artifactDir,
            'artifacts' => $artifacts,
            'strict' => $strict,
            'strict_failures' => $strictFailures,
            'summary' => [
                'candidate_generation_allowed' => false,
                'candidate_payload_count_expected' => self::EXPECTED_PAYLOAD_COUNT,
                'expected_candidate_manifest_sha256' => self::EXPECTED_CANDIDATE_MANIFEST_SHA256,
                'expected_runtime_registry_manifest_sha256' => self::EXPECTED_RUNTIME_REGISTRY_MANIFEST_SHA256,
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
                'out_of_launch_scope_must_equal' => ['1R-I', '1R-J'],
                'launch_scope_batches' => ['1R-A', '1R-B', '1R-C', '1R-D', '1R-E', '1R-F', '1R-G', '1R-H'],
            ],
            'agent_batches' => self::AGENT_BATCHES,
            'allowed_actions' => [
                'read existing docs and code',
                'write run-scoped readiness artifacts',
                'write source ledger templates',
                'prepare small candidate drafts only in a future explicitly authorized PR',
                'run Phase8B export QA against a caller-provided candidate directory',
                'run Phase8D2B inactive import simulation against a caller-provided candidate directory',
            ],
            'forbidden_actions' => [
                'bulk content generation in this PR',
                'production activation',
                'content_pack_activations write',
                'runtime registry switch',
                'production import',
                'frontend fallback copy',
                'fap-web runtime changes',
                'sitemap or llms exposure',
                'public SEO profile generation',
                'private result or attempt identifier export',
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
    private function validationCommands(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'validation_commands',
            'local_pr_validation' => [
                'php -l backend/app/Console/Commands/EnneagramResultPageAgentReadinessCommand.php',
                'php -l backend/app/Services/Enneagram/Assets/Agent/EnneagramResultPageAgentReadiness.php',
                'cd backend && php artisan test tests/Unit/Services/Enneagram/Assets/EnneagramResultPageAgentReadinessTest.php --no-ansi',
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
            'forbidden_public_payload_fields' => [
                'attempt_id',
                'user_id',
                'private_url',
                'private_path',
                'editor_notes',
                'qa_notes',
                'source_selection_notes',
                'internal_metadata',
                'raw_scores',
                'raw_score_vector',
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
     */
    private function goNoGo(array $readinessInventory): string
    {
        return implode("\n", [
            '# Enneagram Result Page Agent Go / No-Go',
            '',
            '- verdict: CONTROL_PACKET_ONLY',
            '- candidate_generation_allowed: false',
            '- ready_for_generation: false',
            '- ready_for_import: false',
            '- ready_for_activation: false',
            '- expected_candidate_manifest_sha256: `'.self::EXPECTED_CANDIDATE_MANIFEST_SHA256.'`',
            '- expected_runtime_registry_manifest_sha256: `'.self::EXPECTED_RUNTIME_REGISTRY_MANIFEST_SHA256.'`',
            '- expected_payload_count: '.self::EXPECTED_PAYLOAD_COUNT,
            '- runtime_registry_matches_expected: '.(((bool) data_get($readinessInventory, 'runtime_registry.matches_expected', false)) ? 'true' : 'false'),
            '- no bulk content generated',
            '- no production import',
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
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'ready_for_generation' => false,
            'ready_for_import' => false,
            'ready_for_runtime' => false,
            'ready_for_production' => false,
            'cms_write_performed' => false,
            'runtime_change_performed' => false,
            'activation_happened' => false,
            'bulk_content_generation_happened' => false,
            'frontend_fallback_allowed' => false,
        ];
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

        return preg_replace('#/Users/[^/]+/#', '/Users/<redacted>/', $path) ?: $path;
    }
}
