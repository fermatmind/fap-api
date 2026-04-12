<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\CareerFirstWaveRolloutQueueSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerFirstWaveRolloutQueueSummary
 */
final class CareerFirstWaveRolloutQueueSummaryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerFirstWaveRolloutQueueSummary $summary */
        $summary = $this->resource;

        return $summary->toArray();
    }
}
