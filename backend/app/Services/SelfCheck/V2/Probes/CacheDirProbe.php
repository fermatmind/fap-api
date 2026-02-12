<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\V2\Probes;

use App\Services\SelfCheck\V2\Contracts\ProbeInterface;
use App\Services\SelfCheck\V2\DTO\ProbeResult;

final class CacheDirProbe implements ProbeInterface
{
    public function name(): string
    {
        return 'cache_dirs';
    }

    public function probe(bool $verbose = false): array
    {
        $paths = [
            'storage_framework_cache' => storage_path('framework/cache'),
            'storage_framework_sessions' => storage_path('framework/sessions'),
            'storage_framework_views' => storage_path('framework/views'),
            'bootstrap_cache' => base_path('bootstrap/cache'),
        ];

        $allOk = true;
        $items = [];

        foreach ($paths as $name => $path) {
            $exists = is_dir($path) || @mkdir($path, 0775, true);
            $writable = $exists ? is_writable($path) : false;
            $probeOk = false;
            $probeErr = '';

            if ($writable) {
                $probeFile = rtrim($path, '/') . '/.__healthz_probe';
                try {
                    @file_put_contents($probeFile, (string) time());
                    @unlink($probeFile);
                    $probeOk = true;
                } catch (\Throwable $e) {
                    $probeErr = (string) $e->getMessage();
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
            ];
        }

        return (new ProbeResult(
            $allOk,
            $allOk ? '' : 'CACHE_DIR_NOT_WRITABLE',
            $allOk ? '' : 'cache dir not writable',
            ['items' => $items],
        ))->toArray($verbose);
    }
}
