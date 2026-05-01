<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Controller;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Career\PublicCareerVisibilityPayloadFilter;
use Illuminate\Http\JsonResponse;

final class CareerLaunchGovernanceClosureController extends Controller
{
    public function __construct(
        private readonly PublicCareerAuthorityResponseCache $responseCache,
        private readonly PublicCareerVisibilityPayloadFilter $visibilityFilter,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(
            $this->visibilityFilter->launchGovernanceClosure($this->responseCache->launchGovernanceClosurePayload())
        );
    }
}
