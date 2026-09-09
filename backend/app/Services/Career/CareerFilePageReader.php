<?php

declare(strict_types=1);

namespace App\Services\Career;

use App\Domain\Career\Display\CareerPageProjector;
use App\Models\Occupation;
use App\Support\PublicProjectionCache as Cache;

/** Reader copy comes exclusively from locale files; publication and identity remain business authority. */
final class CareerFilePageReader
{
    public function __construct(private readonly CareerPageProjector $pages, private readonly PublicCareerAuthorityResponseCache $publication) {}

    public function read(string $slug, string $locale, bool $cacheWrite = true): ?array
    {
        $locale = in_array(strtolower($locale), ['zh', 'zh-cn'], true) ? 'zh-CN' : $locale;
        $projection = $this->publication->filePagePublication($slug, $locale);
        if (! $this->publication->jobDetailProjectionItemIsPublished($projection)) {
            return null;
        }
        $page = $this->pages->read($slug, $locale);
        // No pointer or legacy HTML can shadow the installed file contract.
        $key = self::cacheKey($page);
        $cached = Cache::get($key);
        if ($cacheWrite && (! is_array($cached) || $cached !== $page)) {
            Cache::put($key, $page, 86400);
        }
        $occupation = Occupation::query()->with(['aliases', 'crosswalks'])->where('canonical_slug', $slug)->first();
        $path = '/'.($locale === 'zh-CN' ? 'zh' : 'en').'/career/jobs/'.$slug;

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
                'index_state' => 'indexable', 'index_eligible' => true, 'robots_policy' => 'index,follow',
                'reason_codes' => ['runtime_publish_projection', 'release_gate_pass'],
                'metadata_fingerprint' => $page['source_content_sha256']],
            'career_page' => $page,
        ]);
    }

    public static function cacheKey(array $page): string
    {
        return 'career:page:'.CareerPageProjector::VERSION.':'.$page['subject']['canonical_slug'].':'.$page['locale'].':'.$page['source_content_sha256'];
    }

    public function seo(array $bundle): array
    {
        $page = $bundle['career_page'];
        $path = $bundle['seo_contract']['canonical_path'];
        $title = $page['seo']['title']['text'] ?? $page['subject']['name'];
        $description = $page['seo']['description']['text'];
        $meta = ['title' => $title, 'description' => $description, 'canonical' => $path,
            'robots' => 'index,follow', 'hreflang' => ['en' => '/en/career/jobs/'.$page['subject']['canonical_slug'], 'zh-CN' => '/zh/career/jobs/'.$page['subject']['canonical_slug']]];

        $meta['alternates'] = $meta['hreflang'];
        $meta['og'] = ['title' => $title, 'description' => $description, 'url' => $path, 'image' => null];
        $meta['twitter'] = ['title' => $title, 'description' => $description, 'image' => null];

        return ['meta' => $meta, 'jsonld' => null, 'seo_surface_v1' => [
            'metadata_contract_version' => 'seo.surface.v1', 'surface_type' => 'career_job_detail',
            'canonical_url' => $path, 'robots_policy' => 'index,follow', 'title' => $title,
            'description' => $description ?? '', 'index_eligible' => true, 'index_state' => 'indexable',
            'indexability_state' => 'indexable', 'sitemap_state' => 'included', 'llms_exposure_state' => 'allow',
            'structured_data_keys' => [], 'metadata_fingerprint' => $page['source_content_sha256'],
        ]];
    }
}
