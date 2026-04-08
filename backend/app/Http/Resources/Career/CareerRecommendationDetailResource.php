<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\CareerRecommendationDetailBundle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerRecommendationDetailBundle
 */
final class CareerRecommendationDetailResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerRecommendationDetailBundle $bundle */
        $bundle = $this->resource;

        return $bundle->toArray();
    }
}
