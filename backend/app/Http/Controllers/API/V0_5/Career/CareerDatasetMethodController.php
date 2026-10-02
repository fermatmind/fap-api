<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerDatasetMethodResource;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CareerDatasetMethodController extends Controller
{
    public function __construct(
        private readonly PublicCareerAuthorityResponseCache $responseCache,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $locale = $request->query('locale', 'en');

        return response()->json(CareerDatasetMethodResource::localizePayload(
            $this->responseCache->datasetMethodPayload(),
            is_string($locale) ? $locale : 'en',
        ));
    }
}
