<?php

declare(strict_types=1);

namespace App\Services\Attempts;

use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class ResultProcessingTimingService
{
    /**
     * @return array{
     *     version:string,
     *     phase:string,
     *     queue_wait_ms:int|null,
     *     scoring_ms:int|null,
     *     report_wait_ms:int|null,
     *     server_total_ms:int|null
     * }
     */
    public function forAttempt(int $orgId, string $attemptId): array
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '' || ! SchemaBaseline::hasTable('attempt_submissions')) {
            return [];
        }

        $submission = DB::table('attempt_submissions')
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['state', 'created_at', 'started_at', 'finished_at']);

        if (! is_object($submission)) {
            return [];
        }

        $queuedAt = $this->timestamp($submission->created_at ?? null);
        $startedAt = $this->timestamp($submission->started_at ?? null);
        $scoredAt = $this->timestamp($submission->finished_at ?? null);
        $reportReadyAt = null;
        $reportState = null;

        if (SchemaBaseline::hasTable('report_snapshots')) {
            $snapshotQuery = DB::table('report_snapshots')->where('attempt_id', $attemptId);
            if (SchemaBaseline::hasColumn('report_snapshots', 'org_id')) {
                $snapshotQuery->where('org_id', $orgId);
            }

            $columns = ['created_at'];
            if (SchemaBaseline::hasColumn('report_snapshots', 'status')) {
                $columns[] = 'status';
            }
            if (SchemaBaseline::hasColumn('report_snapshots', 'updated_at')) {
                $columns[] = 'updated_at';
            }

            $snapshot = $snapshotQuery->first($columns);
            if (is_object($snapshot)) {
                $reportState = strtolower(trim((string) ($snapshot->status ?? 'ready')));
                if ($reportState === 'ready') {
                    $reportReadyAt = $this->timestamp($snapshot->updated_at ?? $snapshot->created_at ?? null);
                }
            }
        }

        $submissionState = strtolower(trim((string) ($submission->state ?? 'pending')));
        $phase = match (true) {
            $submissionState === 'failed' || $reportState === 'failed' => 'failed',
            $reportReadyAt instanceof CarbonInterface => 'ready',
            $scoredAt instanceof CarbonInterface => 'reporting',
            $startedAt instanceof CarbonInterface => 'scoring',
            default => 'queued',
        };

        return [
            'version' => 'v1',
            'phase' => $phase,
            'queue_wait_ms' => $this->durationMs($queuedAt, $startedAt),
            'scoring_ms' => $this->durationMs($startedAt, $scoredAt),
            'report_wait_ms' => $this->durationMs($scoredAt, $reportReadyAt),
            'server_total_ms' => $this->durationMs($queuedAt, $reportReadyAt),
        ];
    }

    private function timestamp(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function durationMs(?CarbonInterface $from, ?CarbonInterface $to): ?int
    {
        if (! $from instanceof CarbonInterface || ! $to instanceof CarbonInterface) {
            return null;
        }

        return max(0, (int) round($from->diffInMilliseconds($to, false)));
    }
}
