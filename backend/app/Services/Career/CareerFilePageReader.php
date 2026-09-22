<?php

declare(strict_types=1);

namespace App\Services\Career;

use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Domain\Career\Display\CareerPageProjector;
use App\Models\Occupation;
use App\Support\PublicProjectionCache;

/** Reader copy comes exclusively from locale files; publication and identity remain business authority. */
final class CareerFilePageReader
{
    public function __construct(
        private readonly CareerPageProjector $pages,
        private readonly PublicCareerAuthorityResponseCache $publication,
        private readonly CareerContentV3CanonicalReader $content,
    ) {}

    public function read(string $slug, string $locale, bool $cacheWrite = true): ?array
    {
        $locale = in_array(strtolower($locale), ['zh', 'zh-cn'], true) ? 'zh-CN' : $locale;
        $projection = $this->publication->filePagePublication($slug, $locale);
        if (! $this->publication->jobDetailProjectionItemIsPublished($projection)) {
            return null;
        }
        $entry = $this->pages->fileEntry($slug, $locale);
        $sourceHash = (string) $entry['source_content_sha256'];
        $key = self::cacheKeyFromIdentity($slug, $locale, $sourceHash);
        $cached = PublicProjectionCache::get($key);
        if ($this->validCachedPage($cached, $slug, $locale, $sourceHash)) {
            $page = $cached;
        } else {
            // No pointer or legacy HTML can shadow the installed file contract.
            $page = $this->pages->read($slug, $locale);
            if ($cacheWrite) {
                // The cache key is content-addressed by source_content_sha256;
                // a new body produces a new key, so the old immutable value
                // does not need a time-based expiry.
                PublicProjectionCache::forever($key, $page);
            }
        }
        $occupation = Occupation::query()->with(['aliases', 'crosswalks'])->where('canonical_slug', $slug)->first();
        $path = '/'.($locale === 'zh-CN' ? 'zh' : 'en').'/career/jobs/'.$slug;
        $indexable = $this->content->hasPublicBody($slug, $locale);

        return array_merge($this->publication->filePageBusiness($slug, $locale), [
            'bundle_kind' => 'career_job_detail',
            'bundle_version' => CareerPageProjector::VERSION,
            'identity' => [
                'occupation_uuid' => $occupation?->id ?? $projection['occupation_uuid'] ?? null,
                'canonical_slug' => $slug,
                'entity_level' => $occupation?->entity_level,
                'family_uuid' => $occupation?->family_id ?? $projection['family_uuid'] ?? null,
                'parent_uuid' => $occupation?->parent_id,
            ],
            'titles' => [$locale === 'zh-CN' ? 'canonical_zh' : 'canonical_en' => $page['subject']['name']],
            'alias_index' => $occupation?->aliases?->map(fn ($alias) => ['alias' => $alias->alias, 'normalized' => $alias->normalized, 'lang' => $alias->lang])->all() ?? [],
            'ontology' => ['crosswalks' => $occupation?->crosswalks?->map(fn ($crosswalk) => [
                'source_system' => $crosswalk->source_system, 'source_code' => $crosswalk->source_code,
                'source_title' => $crosswalk->source_title, 'mapping_type' => $crosswalk->mapping_type,
                'confidence_score' => $crosswalk->confidence_score,
            ])->all() ?? []],
            'locale_policy' => ['requested_locale' => $locale, 'available_locales' => ['en', 'zh-CN']],
            'seo_contract' => ['canonical_path' => $path, 'canonical_target' => $path,
                'index_state' => $indexable ? 'indexable' : 'noindex', 'index_eligible' => $indexable,
                'robots_policy' => $indexable ? 'index,follow' : 'noindex,follow',
                'reason_codes' => ['runtime_publish_projection', 'release_gate_pass'],
                'metadata_fingerprint' => $page['source_content_sha256']],
            'career_page' => $page,
        ]);
    }

    public static function cacheKey(array $page): string
    {
        return self::cacheKeyFromIdentity(
            (string) $page['subject']['canonical_slug'],
            (string) $page['locale'],
            (string) $page['source_content_sha256'],
        );
    }

    private static function cacheKeyFromIdentity(string $slug, string $locale, string $sourceHash): string
    {
        return 'career:page:'.CareerPageProjector::VERSION.":{$slug}:{$locale}:{$sourceHash}";
    }

    private function validCachedPage(mixed $page, string $slug, string $locale, string $sourceHash): bool
    {
        return is_array($page)
            && ($page['contract_version'] ?? null) === CareerPageProjector::VERSION
            && ($page['locale'] ?? null) === $locale
            && data_get($page, 'subject.canonical_slug') === $slug
            && ($page['source_content_sha256'] ?? null) === $sourceHash
            && is_array($page['content'] ?? null)
            && data_get($page, 'content.subject.canonical_slug') === $slug
            && data_get($page, 'content.source_content_sha256') === $sourceHash;
    }

    public function seo(array $bundle): array
    {
        $page = $bundle['career_page'];
        $path = $bundle['seo_contract']['canonical_path'];
        $title = $page['seo']['title']['text'] ?? $page['subject']['name'];
        $description = $page['seo']['description']['text'];
        $indexable = $bundle['seo_contract']['index_eligible'] === true;
        $robots = $indexable ? 'index,follow' : 'noindex,follow';
        $alternates = [];
        if ($indexable) {
            foreach (['en' => 'en', 'zh-CN' => 'zh'] as $locale => $segment) {
                $slug = $page['subject']['canonical_slug'];
                if ($this->publication->jobDetailProjectionItemIsPublished($this->publication->filePagePublication($slug, $locale))
                    && $this->content->hasPublicBody($slug, $locale)) {
                    $alternates[$locale] = '/'.$segment.'/career/jobs/'.$slug;
                }
            }
        }
        $meta = ['title' => $title, 'description' => $description, 'canonical' => $path,
            'robots' => $robots, 'hreflang' => $alternates];

        $meta['alternates'] = $meta['hreflang'];
        $meta['og'] = ['title' => $title, 'description' => $description, 'url' => $path, 'image' => null];
        $meta['twitter'] = ['title' => $title, 'description' => $description, 'image' => null];

        return ['meta' => $meta, 'jsonld' => null, 'seo_surface_v1' => [
            'metadata_contract_version' => 'seo.surface.v1', 'surface_type' => 'career_job_detail',
            'canonical_url' => $path, 'robots_policy' => $robots, 'title' => $title,
            'description' => $description ?? '', 'index_eligible' => $indexable, 'index_state' => $indexable ? 'indexable' : 'noindex',
            'indexability_state' => $indexable ? 'indexable' : 'noindex', 'sitemap_state' => $indexable ? 'included' : 'excluded', 'llms_exposure_state' => $indexable ? 'allow' : 'withhold',
            'alternates' => $alternates,
            'structured_data_keys' => [], 'metadata_fingerprint' => $page['source_content_sha256'],
        ]];
    }
}
