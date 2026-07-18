<?php

declare(strict_types=1);

namespace Tests\Fixtures\Career;

use App\Domain\Career\Publish\CareerJobDetailExposureReadiness;

final class CareerJobDetailExposureReadinessFixture implements CareerJobDetailExposureReadiness
{
    /**
     * @param  array<string, string>  $classifications
     */
    public function __construct(
        private readonly string $defaultClassification = 'ready_active',
        private readonly array $classifications = [],
        private readonly array $exposureReady = [],
    ) {}

    public function jobDetailCacheReadiness(string $slug, string $publicLocale = 'zh-CN'): array
    {
        $key = strtolower(trim($slug)).'|'.$this->normalizeLocale($publicLocale);
        $classification = $this->classifications[$key] ?? $this->defaultClassification;
        $ready = in_array($classification, ['ready_active', 'ready_lkg', 'legacy_migratable'], true);

        return [
            'classification' => $classification,
            'payload' => $ready ? ['fixture' => true] : null,
            'version' => $ready ? 'fixture-v1' : null,
        ];
    }

    public function jobDetailCacheIsReady(string $slug, string $publicLocale = 'zh-CN'): bool
    {
        $key = strtolower(trim($slug)).'|'.$this->normalizeLocale($publicLocale);
        if (array_key_exists($key, $this->exposureReady)) {
            return $this->exposureReady[$key] === true;
        }

        return in_array(
            $this->jobDetailCacheReadiness($slug, $publicLocale)['classification'],
            ['ready_active', 'ready_lkg', 'legacy_migratable'],
            true,
        );
    }

    public function jobDetailProjectionItemIsPublished(?array $item): bool
    {
        return is_array($item)
            && ($item['runtime_publish_state'] ?? null) === 'published'
            && ($item['detail_route_enabled'] ?? false) === true
            && ($item['robots_indexable'] ?? false) === true
            && ($item['release_gate_pass'] ?? false) === true;
    }

    private function normalizeLocale(string $locale): string
    {
        return str_starts_with(strtolower(trim($locale)), 'zh') ? 'zh-CN' : 'en';
    }
}
