<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

final class CareerCanonicalRuntimeTruthValidator
{
    /**
     * @return array<string, mixed>
     */
    public function validate(array $truth): array
    {
        $items = is_array($truth['items'] ?? null) ? $truth['items'] : [];
        $failures = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $failures[] = [
                    'index' => $index,
                    'slug' => null,
                    'locale' => null,
                    'reason' => 'truth_item_not_object',
                ];

                continue;
            }

            foreach ($this->validateItem($item, $index) as $failure) {
                $failures[] = $failure;
            }
        }

        return [
            'status' => $failures === [] ? 'pass' : 'blocked',
            'counts' => [
                'items' => count($items),
                'fully_live' => $this->countTrue($items, 'fully_live'),
                'failures' => count($failures),
                'projection_only' => $this->countFailures($failures, 'projection_only'),
                'dataset_only' => $this->countFailures($failures, 'dataset_only'),
                'search_only' => $this->countFailures($failures, 'search_only'),
                'route_only' => $this->countFailures($failures, 'route_only'),
                'sitemap_only' => $this->countFailures($failures, 'sitemap_only'),
                'llms_only' => $this->countFailures($failures, 'llms_only'),
                'llms_full_only' => $this->countFailures($failures, 'llms_full_only'),
            ],
            'failures' => $failures,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateItem(array $item, int $index): array
    {
        $failures = [];
        $slug = strtolower(trim((string) ($item['slug'] ?? '')));
        $locale = strtolower(trim((string) ($item['locale'] ?? '')));
        $published = ($item['projection_state'] ?? null) === CareerRuntimePublishProjectionService::STATE_PUBLISHED;
        $releaseGatePass = (bool) ($item['release_gate_pass'] ?? false);
        $canonicalProjected = $published && $releaseGatePass;
        $surfaces = [
            'dataset' => (bool) ($item['dataset_visible'] ?? false),
            'search' => (bool) ($item['search_visible'] ?? false),
            'route' => (bool) ($item['route_exists'] ?? false) && (bool) ($item['final_200'] ?? false),
            'sitemap' => (bool) ($item['sitemap_live'] ?? false),
            'llms' => (bool) ($item['llms_live'] ?? false),
            'llms_full' => (bool) ($item['llms_full_live'] ?? false),
        ];
        $metadataReady = (bool) ($item['robots_indexable'] ?? false)
            && (bool) ($item['canonical_self'] ?? false);
        $fullyLive = $canonicalProjected
            && $metadataReady
            && ! in_array(false, $surfaces, true);

        if ($slug === '') {
            $failures[] = $this->failure($index, $slug, $locale, 'missing_slug', $surfaces);
        }
        if ($locale === '') {
            $failures[] = $this->failure($index, $slug, $locale, 'missing_locale', $surfaces);
        }

        if ($canonicalProjected && ! $fullyLive) {
            $failures[] = $this->failure($index, $slug, $locale, 'projection_only', $surfaces);
        }

        foreach ($surfaces as $surface => $isLive) {
            if ($isLive && ! $fullyLive) {
                $failures[] = $this->failure($index, $slug, $locale, $surface.'_only', $surfaces);
            }
        }

        if ((bool) ($item['fully_live'] ?? false) !== $fullyLive) {
            $failures[] = $this->failure($index, $slug, $locale, 'fully_live_flag_mismatch', $surfaces);
        }

        return $failures;
    }

    /**
     * @param  array<string, bool>  $surfaces
     * @return array<string, mixed>
     */
    private function failure(int $index, string $slug, string $locale, string $reason, array $surfaces): array
    {
        return [
            'index' => $index,
            'slug' => $slug !== '' ? $slug : null,
            'locale' => $locale !== '' ? $locale : null,
            'reason' => $reason,
            'surfaces' => $surfaces,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function countTrue(array $items, string $field): int
    {
        return count(array_filter($items, static fn (array $item): bool => (bool) ($item[$field] ?? false)));
    }

    /**
     * @param  list<array<string, mixed>>  $failures
     */
    private function countFailures(array $failures, string $reason): int
    {
        return count(array_filter($failures, static fn (array $failure): bool => ($failure['reason'] ?? null) === $reason));
    }
}
