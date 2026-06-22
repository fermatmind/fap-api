<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Services\BigFive\ResultPageV2\AssetAgent\BigFiveResultPageV2AssetAgent;
use App\Services\Enneagram\Assets\Agent\EnneagramResultPageContentBatchAutomation;
use Illuminate\Support\Facades\File;

final class Batch2ResultPageDryRunGate
{
    public const SCHEMA_VERSION = 'fap.result_page.batch2_dry_run_gate.v0.1';

    public const DEFAULT_ARTIFACT_RELATIVE_DIR = 'artifacts/result_page_batch2_dry_run_gate';

    public const DEFAULT_BIGFIVE_CANDIDATE_RELATIVE_DIR = 'content_assets/big5/result_page_v2/agent_runs/candidate_batch_001';

    public const NEXT_ALLOWED_PR = 'FA30-API-03';

    public function __construct(
        private readonly BigFiveResultPageV2AssetAgent $bigFiveAgent,
        private readonly EnneagramResultPageContentBatchAutomation $enneagramAutomation,
    ) {}

    /**
     * @param  array{
     *     run_id?:string,
     *     artifact_dir?:string,
     *     bigfive_candidate_dir?:string,
     *     enneagram_public_payload?:array<string,mixed>|null,
     *     strict?:bool
     * }  $options
     * @return array<string,mixed>
     */
    public function run(array $options = []): array
    {
        $runId = $this->sanitizeSlug((string) ($options['run_id'] ?? 'batch2-dry-run-gate'));
        $artifactDir = $this->artifactDir((string) ($options['artifact_dir'] ?? ''), $runId);
        $bigFiveCandidateDir = $this->absolutePathOrDefault(
            (string) ($options['bigfive_candidate_dir'] ?? ''),
            base_path(self::DEFAULT_BIGFIVE_CANDIDATE_RELATIVE_DIR)
        );
        $strict = ($options['strict'] ?? false) === true;
        $enneagramPublicPayload = is_array($options['enneagram_public_payload'] ?? null)
            ? (array) $options['enneagram_public_payload']
            : null;

        $this->ensureDirectory($artifactDir);

        $bigFive = $this->bigFiveAgent->stageCandidates([
            'run_id' => 'bigfive-stage',
            'artifact_dir' => $artifactDir.'/bigfive',
            'candidate_dir' => $bigFiveCandidateDir,
            'allow_staging_write' => false,
        ]);

        $enneagram = $this->enneagramAutomation->evaluate([
            'run_id' => 'enneagram-evaluate',
            'artifact_dir' => $artifactDir.'/enneagram',
            'public_payload' => $enneagramPublicPayload,
            'strict' => $strict,
        ]);

        $errors = [];
        if (($bigFive['ok'] ?? false) !== true) {
            $errors[] = 'bigfive_batch2_dry_run_gate_blocked';
        }
        if ((bool) data_get($bigFive, 'summary.staging_write_performed', false)) {
            $errors[] = 'bigfive_staging_write_performed';
        }
        if (($enneagram['ok'] ?? false) !== true) {
            $errors[] = 'enneagram_batch2_dry_run_gate_blocked';
        }
        if ((bool) data_get($enneagram, 'summary.bulk_generation_allowed', true)) {
            $errors[] = 'enneagram_bulk_generation_allowed';
        }
        if ((bool) data_get($enneagram, 'summary.production_execution_allowed_for_agent', true)) {
            $errors[] = 'enneagram_production_execution_allowed';
        }

        $ok = $errors === [];
        $status = $ok ? 'success' : 'blocked';

        $report = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'batch2_result_page_dry_run_gate',
            'run_id' => $runId,
            'runtime_use' => 'not_runtime',
            'production_use_allowed' => false,
            'ready_for_pilot' => false,
            'ready_for_runtime' => false,
            'ready_for_production' => false,
            'next_allowed_pr' => self::NEXT_ALLOWED_PR,
            'go_no_go' => $ok ? 'GO_FOR_BATCH2_READBACK_REVIEW_LEDGER_ONLY' : 'NO_GO_CURRENT_PR_BLOCKED',
            'production_go_no_go' => 'NO_GO',
            'bigfive' => [
                'status' => ($bigFive['ok'] ?? false) === true ? 'pass' : 'blocked',
                'candidate_dir' => $this->relativeOrRawPath($bigFiveCandidateDir),
                'summary' => [
                    'selector_candidate_count' => (int) data_get($bigFive, 'summary.selector_candidate_count', 0),
                    'content_candidate_count' => (int) data_get($bigFive, 'summary.content_candidate_count', 0),
                    'validation_error_count' => (int) data_get($bigFive, 'summary.validation_error_count', 0),
                    'review_error_count' => (int) data_get($bigFive, 'summary.review_error_count', 0),
                    'leak_hit_count' => (int) data_get($bigFive, 'summary.leak_hit_count', 0),
                    'staging_write_performed' => (bool) data_get($bigFive, 'summary.staging_write_performed', false),
                ],
                'negative_guarantees' => $this->booleanMap((array) ($bigFive['negative_guarantees'] ?? [])),
                'artifacts' => $this->artifactMap((array) ($bigFive['artifacts'] ?? [])),
            ],
            'enneagram' => [
                'status' => ($enneagram['ok'] ?? false) === true ? 'pass' : 'blocked',
                'summary' => [
                    'payload_count' => (int) data_get($enneagram, 'summary.payload_count', 0),
                    'bulk_generation_allowed' => (bool) data_get($enneagram, 'summary.bulk_generation_allowed', true),
                    'source_mapping_zero_failures' => (bool) data_get($enneagram, 'summary.source_mapping_zero_failures', false),
                    'metadata_leakage_zero' => (bool) data_get($enneagram, 'summary.metadata_leakage_zero', false),
                    'forbidden_claim_zero' => (bool) data_get($enneagram, 'summary.forbidden_claim_zero', false),
                    'fc144_boundary_zero' => (bool) data_get($enneagram, 'summary.fc144_boundary_zero', false),
                    'production_execution_allowed_for_agent' => (bool) data_get($enneagram, 'summary.production_execution_allowed_for_agent', true),
                ],
                'negative_guarantees' => $this->booleanMap((array) ($enneagram['negative_guarantees'] ?? [])),
                'artifacts' => $this->artifactMap((array) ($enneagram['artifacts'] ?? [])),
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
            'error_count' => count($errors),
            'errors' => $errors,
        ];

        $artifacts = [
            'batch2_result_page_dry_run_gate_report.json' => $this->writeJson(
                $artifactDir.'/batch2_result_page_dry_run_gate_report.json',
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
            $mapped[(string) $name] = (string) ($artifact['relative_path'] ?? '');
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
            'bigfive_candidate_generation_happened' => false,
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

        return trim($sanitized, '-') ?: 'batch2-dry-run-gate';
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            File::makeDirectory($path, 0777, true);
        }
    }
}
