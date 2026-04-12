<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Domain\Career\Publish\CareerFirstWaveRecommendationCompanionLinksService;
use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerFirstWaveRecommendationCompanionLinksSummaryResource;
use Illuminate\Http\JsonResponse;

final class CareerFirstWaveRecommendationCompanionLinksController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerFirstWaveRecommendationCompanionLinksService $summaryService,
    ) {}

    public function show(string $type): JsonResponse|CareerFirstWaveRecommendationCompanionLinksSummaryResource
    {
        $summary = $this->summaryService->buildByType($type);

        if ($summary === null) {
            return $this->notFoundResponse('career recommendation companion links unavailable.');
        }

        return new CareerFirstWaveRecommendationCompanionLinksSummaryResource($summary);
    }
}
