<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\API\V0_3\Concerns\ResolvesAttemptOwnership;
use App\Http\Controllers\Controller;
use App\Models\Result;
use App\Services\Analytics\EventRecorder;
use App\Services\Report\BigFivePdfDocumentService;
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
        private BigFivePdfDocumentService $bigFivePdfDocumentService,
        private EventRecorder $eventRecorder,
        protected OrgContext $orgContext,
    ) {}

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

        $scaleCode = strtoupper((string) ($attempt->scale_code ?? ''));
        if ($scaleCode === 'CLINICAL_COMBO_68') {
            $gate = $this->reportGatekeeper->resolve(
                $orgId,
                $id,
                $this->resolveUserId($request),
                $this->resolveAnonId($request),
                $this->orgContext->role(),
                false,
                false,
            );

            if (! ($gate['ok'] ?? false)) {
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

            $report = is_array($gate['report'] ?? null) ? $gate['report'] : [];
            $safeResult = [
                'scale_code' => $scaleCode,
                'quality' => is_array($gate['quality'] ?? null) ? $gate['quality'] : [],
                'scores' => is_array($report['scores'] ?? null) ? $report['scores'] : [],
                'report_tags' => is_array($report['report_tags'] ?? null) ? $report['report_tags'] : [],
                'sections' => is_array($report['sections'] ?? null) ? $report['sections'] : [],
            ];

            $this->eventRecorder->recordFromRequest($request, 'result_view', $this->resolveUserId($request), [
                'scale_code' => (string) ($attempt->scale_code ?? ''),
                'pack_id' => (string) ($attempt->pack_id ?? ''),
                'dir_version' => (string) ($attempt->dir_version ?? ''),
                'type_code' => (string) ($result->type_code ?? ''),
                'attempt_id' => (string) $attempt->id,
                'locked' => (bool) ($gate['locked'] ?? true),
            ]);

            return response()->json([
                'ok' => true,
                'attempt_id' => (string) $attempt->id,
                'type_code' => '',
                'scores' => $safeResult['scores'],
                'scores_pct' => [],
                'result' => $safeResult,
                'report' => $gate,
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

        $payload = $result->result_json;
        if (! is_array($payload)) {
            $payload = [];
        }

        $compatTypeCode = (string) (($payload['type_code'] ?? null) ?? ($result->type_code ?? ''));

        $compatScores = $result->scores_json;
        if (! is_array($compatScores)) {
            $compatScores = $payload['scores_json'] ?? $payload['scores'] ?? [];
        }
        if (! is_array($compatScores)) {
            $compatScores = [];
        }

        $compatScoresPct = $result->scores_pct;
        if (! is_array($compatScoresPct)) {
            $compatScoresPct = $payload['scores_pct'] ?? ($payload['axis_scores_json']['scores_pct'] ?? null);
        }
        if (! is_array($compatScoresPct)) {
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

        if (! ($gate['ok'] ?? false)) {
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

        if (! ($gate['ok'] ?? false)) {
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
        if (! is_array($report)) {
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

        $variant = $this->bigFivePdfDocumentService->normalizeVariant((string) ($gate['variant'] ?? 'free'));
        $locked = (bool) ($gate['locked'] ?? false);
        $fileName = $this->bigFivePdfDocumentService->fileName((string) ($attempt->scale_code ?? 'report'), (string) $attempt->id);
        $inline = in_array(strtolower(trim((string) $request->query('inline', '0'))), ['1', 'true', 'yes', 'on'], true);
        $disposition = $inline ? 'inline' : 'attachment';

        $this->eventRecorder->recordFromRequest($request, 'report_pdf_view', $this->resolveUserId($request), [
            'scale_code' => (string) ($attempt->scale_code ?? ''),
            'pack_id' => (string) ($attempt->pack_id ?? ''),
            'dir_version' => (string) ($attempt->dir_version ?? ''),
            'type_code' => (string) ($result->type_code ?? ''),
            'attempt_id' => (string) $attempt->id,
            'locked' => $locked,
            'variant' => $variant,
        ]);

        $pdfBinary = null;
        if (strtoupper((string) ($attempt->scale_code ?? '')) === 'BIG5_OCEAN') {
            $pdfBinary = $this->bigFivePdfDocumentService->readArtifact((string) $attempt->id, $variant);
        }
        if (! is_string($pdfBinary) || $pdfBinary === '') {
            $pdfBinary = $this->bigFivePdfDocumentService->buildDocument(
                (string) $attempt->id,
                (string) ($attempt->scale_code ?? ''),
                $locked,
                $variant,
                $normsStatus,
                $qualityLevel,
                $sections
            );
            if (strtoupper((string) ($attempt->scale_code ?? '')) === 'BIG5_OCEAN') {
                $this->bigFivePdfDocumentService->storeArtifact((string) $attempt->id, $variant, $pdfBinary);
            }
        }

        return response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$fileName.'"',
            'Cache-Control' => 'private, no-store',
            'X-Report-Scale' => strtoupper((string) ($attempt->scale_code ?? '')),
            'X-Report-Variant' => $variant,
            'X-Report-Locked' => $locked ? 'true' : 'false',
        ]);
    }
}
