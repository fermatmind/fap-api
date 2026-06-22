<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Services\BigFive\ResultPageV2\AssetAgent\BigFiveResultPageV2AssetAgent;
use App\Services\Enneagram\Assets\Agent\EnneagramResultPageAgentReadiness;
use App\Services\Enneagram\Assets\Agent\EnneagramResultPageContentBatchAutomation;
use Illuminate\Support\Facades\File;

final class Batch2ResultPageReadbackReviewLedger
{
    public const SCHEMA_VERSION = 'fap.result_page.batch2_readback_review_ledger.v0.1';

    public const DEFAULT_ARTIFACT_RELATIVE_DIR = 'artifacts/result_page_batch2_readback_review_ledger';

    public const DEFAULT_BIGFIVE_CANDIDATE_RELATIVE_DIR = 'content_assets/big5/result_page_v2/agent_runs/candidate_batch_001';

    public const NEXT_ALLOWED_PR = 'FA30-WEB-02';

    public function __construct(
        private readonly BigFiveResultPageV2AssetAgent $bigFiveAgent,
        private readonly EnneagramResultPageAgentReadiness $enneagramReadiness,
        private readonly EnneagramResultPageContentBatchAutomation $enneagramAutomation,
    ) {}

    /**
     * @param  array{
     *     run_id?:string,
     *     artifact_dir?:string,
     *     bigfive_candidate_dir?:string,
     *     enneagram_source_ledger_dir?:string,
     *     enneagram_public_payload?:array<string,mixed>|null
     * }  $options
     * @return array<string,mixed>
     */
    public function run(array $options = []): array
    {
        $runId = $this->sanitizeSlug((string) ($options['run_id'] ?? 'batch2-readback-review-ledger'));
        $artifactDir = $this->artifactDir((string) ($options['artifact_dir'] ?? ''), $runId);
        $bigFiveCandidateDir = $this->absolutePathOrDefault(
            (string) ($options['bigfive_candidate_dir'] ?? ''),
            base_path(self::DEFAULT_BIGFIVE_CANDIDATE_RELATIVE_DIR)
        );
        $enneagramSourceLedgerDir = trim((string) ($options['enneagram_source_ledger_dir'] ?? ''));
        $enneagramPublicPayload = is_array($options['enneagram_public_payload'] ?? null)
            ? (array) $options['enneagram_public_payload']
            : null;

        $this->ensureDirectory($artifactDir);

        $bigFive = $this->bigFiveAgent->stageCandidates([
            'run_id' => 'bigfive-review',
            'artifact_dir' => $artifactDir.'/bigfive',
            'candidate_dir' => $bigFiveCandidateDir,
            'allow_staging_write' => false,
        ]);
        $bigFiveReviewManifest = $this->readOptionalJson($bigFiveCandidateDir.'/review_manifest.json');

        $enneagramReadiness = $this->enneagramReadiness->audit(array_filter([
            'run_id' => 'enneagram-readiness',
            'artifact_dir' => $artifactDir.'/enneagram-readiness',
            'source_ledger_dir' => $enneagramSourceLedgerDir !== '' ? $enneagramSourceLedgerDir : null,
            'strict' => false,
        ], static fn (mixed $value): bool => $value !== null));

        $enneagramBatch = $this->enneagramAutomation->evaluate([
            'run_id' => 'enneagram-readback',
            'artifact_dir' => $artifactDir.'/enneagram-batch',
            'public_payload' => $enneagramPublicPayload,
            'strict' => false,
        ]);

        $errors = [];
        if (($bigFive['ok'] ?? false) !== true) {
            $errors[] = 'bigfive_readback_review_blocked';
        }
        if ((bool) data_get($bigFive, 'summary.staging_write_performed', false)) {
            $errors[] = 'bigfive_staging_write_performed';
        }
        if ((bool) data_get($enneagramReadiness, 'summary.source_ledger_valid', false) !== true) {
            $errors[] = 'enneagram_source_ledger_invalid';
        }
        if (($enneagramBatch['ok'] ?? false) !== true) {
            $errors[] = 'enneagram_readback_review_blocked';
        }
        if ((bool) data_get($enneagramBatch, 'summary.bulk_generation_allowed', true)) {
            $errors[] = 'enneagram_bulk_generation_allowed';
        }
        if ((bool) data_get($enneagramBatch, 'summary.production_execution_allowed_for_agent', true)) {
            $errors[] = 'enneagram_production_execution_allowed';
        }

        $ok = $errors === [];
        $status = $ok ? 'success' : 'blocked';

        $report = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'batch2_result_page_readback_review_ledger',
            'run_id' => $runId,
            'runtime_use' => 'not_runtime',
            'production_use_allowed' => false,
            'ready_for_pilot' => false,
            'ready_for_runtime' => false,
            'ready_for_production' => false,
            'authority_state' => 'backend_readback_review_authority_only',
            'next_allowed_pr' => self::NEXT_ALLOWED_PR,
            'go_no_go' => $ok ? 'GO_FOR_FA30-WEB-02_FRONTEND_RUNTIME_QA_ONLY' : 'NO_GO_CURRENT_PR_BLOCKED',
            'production_go_no_go' => 'NO_GO',
            'bigfive' => [
                'status' => ($bigFive['ok'] ?? false) === true ? 'pass' : 'blocked',
                'candidate_dir' => $this->relativeOrRawPath($bigFiveCandidateDir),
                'review_manifest' => [
                    'present' => $bigFiveReviewManifest !== null,
                    'human_reviewed' => (bool) ($bigFiveReviewManifest['human_reviewed'] ?? false),
                    'review_status' => (string) ($bigFiveReviewManifest['review_status'] ?? ''),
                    'runtime_use' => (string) ($bigFiveReviewManifest['runtime_use'] ?? ''),
                    'production_use_allowed' => (bool) ($bigFiveReviewManifest['production_use_allowed'] ?? false),
                    'ready_for_pilot' => (bool) ($bigFiveReviewManifest['ready_for_pilot'] ?? false),
                    'ready_for_runtime' => (bool) ($bigFiveReviewManifest['ready_for_runtime'] ?? false),
                    'ready_for_production' => (bool) ($bigFiveReviewManifest['ready_for_production'] ?? false),
                    'reviewed_by' => $this->nullableString($bigFiveReviewManifest['reviewed_by'] ?? null),
                    'reviewed_at' => $this->nullableString($bigFiveReviewManifest['reviewed_at'] ?? null),
                ],
                'summary' => [
                    'selector_candidate_count' => (int) data_get($bigFive, 'summary.selector_candidate_count', 0),
                    'content_candidate_count' => (int) data_get($bigFive, 'summary.content_candidate_count', 0),
                    'validation_error_count' => (int) data_get($bigFive, 'summary.validation_error_count', 0),
                    'review_error_count' => (int) data_get($bigFive, 'summary.review_error_count', 0),
                    'leak_hit_count' => (int) data_get($bigFive, 'summary.leak_hit_count', 0),
                    'staging_write_performed' => (bool) data_get($bigFive, 'summary.staging_write_performed', false),
                ],
                'readback_authority' => [
                    'authority_layer' => 'review_manifest_and_candidate_validation',
                    'frontend_fallback_allowed' => false,
                    'public_runtime_allowed' => false,
                    'production_write_allowed' => false,
                ],
                'negative_guarantees' => $this->booleanMap((array) ($bigFive['negative_guarantees'] ?? [])),
                'artifacts' => $this->artifactMap((array) ($bigFive['artifacts'] ?? [])),
            ],
            'enneagram' => [
                'status' => ((bool) data_get($enneagramReadiness, 'summary.source_ledger_valid', false) === true
                    && ($enneagramBatch['ok'] ?? false) === true) ? 'pass' : 'blocked',
                'source_ledger' => [
                    'valid' => (bool) data_get($enneagramReadiness, 'summary.source_ledger_valid', false),
                    'candidate_dir_provided' => (bool) data_get($enneagramReadiness, 'summary.candidate_dir_provided', false),
                    'candidate_contract_valid' => (bool) data_get($enneagramReadiness, 'summary.candidate_contract_valid', false),
                    'ready_for_generation' => (bool) data_get($enneagramReadiness, 'summary.ready_for_generation', false),
                    'ready_for_import' => (bool) data_get($enneagramReadiness, 'summary.ready_for_import', false),
                    'ready_for_activation' => (bool) data_get($enneagramReadiness, 'summary.ready_for_activation', false),
                ],
                'batch_summary' => [
                    'payload_count' => (int) data_get($enneagramBatch, 'summary.payload_count', 0),
                    'bulk_generation_allowed' => (bool) data_get($enneagramBatch, 'summary.bulk_generation_allowed', true),
                    'source_mapping_zero_failures' => (bool) data_get($enneagramBatch, 'summary.source_mapping_zero_failures', false),
                    'metadata_leakage_zero' => (bool) data_get($enneagramBatch, 'summary.metadata_leakage_zero', false),
                    'forbidden_claim_zero' => (bool) data_get($enneagramBatch, 'summary.forbidden_claim_zero', false),
                    'fc144_boundary_zero' => (bool) data_get($enneagramBatch, 'summary.fc144_boundary_zero', false),
                    'production_execution_allowed_for_agent' => (bool) data_get($enneagramBatch, 'summary.production_execution_allowed_for_agent', true),
                ],
                'readback_authority' => [
                    'authority_layer' => 'source_ledger_and_batch_reports',
                    'frontend_fallback_allowed' => false,
                    'public_runtime_allowed' => false,
                    'production_write_allowed' => false,
                ],
                'negative_guarantees' => [
                    'readiness' => $this->booleanMap((array) ($enneagramReadiness['negative_guarantees'] ?? [])),
                    'batch' => $this->booleanMap((array) ($enneagramBatch['negative_guarantees'] ?? [])),
                ],
                'artifacts' => [
                    'readiness' => $this->artifactMap((array) ($enneagramReadiness['artifacts'] ?? [])),
                    'batch' => $this->artifactMap((array) ($enneagramBatch['artifacts'] ?? [])),
                ],
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
            'error_count' => count($errors),
            'errors' => $errors,
        ];

        $artifacts = [
            'batch2_result_page_readback_review_ledger_report.json' => $this->writeJson(
                $artifactDir.'/batch2_result_page_readback_review_ledger_report.json',
                $report
            ),
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $ok,
            'status' => $status,
            'run_id' => $runId,
            'artifact_dir' => $this->relativeOrRawPath($artifactDir),
            'artifacts' => $artifacts,
            'summary' => [
                'go_no_go' => $report['go_no_go'],
                'production_go_no_go' => $report['production_go_no_go'],
                'next_allowed_pr' => self::NEXT_ALLOWED_PR,
                'bigfive_status' => data_get($report, 'bigfive.status'),
                'enneagram_status' => data_get($report, 'enneagram.status'),
                'authority_state' => $report['authority_state'],
            ],
            'errors' => $errors,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,string>
     */
    private function writeJson(string $path, array $payload): array
    {
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return [
            'relative_path' => $this->relativeOrRawPath($path),
            'sha256' => hash_file('sha256', $path) ?: '',
        ];
    }

    /**
     * @param  array<string,mixed>  $artifacts
     * @return array<string,string>
     */
    private function artifactMap(array $artifacts): array
    {
        $mapped = [];
        foreach ($artifacts as $name => $artifact) {
            if (! is_array($artifact)) {
                continue;
            }
            $mapped[(string) $name] = (string) ($artifact['relative_path'] ?? data_get($artifact, 'path', ''));
        }

        return $mapped;
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,bool>
     */
    private function booleanMap(array $values): array
    {
        $mapped = [];
        foreach ($values as $key => $value) {
            $mapped[(string) $key] = (bool) $value;
        }

        return $mapped;
    }

    /**
     * @return array<string,bool>
     */
    private function negativeGuarantees(): array
    {
        return [
            'bigfive_staging_write_happened' => false,
            'enneagram_bulk_generation_happened' => false,
            'candidate_import_happened' => false,
            'production_activation_happened' => false,
            'runtime_switch_happened' => false,
            'production_write_happened' => false,
            'frontend_change_happened' => false,
        ];
    }

    private function artifactDir(string $root, string $runId): string
    {
        $artifactRoot = trim($root) !== '' ? rtrim($root, DIRECTORY_SEPARATOR) : base_path(self::DEFAULT_ARTIFACT_RELATIVE_DIR);

        return $artifactRoot.DIRECTORY_SEPARATOR.$runId;
    }

    private function absolutePathOrDefault(string $value, string $default): string
    {
        $value = trim($value);
        if ($value === '') {
            return $default;
        }

        return str_starts_with($value, DIRECTORY_SEPARATOR) ? $value : base_path($value);
    }

    private function relativeOrRawPath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }

        return $path;
    }

    private function sanitizeSlug(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($value)) ?: '';

        return trim($sanitized, '-') ?: 'batch2-readback-review-ledger';
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            File::makeDirectory($path, 0777, true);
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readOptionalJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
