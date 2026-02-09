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
        $userId = $this->orgContext->userId();
        $token = trim((string) $request->header('X-Resume-Token', ''));

        if ($token === '' && $userId === null) {
            abort(404);
        }

        $attemptQuery = Attempt::query()
            ->where('id', $attempt_id)
            ->where('org_id', $orgId);

        if ($userId !== null) {
            $attemptQuery->where('user_id', (string) $userId);
        }

        $attempt = $attemptQuery->firstOrFail();

        $result = $this->progressService->saveProgress($attempt, $token !== '' ? $token : null, $userId, $payload);
        if (!($result['ok'] ?? false)) {
            $status = (int) ($result['status'] ?? 400);
            if (in_array($status, [401, 403], true)) {
                abort(404);
            }
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
        $userId = $this->orgContext->userId();
        $token = trim((string) $request->header('X-Resume-Token', ''));

        if ($token === '' && $userId === null) {
            abort(404);
        }

        $attemptQuery = Attempt::query()
            ->where('id', $attempt_id)
            ->where('org_id', $orgId);

        if ($userId !== null) {
            $attemptQuery->where('user_id', (string) $userId);
        }

        $attempt = $attemptQuery->firstOrFail();

        $result = $this->progressService->getProgress($attempt, $token !== '' ? $token : null, $userId);
        if (!($result['ok'] ?? false)) {
            $status = (int) ($result['status'] ?? 400);
            if (in_array($status, [401, 403], true)) {
                abort(404);
            }
            return response()->json($result, $status);
        }

        return response()->json(array_merge([
            'ok' => true,
        ], $result['data'] ?? []));
    }
}
