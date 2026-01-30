<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Services\Attempts\AttemptProgressService;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttemptProgressController extends Controller
{
    public function __construct(
        private AttemptProgressService $progressService,
        private OrgContext $orgContext,
    ) {
    }

    /**
     * PUT /api/v0.3/attempts/{attempt_id}/progress
     */
    public function upsert(Request $request, string $attempt_id): JsonResponse
    {
        $payload = $request->validate([
            'seq' => ['required', 'integer', 'min:0'],
            'cursor' => ['nullable', 'string', 'max:255'],
            'duration_ms' => ['required', 'integer', 'min:0'],
            'answers' => ['nullable', 'array'],
            'answers.*.question_id' => ['required_with:answers', 'string', 'max:128'],
            'answers.*.question_type' => ['nullable', 'string', 'max:32'],
            'answers.*.question_index' => ['nullable', 'integer', 'min:0'],
            'answers.*.code' => ['nullable', 'string', 'max:64'],
            'answers.*.answer' => ['nullable', 'array'],
        ]);

        $orgId = $this->orgContext->orgId();
        $attempt = Attempt::where('id', $attempt_id)->where('org_id', $orgId)->first();
        if (!$attempt) {
            return response()->json([
                'ok' => false,
                'error' => 'ATTEMPT_NOT_FOUND',
                'message' => 'attempt not found.',
            ], 404);
        }

        $token = trim((string) $request->header('X-Resume-Token', ''));
        $userId = $this->orgContext->userId();
        if ($token === '' && $userId === null) {
            return response()->json([
                'ok' => false,
                'error' => 'RESUME_TOKEN_REQUIRED',
                'message' => 'resume token required.',
            ], 401);
        }

        $result = $this->progressService->saveProgress($attempt, $token !== '' ? $token : null, $userId, $payload);
        if (!($result['ok'] ?? false)) {
            $status = (int) ($result['status'] ?? 400);
            return response()->json($result, $status);
        }

        return response()->json(array_merge([
            'ok' => true,
        ], $result['data'] ?? []));
    }

    /**
     * GET /api/v0.3/attempts/{attempt_id}/progress
     */
    public function show(Request $request, string $attempt_id): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $attempt = Attempt::where('id', $attempt_id)->where('org_id', $orgId)->first();
        if (!$attempt) {
            return response()->json([
                'ok' => false,
                'error' => 'ATTEMPT_NOT_FOUND',
                'message' => 'attempt not found.',
            ], 404);
        }

        $token = trim((string) $request->header('X-Resume-Token', ''));
        $userId = $this->orgContext->userId();
        if ($token === '' && $userId === null) {
            return response()->json([
                'ok' => false,
                'error' => 'RESUME_TOKEN_REQUIRED',
                'message' => 'resume token required.',
            ], 401);
        }

        $result = $this->progressService->getProgress($attempt, $token !== '' ? $token : null, $userId);
        if (!($result['ok'] ?? false)) {
            $status = (int) ($result['status'] ?? 400);
            return response()->json($result, $status);
        }

        return response()->json(array_merge([
            'ok' => true,
        ], $result['data'] ?? []));
    }
}
