<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use App\Http\Controllers\HealthzController;

class OpsHealthzSnapshot extends Command
{
    protected $signature = 'ops:healthz-snapshot
        {--env= : Environment name}
        {--base-url= : Base url for healthz}';

    protected $description = 'Fetch /api/healthz and persist ops health snapshot';

    public function handle(): int
    {
        $env = trim((string) $this->option('env'));
        if ($env === '') {
            $env = (string) (\App\Support\RuntimeConfig::raw('FAP_ENV') ?: config('app.env') ?: 'local');
        }

        $baseUrl = trim((string) $this->option('base-url'));
        if ($baseUrl === '') {
            $baseUrl = (string) (\App\Support\RuntimeConfig::raw('FAP_BASE_URL') ?: 'http://127.0.0.1:8000');
        }

        $url = rtrim($baseUrl, '/') . '/api/healthz';

        try {
            $data = null;
            try {
                $response = Http::acceptJson()->retry(3, 100)->timeout(5)->get($url);
                if ($response->ok()) {
                    $json = $response->json();
                    if (is_array($json)) {
                        $data = $json;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[ops:healthz-snapshot] http failed', ['error' => $e->getMessage()]);
            }

            if ($data === null) {
                $data = $this->fetchLocalHealthz();
            }

            if (!is_array($data)) {
                $this->error('Healthz response not available');
                return 1;
            }

            $deps = $data['deps'] ?? [];
            if (!is_array($deps)) {
                $deps = [];
            }

            $errorCodes = $this->extractErrorCodes($deps);
            $depsJson = json_encode($deps, JSON_UNESCAPED_UNICODE);
            $errorJson = $errorCodes === null ? null : json_encode($errorCodes, JSON_UNESCAPED_UNICODE);

            DB::table('ops_healthz_snapshots')->insert([
                'env' => $env,
                'revision' => $this->resolveRevision(),
                'ok' => !empty($data['ok']) ? 1 : 0,
                'deps_json' => $depsJson,
                'error_codes_json' => $errorJson,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ops:healthz-snapshot] failed', ['error' => $e->getMessage()]);
            $this->error('Failed: ' . $e->getMessage());
            return 1;
        }

        $this->info('ok');
        return 0;
    }

    private function extractErrorCodes(array $deps): ?array
    {
        $codes = [];
        $counts = [];

        foreach ($deps as $dep) {
            if (!is_array($dep)) {
                continue;
            }
            $code = $dep['error_code'] ?? null;
            if (!is_string($code) || trim($code) === '') {
                continue;
            }
            $code = trim($code);
            $codes[] = $code;
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }

        if (empty($codes)) {
            return null;
        }

        return [
            'codes' => array_values(array_unique($codes)),
            'counts' => $counts,
        ];
    }

    private function fetchLocalHealthz(): ?array
    {
        try {
            $request = Request::create('/api/healthz', 'GET');
            $controller = app(HealthzController::class);
            $response = $controller->show($request);
            $payload = $response->getData(true);
            if (is_array($payload)) {
                return $payload;
            }
        } catch (\Throwable $e) {
            Log::warning('[ops:healthz-snapshot] local fallback failed', ['error' => $e->getMessage()]);
        }

        return null;
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
            if (is_file($path)) {
                $content = trim((string) file_get_contents($path));
                if ($content !== '') {
                    return $content;
                }
            }
        }

        $repoRoot = base_path('..');
        if (is_dir($repoRoot . '/.git')) {
            $cmd = 'git -C ' . escapeshellarg($repoRoot) . ' rev-parse HEAD 2>/dev/null';
            $rev = trim((string) @shell_exec($cmd));
            if ($rev !== '') {
                return $rev;
            }
        }

        return 'unknown';
    }
}
