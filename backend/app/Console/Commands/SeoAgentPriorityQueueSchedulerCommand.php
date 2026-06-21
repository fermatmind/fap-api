<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class SeoAgentPriorityQueueSchedulerCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-priority-queue-scheduler.v1';

    protected $signature = 'seo-agent:priority-queue-scheduler
        {--mode=weekly-l5-low-risk : Scheduler mode; only weekly-l5-low-risk is supported}
        {--sources=cms-tdk-gap,runtime-seo-qa,cms-faq-gap : Comma-separated readonly discovery sources}
        {--limit=100 : Discovery candidate limit, bounded 1..250}
        {--publish-limit=3 : Maximum low-risk ContentPage canaries to publish, 1..3}
        {--draft-limit=10 : Maximum low-risk CMS draft revisions to write, 1..10}
        {--base-url= : Public site base URL for IndexNow target resolution}
        {--artifact-dir= : Directory for L5 scheduler evidence artifacts}
        {--json : Emit JSON summary}';

    protected $description = 'External-cron L5-A low-risk SEO Agent orchestrator; writes drafts, publishes bounded ContentPage canaries, and submits IndexNow only.';

    public function handle(): int
    {
        if ((string) $this->option('mode') !== 'weekly-l5-low-risk') {
            return $this->finish($this->failureSummary('unsupported_mode'));
        }

        $sources = $this->sources();
        if ($sources === []) {
            return $this->finish($this->failureSummary('invalid_sources'));
        }

        $publishLimit = $this->boundedInt('publish-limit', 1, 3);
        $draftLimit = $this->boundedInt('draft-limit', 1, 10);
        if ($publishLimit === null) {
            return $this->finish($this->failureSummary('publish_limit_out_of_bounds'));
        }
        if ($draftLimit === null) {
            return $this->finish($this->failureSummary('draft_limit_out_of_bounds'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $limit = $this->limit();
        $timestamp = Carbon::now('UTC')->format('Ymd\THis\Z');
        $runDir = rtrim($artifactDir, '/').'/priority-queue-scheduler-run-'.$timestamp;
        if (! is_dir($runDir) && ! mkdir($runDir, 0775, true) && ! is_dir($runDir)) {
            return $this->finish($this->failureSummary('run_artifact_dir_unwritable'));
        }

        $steps = [];
        $weekly = $this->runSubCommand('seo-agent:weekly-draft-write-auto', [
            '--sources' => implode(',', $sources),
            '--limit' => $limit,
            '--draft-limit' => $draftLimit,
            '--artifact-dir' => $this->stepDir($runDir, '01-weekly-draft-write-auto'),
            '--json' => true,
        ]);
        $steps['weekly_draft_write_auto'] = $this->stepSummary($weekly);
        if (! $this->ok($weekly)) {
            return $this->finishWithEvidence($artifactDir, $timestamp, 'blocked', $sources, $limit, $draftLimit, $publishLimit, $steps, [
                'issue' => 'weekly_draft_write_auto_failed',
            ]);
        }

        $weeklyArtifactPath = (string) data_get($weekly, 'artifact.path', '');
        if ($weeklyArtifactPath === '' || ! is_file($weeklyArtifactPath)) {
            return $this->finishWithEvidence($artifactDir, $timestamp, 'blocked', $sources, $limit, $draftLimit, $publishLimit, $steps, [
                'issue' => 'weekly_draft_write_auto_artifact_missing',
            ]);
        }

        $weeklyEvidence = $this->readJson($weeklyArtifactPath);
        $packagePath = (string) data_get($weeklyEvidence, 'filtered_package.path', '');
        $draftWriteEvidencePath = (string) data_get($weeklyEvidence, 'draft_write.artifact.path', '');
        if (($draftWriteEvidencePath === '' || ! is_file($draftWriteEvidencePath)) && $packagePath !== '' && is_file($packagePath)) {
            $draftWriteRef = $this->writeArtifact($this->stepDir($runDir, '01-weekly-draft-write-auto'), 'seo-agent-priority-queue-scheduler-draft-write-evidence-'.$timestamp.'.json', [
                'schema_version' => 'seo-agent-controlled-cms-draft-write.v1',
                'ok' => (bool) data_get($weeklyEvidence, 'draft_write.ok', false),
                'status' => (string) data_get($weeklyEvidence, 'draft_write.status', ''),
                'execute' => true,
                'approval_mode' => 'low_risk_auto_approved',
                'package_sha256' => hash_file('sha256', $packagePath) ?: '',
                'writes_attempted' => (bool) data_get($weeklyEvidence, 'draft_write.writes_attempted', false),
                'writes_committed' => (bool) data_get($weeklyEvidence, 'draft_write.writes_committed', false),
                'rows_created' => (int) data_get($weeklyEvidence, 'draft_write.rows_created', 0),
                'rows_skipped_existing' => (int) data_get($weeklyEvidence, 'draft_write.rows_skipped_existing', 0),
                'negative_guarantees' => [
                    'cms_publish' => false,
                    'search_channel_submit' => false,
                    'indexing_request' => false,
                ],
            ]);
            $draftWriteEvidencePath = (string) $draftWriteRef['path'];
            $steps['weekly_draft_write_auto']['draft_write_evidence'] = $draftWriteRef;
        }

        $preflight = $this->runSubCommand('seo-agent:auto-rollback-guard', [
            '--run-evidence' => $weeklyArtifactPath,
            '--mode' => 'preflight',
            '--artifact-dir' => $this->stepDir($runDir, '02-rollback-preflight'),
            '--json' => true,
        ]);
        $steps['rollback_preflight'] = $this->stepSummary($preflight);
        if (! $this->guardPassed($preflight)) {
            return $this->finishWithEvidence($artifactDir, $timestamp, 'blocked', $sources, $limit, $draftLimit, $publishLimit, $steps, [
                'issue' => 'rollback_preflight_blocked',
            ]);
        }

        $draftRows = (int) ($weekly['rows_created'] ?? 0) + (int) ($weekly['rows_skipped_existing'] ?? 0);
        if ($draftRows < 1 || $packagePath === '' || ! is_file($packagePath) || $draftWriteEvidencePath === '' || ! is_file($draftWriteEvidencePath)) {
            return $this->finishWithEvidence($artifactDir, $timestamp, 'success', $sources, $limit, $draftLimit, $publishLimit, $steps, [
                'issue' => null,
                'skip_reason' => 'no_low_risk_draft_rows_available_for_publish',
            ]);
        }

        $publish = $this->runSubCommand('seo-agent:cms-publish-auto-canary', [
            '--package' => $packagePath,
            '--draft-write-evidence' => $draftWriteEvidencePath,
            '--limit' => $publishLimit,
            '--artifact-dir' => $this->stepDir($runDir, '03-cms-publish-auto-canary'),
            '--auto-approve-low-risk' => true,
            '--execute' => true,
            '--json' => true,
        ]);
        $steps['cms_publish_auto_canary'] = $this->stepSummary($publish);
        if (! $this->ok($publish)) {
            return $this->finishWithEvidence($artifactDir, $timestamp, 'blocked', $sources, $limit, $draftLimit, $publishLimit, $steps, [
                'issue' => 'cms_publish_auto_canary_failed',
            ]);
        }

        $publishArtifactPath = (string) data_get($publish, 'artifact.path', '');
        $publishedCount = (int) ($publish['published_or_planned_count'] ?? 0);
        if ($publishedCount < 1 || $publishArtifactPath === '' || ! is_file($publishArtifactPath)) {
            return $this->finishWithEvidence($artifactDir, $timestamp, 'success', $sources, $limit, $draftLimit, $publishLimit, $steps, [
                'issue' => null,
                'skip_reason' => 'no_new_content_page_canary_published',
            ]);
        }

        $indexnowInput = [
            '--publish-evidence' => $publishArtifactPath,
            '--limit' => $publishLimit,
            '--artifact-dir' => $this->stepDir($runDir, '04-post-publish-indexnow-auto'),
            '--execute' => true,
            '--json' => true,
        ];
        $baseUrl = trim((string) $this->option('base-url'));
        if ($baseUrl !== '') {
            $indexnowInput['--base-url'] = $baseUrl;
        }
        $indexnow = $this->runSubCommand('seo-agent:post-publish-indexnow-auto', $indexnowInput);
        $steps['post_publish_indexnow_auto'] = $this->stepSummary($indexnow);
        if (! $this->ok($indexnow)) {
            return $this->finishWithEvidence($artifactDir, $timestamp, 'blocked', $sources, $limit, $draftLimit, $publishLimit, $steps, [
                'issue' => 'post_publish_indexnow_auto_failed',
            ]);
        }

        $postPublish = $this->runSubCommand('seo-agent:auto-rollback-guard', [
            '--run-evidence' => $publishArtifactPath,
            '--mode' => 'post-publish',
            '--artifact-dir' => $this->stepDir($runDir, '05-rollback-post-publish'),
            '--json' => true,
        ]);
        $steps['rollback_post_publish'] = $this->stepSummary($postPublish);
        if (! $this->guardPassed($postPublish)) {
            return $this->finishWithEvidence($artifactDir, $timestamp, 'blocked', $sources, $limit, $draftLimit, $publishLimit, $steps, [
                'issue' => 'rollback_post_publish_blocked',
            ]);
        }

        return $this->finishWithEvidence($artifactDir, $timestamp, 'success', $sources, $limit, $draftLimit, $publishLimit, $steps, [
            'issue' => null,
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

    private function boundedInt(string $option, int $min, int $max): ?int
    {
        $value = filter_var($this->option($option), FILTER_VALIDATE_INT);

        return is_int($value) && $value >= $min && $value <= $max ? $value : null;
    }

    private function artifactDir(): ?string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            $dir = storage_path('app/seo-agent/priority-queue-scheduler');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    private function stepDir(string $runDir, string $name): string
    {
        $dir = rtrim($runDir, '/').'/'.$name;
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('step_artifact_dir_unwritable');
        }

        return $dir;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function runSubCommand(string $name, array $input): array
    {
        $command = $this->getApplication()?->find($name);
        if ($command === null) {
            return $this->failureSummary($name.'_missing');
        }

        $buffer = new BufferedOutput();
        $exitCode = $command->run(new ArrayInput(['command' => $name, ...$input]), $buffer);
        $summary = json_decode(trim($buffer->fetch()), true);
        if (! is_array($summary)) {
            return $this->failureSummary($name.'_summary_json_invalid', [
                'exit_code' => $exitCode,
            ]);
        }

        $summary['exit_code'] = $exitCode;

        return $summary;
    }

    private function ok(array $summary): bool
    {
        return ($summary['ok'] ?? false) === true && (int) ($summary['exit_code'] ?? 0) === 0;
    }

    private function guardPassed(array $summary): bool
    {
        return $this->ok($summary)
            && in_array((string) ($summary['status'] ?? ''), ['pass', 'success'], true)
            && (bool) ($summary['stop_the_line'] ?? true) === false;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('artifact_json_invalid');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function stepSummary(array $summary): array
    {
        return [
            'schema_version' => (string) ($summary['schema_version'] ?? ''),
            'ok' => (bool) ($summary['ok'] ?? false),
            'status' => (string) ($summary['status'] ?? ''),
            'exit_code' => (int) ($summary['exit_code'] ?? 0),
            'artifact' => is_array($summary['artifact'] ?? null) ? (array) $summary['artifact'] : [],
            'issues' => array_values(array_map('strval', (array) ($summary['issues'] ?? []))),
            'rows_created' => (int) ($summary['rows_created'] ?? 0),
            'rows_skipped_existing' => (int) ($summary['rows_skipped_existing'] ?? 0),
            'selected_count' => (int) ($summary['selected_count'] ?? 0),
            'published_or_planned_count' => (int) ($summary['published_or_planned_count'] ?? 0),
            'written_items' => (int) ($summary['written_items'] ?? 0),
            'submitted_count' => (int) ($summary['submitted_count'] ?? 0),
            'duplicate_submitted_count' => (int) ($summary['duplicate_submitted_count'] ?? 0),
            'stop_the_line' => (bool) ($summary['stop_the_line'] ?? false),
            'rollback_executed_count' => (int) ($summary['rollback_executed_count'] ?? 0),
        ];
    }

    /**
     * @param  list<string>  $sources
     * @param  array<string, array<string, mixed>>  $steps
     * @param  array<string, mixed>  $extra
     */
    private function finishWithEvidence(
        string $artifactDir,
        string $timestamp,
        string $status,
        array $sources,
        int $limit,
        int $draftLimit,
        int $publishLimit,
        array $steps,
        array $extra
    ): int {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'SEO-AGENT-PRIORITY-QUEUE-SCHEDULER-01',
            'status' => $status,
            'run_mode' => 'weekly_l5_low_risk',
            'trigger' => 'external_cron_or_manual_cli',
            'command' => 'php artisan seo-agent:priority-queue-scheduler',
            'sources' => $sources,
            'limit' => $limit,
            'draft_limit' => $draftLimit,
            'publish_limit' => $publishLimit,
            'steps' => $steps,
            ...$extra,
            'allowed_actions' => [
                'readonly_discovery',
                'auto_approval_policy_evaluation',
                'cms_draft_revision_write',
                'content_page_publish_canary',
                'url_truth_read',
                'search_channel_queue_write',
                'search_channel_queue_approve',
                'indexnow_live_submit',
                'rollback_guard_preflight',
                'rollback_guard_post_publish',
                'evidence_artifact_write',
            ],
            'forbidden_actions' => $this->forbiddenActions(),
            'cron_boundary' => [
                'external_cron_supported' => true,
                'laravel_scheduler_enabled_by_pr' => false,
                'queue_worker_started_by_pr' => false,
                'recommended_lock' => 'flock -n /tmp/seo-agent-priority-queue-scheduler.lock',
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
        $artifact = $this->writeArtifact($artifactDir, 'seo-agent-priority-queue-scheduler-'.$timestamp.'.json', $payload);

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $status === 'success',
            'status' => $status,
            'issue' => $extra['issue'] ?? null,
            'skip_reason' => $extra['skip_reason'] ?? null,
            'artifact' => $artifact,
            'steps' => $steps,
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
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
            'schema_version' => (string) ($payload['schema_version'] ?? self::SCHEMA_VERSION),
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
            if (($summary['issue'] ?? null) !== null) {
                $this->line('issue='.(string) $summary['issue']);
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
            'article_auto_publish',
            'cms_bulk_publish',
            'google_indexing_api_call',
            'baidu_live_submit',
            'google_sitemap_live_submit',
            'laravel_scheduler_activation',
            'queue_worker_activation',
            'frontend_code_mutation',
            'production_env_update',
            'external_model_api_call',
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function negativeGuarantees(): array
    {
        return [
            'article_auto_publish' => false,
            'cms_bulk_publish' => false,
            'publish_limit_above_three' => false,
            'draft_limit_above_ten' => false,
            'google_indexing_api_call' => false,
            'baidu_live_submit' => false,
            'google_sitemap_live_submit' => false,
            'laravel_scheduler_activation' => false,
            'queue_worker_started' => false,
            'frontend_code_mutation' => false,
            'production_env_change' => false,
            'external_model_api_call' => false,
        ];
    }
}
