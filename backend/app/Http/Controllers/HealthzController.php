<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

class HealthzController extends Controller
{
    public function show(Request $request)
    {
        $service = 'Fermat Assessment Platform API';
        $version = config('app.version', env('APP_VERSION', 'unknown'));
        $nowIso = now()->toIso8601String();

        $region = (string) $request->query('region', 'CN_MAINLAND');
        $locale = (string) $request->query('locale', 'zh-CN');

        $deps = [];
        $deps['db'] = $this->checkDb();
        $deps['redis'] = $this->checkRedis();
        $deps['queue'] = $this->checkQueue();
        $deps['cache_dirs'] = $this->checkCacheDirs();
        $deps['content_source'] = $this->checkContentSource($region, $locale);

        $allOk = true;
        foreach ($deps as $value) {
            if (($value['ok'] ?? false) !== true) {
                $allOk = false;
                break;
            }
        }

        return response()->json([
            'ok' => $allOk,
            'service' => $service,
            'version' => $version,
            'time' => $nowIso,
            'deps' => $deps,
        ]);
    }

    private function checkDb(): array
    {
        $t0 = microtime(true);
        try {
            DB::select('select 1 as ok');
            $ms = (int) round((microtime(true) - $t0) * 1000);

            return [
                'ok' => true,
                'driver' => (string) config('database.default'),
                'latency_ms' => $ms,
            ];
        } catch (\Throwable $e) {
            $ms = (int) round((microtime(true) - $t0) * 1000);

            return [
                'ok' => false,
                'driver' => (string) config('database.default'),
                'latency_ms' => $ms,
                'error_code' => 'DB_UNAVAILABLE',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function checkRedis(): array
    {
        $t0 = microtime(true);
        try {
            $client = (string) config('database.redis.client', 'redis');
            $pong = Redis::connection()->ping();
            $ms = (int) round((microtime(true) - $t0) * 1000);

            return [
                'ok' => (string) $pong === 'PONG' || $pong === true,
                'client' => $client,
                'latency_ms' => $ms,
            ];
        } catch (\Throwable $e) {
            $ms = (int) round((microtime(true) - $t0) * 1000);

            return [
                'ok' => false,
                'client' => (string) config('database.redis.client', 'redis'),
                'latency_ms' => $ms,
                'error_code' => 'REDIS_UNAVAILABLE',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function checkQueue(): array
    {
        $driver = (string) config('queue.default', 'sync');

        try {
            if ($driver === 'database') {
                $hasJobs = Schema::hasTable('jobs');
                $hasFailed = Schema::hasTable('failed_jobs');

                if (!$hasJobs) {
                    return [
                        'ok' => false,
                        'driver' => $driver,
                        'error_code' => 'QUEUE_TABLE_MISSING',
                        'message' => 'jobs table not found',
                    ];
                }

                DB::select('select 1 as ok');

                return [
                    'ok' => true,
                    'driver' => $driver,
                    'tables' => [
                        'jobs' => $hasJobs,
                        'failed_jobs' => $hasFailed,
                    ],
                ];
            }

            if ($driver === 'redis') {
                $pong = Redis::connection()->ping();
                $ok = ((string) $pong === 'PONG' || $pong === true);

                return [
                    'ok' => $ok,
                    'driver' => $driver,
                ];
            }

            return [
                'ok' => true,
                'driver' => $driver,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'driver' => $driver,
                'error_code' => 'QUEUE_UNAVAILABLE',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function checkCacheDirs(): array
    {
        $paths = [
            'storage_framework_cache' => storage_path('framework/cache'),
            'storage_framework_sessions' => storage_path('framework/sessions'),
            'storage_framework_views' => storage_path('framework/views'),
            'bootstrap_cache' => base_path('bootstrap/cache'),
        ];

        $items = [];
        $allOk = true;

        foreach ($paths as $name => $path) {
            $exists = is_dir($path);
            if (!$exists) {
                @mkdir($path, 0775, true);
                $exists = is_dir($path);
            }

            $writable = $exists ? is_writable($path) : false;

            $probeOk = false;
            $probeErr = '';
            if ($writable) {
                $probeFile = rtrim($path, '/').'/.__healthz_probe';
                try {
                    @file_put_contents($probeFile, (string) time());
                    @unlink($probeFile);
                    $probeOk = true;
                } catch (\Throwable $e) {
                    $probeOk = false;
                    $probeErr = $e->getMessage();
                }
            }

            $ok = $exists && $writable && $probeOk;
            if (!$ok) {
                $allOk = false;
            }

            $items[$name] = [
                'ok' => $ok,
                'path' => $path,
                'exists' => $exists,
                'writable' => $writable,
                'probe_ok' => $probeOk,
                'message' => $probeErr,
                'error_code' => $ok ? '' : 'CACHE_DIR_NOT_WRITABLE',
            ];
        }

        return [
            'ok' => $allOk,
            'items' => $items,
        ];
    }

    private function checkContentSource(string $region, string $locale): array
    {
        $base = realpath(base_path('..'.DIRECTORY_SEPARATOR.'content_packages'))
            ?: base_path('..'.DIRECTORY_SEPARATOR.'content_packages');

        $defaultDir = rtrim($base, '/').'/default/'.$region.'/'.$locale;

        $existsBase = is_dir($base);
        $existsDefault = is_dir($defaultDir);

        $readableBase = $existsBase ? is_readable($base) : false;
        $readableDefault = $existsDefault ? is_readable($defaultDir) : false;

        $ok = $existsBase && $readableBase && $existsDefault && $readableDefault;

        return [
            'ok' => $ok,
            'base_path' => $base,
            'default_path' => $defaultDir,
            'region' => $region,
            'locale' => $locale,
            'error_code' => $ok ? '' : 'CONTENT_SOURCE_NOT_READY',
            'message' => $ok ? '' : 'content_packages default path not readable',
        ];
    }
}
