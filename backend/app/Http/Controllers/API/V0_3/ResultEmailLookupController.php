<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Http\Requests\V0_3\ResultEmailLookupRequest;
use App\Services\Results\ResultEmailLookupService;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;

final class ResultEmailLookupController extends Controller
{
    public function __construct(
        private readonly ResultEmailLookupService $lookup,
        private readonly OrgContext $orgContext,
    ) {}

    /**
     * POST /api/v0.3/results/lookup-by-email
     */
    public function store(ResultEmailLookupRequest $request): JsonResponse
    {
        /** @var array{email:string,locale?:string|null} $payload */
        $payload = $request->validated();

        return response()->json($this->lookup->lookup(
            (string) $payload['email'],
            (int) $this->orgContext->orgId(),
            $payload['locale'] ?? null,
        ));
    }
}
