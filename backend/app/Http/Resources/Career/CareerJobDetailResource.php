<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\CareerJobDetailBundle;
use App\Services\Career\StructuredData\CareerStructuredDataBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerJobDetailBundle
 */
final class CareerJobDetailResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerJobDetailBundle $bundle */
        $bundle = $this->resource;

        return array_merge($bundle->toArray(), [
            'structured_data' => $this->buildStructuredData($bundle),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStructuredData(CareerJobDetailBundle $bundle): array
    {
        $payload = app(CareerStructuredDataBuilder::class)->build('career_job_detail', $bundle);
        $fragments = is_array($payload['fragments'] ?? null) ? $payload['fragments'] : [];

        return [
            'occupation' => is_array($fragments['occupation'] ?? null) ? $fragments['occupation'] : [],
            'breadcrumb_list' => is_array($fragments['breadcrumb_list'] ?? null) ? $fragments['breadcrumb_list'] : [],
        ];
    }
}
