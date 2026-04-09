<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\FirstWaveReadinessSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FirstWaveReadinessSummary
 */
final class FirstWaveReadinessSummaryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var FirstWaveReadinessSummary $summary */
        $summary = $this->resource;

        return $summary->toArray();
    }
}
