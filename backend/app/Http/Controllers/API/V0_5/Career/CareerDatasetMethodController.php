<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Controller;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Http\JsonResponse;

final class CareerDatasetMethodController extends Controller
{
    public function __construct(
        private readonly PublicCareerAuthorityResponseCache $responseCache,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->responseCache->datasetMethodPayload());
    }
}
