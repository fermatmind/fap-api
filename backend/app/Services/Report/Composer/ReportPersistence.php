<?php

declare(strict_types=1);

namespace App\Services\Report\Composer;

use App\Services\Storage\ArtifactStore;
use Illuminate\Support\Facades\Log;

class ReportPersistence
{
    public function __construct(
        private readonly ArtifactStore $artifactStore
    ) {
    }

    public function persist(string $scaleCode, string $attemptId, array $payload): void
    {
        try {
            $this->artifactStore->putReportJson($scaleCode, $attemptId, $payload);
            $pathLatest = $this->artifactStore->reportJsonPath($scaleCode, $attemptId);

            Log::info('[REPORT] persisted', [
                'scale_code' => $scaleCode,
                'attempt_id' => $attemptId,
                'disk' => 'local',
                'root' => (string) config('filesystems.disks.local.root'),
                'latest' => $pathLatest,
                'latest_exists' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[REPORT] persist_report_failed', [
                'scale_code' => $scaleCode,
                'attempt_id' => $attemptId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
