<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Exceptions\Api\ApiProblemException;
use App\Http\Controllers\API\V0_3\Concerns\ResolvesAttemptOwnership;
use App\Http\Controllers\Controller;
use App\Http\Requests\V0_3\AttemptEmailBindingRequest;
use App\Models\Attempt;
use App\Services\Attempts\AttemptEmailBindingService;
use Illuminate\Http\JsonResponse;

final class AttemptEmailBindingController extends Controller
{
    use ResolvesAttemptOwnership;

    public function __construct(
        private readonly AttemptEmailBindingService $bindings,
    ) {}

    /**
     * POST /api/v0.3/attempts/{id}/email-bind
     */
    public function store(AttemptEmailBindingRequest $request, string $id): JsonResponse
    {
        $attempt = $this->ownedAttemptQuery($request, $id)->first();
        if (! $attempt instanceof Attempt) {
            throw new ApiProblemException(404, 'RESOURCE_NOT_FOUND', 'attempt not found.');
        }

        /** @var array{email:string,locale?:string|null,surface?:string|null} $payload */
        $payload = $request->validated();

        $result = $this->bindings->bind(
            $attempt,
            (string) $payload['email'],
            $this->resolveUserId($request),
            $this->resolveAnonId($request),
            [
                'locale' => $payload['locale'] ?? null,
                'surface' => $payload['surface'] ?? 'result_gate',
            ],
        );

        return response()->json($result);
    }
}
