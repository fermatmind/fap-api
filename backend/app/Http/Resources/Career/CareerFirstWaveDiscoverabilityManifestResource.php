<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\CareerFirstWaveDiscoverabilityManifest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerFirstWaveDiscoverabilityManifest
 */
final class CareerFirstWaveDiscoverabilityManifestResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerFirstWaveDiscoverabilityManifest $manifest */
        $manifest = $this->resource;

        return $manifest->toArray();
    }
}
