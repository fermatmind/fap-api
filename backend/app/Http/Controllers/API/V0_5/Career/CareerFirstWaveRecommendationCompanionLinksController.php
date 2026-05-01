<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Domain\Career\Publish\CareerFirstWaveRecommendationCompanionLinksService;
use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerFirstWaveRecommendationCompanionLinksSummaryResource;
use App\Services\Scale\PublicScaleInputGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CareerFirstWaveRecommendationCompanionLinksController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerFirstWaveRecommendationCompanionLinksService $summaryService,
        private readonly PublicScaleInputGuard $publicInputGuard,
    ) {}

    /**
     * Existing B38 endpoint, additively extended for public-safe recommendation support links,
     * including canonical topic support rows for recommendation subjects only.
     */
    public function show(Request $request, string $type): JsonResponse|CareerFirstWaveRecommendationCompanionLinksSummaryResource
    {
        $summary = $this->summaryService->buildByType($type, $this->resolveLocale($request));

        if ($summary === null) {
            return $this->notFoundResponse('career recommendation companion links unavailable.');
        }

        return new CareerFirstWaveRecommendationCompanionLinksSummaryResource($summary);
    }

    private function resolveLocale(Request $request): string
    {
        $locale = $this->publicInputGuard->normalizeRequestedLocale($request, 'en');

        return str_starts_with(strtolower($locale), 'zh') ? 'zh-CN' : 'en';
    }
}
