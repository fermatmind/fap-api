<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Http\Controllers\HealthzController;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

final class EvidencePack extends Command
{
    protected $signature = 'ops:evidence-pack
        {--revision= : Revision label override}
        {--ts= : Timestamp label override, e.g. 20260227T120000Z}
        {--window-minutes=60 : Queue and slow-query lookback window in minutes}
        {--base-url= : Base URL for remote healthz fallback}';

    protected $description = 'Generate global scaling evidence pack under storage/app/evidence/<revision>/<ts>.';

    public function handle(): int
    {
        $windowMinutes = max(1, (int) $this->option('window-minutes'));

        $revision = $this->sanitizePathSegment(
            (string) $this->option('revision'),
            $this->resolveRevision()
        );
        $timestamp = $this->sanitizePathSegment(
            (string) $this->option('ts'),
            CarbonImmutable::now('UTC')->format('Ymd\THis\Z')
        );

        $packDir = storage_path('app/evidence/'.$revision.'/'.$timestamp);
        File::ensureDirectoryExists($packDir);

        $healthzSnapshot = $this->collectHealthzSnapshot((string) $this->option('base-url'));
        $queueBacklogSnapshot = $this->collectQueueBacklogProbeSnapshot($windowMinutes);
        $slowQuerySnapshot = $this->collectSlowQueryTelemetrySnapshot($windowMinutes);

        $revisionPayload = [
            'revision' => $revision,
            'timestamp' => $timestamp,
            'window_minutes' => $windowMinutes,
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];

        $manifestPayload = [
            'revision' => $revision,
            'timestamp' => $timestamp,
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'artifacts' => [
                'revision' => 'revision.json',
                'healthz_snapshot' => 'healthz_snapshot.json',
                'queue_backlog_probe' => 'queue_backlog_probe.json',
                'slow_query_telemetry' => 'slow_query_telemetry.json',
            ],
        ];

        $this->writeJson($packDir.'/revision.json', $revisionPayload);
        $this->writeJson($packDir.'/healthz_snapshot.json', $healthzSnapshot);
        $this->writeJson($packDir.'/queue_backlog_probe.json', $queueBacklogSnapshot);
        $this->writeJson($packDir.'/slow_query_telemetry.json', $slowQuerySnapshot);
        $this->writeJson($packDir.'/manifest.json', $manifestPayload);

        $this->info($packDir);

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function collectHealthzSnapshot(string $baseUrlOption): array
    {
        $capturedAt = CarbonImmutable::now('UTC')->toIso8601String();
        $source = 'local_controller';
        $payload = null;

        try {
            $request = Request::create('/api/healthz', 'GET');
            $response = app(HealthzController::class)->show($request);
            $data = $response->getData(true);
            if (is_array($data)) {
                $payload = $data;
            }
        } catch (\Throwable) {
            $payload = null;
        }

        if ($payload === null) {
            $baseUrl = trim($baseUrlOption);
            if ($baseUrl === '') {
                $baseUrl = (string) (\App\Support\RuntimeConfig::raw('FAP_BASE_URL') ?: 'http://127.0.0.1:8000');
            }
            $url = rtrim($baseUrl, '/').'/api/healthz';

            try {
                $response = Http::acceptJson()->retry(2, 100)->timeout(5)->get($url);
                $data = $response->json();
                if (is_array($data)) {
                    $payload = $data;
                    $source = 'http';
                }
            } catch (\Throwable) {
                $payload = null;
            }
        }

        return [
            'captured_at' => $capturedAt,
            'source' => $source,
            'ok' => is_array($payload) ? (bool) ($payload['ok'] ?? false) : false,
            'payload' => is_array($payload) ? $payload : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function collectQueueBacklogProbeSnapshot(int $windowMinutes): array
    {
        $exitCode = Artisan::call('ops:queue-backlog-probe', [
            '--json' => 1,
            '--strict' => 0,
            '--window-minutes' => $windowMinutes,
        ]);
        $output = trim((string) Artisan::output());

        $decoded = json_decode($output, true);

        return [
            'captured_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'command' => 'ops:queue-backlog-probe',
            'exit_code' => $exitCode,
            'payload' => is_array($decoded) ? $decoded : null,
            'raw_output' => is_array($decoded) ? null : $output,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function collectSlowQueryTelemetrySnapshot(int $windowMinutes): array
    {
        $logPath = (string) config('logging.channels.single.path', storage_path('logs/laravel.log'));
        $windowStart = CarbonImmutable::now('UTC')->subMinutes($windowMinutes);
        $windowTotal = 0;
        $maxSqlMs = 0.0;
        $lastSeenAt = null;
        $byRoute = [];
        $byConnection = [];
        $samples = [];

        foreach ($this->readRecentLogLines($logPath, 8 * 1024 * 1024) as $line) {
            $entry = json_decode($line, true);
            if (! is_array($entry)) {
                continue;
            }
            if ((string) ($entry['message'] ?? '') !== 'SLOW_QUERY_DETECTED') {
                continue;
            }

            $entryAt = $this->extractEntryTimestamp($entry);
            if ($entryAt !== null && $entryAt->lt($windowStart)) {
                continue;
            }

            $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
            $route = trim((string) ($context['route'] ?? 'unknown'));
            if ($route === '') {
                $route = 'unknown';
            }
            $connection = trim((string) ($context['connection'] ?? 'unknown'));
            if ($connection === '') {
                $connection = 'unknown';
            }
            $sqlMs = max(0.0, (float) ($context['sql_ms'] ?? 0));

            $windowTotal++;
            $byRoute[$route] = ($byRoute[$route] ?? 0) + 1;
            $byConnection[$connection] = ($byConnection[$connection] ?? 0) + 1;
            $maxSqlMs = max($maxSqlMs, $sqlMs);
            if ($entryAt !== null) {
                $lastSeenAt = $entryAt->toIso8601String();
            }

            if (count($samples) < 20) {
                $samples[] = [
                    'datetime' => $entryAt?->toIso8601String(),
                    'route' => $route,
                    'connection' => $connection,
                    'sql_ms' => round($sqlMs, 3),
                    'request_id' => (string) ($context['request_id'] ?? ''),
                ];
            }
        }

        ksort($byRoute);
        ksort($byConnection);

        return [
            'captured_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'window_minutes' => $windowMinutes,
            'log_path' => $logPath,
            'window_total' => $windowTotal,
            'max_sql_ms' => round($maxSqlMs, 3),
            'last_seen_at' => $lastSeenAt,
            'by_route' => $byRoute,
            'by_connection' => $byConnection,
            'samples' => $samples,
        ];
    }

    /**
     * @return list<string>
     */
    private function readRecentLogLines(string $path, int $maxBytes): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if (! is_string($content) || $content === '') {
            return [];
        }

        if (strlen($content) > $maxBytes) {
            $content = substr($content, -$maxBytes);
            $firstLineBreak = strpos($content, "\n");
            if ($firstLineBreak !== false) {
                $content = substr($content, $firstLineBreak + 1);
            }
        }

        $lines = preg_split('/\R/', $content) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            $lines
        ), static fn (string $line): bool => $line !== ''));
    }

    private function extractEntryTimestamp(array $entry): ?CarbonImmutable
    {
        $raw = trim((string) ($entry['datetime'] ?? ''));
        if ($raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function writeJson(string $path, array $payload): void
    {
        file_put_contents($path, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    private function sanitizePathSegment(string $candidate, string $fallback): string
    {
        $value = trim($candidate);
        if ($value === '') {
            $value = trim($fallback);
        }
        if ($value === '') {
            $value = 'unknown';
        }

        $value = preg_replace('/[^A-Za-z0-9._-]/', '_', $value);
        if (! is_string($value) || trim($value) === '') {
            return 'unknown';
        }

        return trim($value, '._-') !== '' ? trim($value) : 'unknown';
    }

    private function resolveRevision(): string
    {
        $envRev = \App\Support\RuntimeConfig::raw('SENTRY_RELEASE') ?: \App\Support\RuntimeConfig::raw('REVISION');
        if (is_string($envRev) && trim($envRev) !== '') {
            return trim($envRev);
        }

        $candidates = [
            base_path('REVISION'),
            base_path('../REVISION'),
        ];

        foreach ($candidates as $path) {
            if (! is_file($path)) {
                continue;
            }
            $content = trim((string) file_get_contents($path));
            if ($content !== '') {
                return $content;
            }
        }

        $repoRoot = base_path('..');
        if (is_dir($repoRoot.'/.git')) {
            $cmd = 'git -C '.escapeshellarg($repoRoot).' rev-parse HEAD 2>/dev/null';
            $rev = trim((string) @shell_exec($cmd));
            if ($rev !== '') {
                return $rev;
            }
        }

        return 'unknown';
    }
}
