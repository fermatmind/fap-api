<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\CareerFirstWaveRecommendationCompanionLinksSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerFirstWaveRecommendationCompanionLinksSummary
 */
final class CareerFirstWaveRecommendationCompanionLinksSummaryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerFirstWaveRecommendationCompanionLinksSummary $summary */
        $summary = $this->resource;

        return $summary->toArray();
    }
}
