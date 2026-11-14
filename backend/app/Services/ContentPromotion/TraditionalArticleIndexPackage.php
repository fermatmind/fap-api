<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use DomainException;

final class TraditionalArticleIndexPackage
{
    public const FILE = 'traditional-article-index.json';

    public const SCHEMA = 'fermatmind.traditional_article_index.v1';

    public const MAX_BATCH_SIZE = 115;

    public static function read(string $bytes, string $environment): array
    {
        $manifest = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (($manifest['schema'] ?? null) !== self::SCHEMA
            || array_keys($manifest['environments'] ?? []) !== ['staging', 'production']
            || ! isset($manifest['environments'][$environment])) {
            throw new DomainException('public_content_53_traditional_index_package_invalid');
        }

        $cohorts = [];
        foreach ($manifest['environments'] as $name => $rows) {
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) < 1 || count($rows) > self::MAX_BATCH_SIZE) {
                throw new DomainException('public_content_53_traditional_index_count_invalid');
            }
            $members = [];
            $cohorts[$name] = [];
            foreach ($rows as $row) {
                $target = $row['target'] ?? null;
                $peers = $row['peers'] ?? null;
                $sourceLocale = $row['source_locale'] ?? null;
                if (! is_array($target) || ($target['locale'] ?? null) !== 'zh-TW'
                    || ! is_array($peers) || ! array_is_list($peers) || count($peers) < 1 || count($peers) > 2
                    || ! in_array($sourceLocale, ['en', 'zh-CN'], true)
                    || ! is_bool($row['llms_eligible'] ?? null)
                    || ! self::digest($row['body_sha256'] ?? null) || ! self::digest($row['title_sha256'] ?? null)) {
                    throw new DomainException('public_content_53_traditional_index_row_invalid');
                }
                $byLocale = [];
                foreach ([$target, ...$peers] as $identity) {
                    $canonical = self::canonical($identity);
                    $locale = $identity['locale'];
                    if (isset($byLocale[$locale]) || isset($members[$identity['id']]) || isset($members[$canonical])) {
                        throw new DomainException('public_content_53_traditional_index_duplicate_identity');
                    }
                    $members[$identity['id']] = $members[$canonical] = true;
                    $byLocale[$locale] = $identity;
                    if (array_key_exists('staging_control', $identity)) {
                        $control = $identity['staging_control'];
                        $stagingCanonicals = [null, $canonical, parse_url($canonical, PHP_URL_PATH)];
                        if ($locale === 'en') {
                            $stagingCanonicals[] = '/articles/'.$identity['slug'];
                        }
                        if ($name !== 'staging' || $locale === 'zh-TW' || ! is_array($control)
                            || ! is_bool($control['indexable'] ?? null) || ! is_bool($control['has_seo'] ?? null)
                            || ! array_key_exists('canonical_url', $control) || ! array_key_exists('robots', $control)
                            || ! in_array($control['canonical_url'], $stagingCanonicals, true)
                            || ($control['robots'] !== null && ! is_string($control['robots']))) {
                            throw new DomainException('public_content_53_traditional_index_staging_control_invalid');
                        }
                    }
                    if ($locale !== 'zh-TW' && ! array_key_exists('hreflang_gate', $identity)) {
                        throw new DomainException('public_content_53_traditional_index_peer_gate_missing');
                    }
                }
                if (! isset($byLocale[$sourceLocale]) || ($row['source_id'] ?? null) !== $byLocale[$sourceLocale]['id']) {
                    throw new DomainException('public_content_53_traditional_index_source_mismatch');
                }
                $cohorts[$name][$target['slug']] = [
                    'kind' => 'article',
                    'page_id' => 'TRADITIONAL-INDEX-'.$target['slug'],
                    'identity' => ['org_id' => 0, 'locale' => 'zh-TW', 'slug' => $target['slug']],
                    'traditional_article_index' => $row,
                ];
            }
            ksort($cohorts[$name]);
        }
        if (array_keys($cohorts['staging']) !== array_keys($cohorts['production'])) {
            throw new DomainException('public_content_53_traditional_index_environment_scope_mismatch');
        }

        foreach ($cohorts['production'] as $slug => $candidate) {
            $stage = $cohorts['staging'][$slug]['traditional_article_index'];
            $production = $candidate['traditional_article_index'];
            foreach (['body_sha256', 'title_sha256', 'source_locale'] as $key) {
                if ($stage[$key] !== $production[$key]) {
                    throw new DomainException('public_content_53_traditional_index_environment_copy_mismatch');
                }
            }
        }

        return ['candidates' => array_values($cohorts[$environment])];
    }

    public static function mutationCounts(PromotionContext $context): ?array
    {
        if ($context->lane !== 'W3' || $context->subscope !== Adapters\PublicContent53PromotionAdapter::SUBSCOPE
            || realpath($context->packageDirectory) !== realpath(base_path(PublicContent53Package::PACKAGE))) {
            return null;
        }
        $file = $context->packageDirectory.'/'.self::FILE;
        if (! is_file($file) || is_link($file)) {
            return null;
        }
        $bytes = file_get_contents($file);
        $chain = self::SCHEMA."\n".self::FILE."\n".hash('sha256', $bytes)."\n";
        if (! hash_equals($context->packageSha256, hash('sha256', $chain))) {
            return null;
        }
        $rows = self::read($bytes, PublicContent53Package::environment())['candidates'];

        if (count($rows) !== $context->expectedRowCount) {
            return null;
        }

        return [
            'indexability_mutation_count' => count($rows),
            'sitemap_mutation_count' => count($rows),
            'llms_mutation_count' => count(array_filter($rows, static fn (array $row): bool => $row['traditional_article_index']['llms_eligible'])),
        ];
    }

    private static function canonical(mixed $identity): string
    {
        if (! is_array($identity) || ! is_int($identity['id'] ?? null) || $identity['id'] < 1
            || ! is_int($identity['published_revision_id'] ?? null) || $identity['published_revision_id'] < 1
            || ! is_string($identity['translation_group_id'] ?? null) || trim($identity['translation_group_id']) === ''
            || ! is_string($identity['slug'] ?? null) || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $identity['slug']) !== 1) {
            throw new DomainException('public_content_53_traditional_index_identity_invalid');
        }
        $segment = match ($identity['locale'] ?? null) {
            'en' => 'en',
            'zh-CN' => 'zh',
            'zh-TW' => 'zh-TW',
            default => null,
        };
        $canonical = 'https://fermatmind.com/'.$segment.'/articles/'.$identity['slug'];
        if ($segment === null || ($identity['canonical'] ?? null) !== $canonical) {
            throw new DomainException('public_content_53_traditional_index_canonical_invalid');
        }

        return $canonical;
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }
}
