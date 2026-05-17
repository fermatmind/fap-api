<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Sources;

final class BackendAuthorityUrlTruthSource implements UrlTruthInventorySource
{
    public function candidates(): array
    {
        return [];
    }

    public function metadata(): array
    {
        return [
            'source' => 'backend_authority_adapter_skeleton',
            'backend_sitemap_source_available' => true,
            'fixture_driven_until_authority_adapter_is_wired' => true,
            'fetches_public_html' => false,
            'external_api_calls' => false,
            'node2_local_laravel_data_source' => false,
            'frontend_fallback_data_source' => false,
            'static_llms_fallback_graph_truth' => false,
        ];
    }
}
