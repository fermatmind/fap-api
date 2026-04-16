<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\CareerThresholdExperimentAuthority;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerThresholdExperimentAuthority
 */
final class CareerRuntimeConfigResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerThresholdExperimentAuthority $authority */
        $authority = $this->resource;

        return $authority->toArray();
    }
}
