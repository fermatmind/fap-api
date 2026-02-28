<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DsarSlaStats extends Command
{
    protected $signature = 'ops:dsar-sla-stats
        {--org-id= : Org id}
        {--window-hours=24 : Lookback hours}
        {--stale-minutes=60 : Pending/running timeout threshold}
        {--json=1 : Output JSON payload}';

    protected $description = 'Output DSAR SLA ops stats (timeouts, failures, retry exhausted, and replay lifecycle).';

    public function handle(): int
    {
        $orgId = $this->positiveInt($this->option('org-id'));
        $windowHours = max(1, (int) ($this->option('window-hours') ?? 24));
        $staleMinutes = max(1, (int) ($this->option('stale-minutes') ?? 60));

        if ($orgId <= 0) {
            $payload = [
                'ok' => false,
                'error_code' => 'INVALID_ORG_ID',
                'org_id' => $orgId,
            ];

            if ($this->isTruthy($this->option('json'))) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error('invalid --org-id, expected positive integer');
            }

            return self::FAILURE;
        }

        $windowStart = now()->subHours($windowHours);
        $staleCutoff = now()->subMinutes($staleMinutes);

        $stats = [
            'timeout_count' => $this->countTimeouts($orgId, $staleCutoff),
            'failed_count' => $this->countFailedRequests($orgId, $windowStart),
            'retry_exhausted_count' => $this->countRetryExhausted($orgId, $windowStart),
            'replay_requested_count' => $this->countReplayAudit($orgId, 'requeue_requested', $windowStart),
            'replay_started_count' => $this->countReplayAudit($orgId, 'requeue_started', $windowStart),
            'replay_done_count' => $this->countReplayAudit($orgId, 'requeue_done', $windowStart),
            'replay_failed_count' => $this->countReplayAudit($orgId, 'requeue_failed', $windowStart),
        ];

        $sources = [
            'timeout_count' => 'dsar_requests.status in (pending,running) with coalesce(updated_at,requested_at,created_at) <= now-stale_minutes',
            'failed_count' => 'dsar_requests.status=failed with coalesce(updated_at,executed_at,requested_at,created_at) >= window_start',
            'retry_exhausted_count' => 'dsar_request_tasks.error_code=USER_DSAR_RETRY_EXHAUSTED with coalesce(updated_at,finished_at,created_at) >= window_start',
            'replay_requested_count' => 'dsar_audit_logs.event_type=requeue_requested and occurred_at >= window_start',
            'replay_started_count' => 'dsar_audit_logs.event_type=requeue_started and occurred_at >= window_start',
            'replay_done_count' => 'dsar_audit_logs.event_type=requeue_done and occurred_at >= window_start',
            'replay_failed_count' => 'dsar_audit_logs.event_type=requeue_failed and occurred_at >= window_start',
        ];

        $payload = [
            'ok' => true,
            'org_id' => $orgId,
            'window_hours' => $windowHours,
            'stale_minutes' => $staleMinutes,
            'generated_at' => now()->toIso8601String(),
            'stats' => $stats,
            'sources' => $sources,
        ];

        if ($this->isTruthy($this->option('json'))) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf(
                'timeouts=%d failed=%d retry_exhausted=%d replay_requested=%d replay_started=%d replay_done=%d replay_failed=%d',
                (int) $stats['timeout_count'],
                (int) $stats['failed_count'],
                (int) $stats['retry_exhausted_count'],
                (int) $stats['replay_requested_count'],
                (int) $stats['replay_started_count'],
                (int) $stats['replay_done_count'],
                (int) $stats['replay_failed_count']
            ));
        }

        return self::SUCCESS;
    }

    private function countTimeouts(int $orgId, \DateTimeInterface $staleCutoff): int
    {
        if (! SchemaBaseline::hasTable('dsar_requests')) {
            return 0;
        }

        return (int) DB::table('dsar_requests')
            ->where('org_id', $orgId)
            ->whereIn('status', ['pending', 'running'])
            ->whereRaw('coalesce(updated_at, requested_at, created_at) <= ?', [$staleCutoff])
            ->count();
    }

    private function countFailedRequests(int $orgId, \DateTimeInterface $windowStart): int
    {
        if (! SchemaBaseline::hasTable('dsar_requests')) {
            return 0;
        }

        return (int) DB::table('dsar_requests')
            ->where('org_id', $orgId)
            ->where('status', 'failed')
            ->whereRaw('coalesce(updated_at, executed_at, requested_at, created_at) >= ?', [$windowStart])
            ->count();
    }

    private function countRetryExhausted(int $orgId, \DateTimeInterface $windowStart): int
    {
        if (! SchemaBaseline::hasTable('dsar_request_tasks')) {
            return 0;
        }

        return (int) DB::table('dsar_request_tasks')
            ->where('org_id', $orgId)
            ->where('error_code', 'USER_DSAR_RETRY_EXHAUSTED')
            ->whereRaw('coalesce(updated_at, finished_at, created_at) >= ?', [$windowStart])
            ->count();
    }

    private function countReplayAudit(int $orgId, string $eventType, \DateTimeInterface $windowStart): int
    {
        if (! SchemaBaseline::hasTable('dsar_audit_logs')) {
            return 0;
        }

        return (int) DB::table('dsar_audit_logs')
            ->where('org_id', $orgId)
            ->where('event_type', $eventType)
            ->whereRaw('coalesce(occurred_at, created_at) >= ?', [$windowStart])
            ->count();
    }

    private function positiveInt(mixed $value): int
    {
        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return 0;
        }

        $int = (int) $raw;

        return $int > 0 ? $int : 0;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
