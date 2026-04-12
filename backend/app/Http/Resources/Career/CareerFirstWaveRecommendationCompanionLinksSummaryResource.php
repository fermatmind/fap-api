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
     * @return array{
     *     summary_kind:string,
     *     summary_version:string,
     *     scope:string,
     *     subject_kind:string,
     *     subject_identity:array<string, mixed>,
     *     counts:array{total:int,job_detail:int,family_hub:int,test_landing:int,topic_detail:int},
     *     companion_links:list<array<string, mixed>>
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var CareerFirstWaveRecommendationCompanionLinksSummary $summary */
        $summary = $this->resource;

        return $summary->toArray();
    }
}
