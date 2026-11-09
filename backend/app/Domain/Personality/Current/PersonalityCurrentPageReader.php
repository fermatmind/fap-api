<?php

declare(strict_types=1);

namespace App\Domain\Personality\Current;

final class PersonalityCurrentPageReader
{
    /** @var array{root:string,manifest:array<string,mixed>,entries:array<string,array<string,mixed>>}|null */
    private static ?array $runtimeIndex = null;

    public function __construct(private readonly PersonalityCurrentAuthorityPackage $package) {}

    /** @return array<string,mixed> */
    public function payload(string $framework, string $pageKind, string $entityKey, string $locale): array
    {
        $page = $this->package->pageFromIndex(
            $this->index(),
            $framework,
            $pageKind,
            $entityKey,
            $locale,
        );

        return $page['payload'];
    }

    /** @return array<string,mixed>|null */
    public function payloadOrNull(string $framework, string $pageKind, string $entityKey, string $locale): ?array
    {
        $identityKey = strtolower(trim($framework)).'|'.strtolower(trim($pageKind)).'|'.strtolower(trim($entityKey)).'|'.$locale;
        if (! isset($this->index()['entries'][$identityKey])) {
            return null;
        }

        return $this->payload($framework, $pageKind, $entityKey, $locale);
    }

    /** @return array<string,mixed>|null */
    public function payloadBySlugOrNull(string $framework, string $slug, string $locale): ?array
    {
        $normalizedFramework = strtolower(trim($framework));
        $normalizedSlug = strtolower(trim($slug));
        foreach ($this->index()['entries'] as $entry) {
            if ($entry['framework'] === $normalizedFramework
                && $entry['locale'] === $locale
                && strtolower((string) $entry['slug']) === $normalizedSlug) {
                return $this->payload($normalizedFramework, $entry['page_kind'], $entry['entity_key'], $locale);
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    public function payloads(string $framework, ?string $pageKind, string $locale): array
    {
        return iterator_to_array($this->payloadsByCanonicalPath($framework, $pageKind, $locale), false);
    }

    /** @return iterable<string,array<string,mixed>> */
    public function payloadsByCanonicalPath(string $framework, ?string $pageKind, string $locale): iterable
    {
        foreach ($this->index()['entries'] as $entry) {
            if ($entry['framework'] !== $framework
                || $entry['locale'] !== $locale
                || ($pageKind !== null && $entry['page_kind'] !== $pageKind)) {
                continue;
            }

            yield $entry['canonical_path'] => $this->payload($framework, $entry['page_kind'], $entry['entity_key'], $locale);
        }
    }

    /** @return array<string,string> */
    public function traditionalLanguageAlternates(array $payload): array
    {
        if (! $this->hasLocale('zh-TW')) {
            return [];
        }

        $canonical = $payload['seo_meta']['canonical_url']
            ?? $payload['personality_public_content_asset_v1']['canonical_path']
            ?? null;
        if (! is_string($canonical)) {
            return [];
        }
        $path = str_starts_with($canonical, 'https://fermatmind.com/')
            ? substr($canonical, strlen('https://fermatmind.com'))
            : $canonical;
        foreach ($this->index()['entries'] as $entry) {
            if ($entry['canonical_path'] !== $path) {
                continue;
            }

            $identityPrefix = substr($entry['identity_key'], 0, -strlen($entry['locale']));
            $entries = $this->index()['entries'];
            $languages = [];
            foreach (['zh-TW', 'en', 'zh-CN'] as $locale) {
                $target = $entries[$identityPrefix.$locale] ?? null;
                if ($target === null) {
                    return [];
                }

                $localized = $entry['locale'] === $locale
                    ? $payload
                    : $this->payload($target['framework'], $target['page_kind'], $target['entity_key'], $locale);
                if (! self::allowsPublicIndex($locale, $target['canonical_path'], $localized)) {
                    return [];
                }

                $languages[$locale] = $target['canonical_path'];
            }

            return $languages;
        }

        return [];
    }

    public static function allowsTraditionalSitemap(string $path, array $payload): bool
    {
        return self::allowsPublicIndex('zh-TW', $path, $payload);
    }

    private static function allowsPublicIndex(string $locale, string $path, array $payload): bool
    {
        $segment = $locale === 'zh-CN' ? 'zh' : $locale;
        if (! preg_match('#^/'.$segment.'/personality(?:/[a-z0-9-]+)*$#D', $path)) {
            return false;
        }

        $asset = $payload['personality_public_content_asset_v1'] ?? null;
        if (is_array($asset)) {
            return ($asset['canonical_path'] ?? null) === $path
                && ($asset['locale'] ?? null) === $locale
                && ($asset['launch_state'] ?? null) === 'published'
                && ($asset['robots'] ?? null) === 'index,follow'
                && ($asset['index_eligible'] ?? null) === true
                && ($asset['sitemap_eligible'] ?? null) === true;
        }

        $meta = $payload['seo_meta'] ?? [];
        $surface = $payload['seo_surface_v1'] ?? null;

        return ($meta['canonical_url'] ?? null) === 'https://fermatmind.com'.$path
            && ($meta['robots'] ?? null) === 'index,follow'
            && ($surface === null || (($surface['indexability_state'] ?? null) === 'indexable'
                && ($surface['robots_policy'] ?? null) === 'index,follow'
                && ($surface['canonical_url'] ?? null) === 'https://fermatmind.com'.$path));
    }

    public function hasLocale(string $locale): bool
    {
        return in_array($locale, $this->index()['manifest']['locales'], true);
    }

    public function aggregateSha256(): string
    {
        $index = $this->index();

        return (string) $index['manifest']['aggregate_sha256'];
    }

    /** @return array{root:string,manifest:array<string,mixed>,entries:array<string,array<string,mixed>>} */
    private function index(): array
    {
        return self::$runtimeIndex ??= $this->package->runtimeIndex(base_path());
    }
}
