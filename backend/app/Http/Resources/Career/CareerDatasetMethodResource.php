<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\CareerPublicDatasetMethodContract;
use App\Services\Career\StructuredData\CareerStructuredDataBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerPublicDatasetMethodContract
 */
final class CareerDatasetMethodResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerPublicDatasetMethodContract $contract */
        $contract = $this->resource;
        $payload = $contract->toArray();

        return array_merge($payload, [
            'structured_data' => $this->buildStructuredData($payload),
        ]);
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array<string, mixed>
     */
    private function buildStructuredData(array $contract): array
    {
        $structured = app(CareerStructuredDataBuilder::class)->build('career_dataset_method', [
            'title' => $contract['title'] ?? null,
            'summary' => $contract['summary'] ?? null,
            'url' => $contract['method_url'] ?? null,
        ]);
        $fragments = is_array($structured['fragments'] ?? null) ? $structured['fragments'] : [];

        return [
            'article' => is_array($fragments['article'] ?? null) ? $fragments['article'] : [],
            'breadcrumb_list' => is_array($fragments['breadcrumb_list'] ?? null) ? $fragments['breadcrumb_list'] : [],
        ];
    }
}
