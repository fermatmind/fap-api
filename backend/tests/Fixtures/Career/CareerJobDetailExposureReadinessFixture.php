<?php

declare(strict_types=1);

namespace Tests\Fixtures\Career;

use App\Domain\Career\Publish\CareerJobDetailExposureReadiness;
use App\Domain\Career\Publish\CareerJobDetailExposureReadinessBatch;

final class CareerJobDetailExposureReadinessFixture implements CareerJobDetailExposureReadiness, CareerJobDetailExposureReadinessBatch
{
    public int $singleCallCount = 0;

    public int $batchCallCount = 0;

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
        $this->singleCallCount++;
        $key = strtolower(trim($slug)).'|'.$this->normalizeLocale($publicLocale);
        $classification = $this->classifications[$key] ?? $this->defaultClassification;
        $ready = in_array($classification, ['ready_active', 'ready_lkg', 'legacy_migratable'], true);

        return [
            'classification' => $classification,
            'payload' => $ready ? ['fixture' => true] : null,
            'version' => $ready ? 'fixture-v1' : null,
        ];
    }

    public function jobDetailCacheReadinessBatch(array $targets, bool $includePayload = true): array
    {
        $this->batchCallCount++;
        $result = [];
        foreach ($targets as $target) {
            $slug = strtolower(trim((string) ($target['slug'] ?? '')));
            $locale = str_starts_with(strtolower(trim((string) ($target['locale'] ?? ''))), 'zh') ? 'zh-CN' : 'en';
            $key = $slug.'|'.$locale;
            $classification = $this->classifications[$key]
                ?? $this->classifications[$slug.'|'.$this->normalizeLocale($locale)]
                ?? $this->defaultClassification;
            $ready = in_array($classification, ['ready_active', 'ready_lkg', 'legacy_migratable'], true);
            $result[$key] = [
                'classification' => $classification,
                'payload' => $ready && $includePayload ? ['fixture' => true] : null,
                'version' => $ready ? 'fixture-v1' : null,
            ];
        }

        return $result;
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
