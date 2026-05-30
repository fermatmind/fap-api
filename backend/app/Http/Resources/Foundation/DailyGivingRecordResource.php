<?php

declare(strict_types=1);

namespace App\Http\Resources\Foundation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property \App\Models\DailyGivingRecord $resource
 */
final class DailyGivingRecordResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return $this->resource->toPublicArray();
    }
}
