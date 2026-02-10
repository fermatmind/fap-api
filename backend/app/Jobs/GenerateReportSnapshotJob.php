<?php

namespace App\Jobs;

use App\Services\Report\ReportSnapshotStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class GenerateReportSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 10, 20];

    public function __construct(
        public int $orgId,
        public string $attemptId,
        public string $triggerSource,
        public ?string $orderNo = null,
    ) {
    }

    public function handle(ReportSnapshotStore $store): void
    {
        $attemptId = trim($this->attemptId);
        if ($attemptId === '' || !Schema::hasTable('report_snapshots')) {
            return;
        }

        $snapshot = $this->snapshotQuery($attemptId)->first();
        if (!$snapshot) {
            return;
        }

        $status = strtolower(trim((string) ($snapshot->status ?? 'ready')));
        if ($status === 'ready') {
            return;
        }

        try {
            $result = $store->createSnapshotForAttempt([
                'org_id' => $this->orgId,
                'attempt_id' => $attemptId,
                'trigger_source' => $this->triggerSource,
                'order_no' => $this->orderNo,
                'org_role' => 'system',
            ]);

            if (!($result['ok'] ?? false)) {
                $error = (string) ($result['error'] ?? 'SNAPSHOT_FAILED');
                $message = (string) ($result['message'] ?? 'report snapshot generation failed.');
                throw new \RuntimeException($error . ': ' . $message);
            }

            $this->updateSnapshotState($attemptId, [
                'status' => 'ready',
                'last_error' => null,
            ]);
        } catch (\Throwable $e) {
            $this->updateSnapshotState($attemptId, [
                'status' => 'failed',
                'last_error' => $this->truncateError($e::class . ': ' . $e->getMessage()),
            ]);

            Log::error('REPORT_SNAPSHOT_GENERATE_FAILED', [
                'org_id' => $this->orgId,
                'attempt_id' => $attemptId,
                'trigger_source' => $this->triggerSource,
                'order_no' => $this->orderNo,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function updateSnapshotState(string $attemptId, array $fields): void
    {
        if (!Schema::hasTable('report_snapshots')) {
            return;
        }

        $updates = [];
        if (Schema::hasColumn('report_snapshots', 'status') && array_key_exists('status', $fields)) {
            $updates['status'] = $fields['status'];
        }
        if (Schema::hasColumn('report_snapshots', 'last_error') && array_key_exists('last_error', $fields)) {
            $updates['last_error'] = $fields['last_error'];
        }
        if (Schema::hasColumn('report_snapshots', 'updated_at')) {
            $updates['updated_at'] = now();
        }

        if (count($updates) > 0) {
            $this->snapshotQuery($attemptId)->update($updates);
        }
    }

    private function snapshotQuery(string $attemptId): Builder
    {
        $base = DB::table('report_snapshots')->where('attempt_id', $attemptId);
        if (!Schema::hasColumn('report_snapshots', 'org_id')) {
            return $base;
        }

        $byOrg = DB::table('report_snapshots')
            ->where('attempt_id', $attemptId)
            ->where('org_id', $this->orgId);
        if ($byOrg->exists()) {
            return $byOrg;
        }

        return $base;
    }

    private function truncateError(string $error): string
    {
        $error = trim($error);
        if ($error === '') {
            return 'snapshot generation failed';
        }

        if (strlen($error) <= 1024) {
            return $error;
        }

        return substr($error, 0, 1024);
    }
}
