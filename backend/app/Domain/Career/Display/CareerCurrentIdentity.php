<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

/** Manifest-bound identity; retained directory IDs are not independent public careers. */
final class CareerCurrentIdentity
{
    public function __construct(private readonly CareerContentV3CanonicalReader $reader) {}

    /** @return array<string,string> */
    public function aliases(): array
    {
        return $this->reader->authority()['manifest']['identity_aliases'] ?? [];
    }

    /** Public identity inventory is derived only from the validated Current manifest. */
    public function inventory(): array
    {
        $authority = $this->reader->authority();
        $slugs = $authority['slugs'];
        sort($slugs, SORT_STRING);

        return [
            'manifest_sha256' => hash_file('sha256', $authority['root'].'/manifest.json'),
            'storage_count' => count($slugs),
            'file_count' => $authority['manifest']['coverage']['files'],
            'slugs' => $slugs,
            'aliases' => (object) $this->aliases(),
        ];
    }

    public function canonicalSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));

        return $this->aliases()[$slug] ?? $slug;
    }

    public function isAlias(string $slug): bool
    {
        return isset($this->aliases()[strtolower(trim($slug))]);
    }

    public function scopes(): array
    {
        return $this->reader->authority()['manifest']['identity_scopes'] ?? [];
    }

    public function definition(string $slug): ?array
    {
        return $this->scopes()[$slug] ?? null;
    }

    public function name(string $slug, string $locale): ?string
    {
        return $this->definition($slug) === null ? null : $this->reader->page($slug, $locale)['subject']['name'];
    }

    /** Apply Current identity to public transports; never change stored compatibility rows. */
    public function projectPayload(array $payload, string $locale): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value) && ! in_array($key, ['content_v3', 'fact_register', 'blocks'], true)) {
                $payload[$key] = $this->projectPayload($value, $locale);
            }
        }
        $slug = (string) (data_get($payload, 'identity.canonical_slug') ?? $payload['canonical_slug'] ?? $payload['slug'] ?? '');
        if ($slug === '' && is_string($payload['url'] ?? null)
            && preg_match('#^/(?:en|zh)/career/jobs/([a-z0-9-]+)/?$#D', (string) parse_url($payload['url'], PHP_URL_PATH), $match) === 1) {
            $slug = $match[1];
        }
        $definition = $this->definition($slug);
        if ($definition === null) {
            return $payload;
        }
        $en = $this->name($slug, 'en');
        $zh = $this->name($slug, 'zh-CN');
        $title = in_array(strtolower($locale), ['en', 'en-us'], true) ? $en : $zh;
        foreach (['title' => $title, 'name' => $title, 'title_en' => $en, 'title_zh' => $zh,
            'canonical_title_en' => $en, 'canonical_title_zh' => $zh] as $key => $value) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = $value;
            }
        }
        if (is_array($payload['titles'] ?? null)) {
            foreach (['canonical_en' => $en, 'canonical_zh' => $zh, 'short_title_en' => $en,
                'short_title_zh' => $zh, 'search_h1_zh' => $zh] as $key => $value) {
                if (array_key_exists($key, $payload['titles'])) {
                    $payload['titles'][$key] = $value;
                }
            }
        }
        // These presentation containers inherit the enclosing career identity.
        // They do not carry their own slug, so recursive projection cannot name them.
        foreach (['display_surface_v1.presentation_v2.hero.title', 'display_surface_v1.page.content.hero.title'] as $path) {
            if (is_string(data_get($payload, $path))) {
                data_set($payload, $path, $title);
            }
        }
        if (is_array($payload['ontology'] ?? null)) {
            $payload['ontology']['crosswalks'] = [];
            foreach ($definition['occupations'] as $occupation) {
                foreach (['onet_soc_2019' => $occupation['code'], 'us_soc' => substr($occupation['code'], 0, 7)] as $system => $code) {
                    $payload['ontology']['crosswalks'][] = [
                        'source_system' => $system, 'source_code' => $code, 'source_title' => $occupation['title'],
                        'mapping_type' => $definition['scope_type'] === 'bounded_official_combination' ? 'combined_member' : 'direct_match',
                        'confidence_score' => 1,
                    ];
                }
            }
        }
        // Old compatibility numbers are bound to a different scope/period. Current v3 owns these facts.
        foreach (['truth_layer', 'truth_summary'] as $container) {
            if (is_array($payload[$container] ?? null)) {
                foreach ($payload[$container] as $key => $value) {
                    if ($key !== 'source_refs') {
                        $payload[$container][$key] = null;
                    }
                }
                $payload[$container]['source_refs'] = array_map(static fn (array $occupation): string => 'onet:'.$occupation['code'], $definition['occupations']);
            }
        }
        if (is_array($payload['structured_data']['occupation'] ?? null)) {
            $payload['structured_data']['occupation']['name'] = $title;
            $payload['structured_data']['occupation']['occupationalCategory'] = array_column($definition['occupations'], 'code');
            unset($payload['structured_data']['occupation']['estimatedSalary']);
        }

        return $payload;
    }

    /** @return list<string> */
    public function searchTerms(string $target): array
    {
        $terms = $this->definition($target) === null ? [] : [$this->name($target, 'en'), $this->name($target, 'zh-CN')];
        foreach ($this->aliases() as $alias => $canonical) {
            if ($canonical !== $target) {
                continue;
            }
            $terms[] = $alias;
            foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                $terms[] = $this->reader->page($alias, $locale)['subject']['name'];
            }
        }

        return array_values(array_unique($terms));
    }

    /** Resolve exact legacy names without making ambiguous prefix guesses. */
    public function canonicalQuery(string $query): string
    {
        $normalized = mb_strtolower(trim($query));
        foreach (array_unique([...array_values($this->aliases()), ...array_keys($this->scopes())]) as $target) {
            foreach ($this->searchTerms($target) as $term) {
                if (mb_strtolower($term) === $normalized) {
                    return $target;
                }
            }
        }

        return $query;
    }
}
