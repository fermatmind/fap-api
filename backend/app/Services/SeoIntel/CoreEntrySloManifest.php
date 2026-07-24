<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use InvalidArgumentException;

final class CoreEntrySloManifest
{
    /**
     * @return array{
     *     schema_version:string,
     *     public_base_url:string,
     *     manifest_sha256:string,
     *     tier_order:list<string>,
     *     targets:list<array<string, mixed>>
     * }
     */
    public function resolve(): array
    {
        $config = config('seo_intel.core_entry_slo');

        if (! is_array($config)) {
            throw new InvalidArgumentException('core_entry_slo_config_missing');
        }

        $tierOrder = $this->stringList($config['tier_order'] ?? []);
        if ($tierOrder !== ['L1', 'L2', 'L3']) {
            throw new InvalidArgumentException('core_entry_slo_tier_order_invalid');
        }

        $privateSegments = array_map(
            static fn (string $value): string => strtolower($value),
            $this->stringList($config['private_path_segments'] ?? [])
        );
        if ($privateSegments === []) {
            throw new InvalidArgumentException('core_entry_slo_private_segments_missing');
        }

        $rawTargets = $config['targets'] ?? null;
        if (! is_array($rawTargets) || ! array_is_list($rawTargets) || $rawTargets === []) {
            throw new InvalidArgumentException('core_entry_slo_targets_missing');
        }

        $targets = [];
        $ids = [];
        $paths = [];

        foreach ($rawTargets as $rawTarget) {
            if (! is_array($rawTarget)) {
                throw new InvalidArgumentException('core_entry_slo_target_invalid');
            }

            $target = $this->target($rawTarget, $tierOrder, $privateSegments);
            $id = $target['id'];
            $path = $target['path'];

            if (isset($ids[$id])) {
                throw new InvalidArgumentException('core_entry_slo_target_id_duplicate');
            }
            if (isset($paths[$path])) {
                throw new InvalidArgumentException('core_entry_slo_target_path_duplicate');
            }

            $ids[$id] = true;
            $paths[$path] = true;
            $targets[] = $target;
        }

        $tierRank = array_flip($tierOrder);
        usort($targets, static function (array $left, array $right) use ($tierRank): int {
            $tierComparison = ($tierRank[$left['tier']] ?? 99) <=> ($tierRank[$right['tier']] ?? 99);

            return $tierComparison !== 0 ? $tierComparison : $left['id'] <=> $right['id'];
        });

        $manifestPayload = [
            'schema_version' => $this->string($config['schema_version'] ?? null),
            'tier_order' => $tierOrder,
            'targets' => $targets,
        ];

        return [
            ...$manifestPayload,
            'public_base_url' => $this->publicBaseUrl(),
            'manifest_sha256' => hash('sha256', json_encode($manifestPayload, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $tierOrder
     * @param  list<string>  $privateSegments
     * @return array<string, mixed>
     */
    private function target(array $raw, array $tierOrder, array $privateSegments): array
    {
        $id = $this->string($raw['id'] ?? null);
        $tier = $this->string($raw['tier'] ?? null);
        $pageFamily = $this->string($raw['page_family'] ?? null);
        $locale = $this->string($raw['locale'] ?? null);
        $path = $this->publicPath($raw['path'] ?? null, $privateSegments);
        $alternatePath = $this->publicPath($raw['alternate_path'] ?? null, $privateSegments);
        $alternateHreflang = $this->string($raw['alternate_hreflang'] ?? null);
        $authorityDependency = $this->string($raw['authority_dependency'] ?? null);
        $ttfbBudgetMs = filter_var($raw['ttfb_budget_ms'] ?? null, FILTER_VALIDATE_INT);

        if (preg_match('/^[a-z0-9_]{3,80}$/', $id) !== 1) {
            throw new InvalidArgumentException('core_entry_slo_target_id_invalid');
        }
        if (! in_array($tier, $tierOrder, true)) {
            throw new InvalidArgumentException('core_entry_slo_target_tier_invalid');
        }
        if (preg_match('/^[a-z0-9_]{3,80}$/', $pageFamily) !== 1) {
            throw new InvalidArgumentException('core_entry_slo_page_family_invalid');
        }
        if (! in_array($locale, ['en', 'zh'], true)) {
            throw new InvalidArgumentException('core_entry_slo_locale_invalid');
        }
        if (! in_array($alternateHreflang, ['en', 'zh-CN'], true)) {
            throw new InvalidArgumentException('core_entry_slo_hreflang_invalid');
        }
        if ($ttfbBudgetMs === false || $ttfbBudgetMs < 100 || $ttfbBudgetMs > 10000) {
            throw new InvalidArgumentException('core_entry_slo_ttfb_budget_invalid');
        }
        if (preg_match('/^[a-z0-9_]{3,120}$/', $authorityDependency) !== 1) {
            throw new InvalidArgumentException('core_entry_slo_authority_dependency_invalid');
        }

        return [
            'id' => $id,
            'tier' => $tier,
            'page_family' => $pageFamily,
            'locale' => $locale,
            'path' => $path,
            'path_sha256' => hash('sha256', $path),
            'alternate_path' => $alternatePath,
            'alternate_hreflang' => $alternateHreflang,
            'ttfb_budget_ms' => $ttfbBudgetMs,
            'ssr_markers' => $this->markerList($raw['ssr_markers'] ?? null),
            'primary_cta_markers' => $this->markerList($raw['primary_cta_markers'] ?? null),
            'last_known_good_markers' => $this->markerList($raw['last_known_good_markers'] ?? null),
            'minimal_shell_markers' => $this->markerList($raw['minimal_shell_markers'] ?? null),
            'authority_dependency' => $authorityDependency,
        ];
    }

    /**
     * @param  list<string>  $privateSegments
     */
    private function publicPath(mixed $value, array $privateSegments): string
    {
        $path = $this->string($value);

        if (
            ! str_starts_with($path, '/')
            || str_contains($path, "\0")
            || str_contains($path, '?')
            || str_contains($path, '#')
            || str_contains($path, '\\')
        ) {
            throw new InvalidArgumentException('core_entry_slo_public_path_invalid');
        }

        $normalized = preg_replace('#/+#', '/', $path);
        if (! is_string($normalized) || preg_match('#^/(en|zh)(?:/|$)#', $normalized) !== 1) {
            throw new InvalidArgumentException('core_entry_slo_public_path_locale_invalid');
        }

        $segments = array_values(array_filter(explode('/', strtolower($normalized))));
        if (array_intersect($segments, $privateSegments) !== []) {
            throw new InvalidArgumentException('core_entry_slo_private_path_forbidden');
        }

        return $normalized !== '/' ? rtrim($normalized, '/') : $normalized;
    }

    /**
     * @return list<string>
     */
    private function markerList(mixed $value): array
    {
        $markers = $this->stringList($value);
        if ($markers === []) {
            throw new InvalidArgumentException('core_entry_slo_marker_list_empty');
        }

        foreach ($markers as $marker) {
            if (
                strlen($marker) > 200
                || str_contains($marker, "\0")
                || str_contains(strtolower($marker), '<script')
            ) {
                throw new InvalidArgumentException('core_entry_slo_marker_invalid');
            }
        }

        return $markers;
    }

    private function publicBaseUrl(): string
    {
        $value = rtrim($this->string(config('seo_intel.public_canonical_host')), '/');
        $parts = parse_url($value);

        if (
            ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
        ) {
            throw new InvalidArgumentException('core_entry_slo_public_base_url_invalid');
        }

        $host = strtolower((string) $parts['host']);
        if (
            $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
        ) {
            throw new InvalidArgumentException('core_entry_slo_public_base_url_private');
        }

        if (
            filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        ) {
            throw new InvalidArgumentException('core_entry_slo_public_base_url_private');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $string = $this->string($item);
            if ($string !== '') {
                $result[] = $string;
            }
        }

        return $result;
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
