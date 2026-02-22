<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Analytics\EventRecorder;
use App\Services\Report\BigFivePdfDocumentService;
use App\Services\Report\ReportGatekeeper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class GenerateBigFiveReportPdfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 10, 20];

    public function __construct(
        public int $orgId,
        public string $attemptId,
        public string $triggerSource,
        public ?string $orderNo = null,
    ) {
        $this->onConnection('database');
        $this->onQueue('reports');
    }

    public function handle(
        ReportGatekeeper $gatekeeper,
        BigFivePdfDocumentService $pdfService,
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
        if (! $attempt) {
            return;
        }
        if (strtoupper((string) ($attempt->scale_code ?? '')) !== 'BIG5_OCEAN') {
            return;
        }

        $result = Result::query()
            ->where('attempt_id', $attemptId)
            ->where('org_id', $this->orgId)
            ->first();
        if (! $result) {
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

        $variant = $pdfService->normalizeVariant((string) ($gate['variant'] ?? 'free'));
        $locked = (bool) ($gate['locked'] ?? true);
        $sections = array_map(
            'strval',
            array_values(
                array_filter(
                    array_column((array) (($gate['report'] ?? [])['sections'] ?? []), 'key'),
                    static fn ($value): bool => is_string($value) && trim($value) !== ''
                )
            )
        );
        $normsStatus = strtoupper(trim((string) (
            data_get($gate, 'norms.status')
            ?? data_get($result->result_json, 'normed_json.norms.status', '')
        )));
        $qualityLevel = strtoupper(trim((string) (
            data_get($gate, 'quality.level')
            ?? data_get($result->result_json, 'normed_json.quality.level', '')
        )));

        $pdfBinary = $pdfService->buildDocument(
            $attemptId,
            (string) ($attempt->scale_code ?? 'BIG5_OCEAN'),
            $locked,
            $variant,
            $normsStatus,
            $qualityLevel,
            $sections
        );
        $artifactPath = $pdfService->storeArtifact($attemptId, $variant, $pdfBinary);

        try {
            $events->record('report_pdf_generated', $userId, [
                'scale_code' => (string) ($attempt->scale_code ?? ''),
                'attempt_id' => $attemptId,
                'variant' => $variant,
                'locked' => $locked,
                'artifact_path' => $artifactPath,
                'trigger_source' => $this->triggerSource,
                'order_no' => $this->orderNo,
            ], [
                'org_id' => $this->orgId,
                'attempt_id' => $attemptId,
                'anon_id' => $anonId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('BIG5_REPORT_PDF_EVENT_RECORD_FAILED', [
                'org_id' => $this->orgId,
                'attempt_id' => $attemptId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
