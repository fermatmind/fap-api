<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\CareerFirstWaveOccupationCompanionLinksSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerFirstWaveOccupationCompanionLinksSummary
 */
final class CareerFirstWaveOccupationCompanionLinksSummaryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerFirstWaveOccupationCompanionLinksSummary $summary */
        $summary = $this->resource;

        return $summary->toArray();
    }
}
