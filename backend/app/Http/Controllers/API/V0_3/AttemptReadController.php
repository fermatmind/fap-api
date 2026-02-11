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
     * GET /api/v0.3/attempts/{id}/result
     */
    public function result(Request $request, string $id): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $userId = $this->resolveUserId($request);
        $anonId = $this->resolveAnonId($request);
        $attempt = $this->ownedAttemptQuery($id)->firstOrFail();

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
        $orgId = $this->orgContext->orgId();
        $userId = $this->resolveUserId($request);
        $anonId = $this->resolveAnonId($request);
        $attempt = $this->ownedAttemptQuery($id)->firstOrFail();

        $result = Result::where('org_id', $orgId)->where('attempt_id', $id)->firstOrFail();

        $gate = $this->reportGatekeeper->resolve(
            $orgId,
            $id,
            $userId !== null ? (string) $userId : null,
            $anonId,
            $this->orgContext->role(),
        );

        if (!($gate['ok'] ?? false)) {
            $status = (int) ($gate['status'] ?? 0);
            if ($status <= 0) {
                $error = strtoupper((string) ($gate['error'] ?? 'REPORT_FAILED'));
                $status = match ($error) {
                    'ATTEMPT_REQUIRED', 'SCALE_REQUIRED' => 400,
                    'ATTEMPT_NOT_FOUND', 'RESULT_NOT_FOUND', 'SCALE_NOT_FOUND' => 404,
                    default => 500,
                };
            }

            return response()->json([
                'ok' => false,
                'error' => (string) ($gate['error'] ?? 'REPORT_FAILED'),
                'message' => (string) ($gate['message'] ?? 'report generation failed.'),
            ], $status);
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
}
