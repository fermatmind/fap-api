<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class SeoAgentWeeklyReadonlyRunnerCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-weekly-readonly-runner.v1';

    protected $signature = 'seo-agent:weekly-readonly-runner
        {--sources=cms-tdk-gap,runtime-seo-qa,cms-faq-gap : Comma-separated readonly sources}
        {--limit=100 : Candidate limit, bounded 1..250}
        {--artifact-dir= : Directory for weekly evidence artifacts}
        {--json : Emit JSON summary}';

    protected $description = 'Run the SEO Agent weekly readonly chain and write a standard evidence artifact without scheduler or writes.';

    public function handle(): int
    {
        $sources = $this->sources();
        if ($sources === []) {
            return $this->finish($this->failureSummary('invalid_sources'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $limit = $this->limit();
        $timestamp = Carbon::now('UTC')->format('Ymd\THis\Z');
        $runDir = rtrim($artifactDir, '/').'/weekly-readonly-run-'.$timestamp;

        $runCommand = $this->getApplication()?->find('seo-agent:run');
        if ($runCommand === null) {
            return $this->finish($this->failureSummary('delegated_command_missing'));
        }

        $runOutputBuffer = new BufferedOutput();
        $exitCode = $runCommand->run(new ArrayInput([
            'command' => 'seo-agent:run',
            '--sources' => implode(',', $sources),
            '--limit' => $limit,
            '--artifact-dir' => $runDir,
            '--json' => true,
        ]), $runOutputBuffer);

        $runOutput = trim($runOutputBuffer->fetch());
        $runSummary = json_decode($runOutput, true);

        if (! is_array($runSummary)) {
            $evidence = $this->weeklyEvidence($sources, $limit, 'blocked', [
                'issues' => ['run_summary_json_invalid'],
                'run_exit_code' => $exitCode,
            ]);
            $artifact = $this->writeArtifact($artifactDir, 'seo-agent-weekly-readonly-runner-'.$timestamp.'.json', $evidence);

            return $this->finish($this->failureSummary('run_summary_json_invalid', [
                'artifact' => $artifact,
            ]));
        }

        $status = $exitCode === self::SUCCESS && ($runSummary['ok'] ?? false) === true ? 'success' : 'blocked';
        $evidence = $this->weeklyEvidence($sources, $limit, $status, [
            'run_exit_code' => $exitCode,
            'run_artifact' => (array) ($runSummary['artifact'] ?? []),
            'candidate_count' => (int) ($runSummary['candidate_count'] ?? 0),
            'worth_optimizing_count' => (int) ($runSummary['worth_optimizing_count'] ?? 0),
            'draft_brief_count' => (int) ($runSummary['draft_brief_count'] ?? 0),
            'issues' => array_values(array_map('strval', (array) ($runSummary['issues'] ?? []))),
        ]);
        $artifact = $this->writeArtifact($artifactDir, 'seo-agent-weekly-readonly-runner-'.$timestamp.'.json', $evidence);

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $status === 'success',
            'status' => $status,
            'sources' => $sources,
            'candidate_count' => (int) ($runSummary['candidate_count'] ?? 0),
            'worth_optimizing_count' => (int) ($runSummary['worth_optimizing_count'] ?? 0),
            'draft_brief_count' => (int) ($runSummary['draft_brief_count'] ?? 0),
            'artifact' => $artifact,
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function sources(): array
    {
        $allowed = ['cms-tdk-gap', 'runtime-seo-qa', 'cms-faq-gap'];
        $sources = array_values(array_unique(array_filter(array_map(
            static fn (string $source): string => trim($source),
            explode(',', (string) $this->option('sources'))
        ))));

        foreach ($sources as $source) {
            if (! in_array($source, $allowed, true)) {
                return [];
            }
        }

        return $sources;
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
            $dir = storage_path('app/seo-agent/weekly');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @param  list<string>  $sources
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function weeklyEvidence(array $sources, int $limit, string $status, array $extra = []): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'SEO-AGENT-WEEKLY-READONLY-RUNNER-01',
            'status' => $status,
            'run_mode' => 'weekly_readonly_discovery_to_dry_run',
            'trigger' => 'manual_cli_or_external_weekly_automation',
            'command' => 'php artisan seo-agent:weekly-readonly-runner',
            'delegated_command' => 'php artisan seo-agent:run',
            'sources' => $sources,
            'limit' => $limit,
            'schedule_contract' => [
                'laravel_production_scheduler_enabled_by_pr' => false,
                'queue_worker_started_by_pr' => false,
                'recommended_frequency' => 'weekly',
                'activation_requires_separate_approval' => true,
            ],
            'chain' => [
                'scanner_artifacts',
                'seo-agent-opportunity-aggregate.v1',
                'seo-agent-run-control-packet.v1',
                'seo-agent-codex-review-handoff.v1',
                'seo-agent-codex-review-verdict.v1',
                'seo-agent-cms-draft-package-dry-run.v1',
                'seo-agent-run-evidence.v1',
            ],
            ...$extra,
            'forbidden_actions' => $this->forbiddenActions(),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
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
            'schema_version' => (string) ($payload['schema_version'] ?? 'unknown'),
            'sanitized_summary' => [
                'candidate_count' => (int) ($payload['candidate_count'] ?? 0),
                'draft_brief_count' => (int) ($payload['draft_brief_count'] ?? 0),
                'forbidden_output_fields_absent' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function failureSummary(string $issue, array $extra = []): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => false,
            'status' => 'blocked',
            'issues' => [$issue],
            ...$extra,
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
            foreach ((array) ($summary['issues'] ?? []) as $issue) {
                $this->line('issue='.(string) $issue);
            }
            if (is_array($summary['artifact'] ?? null)) {
                $this->line('artifact_path='.(string) ($summary['artifact']['path'] ?? ''));
                $this->line('artifact_size='.(string) ($summary['artifact']['size'] ?? 0));
                $this->line('artifact_sha256='.(string) ($summary['artifact']['sha256'] ?? ''));
            }
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function forbiddenActions(): array
    {
        return [
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
            'external_model_api_call',
        ];
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
            'google_search_console_api_call' => false,
            'google_indexing_api_call' => false,
            'external_model_api_call' => false,
            'pr_train_metadata_change' => false,
        ];
    }
}
