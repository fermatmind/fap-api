<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Exceptions\Api\ApiProblemException;
use App\Http\Controllers\API\V0_3\Concerns\ResolvesAttemptOwnership;
use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Services\Attempts\AttemptInviteUnlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AttemptInviteUnlockController extends Controller
{
    use ResolvesAttemptOwnership;

    public function __construct(
        private readonly AttemptInviteUnlockService $inviteUnlocks,
    ) {}

    /**
     * POST /api/v0.3/attempts/{id}/invite-unlocks
     */
    public function store(Request $request, string $id): JsonResponse
    {
        $attempt = $this->resolveOwnedAttempt($request, $id);
        $result = $this->inviteUnlocks->createOrReuseInvite(
            $attempt,
            $this->resolveUserId($request),
            $this->resolveAnonId($request)
        );

        return response()->json(array_merge(['ok' => true], $result));
    }

    /**
     * GET /api/v0.3/attempts/{id}/invite-unlocks
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $attempt = $this->resolveOwnedAttempt($request, $id);
        $result = $this->inviteUnlocks->getInviteProgress($attempt);

        return response()->json(array_merge(['ok' => true], $result));
    }

    private function resolveOwnedAttempt(Request $request, string $id): Attempt
    {
        $attempt = $this->ownedAttemptQuery($request, $id)->first();
        if (! $attempt instanceof Attempt) {
            throw new ApiProblemException(404, 'RESOURCE_NOT_FOUND', 'attempt not found.');
        }

        return $attempt;
    }
}
