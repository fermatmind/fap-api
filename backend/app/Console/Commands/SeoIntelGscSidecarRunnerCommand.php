<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

final class SeoIntelGscSidecarRunnerCommand extends Command
{
    protected $signature = 'seo-intel:gsc-sidecar-runner
        {--mode=preflight : Runner mode: preflight or live-read}
        {--start-date= : Required for live-read, YYYY-MM-DD}
        {--end-date= : Required for live-read, YYYY-MM-DD}
        {--limit=250 : Required for live-read, bounded 1..250}
        {--dimensions=query,page : Required for live-read, comma-separated GSC dimensions}
        {--artifact-dir=/opt/fermatmind/seo-gsc-runner/artifacts : Directory for sanitized JSON artifacts}';

    protected $description = 'Run the HK GSC sidecar read-only wrapper around seo-intel:collect.';

    public function handle(): int
    {
        $mode = trim((string) $this->option('mode'));
        if (! in_array($mode, ['preflight', 'live-read'], true)) {
            $this->error('error=invalid_mode');

            return self::FAILURE;
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null || ! $this->ensureArtifactDir($artifactDir)) {
            $this->error('error=artifact_dir_unwritable');

            return self::FAILURE;
        }

        $arguments = [
            '--collector' => 'gsc_foundation',
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
        ];

        if ($mode === 'preflight') {
            $arguments['--gsc-live-preflight'] = true;
        } else {
            $liveReadArguments = $this->liveReadArguments();
            if ($liveReadArguments === null) {
                return self::FAILURE;
            }

            $arguments = [
                ...$arguments,
                ...$liveReadArguments,
                '--gsc-live-read' => true,
            ];
        }

        $exitCode = Artisan::call('seo-intel:collect', $arguments);
        $rawOutput = trim(Artisan::output());
        $payload = json_decode($rawOutput, true);

        if (! is_array($payload)) {
            $this->error('error=collector_output_not_json');

            return self::FAILURE;
        }

        $artifact = $this->artifactPayload($mode, $arguments, $payload);
        $artifactPath = $this->artifactPath($artifactDir, $mode, $payload);
        $encoded = json_encode($artifact, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if (! is_string($encoded) || file_put_contents($artifactPath, $encoded."\n") === false) {
            $this->error('error=artifact_write_failed');

            return self::FAILURE;
        }

        $byteSize = filesize($artifactPath);
        $sha256 = hash_file('sha256', $artifactPath);

        if (! is_int($byteSize) || ! is_string($sha256)) {
            $this->error('error=artifact_digest_failed');

            return self::FAILURE;
        }

        $boundaryFailure = $this->boundaryFailure($payload);
        $this->line('artifact_path='.$artifactPath);
        $this->line('artifact_size='.$byteSize);
        $this->line('artifact_sha256='.$sha256);
        $this->line('status='.(string) ($payload['status'] ?? 'unknown'));
        $this->line('collector='.(string) ($payload['collector'] ?? 'unknown'));
        $this->line('dry_run='.($this->boolValue($payload['dry_run'] ?? null) ? 'true' : 'false'));
        $this->line('writes_attempted='.($this->boolValue($payload['writes_attempted'] ?? null) ? 'true' : 'false'));
        $this->line('writes_committed='.($this->boolValue($payload['writes_committed'] ?? null) ? 'true' : 'false'));
        $this->line('external_calls_attempted='.($this->boolValue($payload['external_calls_attempted'] ?? null) ? 'true' : 'false'));

        if (isset($payload['items_seen'])) {
            $this->line('items_seen='.(string) $payload['items_seen']);
        }

        if ($boundaryFailure !== null) {
            $this->error('error='.$boundaryFailure);

            return self::FAILURE;
        }

        return $exitCode === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }

    private function artifactDir(): ?string
    {
        $path = trim((string) $this->option('artifact-dir'));
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        return rtrim($path, '/');
    }

    private function ensureArtifactDir(string $artifactDir): bool
    {
        if (! is_dir($artifactDir) && ! mkdir($artifactDir, 0750, true) && ! is_dir($artifactDir)) {
            return false;
        }

        return is_writable($artifactDir);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function liveReadArguments(): ?array
    {
        $startDate = $this->dateOption('start-date');
        $endDate = $this->dateOption('end-date');
        if ($startDate === null || $endDate === null) {
            $this->error('error=live_read_dates_required');

            return null;
        }

        if ($startDate > $endDate) {
            $this->error('error=live_read_date_order_invalid');

            return null;
        }

        $limit = $this->limitOption();
        if ($limit === null) {
            $this->error('error=live_read_limit_invalid');

            return null;
        }

        $dimensions = trim((string) $this->option('dimensions'));
        if ($dimensions === '') {
            $this->error('error=live_read_dimensions_required');

            return null;
        }

        return [
            '--start-date' => $startDate,
            '--end-date' => $endDate,
            '--limit' => $limit,
            '--dimensions' => $dimensions,
        ];
    }

    private function dateOption(string $name): ?string
    {
        $value = trim((string) $this->option($name));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function limitOption(): ?int
    {
        $raw = trim((string) $this->option('limit'));
        if (preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        $limit = (int) $raw;

        return $limit >= 1 && $limit <= 250 ? $limit : null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function artifactPayload(string $mode, array $arguments, array $payload): array
    {
        return [
            'schema_version' => 'gsc-hk-sidecar-runner-wrapper.v1',
            'task' => 'SEO-GSC-HK-SIDECAR-RUNNER-WRAPPER-01',
            'generated_at' => Carbon::now('UTC')->toIso8601String(),
            'mode' => $mode,
            'collector_command' => [
                'name' => 'seo-intel:collect',
                'forced_flags' => [
                    '--collector=gsc_foundation',
                    '--dry-run',
                    '--no-write',
                    '--json',
                    $mode === 'preflight' ? '--gsc-live-preflight' : '--gsc-live-read',
                ],
                'live_read_options' => [
                    'start_date' => $arguments['--start-date'] ?? null,
                    'end_date' => $arguments['--end-date'] ?? null,
                    'limit' => $arguments['--limit'] ?? null,
                    'dimensions' => $arguments['--dimensions'] ?? null,
                ],
            ],
            'summary' => [
                'status' => $payload['status'] ?? null,
                'collector' => $payload['collector'] ?? null,
                'dry_run' => $payload['dry_run'] ?? null,
                'writes_attempted' => $payload['writes_attempted'] ?? null,
                'writes_committed' => $payload['writes_committed'] ?? null,
                'external_calls_attempted' => $payload['external_calls_attempted'] ?? null,
                'items_seen' => $payload['items_seen'] ?? null,
                'data_quality_gate' => data_get($payload, 'metadata.data_quality_gate.status'),
                'date_window' => data_get($payload, 'metadata.date_window'),
            ],
            'boundary_check' => [
                'passed' => $this->boundaryFailure($payload) === null,
                'forbidden_true_fields' => [
                    'writes_attempted',
                    'writes_committed',
                    'metadata.writes_attempted',
                    'metadata.writes_committed',
                    'metadata.cms_write_allowed',
                    'metadata.search_channel_enqueue_allowed',
                    'metadata.indexing_request_allowed',
                ],
            ],
            'payload' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function artifactPath(string $artifactDir, string $mode, array $payload): string
    {
        $timestamp = Carbon::now('UTC')->format('Ymd\THis\Z');
        $status = preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($payload['status'] ?? 'unknown'));

        return sprintf('%s/gsc-%s-wrapper-%s-%s.json', $artifactDir, $mode, $timestamp, $status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function boundaryFailure(array $payload): ?string
    {
        foreach ([
            'writes_attempted' => $payload['writes_attempted'] ?? null,
            'writes_committed' => $payload['writes_committed'] ?? null,
            'metadata.writes_attempted' => data_get($payload, 'metadata.writes_attempted'),
            'metadata.writes_committed' => data_get($payload, 'metadata.writes_committed'),
            'metadata.cms_write_allowed' => data_get($payload, 'metadata.cms_write_allowed'),
            'metadata.search_channel_enqueue_allowed' => data_get($payload, 'metadata.search_channel_enqueue_allowed'),
            'metadata.indexing_request_allowed' => data_get($payload, 'metadata.indexing_request_allowed'),
        ] as $field => $value) {
            if ($this->boolValue($value)) {
                return 'forbidden_boundary_true:'.$field;
            }
        }

        return null;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes'], true);
        }

        return false;
    }
}
