<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Exceptions\Api\ApiProblemException;
use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Services\Attempts\AttemptSubmitService;
use App\Services\Iq\IqOwnerOriginal30BankService;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AttemptQuestionDeliveryController extends Controller
{
    public function __construct(
        private readonly AttemptSubmitService $attemptSubmitService,
        private readonly IqOwnerOriginal30BankService $ownerBank,
    ) {}

    public function show(Request $request, string $attemptId): JsonResponse
    {
        $attemptId = trim($attemptId);
        $index = $request->query('index', 0);
        if (! is_numeric($index)) {
            throw new ApiProblemException(422, 'IQ_QUESTION_INDEX_INVALID', 'question index must be numeric.');
        }

        $context = app(OrgContext::class);
        $actorUserId = $this->resolveUserId($request, $context);
        $actorAnonId = $this->resolveAnonId($request, $context);

        $attempt = $this->attemptSubmitService
            ->ownedAttemptQuery($context, $attemptId, $actorUserId, $actorAnonId)
            ->first();

        if (! $attempt instanceof Attempt) {
            throw new ApiProblemException(404, 'RESOURCE_NOT_FOUND', 'attempt not found.');
        }

        if (! $this->ownerBank->isOwnerOriginalAttempt($attempt)) {
            throw new ApiProblemException(404, 'RESOURCE_NOT_FOUND', 'attempt question delivery is not available.');
        }

        return response()->json(
            $this->ownerBank->publicQuestionPayload($attempt, (int) $index)
        );
    }

    private function resolveUserId(Request $request, OrgContext $context): ?string
    {
        $candidates = [
            $request->attributes->get('fm_user_id'),
            $request->attributes->get('user_id'),
            $context->userId(),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) || is_numeric($candidate)) {
                $value = trim((string) $candidate);
                if ($value !== '' && preg_match('/^\d+$/', $value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function resolveAnonId(Request $request, OrgContext $context): ?string
    {
        $candidates = [
            $request->attributes->get('anon_id'),
            $request->attributes->get('fm_anon_id'),
            $request->attributes->get('client_anon_id'),
            $request->input('anon_id'),
            $context->anonId(),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) || is_numeric($candidate)) {
                $value = trim((string) $candidate);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }
}
