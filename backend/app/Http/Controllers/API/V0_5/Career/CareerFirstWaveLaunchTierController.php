<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Domain\Career\Publish\CareerFirstWaveLaunchTierSummaryService;
use App\Http\Controllers\Controller;
use App\Services\Career\PublicCareerVisibilityPayloadFilter;
use Illuminate\Http\JsonResponse;

final class CareerFirstWaveLaunchTierController extends Controller
{
    public function __construct(
        private readonly CareerFirstWaveLaunchTierSummaryService $summaryService,
        private readonly PublicCareerVisibilityPayloadFilter $visibilityFilter,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(
            $this->visibilityFilter->launchTier($this->summaryService->build()->toArray())
        );
    }
}
