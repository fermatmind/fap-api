<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\CoreEntrySloObserver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

final class SeoIntelCoreEntrySloObserveCommand extends Command
{
    protected $signature = 'seo-intel:core-entry-slo-observe
        {--concurrency= : Public GET concurrency, bounded by config}
        {--timeout= : Per-request timeout seconds, bounded by config}
        {--artifact-dir= : Directory for the sanitized JSON artifact}
        {--json : Emit a machine-readable summary}';

    protected $description = 'Observe the deterministic L1/L2/L3 public SEO entry manifest without DB, CMS, or search writes.';

    public function handle(CoreEntrySloObserver $observer): int
    {
        try {
            $artifact = $observer->observe(
                $this->integerOption('concurrency'),
                $this->integerOption('timeout'),
            );
            $artifactDir = $this->artifactDir();
            $artifactRef = $this->writeArtifact($artifactDir, $artifact);
        } catch (Throwable) {
            return $this->finish($this->failureSummary('core_entry_slo_observation_blocked'));
        }

        $sloMet = ($artifact['slo_met'] ?? false) === true;
        $summary = [
            'schema_version' => CoreEntrySloObserver::SCHEMA_VERSION,
            'task' => CoreEntrySloObserver::TASK,
            'ok' => $sloMet,
            'status' => $sloMet ? 'healthy' : 'incident',
            'slo_met' => $sloMet,
            'target_count' => (int) data_get($artifact, 'manifest.target_count', 0),
            'incident_count' => (int) data_get($artifact, 'ops_read_model.incident_count', 0),
            'alert_priority' => data_get($artifact, 'ops_read_model.alert_priority'),
            'overall_status' => (string) data_get($artifact, 'ops_read_model.overall_status', 'unknown'),
            'incident_category_counts' => (array) data_get($artifact, 'ops_read_model.incident_category_counts', []),
            'artifact' => $artifactRef,
            'local_artifact_write' => true,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];

        return $this->finish($summary);
    }

    private function integerOption(string $key): ?int
    {
        $value = $this->option($key);
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new RuntimeException('invalid_numeric_option');
        }

        return (int) $value;
    }

    private function artifactDir(): string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            $dir = storage_path('app/seo-intel/core-entry-slo');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('artifact_directory_create_failed');
        }
        if (! is_writable($dir)) {
            throw new RuntimeException('artifact_directory_unwritable');
        }

        return rtrim($dir, '/');
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    private function writeArtifact(string $artifactDir, array $artifact): array
    {
        $timestamp = Carbon::now('UTC')->format('Ymd\THis\Z');
        $manifestHash = substr((string) data_get($artifact, 'manifest.sha256', 'unknown'), 0, 12);
        $path = $artifactDir.'/seo-core-entry-slo-'.$timestamp.'-'.$manifestHash.'.json';
        $encoded = json_encode(
            $artifact,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        if (file_put_contents($path, $encoded."\n") === false) {
            throw new RuntimeException('artifact_write_failed');
        }

        return [
            'path' => $path,
            'size' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
            'schema_version' => CoreEntrySloObserver::SCHEMA_VERSION,
            'sanitized' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failureSummary(string $issue): array
    {
        return [
            'schema_version' => CoreEntrySloObserver::SCHEMA_VERSION,
            'task' => CoreEntrySloObserver::TASK,
            'ok' => false,
            'status' => 'blocked',
            'slo_met' => false,
            'target_count' => 0,
            'incident_count' => 0,
            'issues' => [$issue],
            'local_artifact_write' => false,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function finish(array $summary): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $summary,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
        } else {
            $this->line('status='.(string) ($summary['status'] ?? 'unknown'));
            $this->line('slo_met='.(($summary['slo_met'] ?? false) ? 'true' : 'false'));
            $this->line('target_count='.(string) ($summary['target_count'] ?? 0));
            $this->line('incident_count='.(string) ($summary['incident_count'] ?? 0));
            $this->line('alert_priority='.(string) ($summary['alert_priority'] ?? ''));

            foreach ((array) ($summary['issues'] ?? []) as $issue) {
                $this->line('issue='.(string) $issue);
            }

            if (isset($summary['artifact'])) {
                $this->line('artifact_path='.(string) data_get($summary, 'artifact.path', ''));
                $this->line('artifact_sha256='.(string) data_get($summary, 'artifact.sha256', ''));
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
            'sitemap_write' => false,
            'llms_write' => false,
            'search_channel_enqueue' => false,
            'search_channel_submit' => false,
            'indexing_request' => false,
            'sitemap_submission' => false,
            'scheduler_activation' => false,
            'queue_worker_started' => false,
            'production_env_change' => false,
            'private_url_probe' => false,
            'google_search_console_api_call' => false,
            'google_indexing_api_call' => false,
        ];
    }
}
