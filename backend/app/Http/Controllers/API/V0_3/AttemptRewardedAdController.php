<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Exceptions\Api\ApiProblemException;
use App\Http\Controllers\API\V0_3\Concerns\ResolvesAttemptOwnership;
use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Services\Commerce\RewardedAdUnlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AttemptRewardedAdController extends Controller
{
    use ResolvesAttemptOwnership;

    public function __construct(private readonly RewardedAdUnlockService $rewardedAds) {}

    public function store(Request $request, string $id): JsonResponse
    {
        $payload = $request->validate([
            'ad_unit_id' => ['required', 'string', 'max:136'],
            'attempt_id' => ['prohibited'],
            'is_ended' => ['prohibited'],
            'proof' => ['prohibited'],
            'receipt' => ['prohibited'],
        ]);

        return $this->respond($this->rewardedAds->create(
            $this->ownedAttempt($request, $id),
            $this->resolveUserId($request),
            $this->resolveAnonId($request),
            (string) $payload['ad_unit_id'],
        ));
    }

    public function show(Request $request, string $id, string $session_id): JsonResponse
    {
        return $this->respond($this->rewardedAds->show(
            $this->ownedAttempt($request, $id),
            $this->resolveUserId($request),
            $this->resolveAnonId($request),
            $session_id,
        ));
    }

    public function complete(Request $request, string $id, string $session_id): JsonResponse
    {
        $payload = $request->validate([
            'ad_unit_id' => ['required', 'string', 'max:136'],
            'is_ended' => ['required', 'boolean'],
            'attempt_id' => ['prohibited'],
            'proof' => ['prohibited'],
            'receipt' => ['prohibited'],
        ]);
        $result = $this->rewardedAds->complete(
            $this->ownedAttempt($request, $id),
            $this->resolveUserId($request),
            $this->resolveAnonId($request),
            $session_id,
            (string) $payload['ad_unit_id'],
            (bool) $payload['is_ended'],
        );
        if (($result['ok'] ?? false) !== true) {
            return $this->respond($result);
        }

        $reportAccess = app(AttemptReadController::class)->reportAccess($request, $id);

        return response()->json(array_merge($result, [
            'report_access' => $reportAccess->getData(true),
        ]));
    }

    private function ownedAttempt(Request $request, string $id): Attempt
    {
        /** @var Attempt|null $attempt */
        $attempt = $this->ownedAttemptQuery($request, $id)->first();
        if (! $attempt instanceof Attempt) {
            throw new ApiProblemException(404, 'RESOURCE_NOT_FOUND', 'attempt not found.');
        }

        return $attempt;
    }

    /** @param array<string,mixed> $result */
    private function respond(array $result): JsonResponse
    {
        return response()->json($result, ($result['ok'] ?? false) === true ? 200 : (int) ($result['status'] ?? 400));
    }
}
