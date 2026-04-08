<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\CareerSearchResultBundle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerSearchResultBundle
 */
final class CareerSearchResultResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerSearchResultBundle $bundle */
        $bundle = $this->resource;

        return $bundle->toArray();
    }
}
