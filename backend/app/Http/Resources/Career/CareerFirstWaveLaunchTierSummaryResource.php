<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\CareerFirstWaveLaunchTierSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerFirstWaveLaunchTierSummary
 */
final class CareerFirstWaveLaunchTierSummaryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerFirstWaveLaunchTierSummary $summary */
        $summary = $this->resource;

        return $summary->toArray();
    }
}
