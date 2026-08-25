<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

final class SeoTechnicalHealthUiContract
{
    /**
     * SEO-PLATFORM-07 has not published its unified production read model yet.
     * Keep every metric withheld until that authoritative contract is available.
     *
     * @return array{
     *     state:string,
     *     trust:list<array{label_key:string,state:string,value:null,detail_key:string}>,
     *     evidence:list<string>
     * }
     */
    public static function unavailableSnapshot(): array
    {
        return [
            'state' => SeoOperationsUiState::PRODUCTION_UNPROVEN,
            'trust' => [
                self::trustItem('runtime_slo', SeoOperationsUiState::PRODUCTION_UNPROVEN),
                self::trustItem('public_urls', SeoOperationsUiState::PRODUCTION_UNPROVEN),
                self::trustItem('latest_crawl', SeoOperationsUiState::UNAVAILABLE),
                self::trustItem('cache_revision', SeoOperationsUiState::PRODUCTION_UNPROVEN),
            ],
            'evidence' => [
                'http',
                'rendered_robots',
                'url_truth',
                'canonical_sitemap',
                'authority_revision',
            ],
        ];
    }

    /** @return array{label_key:string,state:string,value:null,detail_key:string} */
    private static function trustItem(string $key, string $state): array
    {
        return [
            'label_key' => $key,
            'state' => $state,
            'value' => null,
            'detail_key' => $key.'_hint',
        ];
    }
}
