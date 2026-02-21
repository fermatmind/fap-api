<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Http\Controllers\API\V0_3\Concerns\ResolvesAttemptOwnership;
use App\Models\Result;
use App\Services\Analytics\EventRecorder;
use App\Services\Report\ReportGatekeeper;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AttemptReadController extends Controller
{
    use ResolvesAttemptOwnership;

    public function __construct(
        private ReportGatekeeper $reportGatekeeper,
        private EventRecorder $eventRecorder,
        protected OrgContext $orgContext,
    ) {
    }

    /**
     * GET /api/v0.3/attempts/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->result($request, $id);
    }

    /**
     * GET /api/v0.3/attempts/{id}/result
     */
    public function result(Request $request, string $id): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $attempt = $this->ownedAttemptQuery($request, $id)->firstOrFail();

        $result = Result::where('org_id', $orgId)->where('attempt_id', $id)->firstOrFail();

        $payload = $result->result_json;
        if (!is_array($payload)) {
            $payload = [];
        }

        $compatTypeCode = (string) (($payload['type_code'] ?? null) ?? ($result->type_code ?? ''));

        $compatScores = $result->scores_json;
        if (!is_array($compatScores)) {
            $compatScores = $payload['scores_json'] ?? $payload['scores'] ?? [];
        }
        if (!is_array($compatScores)) {
            $compatScores = [];
        }

        $compatScoresPct = $result->scores_pct;
        if (!is_array($compatScoresPct)) {
            $compatScoresPct = $payload['scores_pct'] ?? ($payload['axis_scores_json']['scores_pct'] ?? null);
        }
        if (!is_array($compatScoresPct)) {
            $compatScoresPct = [];
        }

        $this->eventRecorder->recordFromRequest($request, 'result_view', $this->resolveUserId($request), [
            'scale_code' => (string) ($attempt->scale_code ?? ''),
            'pack_id' => (string) ($attempt->pack_id ?? ''),
            'dir_version' => (string) ($attempt->dir_version ?? ''),
            'type_code' => (string) ($result->type_code ?? ''),
            'attempt_id' => (string) $attempt->id,
        ]);

        return response()->json([
            'ok' => true,
            'attempt_id' => (string) $attempt->id,
            'type_code' => $compatTypeCode,
            'scores' => $compatScores,
            'scores_pct' => $compatScoresPct,
            'result' => $payload,
            'meta' => [
                'scale_code' => (string) ($attempt->scale_code ?? ''),
                'pack_id' => (string) ($attempt->pack_id ?? ''),
                'dir_version' => (string) ($attempt->dir_version ?? ''),
                'content_package_version' => (string) ($attempt->content_package_version ?? ''),
                'scoring_spec_version' => (string) ($attempt->scoring_spec_version ?? ''),
                'report_engine_version' => (string) ($result->report_engine_version ?? 'v1.2'),
            ],
        ]);
    }

    /**
     * GET /api/v0.3/attempts/{id}/report
     */
    public function report(Request $request, string $id): JsonResponse
    {
        $refreshRaw = strtolower(trim((string) $request->query('refresh', '0')));
        $forceRefresh = in_array($refreshRaw, ['1', 'true', 'yes', 'on'], true);

        $orgId = $this->orgContext->orgId();
        $userId = $this->resolveUserId($request);
        $anonId = $this->resolveAnonId($request);
        $attempt = $this->ownedAttemptQuery($request, $id)->firstOrFail();

        $result = Result::where('org_id', $orgId)->where('attempt_id', $id)->firstOrFail();

        $gate = $this->reportGatekeeper->resolve(
            $orgId,
            $id,
            $userId !== null ? (string) $userId : null,
            $anonId,
            $this->orgContext->role(),
            false,
            $forceRefresh,
        );

        if (!($gate['ok'] ?? false)) {
            $status = (int) ($gate['status'] ?? 0);
            if ($status <= 0) {
                $error = strtoupper((string) data_get($gate, 'error_code', data_get($gate, 'error', 'REPORT_FAILED')));
                $status = match ($error) {
                    'ATTEMPT_REQUIRED', 'SCALE_REQUIRED' => 400,
                    'ATTEMPT_NOT_FOUND', 'RESULT_NOT_FOUND', 'SCALE_NOT_FOUND' => 404,
                    default => 500,
                };
            }

            abort($status, (string) ($gate['message'] ?? 'report generation failed.'));
        }

        $this->eventRecorder->recordFromRequest($request, 'report_view', $this->resolveUserId($request), [
            'scale_code' => (string) ($attempt->scale_code ?? ''),
            'pack_id' => (string) ($attempt->pack_id ?? ''),
            'dir_version' => (string) ($attempt->dir_version ?? ''),
            'type_code' => (string) ($result->type_code ?? ''),
            'attempt_id' => (string) $attempt->id,
            'locked' => (bool) ($gate['locked'] ?? false),
        ]);

        $gateMeta = [];
        if (isset($gate['meta']) && is_array($gate['meta'])) {
            $gateMeta = $gate['meta'];
        }

        return response()->json(array_merge($gate, [
            'meta' => array_merge($gateMeta, [
                'scale_code' => (string) ($attempt->scale_code ?? ''),
                'pack_id' => (string) ($attempt->pack_id ?? ''),
                'dir_version' => (string) ($attempt->dir_version ?? ''),
                'content_package_version' => (string) ($attempt->content_package_version ?? ''),
                'scoring_spec_version' => (string) ($attempt->scoring_spec_version ?? ''),
                'report_engine_version' => (string) ($result->report_engine_version ?? 'v1.2'),
            ]),
        ]));
    }

    /**
     * GET /api/v0.3/attempts/{id}/report.pdf
     */
    public function reportPdf(Request $request, string $id): Response
    {
        $orgId = $this->orgContext->orgId();
        $userId = $this->resolveUserId($request);
        $anonId = $this->resolveAnonId($request);
        $attempt = $this->ownedAttemptQuery($request, $id)->firstOrFail();
        $result = Result::where('org_id', $orgId)->where('attempt_id', $id)->firstOrFail();

        $gate = $this->reportGatekeeper->resolve(
            $orgId,
            $id,
            $userId !== null ? (string) $userId : null,
            $anonId,
            $this->orgContext->role(),
            false,
            false,
        );

        if (!($gate['ok'] ?? false)) {
            $status = (int) ($gate['status'] ?? 0);
            if ($status <= 0) {
                $error = strtoupper((string) data_get($gate, 'error_code', data_get($gate, 'error', 'REPORT_FAILED')));
                $status = match ($error) {
                    'ATTEMPT_REQUIRED', 'SCALE_REQUIRED' => 400,
                    'ATTEMPT_NOT_FOUND', 'RESULT_NOT_FOUND', 'SCALE_NOT_FOUND' => 404,
                    default => 500,
                };
            }

            abort($status, (string) ($gate['message'] ?? 'report generation failed.'));
        }

        $report = $gate['report'] ?? [];
        if (!is_array($report)) {
            $report = [];
        }

        $sections = array_map(
            'strval',
            array_values(
                array_filter(
                    array_column((array) ($report['sections'] ?? []), 'key'),
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

        $lines = [
            'Attempt ID: ' . (string) $attempt->id,
            'Scale: ' . strtoupper((string) ($attempt->scale_code ?? '')),
            'Variant: ' . strtolower((string) ($gate['variant'] ?? '')),
            'Locked: ' . (($gate['locked'] ?? false) ? 'true' : 'false'),
        ];
        if ($normsStatus !== '') {
            $lines[] = 'Norms Status: ' . $normsStatus;
        }
        if ($qualityLevel !== '') {
            $lines[] = 'Quality Level: ' . $qualityLevel;
        }
        if ($sections !== []) {
            $lines[] = 'Sections: ' . implode(', ', $sections);
        }

        $scaleSlug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', (string) ($attempt->scale_code ?? 'report')));
        $fileName = trim($scaleSlug, '_') . '_report_' . (string) $attempt->id . '.pdf';
        $inline = in_array(strtolower(trim((string) $request->query('inline', '0'))), ['1', 'true', 'yes', 'on'], true);
        $disposition = $inline ? 'inline' : 'attachment';

        $this->eventRecorder->recordFromRequest($request, 'report_pdf_view', $this->resolveUserId($request), [
            'scale_code' => (string) ($attempt->scale_code ?? ''),
            'pack_id' => (string) ($attempt->pack_id ?? ''),
            'dir_version' => (string) ($attempt->dir_version ?? ''),
            'type_code' => (string) ($result->type_code ?? ''),
            'attempt_id' => (string) $attempt->id,
            'locked' => (bool) ($gate['locked'] ?? false),
            'variant' => (string) ($gate['variant'] ?? ''),
        ]);

        return response($this->buildSimplePdfDocument('FermatMind Report', $lines), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition . '; filename="' . $fileName . '"',
            'Cache-Control' => 'private, no-store',
            'X-Report-Scale' => strtoupper((string) ($attempt->scale_code ?? '')),
            'X-Report-Variant' => strtolower((string) ($gate['variant'] ?? '')),
            'X-Report-Locked' => ($gate['locked'] ?? false) ? 'true' : 'false',
        ]);
    }

    /**
     * @param list<string> $lines
     */
    private function buildSimplePdfDocument(string $title, array $lines): string
    {
        $title = $this->sanitizePdfText($title);
        $stream = "BT\n/F1 14 Tf\n1 0 0 1 40 800 Tm\n(" . $title . ") Tj\n/F1 10 Tf\n";

        $y = 780;
        foreach ($lines as $line) {
            $line = $this->sanitizePdfText($line);
            if ($line === '') {
                continue;
            }
            $stream .= "1 0 0 1 40 {$y} Tm\n(" . $line . ") Tj\n";
            $y -= 14;
            if ($y < 48) {
                break;
            }
        }
        $stream .= "ET\n";

        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
            "5 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream\nendobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[$index + 1] = strlen($pdf);
            $pdf .= $object;
        }

        $startXref = strlen($pdf);
        $pdf .= "xref\n0 6\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $startXref . "\n%%EOF\n";

        return $pdf;
    }

    private function sanitizePdfText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = substr($value, 0, 160);
        $value = preg_replace('/[^ -~]/', '?', $value) ?? '';
        $value = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);

        return $value;
    }
}
