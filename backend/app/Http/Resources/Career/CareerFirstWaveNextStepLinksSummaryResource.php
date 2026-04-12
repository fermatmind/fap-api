<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\CareerFirstWaveNextStepLinksSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerFirstWaveNextStepLinksSummary
 */
final class CareerFirstWaveNextStepLinksSummaryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerFirstWaveNextStepLinksSummary $summary */
        $summary = $this->resource;

        return $summary->toArray();
    }
}
