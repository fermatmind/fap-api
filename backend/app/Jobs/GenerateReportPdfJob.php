<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Analytics\EventRecorder;
use App\Services\Report\Pdf\ReportPdfDocumentService;
use App\Services\Report\ReportGatekeeper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class GenerateReportPdfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 150;

    public bool $failOnTimeout = true;

    /** @var array<int, int> */
    public array $backoff = [5, 10, 20];

    public function __construct(
        public int $orgId,
        public string $attemptId,
        public string $triggerSource,
        public ?string $orderNo = null,
    ) {
        $this->onConnection('database_reports');
        $this->onQueue('reports');
    }

    public function handle(
        ReportGatekeeper $gatekeeper,
        ReportPdfDocumentService $pdfService,
        EventRecorder $events
    ): void {
        $attemptId = trim($this->attemptId);
        if ($attemptId === '') {
            return;
        }

        $attempt = Attempt::query()
            ->where('id', $attemptId)
            ->where('org_id', $this->orgId)
            ->first();
        if (! $attempt instanceof Attempt) {
            return;
        }

        $result = Result::query()
            ->where('attempt_id', $attemptId)
            ->where('org_id', $this->orgId)
            ->first();
        if (! $result instanceof Result) {
            return;
        }

        $userId = $attempt->user_id !== null ? (string) $attempt->user_id : null;
        $anonId = $attempt->anon_id !== null ? (string) $attempt->anon_id : null;

        $gate = $gatekeeper->resolve(
            $this->orgId,
            $attemptId,
            $userId,
            $anonId,
            'system',
            false,
            false
        );
        if (! ($gate['ok'] ?? false)) {
            $errorCode = (string) ($gate['error_code'] ?? $gate['error'] ?? 'REPORT_GATE_FAILED');
            $message = (string) ($gate['message'] ?? 'report gate failed');
            throw new \RuntimeException($errorCode.': '.$message);
        }

        $built = $pdfService->getOrGenerate($attempt, $gate, $result);

        try {
            $events->record('report_pdf_generated', $this->numericUserId($userId), [
                'scale_code' => (string) ($attempt->scale_code ?? ''),
                'attempt_id' => $attemptId,
                'variant' => (string) ($built['variant'] ?? 'free'),
                'locked' => (bool) ($built['locked'] ?? true),
                'artifact_path' => (string) ($built['storage_path'] ?? ''),
                'manifest_hash' => (string) ($built['manifest_hash'] ?? ''),
                'trigger_source' => $this->triggerSource,
                'order_no' => $this->orderNo,
                'cached' => (bool) ($built['cached'] ?? false),
            ], [
                'org_id' => $this->orgId,
                'attempt_id' => $attemptId,
                'anon_id' => $anonId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('REPORT_PDF_EVENT_RECORD_FAILED', [
                'org_id' => $this->orgId,
                'attempt_id' => $attemptId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function numericUserId(?string $userId): ?int
    {
        $userId = trim((string) $userId);
        if ($userId === '' || preg_match('/^\d+$/', $userId) !== 1) {
            return null;
        }

        return (int) $userId;
    }
}
