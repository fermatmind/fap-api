<?php

declare(strict_types=1);

namespace App\Services\Career\Governance;

use App\Models\CareerJobDisplayAsset;
use Illuminate\Database\QueryException;

final class CareerDisplayAssetLineageReporter
{
    public const VERSION = 'career_display_asset_lineage_v0.1';

    /**
     * @return list<array<string, mixed>>
     */
    public function reportForSlugs(array $slugs): array
    {
        return array_map(fn (string $slug): array => $this->reportForSlug($slug), $slugs);
    }

    /**
     * @return array<string, mixed>
     */
    private function reportForSlug(string $slug): array
    {
        try {
            $asset = CareerJobDisplayAsset::query()
                ->where('canonical_slug', $slug)
                ->where('asset_version', 'v4.2')
                ->latest('updated_at')
                ->first();
        } catch (QueryException) {
            return [
                'canonical_slug' => $slug,
                'display_asset_found' => false,
                'display_asset_id' => null,
                'lineage_status' => 'display_asset_store_unavailable',
                'lineage_complete' => false,
                'blockers' => ['display_asset_store_unavailable'],
                'rollback_target' => null,
                'rollback_command_note' => 'Display asset store could not be read; retry in the target environment before any rollback decision.',
            ];
        }

        if (! $asset instanceof CareerJobDisplayAsset) {
            return [
                'canonical_slug' => $slug,
                'display_asset_found' => false,
                'display_asset_id' => null,
                'lineage_status' => 'missing_display_asset',
                'lineage_complete' => false,
                'blockers' => ['missing_display_asset'],
                'rollback_target' => null,
                'rollback_command_note' => 'No display asset row exists for this slug/version.',
            ];
        }

        $metadata = $asset->metadata_json ?? [];
        $lineage = $this->lineage($asset, is_array($metadata) ? $metadata : []);
        $blockers = $this->blockers($lineage);

        return [
            'canonical_slug' => $slug,
            'display_asset_found' => true,
            'display_asset_id' => $asset->id,
            'asset_version' => $asset->asset_version,
            'surface_version' => $asset->surface_version,
            'template_version' => $asset->template_version,
            'asset_type' => $asset->asset_type,
            'status' => $asset->status,
            'lineage_status' => $blockers === [] ? 'complete' : 'incomplete',
            'lineage_complete' => $blockers === [],
            'lineage' => $lineage,
            'api_surface_hash' => $this->apiSurfaceHash($asset),
            'web_validation_status' => data_get($metadata, 'web_validation.status'),
            'created_at' => $asset->created_at?->toISOString(),
            'updated_at' => $asset->updated_at?->toISOString(),
            'rollback_target' => [
                'canonical_slug' => $asset->canonical_slug,
                'asset_version' => $asset->asset_version,
                'display_asset_id' => $asset->id,
            ],
            'rollback_command_note' => 'Rollback must be a guarded follow-up that deletes or supersedes only this display asset version; this validator is read-only.',
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function lineage(CareerJobDisplayAsset $asset, array $metadata): array
    {
        return [
            'workbook_sha256' => data_get($metadata, 'workbook_sha256'),
            'workbook_path_or_basename' => data_get($metadata, 'workbook_basename') ?? data_get($metadata, 'workbook_path'),
            'workbook_row_number' => data_get($metadata, 'row_number'),
            'canonical_slug' => $asset->canonical_slug,
            'asset_version' => $asset->asset_version,
            'import_command' => data_get($metadata, 'command'),
            'import_run_id' => $asset->import_run_id,
            'display_asset_id' => $asset->id,
            'mapper_version' => data_get($metadata, 'mapper_version'),
            'validator_version' => data_get($metadata, 'validator_version'),
            'display_import_stage' => data_get($metadata, 'display_import_stage'),
            'release_gates_internal_only' => is_array(data_get($metadata, 'release_gates')),
        ];
    }

    /**
     * @param  array<string, mixed>  $lineage
     * @return list<string>
     */
    private function blockers(array $lineage): array
    {
        $required = [
            'workbook_sha256',
            'workbook_path_or_basename',
            'workbook_row_number',
            'canonical_slug',
            'asset_version',
            'import_command',
            'display_asset_id',
            'mapper_version',
            'validator_version',
        ];

        $blockers = [];
        foreach ($required as $key) {
            if ($lineage[$key] === null || $lineage[$key] === '') {
                $blockers[] = 'missing_'.$key;
            }
        }

        return $blockers;
    }

    private function apiSurfaceHash(CareerJobDisplayAsset $asset): string
    {
        $payload = [
            'surface_version' => $asset->surface_version,
            'asset_version' => $asset->asset_version,
            'template_version' => $asset->template_version,
            'component_order' => $asset->component_order_json,
            'page' => $asset->page_payload_json,
            'seo' => $asset->seo_payload_json,
            'sources' => $asset->sources_json,
            'structured_data' => $asset->structured_data_json,
            'implementation_contract' => $asset->implementation_contract_json,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
