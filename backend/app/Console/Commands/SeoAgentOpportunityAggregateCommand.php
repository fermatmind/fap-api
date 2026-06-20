<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoAgent\OpportunityAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

final class SeoAgentOpportunityAggregateCommand extends Command
{
    private const FORBIDDEN_STRINGS = [
        'raw_url',
        'raw_query',
        'full_url',
        'credential_path',
        'service_account_json',
        'client_email',
        'private_key',
        'Bearer ',
        'token',
        'cookie',
        'session',
        'content_md',
        'content_html',
        'raw_html',
        'cms_draft_body',
    ];

    protected $signature = 'seo-agent:opportunity-aggregate
        {--inputs= : Comma-separated sanitized scanner artifact paths}
        {--limit=100 : Candidate limit, bounded 1..250}
        {--artifact-dir= : Directory for sanitized JSON artifact}
        {--json : Emit JSON summary}';

    protected $description = 'Aggregate sanitized SEO Agent opportunity source artifacts into one ranked read-only opportunity pool.';

    public function handle(OpportunityAggregator $aggregator): int
    {
        $inputPaths = $this->inputPaths();
        if ($inputPaths === []) {
            return $this->finish($this->failureSummary('inputs_missing_or_unreadable'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $artifacts = [];
        $inputRefs = [];
        foreach ($inputPaths as $path) {
            $raw = (string) file_get_contents($path);
            $forbidden = $this->forbiddenStringsPresent($raw);
            if ($forbidden !== []) {
                return $this->finish($this->failureSummary('forbidden_input_field_present', [
                    'input_path_hash' => hash('sha256', $path),
                    'forbidden_matches' => $forbidden,
                ]));
            }

            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return $this->finish($this->failureSummary('input_json_invalid', [
                    'input_path_hash' => hash('sha256', $path),
                ]));
            }

            $artifacts[] = $decoded;
            $inputRefs[] = [
                'path_hash' => hash('sha256', $path),
                'sha256' => hash_file('sha256', $path) ?: '',
                'schema_version' => (string) ($decoded['schema_version'] ?? $decoded['version'] ?? 'unknown'),
                'candidate_count' => (int) ($decoded['candidate_count'] ?? 0),
            ];
        }

        $artifact = $aggregator->aggregate($artifacts, $this->limit());
        $artifact['input_artifacts'] = $inputRefs;
        $artifactRef = $this->writeArtifact(
            $artifactDir,
            'seo-agent-opportunity-aggregate-'.Carbon::now('UTC')->format('Ymd\THis\Z').'.json',
            $artifact
        );

        return $this->finish([
            'schema_version' => OpportunityAggregator::SCHEMA_VERSION,
            'task' => OpportunityAggregator::TASK,
            'ok' => true,
            'status' => 'success',
            'input_artifact_count' => count($inputRefs),
            'candidate_count' => (int) ($artifact['candidate_count'] ?? 0),
            'artifact' => $artifactRef,
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function inputPaths(): array
    {
        $raw = trim((string) $this->option('inputs'));
        if ($raw === '' || str_contains($raw, "\0")) {
            return [];
        }

        $paths = [];
        foreach (explode(',', $raw) as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            $path = str_starts_with($path, '/') ? $path : base_path($path);
            if (! is_file($path) || ! is_readable($path)) {
                return [];
            }
            $paths[] = $path;
        }

        return array_values(array_unique($paths));
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
     * @return list<string>
     */
    private function forbiddenStringsPresent(string $raw): array
    {
        $matches = [];
        foreach (self::FORBIDDEN_STRINGS as $needle) {
            if (str_contains($raw, $needle)) {
                $matches[] = $needle;
            }
        }

        return $matches;
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
            'schema_version' => OpportunityAggregator::SCHEMA_VERSION,
            'task' => OpportunityAggregator::TASK,
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
            $this->line('candidate_count='.(string) ($summary['candidate_count'] ?? 0));
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
