<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\CareerExplainabilitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerExplainabilitySummary
 */
final class CareerExplainabilitySummaryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerExplainabilitySummary $summary */
        $summary = $this->resource;

        return $summary->toArray();
    }
}
