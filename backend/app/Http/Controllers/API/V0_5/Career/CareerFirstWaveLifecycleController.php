<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Domain\Career\Publish\CareerFirstWaveLifecycleSummaryService;
use App\Http\Controllers\Controller;
use App\Services\Career\PublicCareerVisibilityPayloadFilter;
use Illuminate\Http\JsonResponse;

final class CareerFirstWaveLifecycleController extends Controller
{
    public function __construct(
        private readonly CareerFirstWaveLifecycleSummaryService $summaryService,
        private readonly PublicCareerVisibilityPayloadFilter $visibilityFilter,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(
            $this->visibilityFilter->lifecycle($this->summaryService->build()->toArray())
        );
    }
}
