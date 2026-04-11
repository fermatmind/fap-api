<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\CareerTransitionPreviewBundle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerTransitionPreviewBundle
 */
final class CareerTransitionPreviewResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerTransitionPreviewBundle $bundle */
        $bundle = $this->resource;

        return $bundle->toArray();
    }
}
