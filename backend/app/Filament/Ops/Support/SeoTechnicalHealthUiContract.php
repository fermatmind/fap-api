<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Services\SeoIntel\OpsDashboard\SeoTechnicalHealthReadService;
use Throwable;

final class SeoTechnicalHealthUiContract
{
    /**
     * Fail closed when the unified production read model cannot be read.
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

    /** @return array<string,mixed> */
    public static function snapshot(): array
    {
        try {
            $readModel = app(SeoTechnicalHealthReadService::class)->read();
            $slotCount = data_get($readModel, 'metrics.scheduled_slot_count');
            $crawlerHits = data_get($readModel, 'metrics.crawler_hit_count');

            return $readModel + [
                'trust' => [
                    self::metricTrustItem('runtime_slo', $slotCount, (string) ($readModel['state'] ?? SeoOperationsUiState::PRODUCTION_UNPROVEN)),
                    self::trustItem('public_urls', SeoOperationsUiState::UNAVAILABLE),
                    self::metricTrustItem('latest_crawl', $crawlerHits),
                    self::trustItem('cache_revision', SeoOperationsUiState::UNAVAILABLE),
                ],
                'evidence' => ['http', 'rendered_robots', 'url_truth', 'canonical_sitemap', 'authority_revision'],
            ];
        } catch (Throwable) {
            return self::unavailableSnapshot();
        }
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

    /** @return array{label_key:string,state:string,value:int|null,detail_key:string} */
    private static function metricTrustItem(string $key, mixed $value, ?string $verifiedState = null): array
    {
        $numeric = is_numeric($value) ? (int) $value : null;

        return [
            'label_key' => $key,
            'state' => $numeric === null
                ? SeoOperationsUiState::UNAVAILABLE
                : ($numeric === 0 ? SeoOperationsUiState::VERIFIED_ZERO : ($verifiedState ?? SeoOperationsUiState::PRODUCTION_HEALTHY)),
            'value' => $numeric,
            'detail_key' => $key.'_hint',
        ];
    }
}
