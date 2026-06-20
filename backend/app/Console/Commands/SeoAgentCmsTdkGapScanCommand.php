<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoAgent\CmsTdkGapReadonlyScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

final class SeoAgentCmsTdkGapScanCommand extends Command
{
    protected $signature = 'seo-agent:cms-tdk-gap-scan
        {--surface=all : Surface to scan: articles, content-pages, or all}
        {--limit=100 : Candidate limit, bounded 1..250}
        {--artifact-dir= : Directory for sanitized JSON artifacts}
        {--json : Emit JSON summary}';

    protected $description = 'Read-only CMS TDK gap scanner that emits SEO Agent control packet and Codex review handoff artifacts.';

    public function handle(CmsTdkGapReadonlyScanner $scanner): int
    {
        $surface = trim((string) $this->option('surface'));
        if (! in_array($surface, ['articles', 'content-pages', 'all'], true)) {
            return $this->finish($this->failureSummary('invalid_surface'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $scannerArtifact = $scanner->scan($surface, $this->limit());
        $timestamp = Carbon::now('UTC')->format('Ymd\THis\Z');
        $scannerRef = $this->writeArtifact($artifactDir, 'seo-agent-cms-tdk-gap-scan-'.$timestamp.'.json', $scannerArtifact);
        $packet = $this->runControlPacket($scannerArtifact, $scannerRef);
        $packetRef = $this->writeArtifact($artifactDir, 'seo-agent-run-control-packet-'.$timestamp.'.json', $packet);
        $handoff = $this->codexReviewHandoff($scannerArtifact, $scannerRef, $packetRef);
        $handoffRef = $this->writeArtifact($artifactDir, 'seo-agent-codex-review-handoff-'.$timestamp.'.json', $handoff);

        return $this->finish([
            'schema_version' => CmsTdkGapReadonlyScanner::SCHEMA_VERSION,
            'task' => CmsTdkGapReadonlyScanner::TASK,
            'ok' => true,
            'status' => 'success',
            'surface' => $surface,
            'candidate_count' => $scannerArtifact['candidate_count'] ?? 0,
            'artifacts' => [
                'scanner' => $scannerRef,
                'run_control_packet' => $packetRef,
                'codex_review_handoff' => $handoffRef,
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
    }

    private function limit(): int
    {
        $raw = trim((string) $this->option('limit'));
        if (preg_match('/^\d+$/', $raw) !== 1) {
            return 100;
        }

        return max(1, min((int) $raw, 250));
    }

    private function artifactDir(): ?string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            $dir = storage_path('app/seo-agent');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function writeArtifact(string $artifactDir, string $filename, array $payload): array
    {
        $path = rtrim($artifactDir, '/').'/'.$filename;
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($encoded) || file_put_contents($path, $encoded."\n") === false) {
            throw new RuntimeException('artifact_write_failed');
        }

        return [
            'path' => $path,
            'size' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
            'schema_version' => (string) ($payload['schema_version'] ?? $payload['version'] ?? 'unknown'),
            'sanitized_summary' => [
                'candidate_count' => (int) ($payload['candidate_count'] ?? 0),
                'forbidden_output_fields_absent' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $scannerArtifact
     * @param  array<string, mixed>  $scannerRef
     * @return array<string, mixed>
     */
    private function runControlPacket(array $scannerArtifact, array $scannerRef): array
    {
        return [
            'schema_version' => 'seo-agent-run-control-packet.v1',
            'run_id' => 'cms-tdk-gap-'.Carbon::now('UTC')->format('YmdHis'),
            'run_mode' => 'readonly_discovery',
            'trigger' => 'manual_cli',
            'scope' => [
                'source_family' => 'cms_tdk_gap',
                'surface' => (string) ($scannerArtifact['surface'] ?? 'all'),
                'write_scope' => 'none',
            ],
            'input_refs' => [
                'command' => 'php artisan seo-agent:cms-tdk-gap-scan',
                'surface' => (string) ($scannerArtifact['surface'] ?? 'all'),
                'limit' => (int) ($scannerArtifact['limit'] ?? 100),
            ],
            'evidence_refs' => [$scannerRef],
            'model_review' => [
                'reviewer' => 'codex',
                'role' => 'review_only',
                'execution_permission' => false,
                'required_output' => 'seo-agent-codex-review-handoff.v1',
            ],
            'approval' => [
                'status' => 'not_requested',
                'approved_actions' => [],
            ],
            'forbidden_actions' => [
                'cms_write',
                'cms_publish',
                'search_channel_enqueue',
                'search_channel_submit',
                'indexing_request',
                'sitemap_submission',
                'scheduler_activation',
                'queue_worker_activation',
                'production_env_update',
                'source_code_mutation',
            ],
            'allowed_actions' => [
                'readonly_scan',
                'evidence_artifact_write',
                'codex_review_handoff_artifact',
            ],
            'output_artifacts' => [
                'scanner' => $scannerRef,
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
            'next_step' => 'Codex may review the handoff JSON and recommend dry-run-only CMS draft package scope; it must not execute changes.',
        ];
    }

    /**
     * @param  array<string, mixed>  $scannerArtifact
     * @param  array<string, mixed>  $scannerRef
     * @param  array<string, mixed>  $packetRef
     * @return array<string, mixed>
     */
    private function codexReviewHandoff(array $scannerArtifact, array $scannerRef, array $packetRef): array
    {
        return [
            'schema_version' => 'seo-agent-codex-review-handoff.v1',
            'task' => CmsTdkGapReadonlyScanner::TASK,
            'reviewer' => 'codex',
            'role' => 'review_only',
            'execution_permission' => false,
            'input_control_packet' => $packetRef,
            'input_candidates' => $scannerRef,
            'candidate_count' => (int) ($scannerArtifact['candidate_count'] ?? 0),
            'review_output_contract' => [
                'worth_optimizing' => 'boolean',
                'recommended_action' => 'readonly_review|cms_draft_package_dry_run|defer',
                'risk_flags' => 'list<string>',
                'needs_human_approval' => 'boolean',
            ],
            'candidate_preview' => array_slice((array) ($scannerArtifact['candidates'] ?? []), 0, 20),
            'forbidden_actions' => [
                'cms_write',
                'cms_publish',
                'search_channel_enqueue',
                'search_channel_submit',
                'indexing_request',
                'scheduler_activation',
                'queue_worker_activation',
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failureSummary(string $issue): array
    {
        return [
            'schema_version' => CmsTdkGapReadonlyScanner::SCHEMA_VERSION,
            'task' => CmsTdkGapReadonlyScanner::TASK,
            'ok' => false,
            'status' => 'blocked',
            'issues' => [$issue],
            'candidate_count' => 0,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function finish(array $summary): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('status='.(string) ($summary['status'] ?? 'unknown'));
            $this->line('candidate_count='.(string) ($summary['candidate_count'] ?? 0));
            foreach ((array) ($summary['artifacts'] ?? []) as $name => $artifact) {
                if (is_array($artifact)) {
                    $this->line($name.'_artifact_path='.(string) ($artifact['path'] ?? ''));
                    $this->line($name.'_artifact_size='.(string) ($artifact['size'] ?? 0));
                    $this->line($name.'_artifact_sha256='.(string) ($artifact['sha256'] ?? ''));
                }
            }
            foreach ((array) ($summary['issues'] ?? []) as $issue) {
                $this->line('issue='.(string) $issue);
            }
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, bool>
     */
    private function negativeGuarantees(): array
    {
        return [
            'database_write' => false,
            'cms_write' => false,
            'cms_publish' => false,
            'search_channel_enqueue' => false,
            'search_channel_submit' => false,
            'indexing_request' => false,
            'sitemap_submission' => false,
            'scheduler_activation' => false,
            'queue_worker_started' => false,
            'production_env_change' => false,
            'source_code_mutation' => false,
            'pr_train_metadata_change' => false,
        ];
    }
}
