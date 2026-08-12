<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use DomainException;

final class Top100FrozenPackage
{
    public const BATCH_ID = 'SEO-TOP100-FROZEN-20260812-v1';

    public const SOURCE_SHA256 = 'e4c9788d6fadff53bc33170299971ab57dbff1810ad1fcdb4f97f5f4ee94d150';

    public const PACKAGE_SHA256 = 'cc96a6ceaf6269b3eabba728f8f4153123bc6114e780ae084045858b60a4ff9c';

    public const SUBSCOPE = 'frozen-20260812-v1';

    public const TARGET_PRIORITIES = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 17, 18, 19, 20, 21, 23, 27, 31, 32, 36, 42, 52, 61, 83, 84];

    private const FAMILY_COUNTS = [
        'mbti_profile' => 6,
        'mbti_comparison' => 3,
        'enneagram_wing' => 12,
        'big_five' => 4,
        'article' => 3,
        'test_landing' => 2,
    ];

    /** @return array{manifest:array<string,mixed>,targets:list<array<string,mixed>>,package_sha256:string,target_set_sha256:string,deferred_out_of_target_link_source_count:int} */
    public function inspect(PromotionContext $context): array
    {
        if ($context->lane !== 'TOP100' || $context->subscope !== self::SUBSCOPE
            || $context->expectedRowCount !== 30 || ! hash_equals(self::PACKAGE_SHA256, $context->packageSha256)) {
            throw new DomainException('top100_frozen_context_invalid');
        }

        $manifestBytes = @file_get_contents($context->packageDirectory.'/manifest.json');
        $targetBytes = @file_get_contents($context->packageDirectory.'/targets.json');
        if (! is_string($manifestBytes) || ! is_string($targetBytes)) {
            throw new DomainException('top100_frozen_package_missing');
        }
        $manifest = $this->decode($manifestBytes, 'top100_frozen_manifest_invalid');
        $payload = $this->decode($targetBytes, 'top100_frozen_targets_invalid');
        $targetSha = hash('sha256', $targetBytes);
        if (($manifest['schema_version'] ?? null) !== 'fermatmind.seo_top100_frozen_package.v1'
            || ($manifest['batch_id'] ?? null) !== self::BATCH_ID
            || ($manifest['lane'] ?? null) !== 'TOP100'
            || ($manifest['subscope'] ?? null) !== self::SUBSCOPE
            || ($manifest['source_sha256'] ?? null) !== self::SOURCE_SHA256
            || ($manifest['package_sha256'] ?? null) !== self::PACKAGE_SHA256
            || ($manifest['expected_row_count'] ?? null) !== 30
            || ($manifest['target_priorities'] ?? null) !== self::TARGET_PRIORITIES
            || ($manifest['family_counts'] ?? null) !== self::FAMILY_COUNTS
            || ($manifest['hold_count'] ?? null) !== 68
            || ($manifest['control_count'] ?? null) !== 25
            || ($manifest['permissions'] ?? null) !== []
            || ($manifest['payloads'] ?? null) !== [['path' => 'targets.json', 'sha256' => $targetSha]]) {
            throw new DomainException('top100_frozen_manifest_contract_invalid');
        }
        foreach (['media_mutations', 'canonical_mutations', 'indexability_mutations', 'sitemap_mutations', 'llms_mutations', 'search_mutations'] as $boundary) {
            if (data_get($manifest, 'boundaries.'.$boundary) !== 0) {
                throw new DomainException('top100_frozen_boundary_invalid');
            }
        }
        $manifestForHash = $manifest;
        unset($manifestForHash['package_sha256']);
        $actualPackageSha = hash('sha256', hash('sha256', PromotionContextFactory::canonicalJson($manifestForHash))."\ntargets.json\n".$targetSha."\n");
        if (! hash_equals(self::PACKAGE_SHA256, $actualPackageSha)) {
            throw new DomainException('top100_frozen_package_sha_invalid');
        }

        if (($payload['schema_version'] ?? null) !== 'fermatmind.seo_top100_frozen_targets.v1'
            || ($payload['batch_id'] ?? null) !== self::BATCH_ID
            || ($payload['source_sha256'] ?? null) !== self::SOURCE_SHA256
            || ($payload['target_priorities'] ?? null) !== self::TARGET_PRIORITIES
            || ($payload['hold_fingerprint_sha256'] ?? null) !== ($manifest['hold_fingerprint_sha256'] ?? null)
            || ($payload['control_fingerprint_sha256'] ?? null) !== ($manifest['control_fingerprint_sha256'] ?? null)) {
            throw new DomainException('top100_frozen_targets_contract_invalid');
        }
        $rows = is_array($payload['targets'] ?? null) ? array_values($payload['targets']) : [];
        if (count($rows) !== 30) {
            throw new DomainException('top100_frozen_target_count_invalid');
        }

        $targets = [];
        $priorities = [];
        $urls = [];
        $familyCounts = array_fill_keys(array_keys(self::FAMILY_COUNTS), 0);
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new DomainException('top100_frozen_target_invalid');
            }
            $priority = (int) ($row['priority'] ?? 0);
            $url = trim((string) ($row['url'] ?? ''));
            $family = $this->family((string) ($row['page_type'] ?? ''));
            if (! in_array($priority, self::TARGET_PRIORITIES, true) || isset($priorities[$priority]) || isset($urls[$url])) {
                throw new DomainException('top100_frozen_target_identity_invalid');
            }
            $parts = parse_url($url);
            $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
            if (($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'fermatmind.com'
                || preg_match('~^/(en|zh)/(?:personality|articles|tests)/~', $path) !== 1) {
                throw new DomainException('top100_frozen_target_url_invalid');
            }
            $locale = str_starts_with($path, '/zh/') ? 'zh-CN' : 'en';
            if (($row['locale'] ?? null) !== ($locale === 'zh-CN' ? 'zh' : 'en')) {
                throw new DomainException('top100_frozen_target_locale_invalid');
            }
            $priorities[$priority] = true;
            $urls[$url] = true;
            $familyCounts[$family]++;
            $targets[] = [
                'priority' => $priority,
                'url' => $url,
                'path' => $path,
                'locale' => $locale,
                'family' => $family,
                'slug' => basename($path),
                'patch' => [
                    'seo_title' => $this->proposed($row['proposed_title'] ?? null),
                    'seo_description' => $this->proposed($row['proposed_description'] ?? null),
                    'h1' => $this->proposed($row['proposed_H1_or_KEEP'] ?? null),
                    'intro' => $this->proposed($row['proposed_intro_or_exact_action'] ?? null),
                ],
                'link_sources' => $this->strings($row['internal_link_source_urls'] ?? []),
                'link_anchors' => $this->strings($row['exact_anchor_text'] ?? []),
                'link_targets' => $this->strings($row['internal_link_target_urls'] ?? []),
                'source_row_sha256' => hash('sha256', PromotionContextFactory::canonicalJson($row)),
            ];
        }
        ksort($priorities, SORT_NUMERIC);
        if (array_map('intval', array_keys($priorities)) !== self::TARGET_PRIORITIES || $familyCounts !== self::FAMILY_COUNTS) {
            throw new DomainException('top100_frozen_target_set_invalid');
        }

        $targetUrlSet = array_fill_keys(array_column($targets, 'url'), true);
        $linksBySource = [];
        $deferredLinkSources = 0;
        foreach ($targets as $target) {
            foreach ($target['link_sources'] as $index => $source) {
                if (! isset($targetUrlSet[$source])) {
                    $deferredLinkSources++;

                    continue;
                }
                $anchor = $target['link_anchors'][$index] ?? $target['link_anchors'][0] ?? null;
                $destination = $target['link_targets'][$index] ?? $target['link_targets'][0] ?? null;
                if (! is_string($anchor) || ! is_string($destination) || ! isset($targetUrlSet[$destination])) {
                    continue;
                }
                if (hash_equals($source, $destination)) {
                    continue;
                }
                $destinationPath = (string) parse_url($destination, PHP_URL_PATH);
                $linksBySource[$source][] = [
                    'label' => $anchor,
                    'anchor_text' => $anchor,
                    'href' => $destinationPath,
                    'safe_public_route' => true,
                ];
            }
        }
        foreach ($targets as &$target) {
            $target['internal_links'] = $this->uniqueLinks($linksBySource[$target['url']] ?? []);
            unset($target['link_sources'], $target['link_anchors'], $target['link_targets']);
        }
        unset($target);

        return [
            'manifest' => $manifest,
            'targets' => $targets,
            'package_sha256' => $actualPackageSha,
            'deferred_out_of_target_link_source_count' => $deferredLinkSources,
            'target_set_sha256' => hash('sha256', PromotionContextFactory::canonicalJson(array_map(
                static fn (array $target): array => ['priority' => $target['priority'], 'url' => $target['url']],
                $targets,
            ))),
        ];
    }

    private function family(string $pageType): string
    {
        return match ($pageType) {
            'MBTI profile' => 'mbti_profile',
            'MBTI compare' => 'mbti_comparison',
            'Enneagram wing' => 'enneagram_wing',
            'Big Five', 'Big Five facet' => 'big_five',
            'Article' => 'article',
            'Test' => 'test_landing',
            default => throw new DomainException('top100_frozen_family_invalid'),
        };
    }

    private function proposed(mixed $value): ?string
    {
        $value = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? $value) : '';
        if ($value === '' || preg_match('/^KEEP(?:\s|\x{2014}|$)/iu', $value) === 1) {
            return null;
        }

        return $value;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            throw new DomainException('top100_frozen_link_contract_invalid');
        }

        return array_values(array_map(static function (mixed $item): string {
            if (! is_string($item) || trim($item) === '') {
                throw new DomainException('top100_frozen_link_contract_invalid');
            }

            return trim($item);
        }, $value));
    }

    /** @param list<array{label:string,anchor_text:string,href:string,safe_public_route:bool}> $links @return list<array{label:string,anchor_text:string,href:string,safe_public_route:bool}> */
    private function uniqueLinks(array $links): array
    {
        $unique = [];
        foreach ($links as $link) {
            $unique[$link['anchor_text'].'|'.$link['href']] = $link;
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }

    /** @return array<string,mixed> */
    private function decode(string $bytes, string $error): array
    {
        try {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DomainException($error, previous: $exception);
        }
        if (! is_array($decoded)) {
            throw new DomainException($error);
        }

        return $decoded;
    }
}
