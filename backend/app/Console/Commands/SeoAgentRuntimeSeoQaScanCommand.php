<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoAgent\RuntimeSeoQaReadonlyScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

final class SeoAgentRuntimeSeoQaScanCommand extends Command
{
    protected $signature = 'seo-agent:runtime-seo-qa-scan
        {--source=cms-indexable : Source to scan. Only cms-indexable is supported}
        {--limit=50 : Target limit, bounded 1..100}
        {--artifact-dir= : Directory for sanitized JSON artifact}
        {--json : Emit JSON summary}';

    protected $description = 'Read-only runtime SEO QA scanner for CMS indexable public paths.';

    public function handle(RuntimeSeoQaReadonlyScanner $scanner): int
    {
        $source = trim((string) $this->option('source'));
        if ($source !== 'cms-indexable') {
            return $this->finish($this->failureSummary('invalid_source'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $artifact = $scanner->scan($source, $this->limit());
        $timestamp = Carbon::now('UTC')->format('Ymd\THis\Z');
        $artifactRef = $this->writeArtifact($artifactDir, 'seo-agent-runtime-seo-qa-scan-'.$timestamp.'.json', $artifact);

        return $this->finish([
            'schema_version' => RuntimeSeoQaReadonlyScanner::SCHEMA_VERSION,
            'task' => RuntimeSeoQaReadonlyScanner::TASK,
            'ok' => true,
            'status' => 'success',
            'source' => $source,
            'targets_checked' => (int) ($artifact['targets_checked'] ?? 0),
            'candidate_count' => (int) ($artifact['candidate_count'] ?? 0),
            'artifact' => $artifactRef,
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
    }

    private function limit(): int
    {
        $raw = trim((string) $this->option('limit'));
        if (preg_match('/^\d+$/', $raw) !== 1) {
            return 50;
        }

        return max(1, min((int) $raw, 100));
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
            'schema_version' => (string) ($payload['schema_version'] ?? 'unknown'),
            'sanitized_summary' => [
                'targets_checked' => (int) ($payload['targets_checked'] ?? 0),
                'candidate_count' => (int) ($payload['candidate_count'] ?? 0),
                'forbidden_output_fields_absent' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failureSummary(string $issue): array
    {
        return [
            'schema_version' => RuntimeSeoQaReadonlyScanner::SCHEMA_VERSION,
            'task' => RuntimeSeoQaReadonlyScanner::TASK,
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
            $artifact = (array) ($summary['artifact'] ?? []);
            if ($artifact !== []) {
                $this->line('artifact_path='.(string) ($artifact['path'] ?? ''));
                $this->line('artifact_size='.(string) ($artifact['size'] ?? 0));
                $this->line('artifact_sha256='.(string) ($artifact['sha256'] ?? ''));
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
            'google_search_console_api_call' => false,
            'google_indexing_api_call' => false,
            'pr_train_metadata_change' => false,
        ];
    }
}
