<?php

declare(strict_types=1);

namespace App\Services\Report\Composer;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ReportPersistence
{
    public function persist(string $attemptId, array $payload): void
    {
        try {
            $disk = Storage::disk('local');
            $baseDir = "reports/{$attemptId}";
            $disk->makeDirectory($baseDir);

            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );

            if ($json === false) {
                Log::warning('[REPORT] persist_report_json_encode_failed', [
                    'attempt_id' => $attemptId,
                    'json_error' => json_last_error_msg(),
                ]);
                return;
            }

            $pathLatest = "{$baseDir}/report.json";
            $disk->put($pathLatest, $json);

            $ts = now()->format('Ymd_His');
            $pathSnapshot = "{$baseDir}/report.{$ts}.json";
            $disk->put($pathSnapshot, $json);

            Log::info('[REPORT] persisted', [
                'attempt_id' => $attemptId,
                'disk' => 'local',
                'root' => (string) config('filesystems.disks.local.root'),
                'latest' => $pathLatest,
                'snapshot' => $pathSnapshot,
                'latest_exists' => $disk->exists($pathLatest),
                'latest_abs' => method_exists($disk, 'path') ? $disk->path($pathLatest) : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[REPORT] persist_report_failed', [
                'attempt_id' => $attemptId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
