<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Domain\Career\Publish\FirstWaveReadinessSummaryService;
use App\Http\Controllers\Controller;
use App\Services\Career\PublicCareerVisibilityPayloadFilter;
use Illuminate\Http\JsonResponse;

final class CareerFirstWaveReadinessController extends Controller
{
    public function __construct(
        private readonly FirstWaveReadinessSummaryService $summaryService,
        private readonly PublicCareerVisibilityPayloadFilter $visibilityFilter,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(
            $this->visibilityFilter->readiness($this->summaryService->build()->toArray())
        );
    }
}
