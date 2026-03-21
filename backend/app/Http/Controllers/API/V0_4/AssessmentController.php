<?php

namespace App\Http\Controllers\API\V0_4;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Services\Analytics\EventRecorder;
use App\Services\Assessments\AssessmentService;
use App\Services\Scale\ScaleCodeInputGuard;
use App\Services\Scale\ScaleCodeResponseProjector;
use App\Services\Scale\ScaleRegistry;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AssessmentController extends Controller
{
    public function __construct(
        private AssessmentService $assessments,
        private ScaleRegistry $registry,
        private ScaleCodeInputGuard $inputGuard,
        private ScaleCodeResponseProjector $responseProjector,
        private OrgContext $orgContext,
        private EventRecorder $events,
    ) {}

    /**
     * POST /api/v0.4/orgs/{org_id}/assessments
     */
    public function store(Request $request, string $org_id): JsonResponse
    {
        $payload = $request->validate([
            'scale_code' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:255'],
            'due_at' => ['nullable', 'date'],
        ]);

        $orgId = (int) $org_id;
        if ($orgId <= 0 || $this->orgContext->orgId() !== $orgId) {
            return $this->orgNotFound();
        }

        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return $this->orgNotFound();
        }

        $scaleCode = strtoupper(trim((string) $payload['scale_code']));
        if ($scaleCode === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'SCALE_REQUIRED',
                'message' => 'scale_code is required.',
            ], 400);
        }
        $this->inputGuard->assertAccepted($scaleCode);

        $row = $this->registry->getByCode($scaleCode, $orgId);
        if (! $row) {
            $row = $this->registry->getByCode($scaleCode, 0);
        }
        if (! $row) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'scale not found.',
            ], 404);
        }

        $dueAt = null;
        if (! empty($payload['due_at'])) {
            try {
                $dueAt = Carbon::parse($payload['due_at']);
            } catch (\Throwable $e) {
                return response()->json([
                    'ok' => false,
                    'error_code' => 'DUE_AT_INVALID',
                    'message' => 'due_at invalid.',
                ], 422);
            }
        }

        $assessment = $this->assessments->createAssessment(
            $orgId,
            $scaleCode,
            (string) $payload['title'],
            $userId,
            $dueAt
        );

        return response()->json([
            'ok' => true,
            'assessment' => $this->payloadAssessment($assessment),
        ]);
    }

    /**
     * POST /api/v0.4/orgs/{org_id}/assessments/{id}/invite
     */
    public function invite(Request $request, string $org_id, string $id): JsonResponse
    {
        $payload = $request->validate([
            'subjects' => ['required', 'array', 'min:1'],
            'subjects.*.subject_type' => ['required', 'string', 'in:user,email'],
            'subjects.*.subject_value' => ['required', 'string', 'max:255'],
        ]);

        $orgId = (int) $org_id;
        $assessmentId = (int) $id;
        if ($orgId <= 0 || $assessmentId <= 0 || $this->orgContext->orgId() !== $orgId) {
            return $this->orgNotFound();
        }

        $assessment = $this->assessments->findForOrg($orgId, $assessmentId);
        if (! $assessment) {
            return $this->orgNotFound();
        }

        $subjects = $this->sanitizeSubjects($payload['subjects']);
        if ($subjects === []) {
            return response()->json([
                'ok' => false,
                'error_code' => 'SUBJECTS_INVALID',
                'message' => 'subjects invalid.',
            ], 422);
        }

        $invites = $this->assessments->invite($assessment, $subjects);

        return response()->json([
            'ok' => true,
            'total' => count($subjects),
            'created' => count($invites),
            'invites' => $invites,
        ]);
    }

    /**
     * GET /api/v0.4/orgs/{org_id}/assessments/{id}/progress
     */
    public function progress(Request $request, string $org_id, string $id): JsonResponse
    {
        $orgId = (int) $org_id;
        $assessmentId = (int) $id;
        if ($orgId <= 0 || $assessmentId <= 0 || $this->orgContext->orgId() !== $orgId) {
            return $this->orgNotFound();
        }

        $assessment = $this->assessments->findForOrg($orgId, $assessmentId);
        if (! $assessment) {
            return $this->orgNotFound();
        }

        $progress = $this->assessments->progress($assessment);

        return response()->json(array_merge([
            'ok' => true,
            'assessment_id' => (int) $assessment->id,
        ], $progress));
    }

    /**
     * GET /api/v0.4/orgs/{org_id}/assessments/{id}/summary
     */
    public function summary(Request $request, string $org_id, string $id): JsonResponse
    {
        $orgId = (int) $org_id;
        $assessmentId = (int) $id;
        if ($orgId <= 0 || $assessmentId <= 0 || $this->orgContext->orgId() !== $orgId) {
            return $this->orgNotFound();
        }

        $assessment = $this->assessments->findForOrg($orgId, $assessmentId);
        if (! $assessment) {
            return $this->orgNotFound();
        }

        $summary = $this->assessments->summary($assessment);

        $teamDynamics = is_array($summary['team_dynamics_v1'] ?? null) ? $summary['team_dynamics_v1'] : null;
        if ($teamDynamics !== null) {
            $this->events->record('team_dynamics_summary_view', $this->resolveUserId($request), [
                'assessment_id' => (int) $assessment->id,
                'team_focus_key' => (string) ($teamDynamics['team_focus_key'] ?? ''),
                'supporting_scales' => array_values((array) ($teamDynamics['supporting_scales'] ?? [])),
                'team_member_count' => (int) ($teamDynamics['team_member_count'] ?? 0),
                'analyzed_member_count' => (int) ($teamDynamics['analyzed_member_count'] ?? 0),
                'version' => (string) ($teamDynamics['version'] ?? ''),
                'workspace_scope' => (string) ($teamDynamics['workspace_scope'] ?? ''),
            ], [
                'org_id' => $orgId,
                'scale_code' => (string) ($assessment->scale_code ?? ''),
            ]);
        }

        return response()->json([
            'ok' => true,
            'assessment_id' => (int) $assessment->id,
            'summary' => $summary,
        ]);
    }

    private function payloadAssessment(Assessment $assessment): array
    {
        $scaleCodes = $this->responseProjector->project(
            (string) ($assessment->scale_code ?? ''),
            (string) ($assessment->scale_code_v2 ?? ''),
            $assessment->scale_uid !== null ? (string) $assessment->scale_uid : null
        );

        return [
            'id' => (int) $assessment->id,
            'org_id' => (int) $assessment->org_id,
            'scale_code' => $scaleCodes['scale_code'],
            'scale_code_legacy' => $scaleCodes['scale_code_legacy'],
            'scale_code_v2' => $scaleCodes['scale_code_v2'],
            'scale_uid' => $scaleCodes['scale_uid'],
            'title' => (string) $assessment->title,
            'created_by' => (int) $assessment->created_by,
            'status' => (string) $assessment->status,
            'due_at' => $assessment->due_at?->toISOString(),
            'created_at' => $assessment->created_at?->toISOString(),
            'updated_at' => $assessment->updated_at?->toISOString(),
        ];
    }

    private function resolveUserId(Request $request): ?int
    {
        $raw = (string) ($request->attributes->get('fm_user_id') ?? $request->attributes->get('user_id') ?? '');
        if ($raw === '' || ! preg_match('/^\d+$/', $raw)) {
            return null;
        }

        return (int) $raw;
    }

    private function sanitizeSubjects(array $subjects): array
    {
        $out = [];

        foreach ($subjects as $subject) {
            if (! is_array($subject)) {
                continue;
            }

            $type = strtolower(trim((string) ($subject['subject_type'] ?? '')));
            $value = trim((string) ($subject['subject_value'] ?? ''));

            if ($type === '' || $value === '') {
                continue;
            }

            if ($type === 'email' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            if ($type === 'user' && ! preg_match('/^\d+$/', $value)) {
                continue;
            }

            $out[] = [
                'subject_type' => $type,
                'subject_value' => $value,
            ];
        }

        return $out;
    }

    private function orgNotFound(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'ORG_NOT_FOUND',
            'message' => 'org not found.',
        ], 404);
    }
}
