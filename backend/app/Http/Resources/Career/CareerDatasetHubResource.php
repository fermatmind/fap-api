<?php

declare(strict_types=1);

namespace App\Http\Resources\Career;

use App\DTO\Career\CareerPublicDatasetContract;
use App\Services\Career\StructuredData\CareerStructuredDataBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CareerPublicDatasetContract
 */
final class CareerDatasetHubResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CareerPublicDatasetContract $contract */
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
        $publication = (array) ($contract['publication'] ?? []);
        $distribution = (array) ($publication['distribution'] ?? []);

        $structuredPayload = [
            'dataset_name' => $contract['dataset_name'] ?? null,
            'description' => (string) data_get($contract, 'collection_summary.member_kind', 'career_job_detail').' public dataset hub',
            'url' => $distribution['documentation_url'] ?? null,
            'license' => $publication['license'] ?? null,
            'publisher' => $publication['publisher'] ?? null,
            'distribution' => $distribution,
            'keywords' => ['career', 'dataset', 'occupations'],
        ];

        $structured = app(CareerStructuredDataBuilder::class)->build('career_dataset_hub', $structuredPayload);
        $fragments = is_array($structured['fragments'] ?? null) ? $structured['fragments'] : [];

        return [
            'dataset' => is_array($fragments['dataset'] ?? null) ? $fragments['dataset'] : [],
            'breadcrumb_list' => is_array($fragments['breadcrumb_list'] ?? null) ? $fragments['breadcrumb_list'] : [],
        ];
    }
}
