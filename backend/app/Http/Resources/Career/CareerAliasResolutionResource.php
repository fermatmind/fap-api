<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\CareerAliasResolutionBundle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerAliasResolutionBundle
 */
final class CareerAliasResolutionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerAliasResolutionBundle $bundle */
        $bundle = $this->resource;

        return $bundle->toArray();
    }
}
