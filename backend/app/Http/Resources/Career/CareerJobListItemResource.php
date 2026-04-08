<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\CareerJobListItemBundle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerJobListItemBundle
 */
final class CareerJobListItemResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerJobListItemBundle $bundle */
        $bundle = $this->resource;

        return $bundle->toArray();
    }
}
