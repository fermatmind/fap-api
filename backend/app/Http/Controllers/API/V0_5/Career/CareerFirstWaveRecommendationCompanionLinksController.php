<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Domain\Career\Publish\CareerFirstWaveRecommendationCompanionLinksService;
use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerFirstWaveRecommendationCompanionLinksSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CareerFirstWaveRecommendationCompanionLinksController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerFirstWaveRecommendationCompanionLinksService $summaryService,
    ) {}

    /**
     * Existing B38 endpoint, additively extended for public-safe recommendation support links.
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
        $raw = trim((string) (
            $request->query('locale')
            ?? $request->header('X-FAP-Locale')
            ?? 'en'
        ));

        $normalized = strtolower(str_replace('_', '-', $raw));

        return str_starts_with($normalized, 'zh') ? 'zh-CN' : 'en';
    }
}
