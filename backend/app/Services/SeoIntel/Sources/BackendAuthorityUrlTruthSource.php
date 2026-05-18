<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Sources;

use App\Services\Scale\ScaleRegistry;
use App\Services\SeoIntel\UrlTruthInventoryRecord;
use Illuminate\Support\Carbon;

final class BackendAuthorityUrlTruthSource implements UrlTruthInventorySource
{
    private bool $scaleCatalogAttempted = false;

    private bool $scaleCatalogAvailable = false;

    private ?string $scaleCatalogUnavailableReason = null;

    /**
     * @return list<UrlTruthInventoryRecord>
     */
    public function candidates(): array
    {
        $records = $this->scaleCatalogCandidates();

        if ($records !== []) {
            return $records;
        }

        return $this->configuredBackendAuthorityCandidates();
    }

    public function metadata(): array
    {
        return [
            'source' => 'backend_authority_url_truth_candidate_source',
            'source_authority' => (string) config('seo_intel.url_truth_inventory.source_authority', 'backend_sitemap_source'),
            'backend_sitemap_source_available' => true,
            'scale_catalog_attempted' => $this->scaleCatalogAttempted,
            'scale_catalog_available' => $this->scaleCatalogAvailable,
            'scale_catalog_unavailable_reason' => $this->scaleCatalogUnavailableReason,
            'configured_backend_authority_canary_available' => $this->configuredBackendAuthorityCandidates() !== [],
            'fetches_public_html' => false,
            'external_api_calls' => false,
            'node2_local_laravel_data_source' => false,
            'node2_local_db_data_source' => false,
            'frontend_fallback_data_source' => false,
            'static_llms_fallback_graph_truth' => false,
            'synthetic_production_fixture' => false,
        ];
    }

    /**
     * @return list<UrlTruthInventoryRecord>
     */
    private function scaleCatalogCandidates(): array
    {
        $this->scaleCatalogAttempted = true;

        try {
            $rows = app(ScaleRegistry::class)->listActivePublic(0);
        } catch (\Throwable $exception) {
            $this->scaleCatalogUnavailableReason = 'scale_catalog_unavailable';

            return [];
        }

        $records = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = trim((string) ($row['primary_slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $updatedAt = $this->parseUpdatedAt($row['updated_at'] ?? null);

            $records[] = new UrlTruthInventoryRecord(
                canonicalUrl: $this->canonicalUrl('/en/tests/'.$slug),
                locale: 'en',
                pageEntityType: 'test_detail',
                entityIdOrSlug: $slug,
                sourceAuthority: 'scale_catalog',
                indexabilityState: 'indexable',
                lastmodAt: $updatedAt,
                lastmodSource: 'scales_registry.updated_at',
                cluster: 'tests',
                entitySource: 'scales_registry',
                authorityStatus: 'observed',
                sourceUpdatedAt: $updatedAt,
                metadata: [
                    'source_table_hash' => hash('sha256', 'scales_registry'),
                    'scale_code_hash' => hash('sha256', (string) ($row['code'] ?? $slug)),
                ],
                attributes: [
                    'source_authority' => 'scale_catalog',
                ],
            );
        }

        $this->scaleCatalogAvailable = $records !== [];
        if (! $this->scaleCatalogAvailable) {
            $this->scaleCatalogUnavailableReason = 'scale_catalog_empty';
        }

        return $records;
    }

    /**
     * @return list<UrlTruthInventoryRecord>
     */
    private function configuredBackendAuthorityCandidates(): array
    {
        $records = [];
        $candidates = config('seo_intel.url_truth_inventory.backend_authority_canary_candidates', []);

        if (! is_array($candidates)) {
            return [];
        }

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $path = trim((string) ($candidate['path'] ?? ''));
            $pageEntityType = trim((string) ($candidate['page_entity_type'] ?? ''));
            $sourceAuthority = trim((string) ($candidate['source_authority'] ?? ''));

            if ($path === '' || $pageEntityType === '' || $sourceAuthority === '') {
                continue;
            }

            $records[] = new UrlTruthInventoryRecord(
                canonicalUrl: $this->canonicalUrl($path),
                locale: (string) ($candidate['locale'] ?? 'en'),
                pageEntityType: $pageEntityType,
                entityIdOrSlug: (string) ($candidate['entity_id_or_slug'] ?? $path),
                sourceAuthority: $sourceAuthority,
                indexabilityState: 'indexable',
                lastmodSource: 'backend_authority_canary_contract',
                cluster: (string) ($candidate['cluster'] ?? 'public_surface'),
                entitySource: 'backend_authority',
                authorityStatus: 'canary_contract',
                metadata: [
                    'source_contract' => 'backend_authority_canary_candidates',
                    'path_hash' => hash('sha256', $path),
                ],
                attributes: [
                    'source_authority' => $sourceAuthority,
                ],
            );
        }

        return $records;
    }

    private function canonicalUrl(string $path): string
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', 'https://fermatmind.com')), '/');
        if ($baseUrl === '') {
            $baseUrl = 'https://fermatmind.com';
        }

        return $baseUrl.'/'.ltrim($path, '/');
    }

    private function parseUpdatedAt(mixed $value): ?Carbon
    {
        try {
            if ($value === null || $value === '') {
                return null;
            }

            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
