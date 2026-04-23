<?php

namespace App\Http\Controllers\API\V0_3;

use App\Exceptions\Api\ApiProblemException;
use App\Http\Controllers\API\V0_3\Concerns\ResolvesAttemptOwnership;
use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\AttemptInviteUnlock;
use App\Models\Result;
use App\Models\UnifiedAccessProjection;
use App\Repositories\Report\ReportAccessActor;
use App\Repositories\Report\ReportSubjectRepository;
use App\Services\Access\AttemptUnlockProjectionRepairService;
use App\Services\Analytics\EventRecorder;
use App\Services\Attempts\AttemptSubmissionService;
use App\Services\Attempts\InviteUnlock\InviteUnlockDiagnostics;
use App\Services\BigFive\BigFivePublicFormSummaryBuilder;
use App\Services\BigFive\BigFivePublicProjectionService;
use App\Services\BigFive\ReportEngine\Bridge\BigFiveLiveRuntimeBridge;
use App\Services\Commerce\MbtiAccessHubBuilder;
use App\Services\Enneagram\EnneagramPublicFormSummaryBuilder;
use App\Services\Enneagram\EnneagramPublicProjectionService;
use App\Services\Mbti\MbtiActionJourneyContractService;
use App\Services\Mbti\MbtiAdaptiveSelectionService;
use App\Services\Mbti\MbtiIntraTypeProfileService;
use App\Services\Mbti\MbtiPrivacyConsentContractService;
use App\Services\Mbti\MbtiPublicFormSummaryBuilder;
use App\Services\Mbti\MbtiPublicProjectionService;
use App\Services\Mbti\MbtiPublicSummaryV1Builder;
use App\Services\Mbti\MbtiReadModelContractService;
use App\Services\Mbti\MbtiUserStateOrchestrationService;
use App\Services\Mbti\MbtiWorkingLifeConsolidationService;
use App\Services\Observability\ClinicalComboTelemetry;
use App\Services\Observability\Sds20Telemetry;
use App\Services\Report\InviteUnlockSummaryBuilder;
use App\Services\Report\MbtiPreviewContractBuilder;
use App\Services\Report\Pdf\ReportPdfDocumentService;
use App\Services\Report\ReportAccess;
use App\Services\Report\ReportGatekeeper;
use App\Services\Riasec\RiasecPublicFormSummaryBuilder;
use App\Services\Riasec\RiasecPublicProjectionService;
use App\Services\Scale\ScaleCodeResponseProjector;
use App\Support\OrgContext;
use App\Support\SchemaBaseline;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class AttemptReadController extends Controller
{
    use ResolvesAttemptOwnership;

    private const PUBLIC_RESULT_READ_SCALES = ['MBTI', 'BIG5_OCEAN', 'IQ_RAVEN', 'EQ_60', 'ENNEAGRAM', 'RIASEC'];

    private const SENSITIVE_RESULT_READ_SCALES = ['SDS_20', 'CLINICAL_COMBO_68'];

    public function __construct(
        private AttemptSubmissionService $attemptSubmissionService,
        private ReportGatekeeper $reportGatekeeper,
        private ReportPdfDocumentService $reportPdfDocumentService,
        private BigFivePublicProjectionService $bigFivePublicProjectionService,
        private BigFivePublicFormSummaryBuilder $bigFivePublicFormSummaryBuilder,
        private EnneagramPublicProjectionService $enneagramPublicProjectionService,
        private EnneagramPublicFormSummaryBuilder $enneagramPublicFormSummaryBuilder,
        private RiasecPublicProjectionService $riasecPublicProjectionService,
        private RiasecPublicFormSummaryBuilder $riasecPublicFormSummaryBuilder,
        private MbtiPrivacyConsentContractService $mbtiPrivacyConsentContractService,
        private MbtiPublicProjectionService $mbtiPublicProjectionService,
        private MbtiPublicFormSummaryBuilder $mbtiPublicFormSummaryBuilder,
        private MbtiPublicSummaryV1Builder $mbtiPublicSummaryV1Builder,
        private MbtiUserStateOrchestrationService $mbtiUserStateOrchestrationService,
        private MbtiActionJourneyContractService $mbtiActionJourneyContractService,
        private MbtiAdaptiveSelectionService $mbtiAdaptiveSelectionService,
        private MbtiIntraTypeProfileService $mbtiIntraTypeProfileService,
        private MbtiReadModelContractService $mbtiReadModelContractService,
        private MbtiWorkingLifeConsolidationService $mbtiWorkingLifeConsolidationService,
        private MbtiAccessHubBuilder $mbtiAccessHubBuilder,
        private MbtiPreviewContractBuilder $mbtiPreviewContractBuilder,
        private InviteUnlockSummaryBuilder $inviteUnlockSummaryBuilder,
        private EventRecorder $eventRecorder,
        private ScaleCodeResponseProjector $responseProjector,
        private ReportSubjectRepository $reportSubjects,
        private AttemptUnlockProjectionRepairService $projectionRepair,
        private BigFiveLiveRuntimeBridge $bigFiveLiveRuntimeBridge,
    ) {}

    /**
     * GET /api/v0.3/attempts/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->result($request, $id);
    }

    /**
     * GET /api/v0.3/attempts/{attempt_id}/submission
     */
    public function submission(Request $request, string $attemptId): JsonResponse
    {
        $attempt = $this->resolveAttemptForSubmissionRead($request, $attemptId);
        $payload = $this->latestSubmissionPayload($request, $attemptId, $attempt);

        $status = (int) ($payload['http_status'] ?? 200);
        unset($payload['http_status']);

        return response()->json($payload, $status);
    }

    /**
     * GET /api/v0.3/attempts/{id}/result
     */
    public function result(Request $request, string $id): JsonResponse
    {
        $orgId = $this->currentOrgContext()->orgId();
        $submissionPayload = $this->latestReadableSubmission($request, $id);
        if (($submissionPayload['ok'] ?? false) === true) {
            $submissionState = strtolower(trim((string) data_get($submissionPayload, 'submission.state', 'pending')));
            if (in_array($submissionState, ['pending', 'running'], true)) {
                return $this->pendingSubmissionResponse($id, $submissionPayload, includeReport: false);
            }

            if ($submissionState === 'failed') {
                return $this->failedSubmissionResponse($id, $submissionPayload, includeReport: false);
            }
        }

        $result = Result::query()->where('org_id', $orgId)->where('attempt_id', $id)->first();
        if (! $result instanceof Result) {
            if (($submissionPayload['ok'] ?? false) === true) {
                $submissionState = strtolower(trim((string) data_get($submissionPayload, 'submission.state', 'pending')));
                if ($submissionState === 'succeeded') {
                    return $this->missingResultAfterSubmissionResponse($id, $submissionPayload, includeReport: false);
                }
            }

            throw new ApiProblemException(404, 'RESULT_NOT_FOUND', 'result not found.');
        }

        $responseCodes = $this->resolveResponseScaleCodes($result);
        $scaleCode = $this->resolveNormalizedScaleCode($responseCodes);
        $attempt = $this->resolveAttemptForResultRead($request, $orgId, $id, $scaleCode);
        $attemptId = $this->resolveAttemptId($result, $attempt, $id);
        $packId = $this->preferredStringField($result, $attempt, 'pack_id');
        $dirVersion = $this->preferredStringField($result, $attempt, 'dir_version');
        $contentPackageVersion = $this->preferredStringField($result, $attempt, 'content_package_version');
        $scoringSpecVersion = $this->preferredStringField($result, $attempt, 'scoring_spec_version');
        $reportEngineVersion = $this->preferredStringField($result, null, 'report_engine_version', 'v1.2');
        $mbtiFormSummary = $scaleCode === 'MBTI'
            ? $this->resolveMbtiFormSummary($request, $attempt, $result)
            : null;
        $big5FormSummary = $scaleCode === 'BIG5_OCEAN'
            ? $this->resolveBigFiveFormSummary($request, $attempt, $result)
            : null;
        $enneagramFormSummary = $scaleCode === 'ENNEAGRAM'
            ? $this->resolveEnneagramFormSummary($request, $attempt, $result)
            : null;
        $riasecFormSummary = $scaleCode === 'RIASEC'
            ? $this->riasecPublicFormSummaryBuilder->build($attempt, $result)
            : null;

        if ($scaleCode === 'CLINICAL_COMBO_68') {
            $gate = $this->reportGatekeeper->resolve(
                $orgId,
                $id,
                $this->resolveUserId($request),
                $this->resolveAnonId($request),
                $this->currentOrgContext()->role(),
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
                'scale_code' => $responseCodes['scale_code'],
                'scale_code_legacy' => $responseCodes['scale_code_legacy'],
                'scale_code_v2' => $responseCodes['scale_code_v2'],
                'scale_uid' => $responseCodes['scale_uid'],
                'quality' => is_array($gate['quality'] ?? null) ? $gate['quality'] : [],
                'scores' => is_array($report['scores'] ?? null) ? $report['scores'] : [],
                'report_tags' => is_array($report['report_tags'] ?? null) ? $report['report_tags'] : [],
                'sections' => is_array($report['sections'] ?? null) ? $report['sections'] : [],
            ];

            $request->merge(['attempt_id' => $attemptId]);
            $this->eventRecorder->recordFromRequest($request, 'result_view', $this->resolveUserId($request), [
                'scale_code' => $scaleCode,
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
                'type_code' => (string) ($result->type_code ?? ''),
                'attempt_id' => $attemptId,
                'locked' => (bool) ($gate['locked'] ?? true),
                ...$this->mbtiFormEventMeta($mbtiFormSummary),
                ...$this->big5FormEventMeta($big5FormSummary),
            ]);

            $responsePayload = [
                'ok' => true,
                'attempt_id' => $attemptId,
                'type_code' => '',
                'scores' => $safeResult['scores'],
                'scores_pct' => [],
                'result' => $safeResult,
                'report' => $gate,
                'meta' => [
                    'scale_code' => $responseCodes['scale_code'],
                    'scale_code_legacy' => $responseCodes['scale_code_legacy'],
                    'scale_code_v2' => $responseCodes['scale_code_v2'],
                    'scale_uid' => $responseCodes['scale_uid'],
                    'pack_id' => $packId,
                    'dir_version' => $dirVersion,
                    'content_package_version' => $contentPackageVersion,
                    'scoring_spec_version' => $scoringSpecVersion,
                    'report_engine_version' => $reportEngineVersion,
                ],
            ];
            if (is_array($mbtiFormSummary)) {
                $responsePayload['mbti_form_v1'] = $mbtiFormSummary;
            }

            return response()->json($responsePayload);
        }

        $payload = $result->result_json;
        if (! is_array($payload)) {
            $payload = [];
        }
        $payload['scale_code'] = $responseCodes['scale_code'];
        $payload['scale_code_legacy'] = $responseCodes['scale_code_legacy'];
        $payload['scale_code_v2'] = $responseCodes['scale_code_v2'];
        $payload['scale_uid'] = $responseCodes['scale_uid'];

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

        $big5Projection = $scaleCode === 'BIG5_OCEAN'
            ? $this->bigFivePublicProjectionService->buildFromResult(
                $result,
                (string) ($attempt?->locale ?? config('content_packs.default_locale', 'zh-CN'))
            )
            : [];
        $enneagramProjection = $scaleCode === 'ENNEAGRAM'
            ? $this->enneagramPublicProjectionService->buildFromResult(
                $result,
                (string) ($attempt?->locale ?? config('content_packs.default_locale', 'zh-CN'))
            )
            : [];
        $riasecProjection = $scaleCode === 'RIASEC'
            ? $this->riasecPublicProjectionService->buildFromResult(
                $result,
                (string) ($attempt?->locale ?? config('content_packs.default_locale', 'zh-CN'))
            )
            : [];

        $mbtiEventMeta = $scaleCode === 'MBTI'
            ? $this->resolveMbtiResultViewEventMeta($request, $result, $attempt, $attemptId)
            : [];
        $big5EventMeta = $scaleCode === 'BIG5_OCEAN'
            ? $this->resolveBigFiveEventMetaFromProjection($big5Projection)
            : [];

        $request->merge(['attempt_id' => $attemptId]);
        $this->eventRecorder->recordFromRequest($request, 'result_view', $this->resolveUserId($request), [
            'scale_code' => $scaleCode,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'type_code' => (string) ($result->type_code ?? ''),
            'attempt_id' => $attemptId,
            ...$mbtiEventMeta,
            ...$this->mbtiFormEventMeta($mbtiFormSummary),
            ...$this->big5FormEventMeta($big5FormSummary),
            ...$this->enneagramFormEventMeta($enneagramFormSummary),
            ...$this->riasecFormEventMeta($riasecFormSummary),
            ...$big5EventMeta,
        ]);

        $responsePayload = [
            'ok' => true,
            'attempt_id' => $attemptId,
            'type_code' => $compatTypeCode,
            'scores' => $compatScores,
            'scores_pct' => $compatScoresPct,
            'result' => $payload,
            'meta' => [
                'scale_code' => $responseCodes['scale_code'],
                'scale_code_legacy' => $responseCodes['scale_code_legacy'],
                'scale_code_v2' => $responseCodes['scale_code_v2'],
                'scale_uid' => $responseCodes['scale_uid'],
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
                'content_package_version' => $contentPackageVersion,
                'scoring_spec_version' => $scoringSpecVersion,
                'report_engine_version' => $reportEngineVersion,
            ],
        ];
        if ($big5Projection !== []) {
            $responsePayload['big5_public_projection_v1'] = $big5Projection;
            $controlledNarrative = is_array($big5Projection['controlled_narrative_v1'] ?? null)
                ? $big5Projection['controlled_narrative_v1']
                : [];
            if ($controlledNarrative !== []) {
                $responsePayload['controlled_narrative_v1'] = $controlledNarrative;
            }
            $culturalCalibration = is_array($big5Projection['cultural_calibration_v1'] ?? null)
                ? $big5Projection['cultural_calibration_v1']
                : [];
            if ($culturalCalibration !== []) {
                $responsePayload['cultural_calibration_v1'] = $culturalCalibration;
            }
            $comparative = is_array($big5Projection['comparative_v1'] ?? null)
                ? $big5Projection['comparative_v1']
                : [];
            if ($comparative !== []) {
                $responsePayload['comparative_v1'] = $comparative;
            }
        }
        if (is_array($mbtiFormSummary)) {
            $responsePayload['mbti_form_v1'] = $mbtiFormSummary;
        }
        if (is_array($big5FormSummary)) {
            $responsePayload['big5_form_v1'] = $big5FormSummary;
        }
        if ($enneagramProjection !== []) {
            $responsePayload['enneagram_public_projection_v1'] = $enneagramProjection;
        }
        if (is_array($enneagramFormSummary)) {
            $responsePayload['enneagram_form_v1'] = $enneagramFormSummary;
        }
        if ($riasecProjection !== []) {
            $responsePayload['riasec_public_projection_v1'] = $riasecProjection;
        }
        if (is_array($riasecFormSummary)) {
            $responsePayload['riasec_form_v1'] = $riasecFormSummary;
        }

        return response()->json($responsePayload);
    }

    /**
     * GET /api/v0.3/attempts/{id}/report
     */
    public function report(Request $request, string $id): JsonResponse
    {
        $refreshRaw = strtolower(trim((string) $request->query('refresh', '0')));
        $forceRefresh = in_array($refreshRaw, ['1', 'true', 'yes', 'on'], true);

        $orgId = $this->currentOrgContext()->orgId();
        $userId = $this->resolveUserId($request);
        $anonId = $this->resolveAnonId($request);
        $submissionPayload = $this->latestReadableSubmission($request, $id);
        if (($submissionPayload['ok'] ?? false) === true) {
            $submissionState = strtolower(trim((string) data_get($submissionPayload, 'submission.state', 'pending')));
            if (in_array($submissionState, ['pending', 'running'], true)) {
                return $this->pendingSubmissionResponse($id, $submissionPayload, includeReport: true);
            }

            if ($submissionState === 'failed') {
                return $this->failedSubmissionResponse($id, $submissionPayload, includeReport: true);
            }
        }

        $result = Result::query()->where('org_id', $orgId)->where('attempt_id', $id)->first();
        if (! $result instanceof Result) {
            if (($submissionPayload['ok'] ?? false) === true) {
                $submissionState = strtolower(trim((string) data_get($submissionPayload, 'submission.state', 'pending')));
                if ($submissionState === 'succeeded') {
                    return $this->missingResultAfterSubmissionResponse($id, $submissionPayload, includeReport: true);
                }
            }

            throw new ApiProblemException(404, 'RESULT_NOT_FOUND', 'result not found.');
        }
        $responseCodes = $this->resolveResponseScaleCodes($result);
        $scaleCode = $this->resolveNormalizedScaleCode($responseCodes);
        $attempt = $this->resolveAttemptForReportRead($request, $id, $scaleCode);
        $mbtiFormSummary = $scaleCode === 'MBTI'
            ? $this->resolveMbtiFormSummary($request, $attempt, $result)
            : null;
        $big5FormSummary = $scaleCode === 'BIG5_OCEAN'
            ? $this->resolveBigFiveFormSummary($request, $attempt, $result)
            : null;
        $enneagramFormSummary = $scaleCode === 'ENNEAGRAM'
            ? $this->resolveEnneagramFormSummary($request, $attempt, $result)
            : null;
        $riasecFormSummary = $scaleCode === 'RIASEC'
            ? $this->riasecPublicFormSummaryBuilder->build($attempt, $result)
            : null;

        $gate = $this->resolveReportGate(
            $orgId,
            $id,
            $userId !== null ? (string) $userId : null,
            $anonId,
            $this->currentOrgContext()->role(),
            $this->shouldUsePublicArtifactFallback($request, $attempt),
            $forceRefresh,
        );

        $scaleCode = strtoupper(trim((string) ($attempt->scale_code ?? '')));
        $reportViewMeta = [
            'variant' => strtolower(trim((string) ($gate['variant'] ?? 'free'))),
            'locked' => (bool) ($gate['locked'] ?? false),
            'source' => 'report_api',
            'access_level' => strtolower(trim((string) ($gate['access_level'] ?? 'free'))),
        ];
        if ($scaleCode === 'CLINICAL_COMBO_68') {
            app(ClinicalComboTelemetry::class)->reportViewed($attempt, $reportViewMeta);
        } elseif ($scaleCode === 'SDS_20') {
            app(Sds20Telemetry::class)->reportViewed($attempt, $reportViewMeta);
        }

        $gateMeta = [];
        if (isset($gate['meta']) && is_array($gate['meta'])) {
            $gateMeta = $gate['meta'];
        }
        if (isset($gate['report']) && is_array($gate['report'])) {
            $gate['report'] = $this->projectScaleCodePayload($gate['report'], $responseCodes);
        }

        $responsePayload = array_merge($gate, [
            'scale_code' => $responseCodes['scale_code'],
            'scale_code_legacy' => $responseCodes['scale_code_legacy'],
            'scale_code_v2' => $responseCodes['scale_code_v2'],
            'scale_uid' => $responseCodes['scale_uid'],
            'meta' => array_merge($gateMeta, [
                'scale_code' => $responseCodes['scale_code'],
                'scale_code_legacy' => $responseCodes['scale_code_legacy'],
                'scale_code_v2' => $responseCodes['scale_code_v2'],
                'scale_uid' => $responseCodes['scale_uid'],
                'pack_id' => (string) ($attempt->pack_id ?? ''),
                'dir_version' => (string) ($attempt->dir_version ?? ''),
                'content_package_version' => (string) ($attempt->content_package_version ?? ''),
                'scoring_spec_version' => (string) ($attempt->scoring_spec_version ?? ''),
                'report_engine_version' => (string) ($result->report_engine_version ?? 'v1.2'),
            ]),
        ]);

        if ($scaleCode === 'MBTI') {
            $responsePayload['mbti_public_summary_v1'] = $this->mbtiPublicSummaryV1Builder->buildFromReportEnvelope(
                $result,
                $responsePayload,
                (string) ($attempt->locale ?? config('content_packs.default_locale', 'zh-CN'))
            );
            $responsePayload['mbti_public_projection_v1'] = $this->mbtiPublicProjectionService->buildForReportEnvelope(
                $result,
                $responsePayload,
                (string) ($attempt->locale ?? config('content_packs.default_locale', 'zh-CN')),
                (int) ($attempt->org_id ?? 0)
            );
            $responsePayload[ReportAccess::ACCESS_HUB_KEY] = $this->mbtiAccessHubBuilder->buildForReportContext(
                $attempt,
                $gate,
                $userId !== null ? (string) $userId : null,
                $anonId
            );
            $responsePayload[MbtiPreviewContractBuilder::KEY] = $this->mbtiPreviewContractBuilder->buildFromReportEnvelope(
                $responsePayload
            );
            if (is_array($mbtiFormSummary)) {
                $responsePayload['mbti_form_v1'] = $mbtiFormSummary;
            }
        } elseif ($scaleCode === 'BIG5_OCEAN') {
            if (is_array($big5FormSummary)) {
                $responsePayload['big5_form_v1'] = $big5FormSummary;
            }
            $projection = data_get($responsePayload, 'report._meta.big5_public_projection_v1');
            if (! is_array($projection)) {
                $projection = $this->bigFivePublicProjectionService->buildFromResult(
                    $result,
                    (string) ($attempt->locale ?? config('content_packs.default_locale', 'zh-CN')),
                    strtolower(trim((string) ($gate['variant'] ?? 'free'))),
                    (bool) ($gate['locked'] ?? false)
                );
            }
            $responsePayload['big5_public_projection_v1'] = $projection;
            $controlledNarrative = is_array($projection['controlled_narrative_v1'] ?? null)
                ? $projection['controlled_narrative_v1']
                : [];
            if ($controlledNarrative !== []) {
                $responsePayload['controlled_narrative_v1'] = $controlledNarrative;
            }
            $culturalCalibration = is_array($projection['cultural_calibration_v1'] ?? null)
                ? $projection['cultural_calibration_v1']
                : [];
            if ($culturalCalibration !== []) {
                $responsePayload['cultural_calibration_v1'] = $culturalCalibration;
            }
            $comparative = is_array($projection['comparative_v1'] ?? null)
                ? $projection['comparative_v1']
                : [];
            if ($comparative !== []) {
                $responsePayload['comparative_v1'] = $comparative;
            }
            $engineV2Payload = $this->bigFiveLiveRuntimeBridge->build($attempt, $result, $scaleCode);
            if (is_array($engineV2Payload)) {
                $responsePayload[BigFiveLiveRuntimeBridge::RESPONSE_KEY] = $engineV2Payload;
            }
        } elseif ($scaleCode === 'ENNEAGRAM') {
            if (is_array($enneagramFormSummary)) {
                $responsePayload['enneagram_form_v1'] = $enneagramFormSummary;
            }
            $projection = data_get($responsePayload, 'report._meta.enneagram_public_projection_v1');
            if (! is_array($projection)) {
                $projection = $this->enneagramPublicProjectionService->buildFromResult(
                    $result,
                    (string) ($attempt->locale ?? config('content_packs.default_locale', 'zh-CN')),
                    strtolower(trim((string) ($gate['variant'] ?? 'free'))),
                    (bool) ($gate['locked'] ?? false)
                );
            }
            $responsePayload['enneagram_public_projection_v1'] = $projection;
        } elseif ($scaleCode === 'RIASEC') {
            if (is_array($riasecFormSummary)) {
                $responsePayload['riasec_form_v1'] = $riasecFormSummary;
            }
            $projection = data_get($responsePayload, 'report._meta.riasec_public_projection_v1');
            if (! is_array($projection)) {
                $projection = $this->riasecPublicProjectionService->buildFromResult(
                    $result,
                    (string) ($attempt->locale ?? config('content_packs.default_locale', 'zh-CN'))
                );
            }
            $responsePayload['riasec_public_projection_v1'] = $projection;
        }

        $effectiveMbtiPersonalization = $scaleCode === 'MBTI'
            ? $this->resolveEffectiveMbtiPersonalization(
                $result,
                $attempt,
                $responsePayload,
                ! ((bool) ($gate['locked'] ?? false))
            )
            : [];

        if ($effectiveMbtiPersonalization !== []) {
            $responsePayload = $this->applyMbtiPersonalizationToEnvelope($responsePayload, $effectiveMbtiPersonalization);
            $readContract = data_get($responsePayload, 'report._meta.personalization.read_contract_v1');
            if (is_array($readContract)) {
                $responsePayload['mbti_read_contract_v1'] = $readContract;
            }
            $privacyContract = data_get($responsePayload, 'report._meta.personalization.privacy_contract_v1');
            if (is_array($privacyContract)) {
                $responsePayload['mbti_privacy_contract_v1'] = $privacyContract;
            }
            $narrativeRuntime = data_get($responsePayload, 'report._meta.personalization.narrative_runtime_contract_v1');
            if (is_array($narrativeRuntime)) {
                $responsePayload['narrative_runtime_contract_v1'] = $narrativeRuntime;
            }
            $controlledNarrative = data_get($responsePayload, 'report._meta.personalization.controlled_narrative_v1');
            if (is_array($controlledNarrative)) {
                $responsePayload['controlled_narrative_v1'] = $controlledNarrative;
            }
            $culturalCalibration = data_get($responsePayload, 'report._meta.personalization.cultural_calibration_v1');
            if (is_array($culturalCalibration)) {
                $responsePayload['cultural_calibration_v1'] = $culturalCalibration;
            }
            $crossAssessment = data_get($responsePayload, 'report._meta.personalization.cross_assessment_v1');
            if (is_array($crossAssessment)) {
                $responsePayload['mbti_cross_assessment_v1'] = $crossAssessment;
            }
            $comparative = data_get($responsePayload, 'report._meta.personalization.comparative_v1');
            if (is_array($comparative)) {
                $responsePayload['comparative_v1'] = $comparative;
            }
        }

        $mbtiEventMeta = $scaleCode === 'MBTI'
            ? $this->mbtiTelemetryMetaFromPersonalization($effectiveMbtiPersonalization)
            : [];
        $big5EventMeta = $scaleCode === 'BIG5_OCEAN'
            ? $this->resolveBigFiveEventMetaFromProjection(is_array($responsePayload['big5_public_projection_v1'] ?? null) ? $responsePayload['big5_public_projection_v1'] : [])
            : [];

        $request->merge(['attempt_id' => (string) $attempt->id]);
        $this->eventRecorder->recordFromRequest($request, 'report_view', $this->resolveUserId($request), [
            'scale_code' => (string) ($attempt->scale_code ?? ''),
            'pack_id' => (string) ($attempt->pack_id ?? ''),
            'dir_version' => (string) ($attempt->dir_version ?? ''),
            'type_code' => (string) ($result->type_code ?? ''),
            'attempt_id' => (string) $attempt->id,
            'locked' => (bool) ($gate['locked'] ?? false),
            ...$mbtiEventMeta,
            ...$this->mbtiFormEventMeta($mbtiFormSummary),
            ...$this->big5FormEventMeta($big5FormSummary),
            ...$this->enneagramFormEventMeta($enneagramFormSummary),
            ...$this->riasecFormEventMeta($riasecFormSummary),
            ...$big5EventMeta,
        ]);

        return response()->json($responsePayload);
    }

    /**
     * GET /api/v0.3/attempts/{id}/report-access
     */
    public function reportAccess(Request $request, string $id): JsonResponse
    {
        $startedAt = hrtime(true);
        $orgId = $this->currentOrgContext()->orgId();
        $attempt = $this->resolveAttemptForAccessRead($request, $orgId, $id);
        $submissionPayload = $this->latestReadableSubmission($request, (string) $attempt->id);
        $resultExists = Result::query()
            ->where('org_id', $orgId)
            ->where('attempt_id', (string) $attempt->id)
            ->exists();
        $repairFailed = false;
        $repairFailureCode = null;
        if ($resultExists && strtoupper(trim((string) ($attempt->scale_code ?? ''))) === 'MBTI') {
            try {
                $this->projectionRepair->repairResultReadyProjectionIfNeeded($orgId, (string) $attempt->id);
            } catch (\Throwable $e) {
                $repairFailed = true;
                $repairFailureCode = 'projection_repair_runtime_exception';
                Log::error('REPORT_ACCESS_PROJECTION_REPAIR_FAILED', [
                    'org_id' => $orgId,
                    'attempt_id' => (string) $attempt->id,
                    'exception' => $e,
                ]);
            }
        }

        $projectionReadFailed = false;
        $projectionReadFailureCode = null;
        $projection = $this->resolveProjectionForReportAccess(
            $orgId,
            (string) $attempt->id,
            $projectionReadFailed,
            $projectionReadFailureCode
        );
        $submissionProjectionStates = $this->submissionProjectionStates($resultExists, $submissionPayload);

        if ($submissionProjectionStates !== null) {
            $accessState = trim((string) ($submissionProjectionStates['access_state'] ?? 'locked'));
            $reportState = trim((string) ($submissionProjectionStates['report_state'] ?? 'pending'));
            $pdfState = trim((string) ($submissionProjectionStates['pdf_state'] ?? 'missing'));
            $reasonCode = trim((string) ($submissionProjectionStates['reason_code'] ?? ''));
            $projectionVersion = 1;
            $payloadJson = is_array($submissionProjectionStates['payload_json'] ?? null) ? $submissionProjectionStates['payload_json'] : [];
            $producedAt = optional($projection?->produced_at)->toIso8601String();
            $refreshedAt = optional($projection?->refreshed_at)->toIso8601String();
        } else {
            $fallbackStates = $this->fallbackProjectionStates($orgId, (string) $attempt->id, $submissionPayload);
            $accessState = trim((string) ($projection?->access_state ?? $fallbackStates['access_state']));
            $reportState = trim((string) ($projection?->report_state ?? $fallbackStates['report_state']));
            $pdfState = trim((string) ($projection?->pdf_state ?? $fallbackStates['pdf_state']));
            $reasonCode = trim((string) ($projection?->reason_code ?? $fallbackStates['reason_code']));
            $projectionVersion = (int) ($projection?->projection_version ?? 1);
            $payloadJson = is_array($projection?->payload_json) ? $projection->payload_json : $fallbackStates['payload_json'];
            $producedAt = optional($projection?->produced_at)->toIso8601String();
            $refreshedAt = optional($projection?->refreshed_at)->toIso8601String();
        }

        $isBigFive = strtoupper(trim((string) ($attempt->scale_code ?? ''))) === ReportAccess::SCALE_BIG5_OCEAN;
        $isEnneagram = strtoupper(trim((string) ($attempt->scale_code ?? ''))) === ReportAccess::SCALE_ENNEAGRAM;
        $isRiasec = strtoupper(trim((string) ($attempt->scale_code ?? ''))) === ReportAccess::SCALE_RIASEC;
        if (($isBigFive || $isEnneagram || $isRiasec) && $resultExists) {
            $accessState = 'ready';
            $reportState = 'ready';
            $pdfState = 'ready';
            $payloadJson['access_level'] = ReportAccess::REPORT_ACCESS_FULL;
            $payloadJson['variant'] = ReportAccess::VARIANT_FULL;
            $payloadJson['unlock_stage'] = ReportAccess::UNLOCK_STAGE_FULL;
            $payloadJson['unlock_source'] = ReportAccess::UNLOCK_SOURCE_NONE;
        }

        if ($repairFailed && $resultExists) {
            $reasonCode = in_array($reasonCode, ['', 'projection_missing_result_ready'], true)
                ? 'projection_repair_failed_result_ready_fallback'
                : $reasonCode;
            $payloadJson = array_merge($payloadJson, [
                'fallback' => true,
                'result_exists' => true,
                'repair_fallback' => true,
                'repair_error_code' => $repairFailureCode,
            ]);

            Log::warning('REPORT_ACCESS_PROJECTION_REPAIR_FALLBACK_APPLIED', [
                'org_id' => $orgId,
                'attempt_id' => (string) $attempt->id,
                'reason_code' => $reasonCode,
                'access_state' => $accessState,
                'report_state' => $reportState,
                'pdf_state' => $pdfState,
            ]);
        }

        if ($projectionReadFailed) {
            if (in_array($reasonCode, ['', 'projection_missing_result_ready', 'projection_missing_result_pending'], true)) {
                $reasonCode = $resultExists
                    ? 'projection_read_failed_result_ready_fallback'
                    : 'projection_read_failed_result_pending_fallback';
            }

            $payloadJson = array_merge($payloadJson, [
                'fallback' => true,
                'projection_read_fallback' => true,
                'projection_error_code' => $projectionReadFailureCode,
                'result_exists' => $resultExists,
            ]);
        }

        $mbtiFormSummary = strtoupper(trim((string) ($attempt->scale_code ?? ''))) === 'MBTI'
            ? $this->resolveMbtiFormSummary($request, $attempt)
            : null;
        $big5FormSummary = strtoupper(trim((string) ($attempt->scale_code ?? ''))) === 'BIG5_OCEAN'
            ? $this->resolveBigFiveFormSummary($request, $attempt)
            : null;
        $enneagramFormSummary = strtoupper(trim((string) ($attempt->scale_code ?? ''))) === 'ENNEAGRAM'
            ? $this->resolveEnneagramFormSummary($request, $attempt)
            : null;
        $riasecFormSummary = strtoupper(trim((string) ($attempt->scale_code ?? ''))) === 'RIASEC'
            ? $this->riasecPublicFormSummaryBuilder->build($attempt)
            : null;
        $responsePayload = [
            'ok' => true,
            'attempt_id' => (string) $attempt->id,
            'access_state' => $accessState,
            'report_state' => $reportState,
            'pdf_state' => $pdfState,
            'reason_code' => $reasonCode !== '' ? $reasonCode : null,
            'projection_version' => $projectionVersion,
            'actions' => $this->reportAccessActions($attempt, $accessState, $reportState, $pdfState),
            'payload' => $payloadJson,
            'meta' => [
                'produced_at' => $producedAt,
                'refreshed_at' => $refreshedAt,
            ],
        ];
        if (is_array($mbtiFormSummary)) {
            $responsePayload['mbti_form_v1'] = $mbtiFormSummary;
        }
        if (is_array($big5FormSummary)) {
            $responsePayload['big5_form_v1'] = $big5FormSummary;
        }
        if (is_array($enneagramFormSummary)) {
            $responsePayload['enneagram_form_v1'] = $enneagramFormSummary;
        }
        if (is_array($riasecFormSummary)) {
            $responsePayload['riasec_form_v1'] = $riasecFormSummary;
        }

        $unlockStage = ReportAccess::normalizeUnlockStage((string) data_get(
            $payloadJson,
            'unlock_stage',
            $accessState === 'ready' ? ReportAccess::UNLOCK_STAGE_FULL : ReportAccess::UNLOCK_STAGE_LOCKED
        ));
        $unlockSource = ReportAccess::normalizeUnlockSource((string) data_get(
            $payloadJson,
            'unlock_source',
            ReportAccess::UNLOCK_SOURCE_NONE
        ));
        $payloadJson['unlock_stage'] = $unlockStage;
        $payloadJson['unlock_source'] = $unlockSource;
        $payloadJson['access_level'] = ReportAccess::normalizeReportAccessLevel((string) data_get(
            $payloadJson,
            'access_level',
            $unlockStage === ReportAccess::UNLOCK_STAGE_PARTIAL
                ? ReportAccess::REPORT_ACCESS_PARTIAL
                : ($unlockStage === ReportAccess::UNLOCK_STAGE_FULL
                    ? ReportAccess::REPORT_ACCESS_FULL
                    : ReportAccess::REPORT_ACCESS_FREE)
        ));
        $payloadJson['variant'] = ReportAccess::normalizeVariant((string) data_get(
            $payloadJson,
            'variant',
            $unlockStage === ReportAccess::UNLOCK_STAGE_PARTIAL
                ? ReportAccess::VARIANT_PARTIAL
                : ($unlockStage === ReportAccess::UNLOCK_STAGE_FULL
                    ? ReportAccess::VARIANT_FULL
                    : ReportAccess::VARIANT_FREE)
        ));
        $responsePayload['unlock_stage'] = $unlockStage;
        $responsePayload['unlock_source'] = $unlockSource;
        $inviteSnapshotFailureCode = null;
        $inviteSnapshot = $this->resolveInviteSnapshot(
            (int) ($attempt->org_id ?? 0),
            (string) ($attempt->id ?? ''),
            $inviteSnapshotFailureCode
        );
        if ($inviteSnapshotFailureCode !== null) {
            $payloadJson = array_merge($payloadJson, [
                'fallback' => true,
                'invite_snapshot_fallback' => true,
                'invite_snapshot_error_code' => $inviteSnapshotFailureCode,
                'result_exists' => $resultExists,
            ]);
        }
        $inviteSummary = $this->inviteUnlockSummaryBuilder->build(
            (string) ($attempt->scale_code ?? ''),
            $unlockStage,
            $unlockSource,
            (int) ($inviteSnapshot['completed_invitees'] ?? 0),
            (int) ($inviteSnapshot['required_invitees'] ?? 2)
        );
        $inviteDiagnostics = InviteUnlockDiagnostics::build(
            (int) ($inviteSnapshot['completed_invitees'] ?? 0),
            (int) ($inviteSnapshot['required_invitees'] ?? 2),
            $unlockStage,
            $unlockSource,
            null
        );
        $responsePayload['invite_unlock_v1'] = $inviteSummary;
        $responsePayload['invite_unlock_diag_v1'] = $inviteDiagnostics;
        $payloadJson['invite_unlock_v1'] = $inviteSummary;
        $payloadJson['invite_unlock_diag_v1'] = $inviteDiagnostics;
        $responsePayload['payload'] = $payloadJson;
        $durationMs = (int) floor((hrtime(true) - $startedAt) / 1_000_000);
        Log::info('REPORT_ACCESS_INVITE_UNLOCK_DIAGNOSTIC', [
            'org_id' => $orgId,
            'attempt_id' => (string) ($attempt->id ?? ''),
            'source' => __METHOD__,
            'access_state' => $accessState,
            'report_state' => $reportState,
            'pdf_state' => $pdfState,
            'reason_code' => $reasonCode !== '' ? $reasonCode : null,
            'invite_snapshot_failure_code' => $inviteSnapshotFailureCode,
            'diagnostic_status' => (string) ($inviteDiagnostics['status'] ?? 'locked'),
            'diagnostic_status_reason' => (string) ($inviteDiagnostics['status_reason'] ?? 'unlock_stage_locked'),
            'unlock_stage' => (string) ($inviteDiagnostics['unlock_stage'] ?? 'locked'),
            'unlock_source' => (string) ($inviteDiagnostics['unlock_source'] ?? 'none'),
            'completed_invitees' => (int) ($inviteDiagnostics['completed_invitees'] ?? 0),
            'required_invitees' => (int) ($inviteDiagnostics['required_invitees'] ?? 2),
            'remaining_invitees' => (int) ($inviteDiagnostics['remaining_invitees'] ?? 2),
            'progress_percent' => (int) ($inviteDiagnostics['progress_percent'] ?? 0),
            'duration_ms' => $durationMs,
        ]);

        return response()->json($responsePayload);
    }

    /**
     * @return array{completed_invitees:int,required_invitees:int}|null
     */
    private function resolveInviteSnapshot(int $orgId, string $attemptId, ?string &$failureCode = null): ?array
    {
        $failureCode = null;
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            return null;
        }

        $table = 'attempt_invite_unlocks';
        $tableCheckReason = null;
        $tableCheckExceptionClass = null;
        if (! SchemaBaseline::hasTableWithMeta($table, $tableCheckReason, $tableCheckExceptionClass)) {
            $failureCode = $tableCheckReason === 'schema_query_exception'
                ? 'invite_snapshot_schema_guard_exception'
                : 'invite_snapshot_table_missing';
            Log::warning('REPORT_ACCESS_INVITE_SNAPSHOT_FAILED', [
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'branch' => 'schema_guard',
                'source' => __METHOD__,
                'failure_code' => $failureCode,
                'schema_guard_reason' => $tableCheckReason,
                'schema_guard_exception_class' => $tableCheckExceptionClass,
            ]);

            return null;
        }

        $requiredColumns = ['target_org_id', 'target_attempt_id', 'required_invitees', 'completed_invitees'];
        $missingColumns = [];
        $columnGuardException = null;
        foreach ($requiredColumns as $column) {
            $columnCheckReason = null;
            $columnCheckExceptionClass = null;
            if (! SchemaBaseline::hasColumnWithMeta($table, $column, $columnCheckReason, $columnCheckExceptionClass)) {
                if (in_array($columnCheckReason, ['table_check_exception', 'column_listing_exception'], true)) {
                    $columnGuardException = [
                        'column' => $column,
                        'reason' => $columnCheckReason,
                        'exception_class' => $columnCheckExceptionClass,
                    ];
                    break;
                }
                $missingColumns[] = $column;
            }
        }
        if (is_array($columnGuardException)) {
            $failureCode = 'invite_snapshot_schema_guard_exception';
            Log::warning('REPORT_ACCESS_INVITE_SNAPSHOT_FAILED', [
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'branch' => 'schema_guard',
                'source' => __METHOD__,
                'failure_code' => $failureCode,
                'schema_guard_column' => $columnGuardException['column'],
                'schema_guard_reason' => $columnGuardException['reason'],
                'schema_guard_exception_class' => $columnGuardException['exception_class'],
            ]);

            return null;
        }
        if ($missingColumns !== []) {
            $failureCode = 'invite_snapshot_columns_missing';
            Log::warning('REPORT_ACCESS_INVITE_SNAPSHOT_FAILED', [
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'branch' => 'schema_guard',
                'source' => __METHOD__,
                'failure_code' => $failureCode,
                'missing_columns' => $missingColumns,
            ]);

            return null;
        }

        try {
            $invite = AttemptInviteUnlock::query()
                ->where('target_org_id', $orgId)
                ->where('target_attempt_id', $attemptId)
                ->first(['required_invitees', 'completed_invitees']);
        } catch (QueryException $e) {
            $failureCode = 'invite_snapshot_query_exception';
            Log::error('REPORT_ACCESS_INVITE_SNAPSHOT_FAILED', [
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'branch' => 'query_exception',
                'source' => __METHOD__,
                'failure_code' => $failureCode,
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
                'sql_state' => $e->errorInfo[0] ?? null,
            ]);

            return null;
        } catch (\Throwable $e) {
            $failureCode = 'invite_snapshot_runtime_exception';
            Log::error('REPORT_ACCESS_INVITE_SNAPSHOT_FAILED', [
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'branch' => 'runtime_exception',
                'source' => __METHOD__,
                'failure_code' => $failureCode,
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $invite instanceof AttemptInviteUnlock) {
            return null;
        }

        return [
            'required_invitees' => max(1, (int) ($invite->required_invitees ?? 2)),
            'completed_invitees' => max(0, (int) ($invite->completed_invitees ?? 0)),
        ];
    }

    private function resolveProjectionForReportAccess(
        int $orgId,
        string $attemptId,
        bool &$readFailed = false,
        ?string &$failureCode = null
    ): ?UnifiedAccessProjection {
        $readFailed = false;
        $failureCode = null;
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            return null;
        }

        $table = 'unified_access_projections';
        if (! SchemaBaseline::hasTable($table)) {
            $readFailed = true;
            $failureCode = 'projection_table_missing';
            Log::warning('REPORT_ACCESS_PROJECTION_READ_FAILED', [
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'branch' => 'schema_guard',
                'source' => __METHOD__,
                'failure_code' => $failureCode,
            ]);

            return null;
        }

        if (! SchemaBaseline::hasColumn($table, 'attempt_id')) {
            $readFailed = true;
            $failureCode = 'projection_attempt_id_column_missing';
            Log::warning('REPORT_ACCESS_PROJECTION_READ_FAILED', [
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'branch' => 'schema_guard',
                'source' => __METHOD__,
                'failure_code' => $failureCode,
                'missing_columns' => ['attempt_id'],
            ]);

            return null;
        }

        try {
            return UnifiedAccessProjection::query()
                ->where('attempt_id', $attemptId)
                ->first();
        } catch (QueryException $e) {
            $readFailed = true;
            $failureCode = 'projection_query_exception';
            Log::error('REPORT_ACCESS_PROJECTION_READ_FAILED', [
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'branch' => 'query_exception',
                'source' => __METHOD__,
                'failure_code' => $failureCode,
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
                'sql_state' => $e->errorInfo[0] ?? null,
            ]);

            return null;
        } catch (\Throwable $e) {
            $readFailed = true;
            $failureCode = 'projection_runtime_exception';
            Log::error('REPORT_ACCESS_PROJECTION_READ_FAILED', [
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'branch' => 'runtime_exception',
                'source' => __METHOD__,
                'failure_code' => $failureCode,
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveAttemptForReportRead(
        Request $request,
        string $attemptId,
        string $scaleCode
    ): Attempt {
        if ($this->isPublicReportScale($scaleCode)) {
            $actor = $this->reportActor($request);
            $attempt = $this->reportSubjects->findAttemptForCurrentContext($attemptId, $actor);

            if ($attempt instanceof Attempt) {
                return $attempt;
            }

            $attempt = $this->resolvePublicAttemptFromArtifacts($request, $this->currentOrgContext()->orgId(), $attemptId, $scaleCode);
            if ($attempt instanceof Attempt) {
                return $attempt;
            }

            throw new ApiProblemException(404, 'ATTEMPT_NOT_FOUND', 'attempt not found.');
        }

        $attempt = $this->ownedAttemptQuery($request, $attemptId)->first();
        if ($attempt instanceof Attempt) {
            return $attempt;
        }

        $attempt = $this->resolvePublicAttemptFromArtifacts($request, $this->currentOrgContext()->orgId(), $attemptId);
        if ($attempt instanceof Attempt) {
            return $attempt;
        }

        throw new ApiProblemException(404, 'RESOURCE_NOT_FOUND', 'attempt not found.');
    }

    private function resolveAttemptForAccessRead(Request $request, int $orgId, string $attemptId): Attempt
    {
        $result = Result::query()->where('org_id', $orgId)->where('attempt_id', $attemptId)->first();
        if ($result instanceof Result) {
            $responseCodes = $this->resolveResponseScaleCodes($result);
            $scaleCode = $this->resolveNormalizedScaleCode($responseCodes);

            return $this->resolveAttemptForReportRead($request, $attemptId, $scaleCode);
        }

        $attempt = $this->reportSubjects->findAttemptForCurrentContext($attemptId, $this->reportActor($request));
        if ($attempt instanceof Attempt) {
            return $attempt;
        }

        $attempt = $this->ownedAttemptQuery($request, $attemptId)->first();
        if ($attempt instanceof Attempt) {
            return $attempt;
        }

        $attempt = $this->resolvePublicAttemptFromArtifacts($request, $orgId, $attemptId);
        if ($attempt instanceof Attempt) {
            return $attempt;
        }

        throw new ApiProblemException(404, 'RESOURCE_NOT_FOUND', 'attempt not found.');
    }

    /**
     * @return array{access_state:string,report_state:string,pdf_state:string,reason_code:string,payload_json:array<string,mixed>}
     */
    private function fallbackProjectionStates(int $orgId, string $attemptId, ?array $submissionPayload = null): array
    {
        $resultExists = Result::query()
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->exists();

        if (! $resultExists && ($submissionPayload['ok'] ?? false) === true) {
            $submissionState = strtolower(trim((string) data_get($submissionPayload, 'submission.state', 'pending')));
            if (in_array($submissionState, ['pending', 'running'], true)) {
                return [
                    'access_state' => 'locked',
                    'report_state' => 'pending',
                    'pdf_state' => 'missing',
                    'reason_code' => 'submission_pending',
                    'payload_json' => [
                        'fallback' => true,
                        'result_exists' => false,
                        'submission' => is_array($submissionPayload['submission'] ?? null) ? $submissionPayload['submission'] : [],
                    ],
                ];
            }

            if ($submissionState === 'failed') {
                return [
                    'access_state' => 'locked',
                    'report_state' => 'unavailable',
                    'pdf_state' => 'missing',
                    'reason_code' => 'submission_failed',
                    'payload_json' => [
                        'fallback' => true,
                        'result_exists' => false,
                        'submission' => is_array($submissionPayload['submission'] ?? null) ? $submissionPayload['submission'] : [],
                        'result' => is_array($submissionPayload['result'] ?? null) ? $submissionPayload['result'] : null,
                    ],
                ];
            }

            if ($submissionState === 'succeeded') {
                return [
                    'access_state' => 'locked',
                    'report_state' => 'restoring',
                    'pdf_state' => 'missing',
                    'reason_code' => 'submission_succeeded_result_missing',
                    'payload_json' => [
                        'fallback' => true,
                        'result_exists' => false,
                        'submission' => is_array($submissionPayload['submission'] ?? null) ? $submissionPayload['submission'] : [],
                    ],
                ];
            }
        }

        return [
            'access_state' => $resultExists ? 'locked' : 'pending',
            'report_state' => $resultExists ? 'ready' : 'pending',
            'pdf_state' => 'missing',
            'reason_code' => $resultExists ? 'projection_missing_result_ready' : 'projection_missing_result_pending',
            'payload_json' => [
                'fallback' => true,
                'result_exists' => $resultExists,
            ],
        ];
    }

    /**
     * @return array{access_state:string,report_state:string,pdf_state:string,reason_code:string,payload_json:array<string,mixed>}|null
     */
    private function submissionProjectionStates(bool $resultExists, ?array $submissionPayload): ?array
    {
        if (($submissionPayload['ok'] ?? false) !== true) {
            return null;
        }

        $submissionState = strtolower(trim((string) data_get($submissionPayload, 'submission.state', 'pending')));
        if (in_array($submissionState, ['pending', 'running'], true)) {
            return [
                'access_state' => 'locked',
                'report_state' => 'pending',
                'pdf_state' => 'missing',
                'reason_code' => 'submission_pending',
                'payload_json' => [
                    'fallback' => true,
                    'result_exists' => $resultExists,
                    'submission' => is_array($submissionPayload['submission'] ?? null) ? $submissionPayload['submission'] : [],
                ],
            ];
        }

        if ($submissionState === 'failed') {
            return [
                'access_state' => 'locked',
                'report_state' => 'unavailable',
                'pdf_state' => 'missing',
                'reason_code' => 'submission_failed',
                'payload_json' => [
                    'fallback' => true,
                    'result_exists' => $resultExists,
                    'submission' => is_array($submissionPayload['submission'] ?? null) ? $submissionPayload['submission'] : [],
                    'result' => is_array($submissionPayload['result'] ?? null) ? $submissionPayload['result'] : null,
                ],
            ];
        }

        if (! $resultExists && $submissionState === 'succeeded') {
            return [
                'access_state' => 'locked',
                'report_state' => 'restoring',
                'pdf_state' => 'missing',
                'reason_code' => 'submission_succeeded_result_missing',
                'payload_json' => [
                    'fallback' => true,
                    'result_exists' => false,
                    'submission' => is_array($submissionPayload['submission'] ?? null) ? $submissionPayload['submission'] : [],
                ],
            ];
        }

        return null;
    }

    /**
     * @return array<string,string|null>
     */
    private function reportAccessActions(Attempt $attempt, string $accessState, string $reportState, string $pdfState): array
    {
        $pageHref = $this->resultPagePathForAttempt($attempt);
        $lookupHref = '/orders/lookup';
        $historyHref = strtoupper(trim((string) ($attempt->scale_code ?? ''))) === 'MBTI'
            ? '/history/mbti'
            : null;

        return [
            'page_href' => $this->supportsPageEntry($accessState, $reportState) ? $pageHref : null,
            'pdf_href' => $this->supportsPdfDownload($accessState, $pdfState)
                ? "/api/v0.3/attempts/{$attempt->id}/report.pdf"
                : null,
            'wait_href' => $this->isWaitingState($reportState) ? $pageHref : null,
            'history_href' => $historyHref,
            'lookup_href' => $lookupHref,
        ];
    }

    private function resultPagePathForAttempt(Attempt $attempt): string
    {
        $scaleCode = strtoupper(trim((string) ($attempt->scale_code ?? '')));

        return in_array($scaleCode, ['SDS_20', 'CLINICAL_COMBO_68'], true)
            ? "/attempts/{$attempt->id}/report"
            : "/result/{$attempt->id}";
    }

    private function supportsPageEntry(string $accessState, string $reportState): bool
    {
        return ! in_array($this->normalizeProjectionState($accessState, 'access'), ['deleted', 'expired'], true)
            && ! in_array($this->normalizeProjectionState($reportState, 'report'), ['deleted', 'expired', 'unavailable'], true);
    }

    private function supportsPdfDownload(string $accessState, string $pdfState): bool
    {
        return $this->normalizeProjectionState($accessState, 'access') === 'ready'
            && $this->normalizeProjectionState($pdfState, 'pdf') === 'ready';
    }

    private function isWaitingState(string $state): bool
    {
        return in_array($this->normalizeProjectionState($state, 'report'), ['pending', 'restoring'], true);
    }

    private function normalizeProjectionState(string $state, string $kind): string
    {
        $normalized = strtolower(trim($state));

        return match (true) {
            $normalized === 'ready' => 'ready',
            in_array($normalized, ['pending', 'generating', 'queued', 'running', 'submitted'], true) => 'pending',
            in_array($normalized, ['restoring', 'rehydrating'], true) => 'restoring',
            in_array($normalized, ['deleted', 'purged', 'anonymized'], true) => 'deleted',
            $normalized === 'expired' => 'expired',
            $kind === 'access' && in_array($normalized, ['locked', 'recovery_available'], true) => 'locked',
            in_array($normalized, ['missing', 'unavailable', 'archived', 'shrunk', 'failed', 'blocked'], true) => 'unavailable',
            default => $kind === 'access' ? 'locked' : 'unavailable',
        };
    }

    private function resolveReportGate(
        int $orgId,
        string $attemptId,
        ?string $userId,
        ?string $anonId,
        ?string $role,
        bool $forceSystemAccess,
        bool $forceRefresh
    ): array {
        $gate = $this->reportGatekeeper->resolve(
            $orgId,
            $attemptId,
            $userId,
            $anonId,
            $role,
            $forceSystemAccess,
            $forceRefresh,
        );

        if ($gate['ok'] ?? false) {
            return $gate;
        }

        $errorCode = strtoupper((string) data_get($gate, 'error_code', data_get($gate, 'error', 'REPORT_FAILED')));
        $status = (int) ($gate['status'] ?? 0);
        if ($status <= 0) {
            $status = match ($errorCode) {
                'ATTEMPT_REQUIRED', 'SCALE_REQUIRED' => 400,
                'ATTEMPT_NOT_FOUND', 'RESULT_NOT_FOUND', 'SCALE_NOT_FOUND' => 404,
                default => 500,
            };
        }

        $message = trim((string) ($gate['message'] ?? 'report generation failed.'));
        if ($message === '') {
            $message = 'report generation failed.';
        }

        throw new ApiProblemException($status, $errorCode !== '' ? $errorCode : 'REPORT_FAILED', $message);
    }

    /**
     * @return array{scale_code:string,scale_code_legacy:string,scale_code_v2:string,scale_uid:?string}
     */
    private function resolveResponseScaleCodes(object $attempt): array
    {
        return $this->responseProjector->project(
            (string) ($attempt->scale_code ?? ''),
            (string) ($attempt->scale_code_v2 ?? ''),
            $attempt->scale_uid !== null ? (string) $attempt->scale_uid : null
        );
    }

    /**
     * @param  array{scale_code:string,scale_code_legacy:string,scale_code_v2:string,scale_uid:?string}  $responseCodes
     * @return array<string,mixed>
     */
    private function projectScaleCodePayload(array $payload, array $responseCodes): array
    {
        if (array_key_exists('scale_code', $payload)) {
            $payload['scale_code'] = $responseCodes['scale_code'];
            $payload['scale_code_legacy'] = $responseCodes['scale_code_legacy'];
            $payload['scale_code_v2'] = $responseCodes['scale_code_v2'];
            $payload['scale_uid'] = $responseCodes['scale_uid'];
        }

        return $payload;
    }

    private function resolveAttemptForResultRead(Request $request, int $orgId, string $attemptId, string $scaleCode): ?Attempt
    {
        if ($this->isPublicResultScale($scaleCode)) {
            return $this->reportSubjects->findAttemptForCurrentContext($attemptId, $this->reportActor($request));
        }

        $attempt = $this->ownedAttemptQuery($request, $attemptId)->first();
        if ($attempt instanceof Attempt) {
            return $attempt;
        }

        throw new ApiProblemException(404, 'RESOURCE_NOT_FOUND', 'attempt not found.');
    }

    private function resolveMbtiResultViewEventMeta(
        Request $request,
        Result $result,
        ?Attempt $attempt,
        string $attemptId
    ): array {
        if (! $attempt instanceof Attempt) {
            return [];
        }

        $gate = $this->reportGatekeeper->resolve(
            (int) ($attempt->org_id ?? 0),
            $attemptId,
            $this->resolveUserId($request),
            $this->resolveAnonId($request),
            $this->currentOrgContext()->role(),
            false,
            false,
        );

        if (! ($gate['ok'] ?? false)) {
            return [];
        }

        return $this->resolveMbtiEventMetaFromReportEnvelope($result, $attempt, $gate);
    }

    /**
     * @param  array<string,mixed>  $reportEnvelope
     * @return array<string,mixed>
     */
    private function resolveMbtiEventMetaFromReportEnvelope(
        Result $result,
        Attempt $attempt,
        array $reportEnvelope
    ): array {
        return $this->mbtiTelemetryMetaFromPersonalization(
            $this->resolveEffectiveMbtiPersonalization(
                $result,
                $attempt,
                $reportEnvelope,
                ! ((bool) data_get($reportEnvelope, 'locked', false))
            )
        );
    }

    /**
     * @param  array<string,mixed>  $projection
     * @return array<string,mixed>
     */
    private function resolveBigFiveEventMetaFromProjection(array $projection): array
    {
        $traitBands = is_array($projection['trait_bands'] ?? null) ? $projection['trait_bands'] : [];
        $dominantTraits = is_array($projection['dominant_traits'] ?? null) ? $projection['dominant_traits'] : [];
        $sceneFingerprint = is_array($projection['scene_fingerprint'] ?? null) ? $projection['scene_fingerprint'] : [];
        $variantKeys = is_array($projection['variant_keys'] ?? null) ? $projection['variant_keys'] : [];
        $orderedSectionKeys = is_array($projection['ordered_section_keys'] ?? null) ? $projection['ordered_section_keys'] : [];
        $controlledNarrative = is_array($projection['controlled_narrative_v1'] ?? null) ? $projection['controlled_narrative_v1'] : [];
        $culturalCalibration = is_array($projection['cultural_calibration_v1'] ?? null) ? $projection['cultural_calibration_v1'] : [];
        $comparative = is_array($projection['comparative_v1'] ?? null) ? $projection['comparative_v1'] : [];

        $meta = [
            'trait_bands' => $traitBands,
            'dominant_traits' => array_values(array_filter(array_map(
                static fn (mixed $trait): string => is_array($trait) ? trim((string) ($trait['key'] ?? '')) : '',
                $dominantTraits
            ))),
            'scene_fingerprint' => $sceneFingerprint,
            'variant_keys' => array_values(array_filter(array_map('strval', $variantKeys))),
            'ordered_section_keys' => array_values(array_filter(array_map('strval', $orderedSectionKeys))),
        ];

        if ($controlledNarrative !== []) {
            $meta['narrative_contract_version'] = trim((string) ($controlledNarrative['narrative_contract_version'] ?? ''));
            $meta['narrative_runtime_mode'] = trim((string) ($controlledNarrative['runtime_mode'] ?? ''));
            $meta['narrative_provider_name'] = trim((string) ($controlledNarrative['provider_name'] ?? ''));
            $meta['narrative_model_version'] = trim((string) ($controlledNarrative['model_version'] ?? ''));
            $meta['narrative_prompt_version'] = trim((string) ($controlledNarrative['prompt_version'] ?? ''));
            $meta['narrative_fingerprint'] = trim((string) ($controlledNarrative['narrative_fingerprint'] ?? ''));
        }
        if ($culturalCalibration !== []) {
            $meta['locale_context'] = trim((string) ($culturalCalibration['locale_context'] ?? ''));
            $meta['cultural_context'] = trim((string) ($culturalCalibration['cultural_context'] ?? ''));
            $meta['calibrated_section_keys'] = array_values(array_filter(array_map(
                'strval',
                is_array($culturalCalibration['calibrated_section_keys'] ?? null) ? $culturalCalibration['calibrated_section_keys'] : []
            )));
            $meta['calibration_fingerprint'] = trim((string) ($culturalCalibration['calibration_fingerprint'] ?? ''));
            $meta['calibration_contract_version'] = trim((string) ($culturalCalibration['calibration_contract_version'] ?? ''));
            $meta['calibration_policy_version'] = trim((string) ($culturalCalibration['calibration_policy_version'] ?? ''));
            $meta['calibration_source'] = trim((string) ($culturalCalibration['calibration_source'] ?? ''));
        }
        if ($comparative !== []) {
            $meta['comparative_v1'] = $comparative;
            $meta['comparative_contract_version'] = trim((string) ($comparative['comparative_contract_version'] ?? ''));
            $meta['comparative_fingerprint'] = trim((string) ($comparative['comparative_fingerprint'] ?? ''));
            $meta['norming_version'] = trim((string) ($comparative['norming_version'] ?? ''));
            $meta['norming_scope'] = trim((string) ($comparative['norming_scope'] ?? ''));
            $meta['norming_source'] = trim((string) ($comparative['norming_source'] ?? ''));
        }

        return array_filter($meta, static function (mixed $value): bool {
            return is_array($value) ? $value !== [] : $value !== null;
        });
    }

    /**
     * @param  array<string,mixed>  $personalization
     * @return array<string,mixed>
     */
    private function mbtiTelemetryMetaFromPersonalization(array $personalization): array
    {
        $sceneFingerprint = [];
        foreach ((array) ($personalization['scene_fingerprint'] ?? []) as $sceneKey => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $styleKey = trim((string) ($entry['style_key'] ?? $entry['styleKey'] ?? ''));
            if ($styleKey !== '') {
                $sceneFingerprint[(string) $sceneKey] = $styleKey;
            }
        }

        $meta = [
            'identity' => trim((string) ($personalization['identity'] ?? '')),
            'schema_version' => trim((string) ($personalization['schema_version'] ?? '')),
            'dynamic_sections_version' => trim((string) ($personalization['dynamic_sections_version'] ?? '')),
            'variant_keys' => is_array($personalization['variant_keys'] ?? null) ? $personalization['variant_keys'] : [],
            'contrast_keys' => is_array($personalization['contrast_keys'] ?? null) ? $personalization['contrast_keys'] : [],
            'scene_fingerprint' => $sceneFingerprint,
            'boundary_flags' => is_array($personalization['boundary_flags'] ?? null) ? $personalization['boundary_flags'] : [],
            'axis_bands' => is_array($personalization['axis_bands'] ?? null) ? $personalization['axis_bands'] : [],
            'explainability_summary' => trim((string) ($personalization['explainability_summary'] ?? '')),
            'close_call_axes' => is_array($personalization['close_call_axes'] ?? null) ? $personalization['close_call_axes'] : [],
            'neighbor_type_keys' => is_array($personalization['neighbor_type_keys'] ?? null) ? $personalization['neighbor_type_keys'] : [],
            'confidence_or_stability_keys' => is_array($personalization['confidence_or_stability_keys'] ?? null) ? $personalization['confidence_or_stability_keys'] : [],
            'work_style_summary' => trim((string) ($personalization['work_style_summary'] ?? '')),
            'role_fit_keys' => is_array($personalization['role_fit_keys'] ?? null) ? $personalization['role_fit_keys'] : [],
            'collaboration_fit_keys' => is_array($personalization['collaboration_fit_keys'] ?? null) ? $personalization['collaboration_fit_keys'] : [],
            'work_env_preference_keys' => is_array($personalization['work_env_preference_keys'] ?? null) ? $personalization['work_env_preference_keys'] : [],
            'career_next_step_keys' => is_array($personalization['career_next_step_keys'] ?? null) ? $personalization['career_next_step_keys'] : [],
            'action_plan_summary' => trim((string) ($personalization['action_plan_summary'] ?? '')),
            'weekly_action_keys' => is_array($personalization['weekly_action_keys'] ?? null) ? $personalization['weekly_action_keys'] : [],
            'relationship_action_keys' => is_array($personalization['relationship_action_keys'] ?? null) ? $personalization['relationship_action_keys'] : [],
            'work_experiment_keys' => is_array($personalization['work_experiment_keys'] ?? null) ? $personalization['work_experiment_keys'] : [],
            'watchout_keys' => is_array($personalization['watchout_keys'] ?? null) ? $personalization['watchout_keys'] : [],
            'ordered_recommendation_keys' => is_array($personalization['ordered_recommendation_keys'] ?? null) ? $personalization['ordered_recommendation_keys'] : [],
            'ordered_action_keys' => is_array($personalization['ordered_action_keys'] ?? null) ? $personalization['ordered_action_keys'] : [],
            'recommendation_priority_keys' => is_array($personalization['recommendation_priority_keys'] ?? null) ? $personalization['recommendation_priority_keys'] : [],
            'action_priority_keys' => is_array($personalization['action_priority_keys'] ?? null) ? $personalization['action_priority_keys'] : [],
            'reading_focus_key' => trim((string) ($personalization['reading_focus_key'] ?? '')),
            'action_focus_key' => trim((string) ($personalization['action_focus_key'] ?? '')),
            'cross_assessment_v1' => is_array($personalization['cross_assessment_v1'] ?? null) ? $personalization['cross_assessment_v1'] : [],
            'synthesis_keys' => is_array($personalization['synthesis_keys'] ?? null) ? $personalization['synthesis_keys'] : [],
            'supporting_scales' => is_array($personalization['supporting_scales'] ?? null) ? $personalization['supporting_scales'] : [],
            'big5_influence_keys' => is_array($personalization['big5_influence_keys'] ?? null) ? $personalization['big5_influence_keys'] : [],
            'mbti_adjusted_focus_keys' => is_array($personalization['mbti_adjusted_focus_keys'] ?? null) ? $personalization['mbti_adjusted_focus_keys'] : [],
            'working_life_v1' => is_array($personalization['working_life_v1'] ?? null) ? $personalization['working_life_v1'] : [],
            'action_journey_v1' => is_array($personalization['action_journey_v1'] ?? null) ? $personalization['action_journey_v1'] : [],
            'pulse_check_v1' => is_array($personalization['pulse_check_v1'] ?? null) ? $personalization['pulse_check_v1'] : [],
            'longitudinal_memory_v1' => is_array($personalization['longitudinal_memory_v1'] ?? null) ? $personalization['longitudinal_memory_v1'] : [],
            'adaptive_selection_v1' => is_array($personalization['adaptive_selection_v1'] ?? null) ? $personalization['adaptive_selection_v1'] : [],
            'tone_profile_v1' => is_array($personalization['tone_profile_v1'] ?? null) ? $personalization['tone_profile_v1'] : [],
            'comparative_v1' => is_array($personalization['comparative_v1'] ?? null) ? $personalization['comparative_v1'] : [],
            'career_focus_key' => trim((string) ($personalization['career_focus_key'] ?? '')),
            'career_journey_keys' => is_array($personalization['career_journey_keys'] ?? null) ? $personalization['career_journey_keys'] : [],
            'career_action_priority_keys' => is_array($personalization['career_action_priority_keys'] ?? null) ? $personalization['career_action_priority_keys'] : [],
            'intra_type_profile_v1' => is_array($personalization['intra_type_profile_v1'] ?? null) ? $personalization['intra_type_profile_v1'] : [],
            'profile_seed_key' => trim((string) ($personalization['profile_seed_key'] ?? '')),
            'same_type_divergence_keys' => is_array($personalization['same_type_divergence_keys'] ?? null) ? $personalization['same_type_divergence_keys'] : [],
            'section_selection_keys' => is_array($personalization['section_selection_keys'] ?? null) ? $personalization['section_selection_keys'] : [],
            'action_selection_keys' => is_array($personalization['action_selection_keys'] ?? null) ? $personalization['action_selection_keys'] : [],
            'recommendation_selection_keys' => is_array($personalization['recommendation_selection_keys'] ?? null) ? $personalization['recommendation_selection_keys'] : [],
            'cta_bundle_v1' => is_array($personalization['cta_bundle_v1'] ?? null) ? $personalization['cta_bundle_v1'] : [],
            'selection_fingerprint' => trim((string) ($personalization['selection_fingerprint'] ?? '')),
            'selection_evidence' => is_array($personalization['selection_evidence'] ?? null) ? $personalization['selection_evidence'] : [],
            'user_state' => is_array($personalization['user_state'] ?? null) ? $personalization['user_state'] : [],
            'orchestration' => is_array($personalization['orchestration'] ?? null) ? $personalization['orchestration'] : [],
            'continuity' => is_array($personalization['continuity'] ?? null) ? $personalization['continuity'] : [],
            'cultural_calibration_v1' => is_array($personalization['cultural_calibration_v1'] ?? null) ? $personalization['cultural_calibration_v1'] : [],
            'engine_version' => trim((string) ($personalization['engine_version'] ?? '')),
        ];

        if (is_array($personalization['cta_bundle_v1'] ?? null)) {
            $meta['cta_bundle_key'] = trim((string) data_get($personalization, 'cta_bundle_v1.bundle_key', ''));
            $meta['cta_entry_reason'] = trim((string) data_get($personalization, 'cta_bundle_v1.entry_reason', ''));
            $meta['cta_softness_mode'] = trim((string) data_get($personalization, 'cta_bundle_v1.softness_mode', ''));
            $meta['cta_intent'] = trim((string) data_get($personalization, 'cta_bundle_v1.cta_intent', ''));
        }

        $adaptiveSelection = is_array($personalization['adaptive_selection_v1'] ?? null)
            ? $personalization['adaptive_selection_v1']
            : [];
        if ($adaptiveSelection !== []) {
            $meta['adaptive_contract_version'] = trim((string) ($adaptiveSelection['adaptive_contract_version'] ?? $adaptiveSelection['version'] ?? ''));
            $meta['adaptive_fingerprint'] = trim((string) ($adaptiveSelection['adaptive_fingerprint'] ?? ''));
            $meta['selection_rewrite_reason'] = trim((string) ($adaptiveSelection['selection_rewrite_reason'] ?? ''));
            $meta['content_feedback_weights'] = is_array($adaptiveSelection['content_feedback_weights'] ?? null)
                ? $adaptiveSelection['content_feedback_weights']
                : [];
            $meta['action_effect_weights'] = is_array($adaptiveSelection['action_effect_weights'] ?? null)
                ? $adaptiveSelection['action_effect_weights']
                : [];
            $meta['recommendation_effect_weights'] = is_array($adaptiveSelection['recommendation_effect_weights'] ?? null)
                ? $adaptiveSelection['recommendation_effect_weights']
                : [];
            $meta['cta_effect_weights'] = is_array($adaptiveSelection['cta_effect_weights'] ?? null)
                ? $adaptiveSelection['cta_effect_weights']
                : [];
            $meta['next_best_action_v1'] = is_array($adaptiveSelection['next_best_action_v1'] ?? null)
                ? $adaptiveSelection['next_best_action_v1']
                : [];
            $meta['next_best_action_key'] = trim((string) data_get($adaptiveSelection, 'next_best_action_v1.key', ''));
            $meta['next_best_action_section_key'] = trim((string) data_get($adaptiveSelection, 'next_best_action_v1.section_key', ''));
            $meta['next_best_action_reason'] = trim((string) data_get($adaptiveSelection, 'next_best_action_v1.reason', ''));
        }

        $toneProfile = is_array($personalization['tone_profile_v1'] ?? null)
            ? $personalization['tone_profile_v1']
            : [];
        if ($toneProfile !== []) {
            $meta['tone_contract_version'] = trim((string) ($toneProfile['tone_contract_version'] ?? $toneProfile['version'] ?? ''));
            $meta['tone_fingerprint'] = trim((string) ($toneProfile['tone_fingerprint'] ?? ''));
            $meta['tone_scope'] = trim((string) ($toneProfile['tone_scope'] ?? ''));
            $meta['default_tone_mode'] = trim((string) ($toneProfile['default_tone_mode'] ?? ''));
            $meta['tone_reason'] = trim((string) ($toneProfile['tone_reason'] ?? ''));
            $meta['phrasing_mode'] = trim((string) ($toneProfile['phrasing_mode'] ?? ''));
            $meta['tone_softness_mode'] = trim((string) ($toneProfile['tone_softness_mode'] ?? ''));
            $meta['section_tone_modes'] = is_array($toneProfile['section_tone_modes'] ?? null)
                ? $toneProfile['section_tone_modes']
                : [];
            $meta['section_tone_reasons'] = is_array($toneProfile['section_tone_reasons'] ?? null)
                ? $toneProfile['section_tone_reasons']
                : [];
            $meta['tone_anchor_keys'] = array_values(array_filter(array_map(
                'strval',
                is_array($toneProfile['tone_anchor_keys'] ?? null) ? $toneProfile['tone_anchor_keys'] : []
            )));
            $meta['tone_evidence'] = is_array($toneProfile['tone_evidence'] ?? null)
                ? $toneProfile['tone_evidence']
                : [];
        }

        $narrativeRuntime = is_array($personalization['narrative_runtime_contract_v1'] ?? null)
            ? $personalization['narrative_runtime_contract_v1']
            : [];
        $controlledNarrative = is_array($personalization['controlled_narrative_v1'] ?? null)
            ? $personalization['controlled_narrative_v1']
            : [];
        if ($controlledNarrative !== []) {
            $meta['narrative_contract_version'] = trim((string) ($controlledNarrative['narrative_contract_version'] ?? ''));
        }
        if ($narrativeRuntime !== []) {
            $meta['narrative_runtime_contract_version'] = trim((string) ($narrativeRuntime['version'] ?? ''));
            $meta['narrative_runtime_mode'] = trim((string) ($narrativeRuntime['runtime_mode'] ?? ''));
            $meta['narrative_provider_name'] = trim((string) ($narrativeRuntime['provider_name'] ?? ''));
            $meta['narrative_model_version'] = trim((string) ($narrativeRuntime['model_version'] ?? ''));
            $meta['narrative_prompt_version'] = trim((string) ($narrativeRuntime['prompt_version'] ?? ''));
            $meta['narrative_fingerprint'] = trim((string) ($narrativeRuntime['narrative_fingerprint'] ?? ''));
            $meta['narrative_fail_open_mode'] = trim((string) ($narrativeRuntime['fail_open_mode'] ?? ''));
        }

        $privacyContract = is_array($personalization['privacy_contract_v1'] ?? null)
            ? $personalization['privacy_contract_v1']
            : [];
        if ($privacyContract !== []) {
            $meta = array_merge($meta, $this->mbtiPrivacyConsentContractService->buildTelemetryConsentMeta($privacyContract));
        }

        $culturalCalibration = is_array($personalization['cultural_calibration_v1'] ?? null)
            ? $personalization['cultural_calibration_v1']
            : [];
        if ($culturalCalibration !== []) {
            $meta['locale_context'] = trim((string) ($culturalCalibration['locale_context'] ?? ''));
            $meta['cultural_context'] = trim((string) ($culturalCalibration['cultural_context'] ?? ''));
            $meta['calibrated_section_keys'] = array_values(array_filter(array_map(
                'strval',
                is_array($culturalCalibration['calibrated_section_keys'] ?? null) ? $culturalCalibration['calibrated_section_keys'] : []
            )));
            $meta['calibration_fingerprint'] = trim((string) ($culturalCalibration['calibration_fingerprint'] ?? ''));
            $meta['calibration_contract_version'] = trim((string) ($culturalCalibration['calibration_contract_version'] ?? ''));
            $meta['calibration_policy_version'] = trim((string) ($culturalCalibration['calibration_policy_version'] ?? ''));
            $meta['calibration_source'] = trim((string) ($culturalCalibration['calibration_source'] ?? ''));
        }
        $comparative = is_array($personalization['comparative_v1'] ?? null)
            ? $personalization['comparative_v1']
            : [];
        if ($comparative !== []) {
            $meta['comparative_contract_version'] = trim((string) ($comparative['comparative_contract_version'] ?? ''));
            $meta['comparative_fingerprint'] = trim((string) ($comparative['comparative_fingerprint'] ?? ''));
            $meta['norming_version'] = trim((string) ($comparative['norming_version'] ?? ''));
            $meta['norming_scope'] = trim((string) ($comparative['norming_scope'] ?? ''));
            $meta['norming_source'] = trim((string) ($comparative['norming_source'] ?? ''));
        }

        $journey = is_array($personalization['action_journey_v1'] ?? null)
            ? $personalization['action_journey_v1']
            : [];
        if ($journey !== []) {
            $meta['journey_contract_version'] = trim((string) ($journey['journey_contract_version'] ?? ''));
            $meta['journey_fingerprint_version'] = trim((string) ($journey['journey_fingerprint_version'] ?? ''));
            $meta['journey_fingerprint'] = trim((string) ($journey['journey_fingerprint'] ?? ''));
            $meta['journey_scope'] = trim((string) ($journey['journey_scope'] ?? ''));
            $meta['journey_state'] = trim((string) ($journey['journey_state'] ?? ''));
            $meta['progress_state'] = trim((string) ($journey['progress_state'] ?? ''));
            $meta['completed_action_keys'] = array_values(array_filter(array_map(
                'strval',
                is_array($journey['completed_action_keys'] ?? null) ? $journey['completed_action_keys'] : []
            )));
            $meta['recommended_next_pulse_keys'] = array_values(array_filter(array_map(
                'strval',
                is_array($journey['recommended_next_pulse_keys'] ?? null) ? $journey['recommended_next_pulse_keys'] : []
            )));
            $meta['last_pulse_signal'] = trim((string) ($journey['last_pulse_signal'] ?? ''));
            $meta['revisit_reorder_reason'] = trim((string) ($journey['revisit_reorder_reason'] ?? ''));
        }

        $pulseCheck = is_array($personalization['pulse_check_v1'] ?? null)
            ? $personalization['pulse_check_v1']
            : [];
        if ($pulseCheck !== []) {
            $meta['pulse_contract_version'] = trim((string) ($pulseCheck['pulse_contract_version'] ?? ''));
            $meta['pulse_state'] = trim((string) ($pulseCheck['pulse_state'] ?? ''));
            $meta['pulse_prompt_keys'] = array_values(array_filter(array_map(
                'strval',
                is_array($pulseCheck['pulse_prompt_keys'] ?? null) ? $pulseCheck['pulse_prompt_keys'] : []
            )));
            $meta['pulse_feedback_mode'] = trim((string) ($pulseCheck['pulse_feedback_mode'] ?? ''));
            $meta['next_pulse_target'] = trim((string) ($pulseCheck['next_pulse_target'] ?? ''));
        }

        $longitudinalMemory = is_array($personalization['longitudinal_memory_v1'] ?? null)
            ? $personalization['longitudinal_memory_v1']
            : [];
        if ($longitudinalMemory !== []) {
            $meta['memory_contract_version'] = trim((string) ($longitudinalMemory['memory_contract_version'] ?? ''));
            $meta['memory_fingerprint'] = trim((string) ($longitudinalMemory['memory_fingerprint'] ?? ''));
            $meta['memory_scope'] = trim((string) ($longitudinalMemory['memory_scope'] ?? ''));
            $meta['memory_state'] = trim((string) ($longitudinalMemory['memory_state'] ?? ''));
            $meta['memory_progression_state'] = trim((string) ($longitudinalMemory['progression_state'] ?? ''));
            $meta['section_history_keys'] = array_values(array_filter(array_map(
                'strval',
                is_array($longitudinalMemory['section_history_keys'] ?? null) ? $longitudinalMemory['section_history_keys'] : []
            )));
            $meta['behavior_delta_keys'] = array_values(array_filter(array_map(
                'strval',
                is_array($longitudinalMemory['behavior_delta_keys'] ?? null) ? $longitudinalMemory['behavior_delta_keys'] : []
            )));
            $meta['dominant_interest_keys'] = array_values(array_filter(array_map(
                'strval',
                is_array($longitudinalMemory['dominant_interest_keys'] ?? null) ? $longitudinalMemory['dominant_interest_keys'] : []
            )));
            $meta['resume_bias_keys'] = array_values(array_filter(array_map(
                'strval',
                is_array($longitudinalMemory['resume_bias_keys'] ?? null) ? $longitudinalMemory['resume_bias_keys'] : []
            )));
            $meta['memory_rewrite_keys'] = array_values(array_filter(array_map(
                'strval',
                is_array($longitudinalMemory['memory_rewrite_keys'] ?? null) ? $longitudinalMemory['memory_rewrite_keys'] : []
            )));
            $meta['memory_rewrite_reason'] = trim((string) ($longitudinalMemory['memory_rewrite_reason'] ?? ''));
        }

        return array_filter($meta, static function (mixed $value, string $key): bool {
            if (in_array($key, [
                'same_type_divergence_keys',
                'section_selection_keys',
                'action_selection_keys',
                'recommendation_selection_keys',
                'section_history_keys',
                'behavior_delta_keys',
                'dominant_interest_keys',
                'resume_bias_keys',
                'memory_rewrite_keys',
            ], true)) {
                return true;
            }

            if (is_string($value)) {
                return $value !== '';
            }

            if (is_array($value)) {
                return $value !== [];
            }

            return $value !== null;
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  array<string,mixed>  $reportEnvelope
     * @return array<string,mixed>
     */
    private function resolveMbtiPersonalizationFromReportEnvelope(
        Result $result,
        Attempt $attempt,
        array $reportEnvelope
    ): array {
        $reportPersonalization = data_get($reportEnvelope, 'report._meta.personalization');
        if (is_array($reportPersonalization) && $reportPersonalization !== []) {
            return $reportPersonalization;
        }

        $projectionPersonalization = data_get($reportEnvelope, 'mbti_public_projection_v1._meta.personalization');
        if (is_array($projectionPersonalization) && $projectionPersonalization !== []) {
            return $projectionPersonalization;
        }

        if (! is_array(data_get($reportEnvelope, 'report'))) {
            return [];
        }

        $normalizedEnvelope = $reportEnvelope;
        $normalizedEnvelope['meta'] = array_merge(
            is_array($reportEnvelope['meta'] ?? null) ? $reportEnvelope['meta'] : [],
            [
                'pack_id' => (string) ($attempt->pack_id ?? ''),
                'dir_version' => (string) ($attempt->dir_version ?? ''),
                'report_engine_version' => (string) ($result->report_engine_version ?? 'v1.2'),
            ],
        );

        $projection = $this->mbtiPublicProjectionService->buildForReportEnvelope(
            $result,
            $normalizedEnvelope,
            (string) ($attempt->locale ?? config('content_packs.default_locale', 'zh-CN')),
            (int) ($attempt->org_id ?? 0),
        );

        $projectionPersonalization = data_get($projection, '_meta.personalization');

        return is_array($projectionPersonalization) ? $projectionPersonalization : [];
    }

    /**
     * @param  array<string,mixed>  $reportEnvelope
     * @return array<string,mixed>
     */
    private function resolveEffectiveMbtiPersonalization(
        Result $result,
        Attempt $attempt,
        array $reportEnvelope,
        bool $hasUnlock
    ): array {
        $personalization = $this->resolveMbtiPersonalizationFromReportEnvelope($result, $attempt, $reportEnvelope);
        if ($personalization === []) {
            return [];
        }

        $effective = $this->mbtiUserStateOrchestrationService->overlayEffective(
            $personalization,
            (int) ($attempt->org_id ?? 0),
            (string) ($attempt->id ?? ''),
            $hasUnlock
        );

        $effective = app(\App\Services\Mbti\MbtiBigFiveSynthesisService::class)->attach($effective, [
            'org_id' => (int) ($attempt->org_id ?? 0),
            'user_id' => $attempt->user_id ?? null,
            'anon_id' => $attempt->anon_id ?? null,
            'attempt_id' => (string) ($attempt->id ?? ''),
            'locale' => (string) ($attempt->locale ?? config('content_packs.default_locale', 'zh-CN')),
        ]);

        $effective = $this->mbtiWorkingLifeConsolidationService->attach($effective);
        $effective = $this->mbtiActionJourneyContractService->attach($effective);
        $effective = $this->mbtiIntraTypeProfileService->attach($effective);

        $longitudinalMemoryService = app(\App\Services\Mbti\MbtiLongitudinalMemoryService::class);
        $canonicalMemory = is_array($personalization['longitudinal_memory_v1'] ?? null)
            ? $personalization['longitudinal_memory_v1']
            : [];

        if ($canonicalMemory !== []) {
            $effective = $longitudinalMemoryService->attachExistingMemory($effective, $canonicalMemory);
        } else {
            $effective = $longitudinalMemoryService->attach($effective, [
                'org_id' => (int) ($attempt->org_id ?? 0),
                'user_id' => $attempt->user_id ?? null,
                'anon_id' => $attempt->anon_id ?? null,
                'attempt_id' => (string) ($attempt->id ?? ''),
                'locale' => (string) ($attempt->locale ?? config('content_packs.default_locale', 'zh-CN')),
            ]);
        }

        $canonicalAdaptive = is_array($personalization['adaptive_selection_v1'] ?? null)
            ? $personalization['adaptive_selection_v1']
            : [];

        if ($canonicalAdaptive !== []) {
            $effective = $this->mbtiAdaptiveSelectionService->attachExistingAdaptive($effective, $canonicalAdaptive);
        } else {
            $effective = $this->mbtiAdaptiveSelectionService->attach($effective);
        }

        return app(\App\Services\Mbti\MbtiResultPersonalizationService::class)
            ->attachCtaBundleForExistingPersonalization($effective, [
                'pack_id' => trim((string) ($personalization['pack_id'] ?? '')),
                'dir_version' => trim((string) ($personalization['content_package_dir'] ?? '')),
                'locale' => (string) ($attempt->locale ?? config('content_packs.default_locale', 'zh-CN')),
            ]);
    }

    /**
     * @param  array<string,mixed>  $reportEnvelope
     * @param  array<string,mixed>  $personalization
     * @return array<string,mixed>
     */
    private function applyMbtiPersonalizationToEnvelope(array $reportEnvelope, array $personalization): array
    {
        if ($personalization === []) {
            return $reportEnvelope;
        }

        if (is_array(data_get($reportEnvelope, 'report'))) {
            $reportMeta = is_array(data_get($reportEnvelope, 'report._meta')) ? data_get($reportEnvelope, 'report._meta') : [];
            $canonicalPersonalization = is_array($reportMeta['personalization'] ?? null) ? $reportMeta['personalization'] : [];
            $reportMeta['personalization'] = $this->mbtiReadModelContractService->applyOverlayPatch(
                $canonicalPersonalization,
                $personalization
            );
            data_set($reportEnvelope, 'report._meta', $reportMeta);
        }

        if (is_array(data_get($reportEnvelope, 'mbti_public_projection_v1'))) {
            $projectionMeta = is_array(data_get($reportEnvelope, 'mbti_public_projection_v1._meta'))
                ? data_get($reportEnvelope, 'mbti_public_projection_v1._meta')
                : [];
            $canonicalPersonalization = is_array($projectionMeta['personalization'] ?? null)
                ? $projectionMeta['personalization']
                : [];
            $projectionMeta['personalization'] = $this->mbtiReadModelContractService->applyOverlayPatch(
                $canonicalPersonalization,
                $personalization
            );
            data_set($reportEnvelope, 'mbti_public_projection_v1._meta', $projectionMeta);
        }

        return $reportEnvelope;
    }

    private function isPublicResultScale(string $scaleCode): bool
    {
        return in_array($scaleCode, self::PUBLIC_RESULT_READ_SCALES, true);
    }

    private function isPublicReportScale(string $scaleCode): bool
    {
        return $this->isPublicResultScale($scaleCode);
    }

    private function resolveNormalizedScaleCode(array $responseCodes): string
    {
        $legacy = strtoupper(trim((string) ($responseCodes['scale_code_legacy'] ?? '')));
        if ($legacy !== '') {
            return $legacy;
        }

        return strtoupper(trim((string) ($responseCodes['scale_code'] ?? '')));
    }

    private function reportActor(Request $request): ReportAccessActor
    {
        return ReportAccessActor::from(
            $this->resolveUserId($request),
            $this->resolveAnonId($request),
            $this->currentOrgContext()->role(),
        );
    }

    private function currentOrgContext(): OrgContext
    {
        return app(OrgContext::class);
    }

    private function latestReadableSubmission(Request $request, string $attemptId): ?array
    {
        $attempt = $this->resolveReadableAttemptForSubmissionPayload($request, $attemptId);

        if (! $attempt instanceof Attempt) {
            return null;
        }

        $payload = $this->latestSubmissionPayload($request, $attemptId, $attempt);

        return ($payload['ok'] ?? false) === true ? $payload : null;
    }

    private function resolveAttemptForSubmissionRead(Request $request, string $attemptId): Attempt
    {
        $attempt = $this->resolveReadableAttemptForSubmissionPayload($request, $attemptId);
        if ($attempt instanceof Attempt) {
            return $attempt;
        }

        throw new ApiProblemException(404, 'RESOURCE_NOT_FOUND', 'attempt not found.');
    }

    private function resolveReadableAttemptForSubmissionPayload(Request $request, string $attemptId): ?Attempt
    {
        $attempt = $this->reportSubjects->findAttemptForCurrentContext($attemptId, $this->reportActor($request));
        if ($attempt instanceof Attempt) {
            return $attempt;
        }

        $attempt = $this->ownedAttemptQuery($request, $attemptId)->first();
        if ($attempt instanceof Attempt) {
            return $attempt;
        }

        return $this->resolvePublicAttemptFromArtifacts($request, $this->currentOrgContext()->orgId(), $attemptId);
    }

    /**
     * @return array<string,mixed>
     */
    private function latestSubmissionPayload(Request $request, string $attemptId, Attempt $attempt): array
    {
        $payload = $this->attemptSubmissionService->latestForAttempt(
            $this->currentOrgContext(),
            $attemptId,
            $this->resolveUserId($request),
            $this->resolveAnonId($request)
        );
        if (($payload['ok'] ?? false) === true) {
            return $payload;
        }

        if (! $this->shouldUsePublicArtifactFallback($request, $attempt)) {
            return $payload;
        }

        $fallbackUserId = isset($attempt->user_id) && $attempt->user_id !== null ? (string) $attempt->user_id : null;
        $fallbackAnonId = isset($attempt->anon_id) && $attempt->anon_id !== null ? (string) $attempt->anon_id : null;
        if ($fallbackUserId === null && $fallbackAnonId === null) {
            return $payload;
        }

        $fallbackPayload = $this->attemptSubmissionService->latestForAttempt(
            $this->currentOrgContext(),
            $attemptId,
            $fallbackUserId,
            $fallbackAnonId
        );

        return ($fallbackPayload['ok'] ?? false) === true ? $fallbackPayload : $payload;
    }

    private function resolvePublicAttemptFromArtifacts(
        Request $request,
        int $orgId,
        string $attemptId,
        ?string $scaleCode = null
    ): ?Attempt {
        if ($this->hasResolvedReadActor($request)) {
            return null;
        }

        $attempt = $this->reportSubjects->findAttemptForSystem($orgId, $attemptId);
        if (! $attempt instanceof Attempt) {
            return null;
        }

        $normalizedScaleCode = strtoupper(trim($scaleCode !== null ? $scaleCode : (string) ($attempt->scale_code ?? '')));
        if (! $this->isPublicReportScale($normalizedScaleCode)) {
            return null;
        }

        if (! $this->hasPublicReadableArtifact($orgId, $attemptId)) {
            return null;
        }

        return $attempt;
    }

    private function shouldUsePublicArtifactFallback(Request $request, Attempt $attempt): bool
    {
        if ($this->hasResolvedReadActor($request)) {
            return false;
        }

        if (! $this->isPublicReportScale(strtoupper(trim((string) ($attempt->scale_code ?? ''))))) {
            return false;
        }

        return $this->hasPublicReadableArtifact((int) ($attempt->org_id ?? 0), (string) ($attempt->id ?? ''));
    }

    private function hasResolvedReadActor(Request $request): bool
    {
        return $this->resolveUserId($request) !== null || $this->resolveAnonId($request) !== null;
    }

    private function hasPublicReadableArtifact(int $orgId, string $attemptId): bool
    {
        if (Result::query()->where('org_id', $orgId)->where('attempt_id', $attemptId)->exists()) {
            return true;
        }

        return UnifiedAccessProjection::query()->where('attempt_id', $attemptId)->exists();
    }

    private function pendingSubmissionResponse(string $attemptId, array $submissionPayload, bool $includeReport): JsonResponse
    {
        $payload = [
            'ok' => true,
            'attempt_id' => $attemptId,
            'generating' => true,
            'submission_state' => (string) data_get($submissionPayload, 'submission.state', 'pending'),
            'submission' => is_array($submissionPayload['submission'] ?? null) ? $submissionPayload['submission'] : [],
            'result' => null,
        ];
        if ($includeReport) {
            $payload['report'] = [];
        }

        return response()->json($payload, 202);
    }

    private function failedSubmissionResponse(string $attemptId, array $submissionPayload, bool $includeReport): JsonResponse
    {
        $message = trim((string) data_get($submissionPayload, 'submission.error_message', ''));
        if ($message === '') {
            $message = trim((string) data_get($submissionPayload, 'result.message', 'submission failed.'));
        }

        $payload = [
            'ok' => false,
            'attempt_id' => $attemptId,
            'error_code' => 'SUBMISSION_FAILED',
            'message' => $message !== '' ? $message : 'submission failed.',
            'generating' => false,
            'submission_state' => (string) data_get($submissionPayload, 'submission.state', 'failed'),
            'submission' => is_array($submissionPayload['submission'] ?? null) ? $submissionPayload['submission'] : [],
            'result' => is_array($submissionPayload['result'] ?? null) ? $submissionPayload['result'] : null,
        ];
        if ($includeReport) {
            $payload['report'] = [];
        }

        return response()->json($payload);
    }

    private function missingResultAfterSubmissionResponse(string $attemptId, array $submissionPayload, bool $includeReport): JsonResponse
    {
        $payload = [
            'ok' => false,
            'attempt_id' => $attemptId,
            'error_code' => 'SUBMISSION_SUCCEEDED_RESULT_MISSING',
            'message' => 'submission succeeded but result is not readable yet.',
            'generating' => false,
            'submission_state' => (string) data_get($submissionPayload, 'submission.state', 'succeeded'),
            'submission' => is_array($submissionPayload['submission'] ?? null) ? $submissionPayload['submission'] : [],
            'result' => null,
        ];
        if ($includeReport) {
            $payload['report'] = [];
        }

        return response()->json($payload);
    }

    private function resolveAttemptId(Result $result, ?Attempt $attempt, string $fallbackAttemptId): string
    {
        if ($attempt instanceof Attempt) {
            return (string) $attempt->id;
        }

        $resultAttemptId = trim((string) ($result->attempt_id ?? ''));
        if ($resultAttemptId !== '') {
            return $resultAttemptId;
        }

        return $fallbackAttemptId;
    }

    private function preferredStringField(object $primary, ?object $fallback, string $field, string $default = ''): string
    {
        $primaryValue = trim((string) ($primary->{$field} ?? ''));
        if ($primaryValue !== '') {
            return $primaryValue;
        }

        $fallbackValue = trim((string) ($fallback?->{$field} ?? ''));
        if ($fallbackValue !== '') {
            return $fallbackValue;
        }

        return $default;
    }

    /**
     * GET /api/v0.3/attempts/{id}/report.pdf
     */
    public function reportPdf(Request $request, string $id): Response
    {
        $orgId = $this->currentOrgContext()->orgId();
        $userId = $this->resolveUserId($request);
        $anonId = $this->resolveAnonId($request);
        $result = Result::where('org_id', $orgId)->where('attempt_id', $id)->firstOrFail();
        $responseCodes = $this->resolveResponseScaleCodes($result);
        $scaleCode = $this->resolveNormalizedScaleCode($responseCodes);
        $attempt = $this->resolveAttemptForReportRead($request, $id, $scaleCode);
        $mbtiFormSummary = $scaleCode === 'MBTI'
            ? $this->resolveMbtiFormSummary($request, $attempt, $result)
            : null;
        $big5FormSummary = $scaleCode === 'BIG5_OCEAN'
            ? $this->resolveBigFiveFormSummary($request, $attempt, $result)
            : null;
        $enneagramFormSummary = $scaleCode === 'ENNEAGRAM'
            ? $this->resolveEnneagramFormSummary($request, $attempt, $result)
            : null;

        $gate = $this->reportGatekeeper->resolve(
            $orgId,
            $id,
            $userId !== null ? (string) $userId : null,
            $anonId,
            $this->currentOrgContext()->role(),
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

        $variant = $this->reportPdfDocumentService->normalizeVariant((string) ($gate['variant'] ?? 'free'));
        $locked = (bool) ($gate['locked'] ?? false);
        $fileName = $this->reportPdfDocumentService->fileName((string) ($attempt->scale_code ?? 'report'), (string) $attempt->id);
        $inline = in_array(strtolower(trim((string) $request->query('inline', '0'))), ['1', 'true', 'yes', 'on'], true);
        $disposition = $inline ? 'inline' : 'attachment';

        $request->merge(['attempt_id' => (string) $attempt->id]);
        $this->eventRecorder->recordFromRequest($request, 'report_pdf_view', $this->resolveUserId($request), [
            'scale_code' => (string) ($attempt->scale_code ?? ''),
            'pack_id' => (string) ($attempt->pack_id ?? ''),
            'dir_version' => (string) ($attempt->dir_version ?? ''),
            'type_code' => (string) ($result->type_code ?? ''),
            'attempt_id' => (string) $attempt->id,
            'locked' => $locked,
            'variant' => $variant,
            ...$this->mbtiFormEventMeta($mbtiFormSummary),
            ...$this->big5FormEventMeta($big5FormSummary),
            ...$this->enneagramFormEventMeta($enneagramFormSummary),
        ]);

        $generated = $this->reportPdfDocumentService->getOrGenerate($attempt, $gate, $result);
        $pdfBinary = (string) ($generated['binary'] ?? '');
        $variant = (string) ($generated['variant'] ?? $variant);
        $locked = (bool) ($generated['locked'] ?? $locked);

        return response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$fileName.'"',
            'Cache-Control' => 'private, no-store',
            'X-Report-Scale' => strtoupper((string) ($attempt->scale_code ?? '')),
            'X-Report-Variant' => $variant,
            'X-Report-Locked' => $locked ? 'true' : 'false',
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveMbtiFormSummary(Request $request, ?Attempt $attempt, ?Result $result = null): ?array
    {
        return $this->mbtiPublicFormSummaryBuilder->summarizeForAttempt(
            $attempt,
            $result,
            $this->resolvePublicReadLocale($request, $attempt?->locale)
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveBigFiveFormSummary(Request $request, ?Attempt $attempt, ?Result $result = null): ?array
    {
        return $this->bigFivePublicFormSummaryBuilder->summarizeForAttempt(
            $attempt,
            $result,
            $this->resolvePublicReadLocale($request, $attempt?->locale)
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveEnneagramFormSummary(Request $request, ?Attempt $attempt, ?Result $result = null): ?array
    {
        return $this->enneagramPublicFormSummaryBuilder->summarizeForAttempt(
            $attempt,
            $result,
            $this->resolvePublicReadLocale($request, $attempt?->locale)
        );
    }

    /**
     * @param  array<string,mixed>|null  $summary
     * @return array<string,string>
     */
    private function mbtiFormEventMeta(?array $summary): array
    {
        $formCode = trim((string) ($summary['form_code'] ?? ''));

        return $formCode !== '' ? ['form_code' => $formCode] : [];
    }

    /**
     * @param  array<string,mixed>|null  $summary
     * @return array<string,string>
     */
    private function big5FormEventMeta(?array $summary): array
    {
        $formCode = trim((string) ($summary['form_code'] ?? ''));

        return $formCode !== '' ? ['form_code' => $formCode] : [];
    }

    /**
     * @param  array<string,mixed>|null  $summary
     * @return array<string,string>
     */
    private function enneagramFormEventMeta(?array $summary): array
    {
        $formCode = trim((string) ($summary['form_code'] ?? ''));

        return $formCode !== '' ? ['form_code' => $formCode] : [];
    }

    /**
     * @param  array<string,mixed>|null  $summary
     * @return array<string,string>
     */
    private function riasecFormEventMeta(?array $summary): array
    {
        $formCode = trim((string) ($summary['form_code'] ?? ''));

        return $formCode !== '' ? ['form_code' => $formCode] : [];
    }

    private function resolvePublicReadLocale(Request $request, ?string $fallback = null): ?string
    {
        foreach ([
            $request->query('locale'),
            $request->header('X-Fap-Locale', ''),
            $request->header('X-Locale', ''),
            $request->getLocale(),
            $fallback,
            config('content_packs.default_locale', 'zh-CN'),
        ] as $candidate) {
            $normalized = trim((string) $candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }
}
