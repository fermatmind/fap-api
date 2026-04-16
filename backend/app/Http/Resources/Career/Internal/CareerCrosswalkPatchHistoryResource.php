<?php

declare(strict_types=1);

namespace App\Http\Resources\Career\Internal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CareerCrosswalkPatchHistoryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return is_array($this->resource) ? $this->resource : [];
    }
}
