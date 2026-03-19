<?php

namespace App\Http\Controllers\API\V0_3;

use App\Exceptions\Api\ApiProblemException;
use App\Http\Controllers\API\V0_3\Concerns\ResolvesAttemptOwnership;
use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\Result;
use App\Repositories\Report\ReportAccessActor;
use App\Repositories\Report\ReportSubjectRepository;
use App\Services\Analytics\EventRecorder;
use App\Services\Attempts\AttemptSubmissionService;
use App\Services\Commerce\MbtiAccessHubBuilder;
use App\Services\Mbti\MbtiPublicProjectionService;
use App\Services\Mbti\MbtiResultPersonalizationService;
use App\Services\Mbti\MbtiPublicSummaryV1Builder;
use App\Services\Observability\ClinicalComboTelemetry;
use App\Services\Observability\Sds20Telemetry;
use App\Services\Report\Pdf\ReportPdfDocumentService;
use App\Services\Report\ReportAccess;
use App\Services\Report\ReportGatekeeper;
use App\Services\Scale\ScaleCodeResponseProjector;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AttemptReadController extends Controller
{
    use ResolvesAttemptOwnership;

    private const PUBLIC_RESULT_READ_SCALES = ['MBTI', 'BIG5_OCEAN', 'IQ_RAVEN', 'EQ_60'];

    private const SENSITIVE_RESULT_READ_SCALES = ['SDS_20', 'CLINICAL_COMBO_68'];

    private const MBTI_AXIS_ORDER = ['EI', 'SN', 'TF', 'JP', 'AT'];

    private const MBTI_AXIS_TYPE_INDEX = ['EI' => 0, 'SN' => 1, 'TF' => 2, 'JP' => 3];

    private const MBTI_AXIS_SIDE_FALLBACK = [
        'EI' => ['E', 'I'],
        'SN' => ['S', 'N'],
        'TF' => ['T', 'F'],
        'JP' => ['J', 'P'],
        'AT' => ['A', 'T'],
    ];

    public function __construct(
        private AttemptSubmissionService $attemptSubmissionService,
        private ReportGatekeeper $reportGatekeeper,
        private ReportPdfDocumentService $reportPdfDocumentService,
        private MbtiPublicProjectionService $mbtiPublicProjectionService,
        private MbtiPublicSummaryV1Builder $mbtiPublicSummaryV1Builder,
        private MbtiAccessHubBuilder $mbtiAccessHubBuilder,
        private EventRecorder $eventRecorder,
        private ScaleCodeResponseProjector $responseProjector,
        private ReportSubjectRepository $reportSubjects,
        private MbtiResultPersonalizationService $mbtiPersonalization,
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
     * GET /api/v0.3/attempts/{attempt_id}/submission
     */
    public function submission(Request $request, string $attemptId): JsonResponse
    {
        $this->ownedAttemptQuery($request, $attemptId)->firstOrFail();

        $payload = $this->attemptSubmissionService->latestForAttempt(
            $this->orgContext,
            $attemptId,
            $this->resolveUserId($request),
            $this->resolveAnonId($request)
        );

        $status = (int) ($payload['http_status'] ?? 200);
        unset($payload['http_status']);

        return response()->json($payload, $status);
    }

    /**
     * GET /api/v0.3/attempts/{id}/result
     */
    public function result(Request $request, string $id): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $result = Result::query()->where('org_id', $orgId)->where('attempt_id', $id)->first();
        if (! $result instanceof Result) {
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
                'scale_code' => $responseCodes['scale_code'],
                'scale_code_legacy' => $responseCodes['scale_code_legacy'],
                'scale_code_v2' => $responseCodes['scale_code_v2'],
                'scale_uid' => $responseCodes['scale_uid'],
                'quality' => is_array($gate['quality'] ?? null) ? $gate['quality'] : [],
                'scores' => is_array($report['scores'] ?? null) ? $report['scores'] : [],
                'report_tags' => is_array($report['report_tags'] ?? null) ? $report['report_tags'] : [],
                'sections' => is_array($report['sections'] ?? null) ? $report['sections'] : [],
            ];

            $this->eventRecorder->recordFromRequest($request, 'result_view', $this->resolveUserId($request), [
                'scale_code' => $scaleCode,
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
                'type_code' => (string) ($result->type_code ?? ''),
                'attempt_id' => $attemptId,
                'locked' => (bool) ($gate['locked'] ?? true),
            ]);

            return response()->json([
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
            ]);
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

        $mbtiEventMeta = $scaleCode === 'MBTI'
            ? $this->mbtiTelemetryMetaFromPersonalization(
                $this->buildMbtiPersonalizationForResultEvent(
                    $result,
                    $attempt,
                    $compatTypeCode,
                    $compatScoresPct,
                    $this->resolveMbtiAxisStates($result, $payload),
                    $packId,
                    $dirVersion,
                    $reportEngineVersion
                )
            )
            : [];

        $this->eventRecorder->recordFromRequest($request, 'result_view', $this->resolveUserId($request), [
            'scale_code' => $scaleCode,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'type_code' => (string) ($result->type_code ?? ''),
            'attempt_id' => $attemptId,
            ...$mbtiEventMeta,
        ]);

        return response()->json([
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
        $result = Result::query()->where('org_id', $orgId)->where('attempt_id', $id)->first();
        if (! $result instanceof Result) {
            $submissionPayload = $this->attemptSubmissionService->latestForAttempt(
                $this->orgContext,
                $id,
                $userId !== null ? (string) $userId : null,
                $anonId
            );

            if (($submissionPayload['ok'] ?? false) === true && ($submissionPayload['generating'] ?? false) === true) {
                return response()->json([
                    'ok' => true,
                    'attempt_id' => $id,
                    'generating' => true,
                    'submission_state' => (string) data_get($submissionPayload, 'submission.state', 'pending'),
                    'submission' => is_array($submissionPayload['submission'] ?? null) ? $submissionPayload['submission'] : [],
                    'result' => null,
                    'report' => [],
                ], 202);
            }

            throw new ApiProblemException(404, 'RESULT_NOT_FOUND', 'result not found.');
        }
        $responseCodes = $this->resolveResponseScaleCodes($result);
        $scaleCode = $this->resolveNormalizedScaleCode($responseCodes);
        $attempt = $this->resolveAttemptForReportRead($request, $orgId, $id, $scaleCode);

        $gate = $this->resolveReportGate(
            $orgId,
            $id,
            $userId !== null ? (string) $userId : null,
            $anonId,
            $this->orgContext->role(),
            false,
            $forceRefresh,
        );

        $reportPersonalization = is_array(data_get($gate, 'report._meta.personalization'))
            ? data_get($gate, 'report._meta.personalization')
            : [];
        $mbtiEventMeta = $scaleCode === 'MBTI'
            ? $this->mbtiTelemetryMetaFromPersonalization(
                $reportPersonalization !== []
                    ? $reportPersonalization
                    : $this->buildMbtiPersonalizationForResultEvent(
                        $result,
                        $attempt,
                        (string) ($result->type_code ?? ''),
                        is_array($result->scores_pct) ? $result->scores_pct : [],
                        $this->resolveMbtiAxisStates($result, []),
                        (string) ($attempt->pack_id ?? ''),
                        (string) ($attempt->dir_version ?? ''),
                        (string) ($result->report_engine_version ?? 'v1.2')
                    )
            )
            : [];

        $this->eventRecorder->recordFromRequest($request, 'report_view', $this->resolveUserId($request), [
            'scale_code' => (string) ($attempt->scale_code ?? ''),
            'pack_id' => (string) ($attempt->pack_id ?? ''),
            'dir_version' => (string) ($attempt->dir_version ?? ''),
            'type_code' => (string) ($result->type_code ?? ''),
            'attempt_id' => (string) $attempt->id,
            'locked' => (bool) ($gate['locked'] ?? false),
            ...$mbtiEventMeta,
        ]);
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
        }

        return response()->json($responsePayload);
    }

    private function resolveAttemptForReportRead(Request $request, int $orgId, string $attemptId, string $scaleCode): Attempt
    {
        if ($this->isPublicReportScale($scaleCode)) {
            $attempt = $this->reportSubjects->findAttemptForCurrentContext($attemptId, $this->reportActor($request));

            if ($attempt instanceof Attempt) {
                return $attempt;
            }

            throw new ApiProblemException(404, 'ATTEMPT_NOT_FOUND', 'attempt not found.');
        }

        $attempt = $this->ownedAttemptQuery($request, $attemptId)->first();
        if ($attempt instanceof Attempt) {
            return $attempt;
        }

        throw new ApiProblemException(404, 'RESOURCE_NOT_FOUND', 'attempt not found.');
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

    /**
     * @param  array<string,mixed>  $scoresPct
     * @param  array<string,mixed>  $axisStates
     * @return array<string,mixed>
     */
    private function buildMbtiPersonalizationForResultEvent(
        Result $result,
        ?Attempt $attempt,
        string $typeCode,
        array $scoresPct,
        array $axisStates,
        string $packId,
        string $dirVersion,
        string $reportEngineVersion
    ): array {
        $normalizedTypeCode = strtoupper(trim($typeCode));
        if ($normalizedTypeCode === '') {
            return [];
        }

        $scores = [];
        foreach (self::MBTI_AXIS_ORDER as $axisCode) {
            $pctRaw = $scoresPct[$axisCode] ?? null;
            if (! is_numeric($pctRaw)) {
                continue;
            }

            $pct = (int) round((float) $pctRaw);
            $scores[$axisCode] = [
                'pct' => $pct,
                'delta' => (int) round(abs($pct - 50)),
                'side' => $this->resolveMbtiAxisSide($axisCode, $normalizedTypeCode, $pct),
                'state' => trim((string) ($axisStates[$axisCode] ?? '')),
            ];
        }

        if ($scores === []) {
            return [];
        }

        return $this->mbtiPersonalization->buildForReportPayload([
            'type_code' => $normalizedTypeCode,
            'scores' => $scores,
            'axis_states' => $axisStates,
        ], [
            'type_code' => $normalizedTypeCode,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'locale' => (string) ($attempt?->locale ?? config('content_packs.default_locale', 'zh-CN')),
            'engine_version' => $reportEngineVersion,
        ]);
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
            'variant_keys' => is_array($personalization['variant_keys'] ?? null) ? $personalization['variant_keys'] : [],
            'scene_fingerprint' => $sceneFingerprint,
            'boundary_flags' => is_array($personalization['boundary_flags'] ?? null) ? $personalization['boundary_flags'] : [],
            'axis_bands' => is_array($personalization['axis_bands'] ?? null) ? $personalization['axis_bands'] : [],
            'engine_version' => trim((string) ($personalization['engine_version'] ?? '')),
        ];

        return array_filter($meta, static function (mixed $value): bool {
            if (is_string($value)) {
                return $value !== '';
            }

            if (is_array($value)) {
                return $value !== [];
            }

            return $value !== null;
        });
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function resolveMbtiAxisStates(Result $result, array $payload): array
    {
        if (is_array($result->axis_states)) {
            return $result->axis_states;
        }

        $nested = data_get($payload, 'axis_scores_json.axis_states');
        if (is_array($nested)) {
            return $nested;
        }

        return [];
    }

    private function resolveMbtiAxisSide(string $axisCode, string $typeCode, int $pct): string
    {
        $normalizedAxis = strtoupper(trim($axisCode));
        $normalizedType = strtoupper(trim($typeCode));

        if ($normalizedAxis === 'AT' && preg_match('/-(A|T)$/', $normalizedType, $matches) === 1) {
            return (string) ($matches[1] ?? '');
        }

        if (isset(self::MBTI_AXIS_TYPE_INDEX[$normalizedAxis])) {
            $index = self::MBTI_AXIS_TYPE_INDEX[$normalizedAxis];
            if (isset($normalizedType[$index])) {
                return $normalizedType[$index];
            }
        }

        [$primary, $secondary] = self::MBTI_AXIS_SIDE_FALLBACK[$normalizedAxis] ?? ['E', 'I'];

        return $pct >= 50 ? $primary : $secondary;
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
            $this->orgContext->role(),
        );
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

        $variant = $this->reportPdfDocumentService->normalizeVariant((string) ($gate['variant'] ?? 'free'));
        $locked = (bool) ($gate['locked'] ?? false);
        $fileName = $this->reportPdfDocumentService->fileName((string) ($attempt->scale_code ?? 'report'), (string) $attempt->id);
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
}
