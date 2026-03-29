<?php

declare(strict_types=1);

namespace App\Services\Report\Composer;

use App\Services\Storage\ArtifactStore;
use Illuminate\Support\Facades\Log;

class ReportPersistence
{
    public function __construct(
        private readonly ArtifactStore $artifactStore,
    ) {}

    public function persist(string $attemptId, array $payload): void
    {
        try {
            $pathLatest = $this->artifactStore->putReportJson('MBTI', $attemptId, $payload);

            Log::info('[REPORT] persisted', [
                'attempt_id' => $attemptId,
                'disk' => 'local',
                'root' => (string) config('filesystems.disks.local.root'),
                'latest' => $pathLatest,
                'snapshot' => null,
                'latest_exists' => $this->artifactStore->exists($pathLatest),
                'latest_abs' => storage_path('app/private/'.$pathLatest),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[REPORT] persist_report_failed', [
                'attempt_id' => $attemptId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
