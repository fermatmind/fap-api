<?php

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Analytics\EventRecorder;
use App\Services\Assessment\GenericReportBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportSnapshotStore
{
    private const SNAPSHOT_VERSION = 'v1';
    private const REPORT_ENGINE_VERSION = 'v1.2';

    public function __construct(
        private ReportComposer $reportComposer,
        private GenericReportBuilder $genericReportBuilder,
        private EventRecorder $eventRecorder,
    ) {
    }

    /**
     * @param array $ctx {org_id:int, attempt_id:string, trigger_source:string, order_no?:string}
     */
    public function createSnapshotForAttempt(array $ctx): array
    {
        $orgId = (int) ($ctx['org_id'] ?? 0);
        $attemptId = trim((string) ($ctx['attempt_id'] ?? ''));
        $trigger = trim((string) ($ctx['trigger_source'] ?? ''));
        $orderNo = trim((string) ($ctx['order_no'] ?? ''));

        if ($attemptId === '') {
            return $this->badRequest('ATTEMPT_REQUIRED', 'attempt_id is required.');
        }

        if (!Schema::hasTable('report_snapshots')) {
            return $this->tableMissing('report_snapshots');
        }
        if (!Schema::hasTable('attempts')) {
            return $this->tableMissing('attempts');
        }
        if (!Schema::hasTable('results')) {
            return $this->tableMissing('results');
        }

        $existing = DB::table('report_snapshots')->where('attempt_id', $attemptId)->first();
        if ($existing) {
            return [
                'ok' => true,
                'snapshot' => $this->normalizeSnapshot($existing),
                'idempotent' => true,
            ];
        }

        $attempt = Attempt::where('id', $attemptId)->where('org_id', $orgId)->first();
        if (!$attempt) {
            return $this->notFound('ATTEMPT_NOT_FOUND', 'attempt not found.');
        }

        $result = Result::where('org_id', $orgId)->where('attempt_id', $attemptId)->first();
        if (!$result) {
            return $this->notFound('RESULT_NOT_FOUND', 'result not found.');
        }

        $scaleCode = strtoupper((string) ($attempt->scale_code ?? ''));
        $packId = (string) ($attempt->pack_id ?? '');
        $dirVersion = (string) ($attempt->dir_version ?? '');
        $scoringSpecVersion = $attempt->scoring_spec_version ?? $result->scoring_spec_version ?? null;

        $report = $this->buildReport($scaleCode, $attempt, $result);
        if (!is_array($report)) {
            return $this->serverError('REPORT_FAILED', 'report generation failed.');
        }

        $reportJson = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($reportJson === false) {
            return $this->serverError('REPORT_ENCODE_FAILED', 'report json encode failed.');
        }

        $now = now();

        $row = [
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'order_no' => $orderNo !== '' ? $orderNo : null,
            'scale_code' => $scaleCode,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'scoring_spec_version' => $scoringSpecVersion,
            'report_engine_version' => self::REPORT_ENGINE_VERSION,
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'report_json' => $reportJson,
            'created_at' => $now,
        ];

        $inserted = DB::table('report_snapshots')->insertOrIgnore($row);
        if (!$inserted) {
            $existing = DB::table('report_snapshots')->where('attempt_id', $attemptId)->first();
            return [
                'ok' => true,
                'snapshot' => $existing ? $this->normalizeSnapshot($existing) : null,
                'idempotent' => true,
            ];
        }

        $this->eventRecorder->record('report_snapshot_created', null, [
            'scale_code' => $scaleCode,
            'attempt_id' => $attemptId,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'order_no' => $orderNo !== '' ? $orderNo : null,
            'trigger_source' => $trigger !== '' ? $trigger : null,
        ], [
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
        ]);

        $snapshot = DB::table('report_snapshots')->where('attempt_id', $attemptId)->first();

        return [
            'ok' => true,
            'snapshot' => $snapshot ? $this->normalizeSnapshot($snapshot) : null,
            'idempotent' => false,
        ];
    }

    private function buildReport(string $scaleCode, Attempt $attempt, Result $result): ?array
    {
        if ($scaleCode === 'MBTI') {
            $composed = $this->reportComposer->compose((string) $attempt->id, []);
            if (!($composed['ok'] ?? false)) {
                return null;
            }
            $report = $composed['report'] ?? null;
            return is_array($report) ? $report : null;
        }

        $report = $this->genericReportBuilder->build($attempt, $result);
        return is_array($report) ? $report : null;
    }

    private function normalizeSnapshot(object $row): array
    {
        $payload = [
            'org_id' => (int) ($row->org_id ?? 0),
            'attempt_id' => (string) ($row->attempt_id ?? ''),
            'order_no' => $row->order_no ?? null,
            'scale_code' => (string) ($row->scale_code ?? ''),
            'pack_id' => (string) ($row->pack_id ?? ''),
            'dir_version' => (string) ($row->dir_version ?? ''),
            'scoring_spec_version' => $row->scoring_spec_version ?? null,
            'report_engine_version' => (string) ($row->report_engine_version ?? self::REPORT_ENGINE_VERSION),
            'snapshot_version' => (string) ($row->snapshot_version ?? self::SNAPSHOT_VERSION),
            'created_at' => (string) ($row->created_at ?? ''),
        ];

        $reportJson = $row->report_json ?? null;
        if (is_string($reportJson)) {
            $decoded = json_decode($reportJson, true);
            $payload['report_json'] = is_array($decoded) ? $decoded : [];
        } elseif (is_array($reportJson)) {
            $payload['report_json'] = $reportJson;
        } else {
            $payload['report_json'] = [];
        }

        return $payload;
    }

    private function tableMissing(string $table): array
    {
        return [
            'ok' => false,
            'error' => 'TABLE_MISSING',
            'message' => "{$table} table missing.",
        ];
    }

    private function badRequest(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ];
    }

    private function notFound(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ];
    }

    private function serverError(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
            'status' => 500,
        ];
    }
}
