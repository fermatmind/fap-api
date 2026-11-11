<?php

declare(strict_types=1);

namespace App\Services\Career;

use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Domain\Career\Display\CareerJobDetailCanonicalCacheReader;
use App\Domain\Career\Display\CareerPageOccupationCrosswalks;
use App\Domain\Career\Display\CareerPageProjector;
use App\Models\Occupation;
use App\Support\PublicProjectionCache;
use Illuminate\Support\Facades\Log;

/** Reader copy comes exclusively from locale files; publication and identity remain business authority. */
final class CareerFilePageReader
{
    public function __construct(
        private readonly CareerPageProjector $pages,
        private readonly PublicCareerAuthorityResponseCache $publication,
        private readonly CareerContentV3CanonicalReader $content,
        private readonly CareerJobDetailCanonicalCacheReader $codec,
    ) {}

    public function read(string $slug, string $locale, bool $cacheWrite = true, bool $presenceOnly = false): ?array
    {
        $locale = match (strtolower($locale)) {
            'zh', 'zh-cn' => 'zh-CN',
            'zh-tw' => 'zh-TW',
            default => $locale,
        };
        $traditional = $locale === 'zh-TW';
        // The installed locale file grants translated copy. The existing
        // publication projection still governs the canonical occupation.
        $projection = $this->publication->filePagePublication($slug, $traditional ? 'zh-CN' : $locale);
        if (! $this->publication->jobDetailProjectionItemIsPublished($projection)) {
            return null;
        }
        $authority = $this->content->authority();
        $hasTraditionalFile = isset($authority['entries'][$slug]['zh-TW']);
        if ($traditional && ! $hasTraditionalFile) {
            return null;
        }
        $entry = $this->pages->fileEntry($slug, $locale);
        $path = $authority['root'].'/'.$entry['path'];
        if (! is_file($path) || is_link($path) || filesize($path) !== $entry['bytes']
            || ! hash_equals($entry['sha256'], hash_file('sha256', $path))) {
            throw new \App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_FILE_HASH_MISMATCH');
        }
        // Route status probes need published identity, not a second copy of the
        // full page, business projection, or occupation relations. Keep the
        // same publication and authoritative-file checks as the full reader.
        if ($presenceOnly) {
            return [
                'bundle_kind' => 'career_job_presence',
                'bundle_version' => 'career.detail.presence.v1',
                'identity' => ['canonical_slug' => $slug],
            ];
        }
        $sourceHash = (string) $entry['source_content_sha256'];
        $key = self::cacheKeyFromIdentity($slug, $locale, $sourceHash);
        try {
            $cached = $this->codec->decode(PublicProjectionCache::get($key));
        } catch (\Throwable) {
            $cached = null;
        }
        if ($this->validCachedPage($cached, $slug, $locale, $sourceHash)) {
            $page = $cached;
        } else {
            // No pointer or legacy HTML can shadow the installed file contract.
            $page = $this->pages->read($slug, $locale);
            if ($cacheWrite) {
                // The cache key is content-addressed by source_content_sha256;
                // a new body produces a new key, so the old immutable value
                // does not need a time-based expiry.
                try {
                    PublicProjectionCache::forever($key, $this->codec->encode($page));
                } catch (\Throwable) {
                    // A full/unwritable derived store cannot suppress validated file copy.
                    // Publication/identity and file validation above still fail closed.
                    Log::notice('career_optional_page_cache_write_failed', [
                        'canonical_slug' => $slug,
                        'locale' => $locale,
                    ]);
                }
            }
        }
        // Older immutable caches use the same public schema; apply the same
        // idempotent link projection on hits without rewriting source or cache.
        $page = $this->pages->normalizeLinks($page);
        $occupation = Occupation::query()->with(['aliases', 'crosswalks'])->where('canonical_slug', $slug)->first();
        $currentCrosswalks = CareerPageOccupationCrosswalks::resolve($page['content']);
        $compatibilityCrosswalks = $occupation?->crosswalks?->map(fn ($crosswalk) => [
            'source_system' => $crosswalk->source_system, 'source_code' => $crosswalk->source_code,
            'source_title' => $crosswalk->source_title, 'mapping_type' => $crosswalk->mapping_type,
            'confidence_score' => $crosswalk->confidence_score,
        ])->all() ?? [];
        $crosswalks = $currentCrosswalks === null ? $compatibilityCrosswalks : [
            ...array_filter($compatibilityCrosswalks, static fn (array $row): bool => ! in_array($row['source_system'], ['us_soc', 'onet_soc_2019'], true)),
            ...$currentCrosswalks,
        ];
        [$segment, $titleKey] = match ($locale) {
            'zh-CN' => ['zh', 'canonical_zh'],
            'zh-TW' => ['zh-TW', 'canonical_zh_tw'],
            default => ['en', 'canonical_en'],
        };
        $path = '/'.$segment.'/career/jobs/'.$slug;
        $indexable = ! $traditional && $this->content->hasPublicBody($slug, $locale);
        $business = $traditional ? [] : $this->publication->filePageBusiness($slug, $locale);

        return array_merge($business, [
            'bundle_kind' => 'career_job_detail',
            'bundle_version' => CareerPageProjector::VERSION,
            'identity' => [
                'occupation_uuid' => $occupation?->id ?? $projection['occupation_uuid'] ?? null,
                'canonical_slug' => $slug,
                'entity_level' => $occupation?->entity_level,
                'family_uuid' => $occupation?->family_id ?? $projection['family_uuid'] ?? null,
                'parent_uuid' => $occupation?->parent_id,
            ],
            'titles' => [$titleKey => $page['subject']['name']],
            'alias_index' => $occupation?->aliases?->map(fn ($alias) => ['alias' => $alias->alias, 'normalized' => $alias->normalized, 'lang' => $alias->lang])->all() ?? [],
            'ontology' => ['crosswalks' => $crosswalks],
            'locale_policy' => ['requested_locale' => $locale, 'available_locales' => $hasTraditionalFile ? ['en', 'zh-CN', 'zh-TW'] : ['en', 'zh-CN']],
            'seo_contract' => ['canonical_path' => $path, 'canonical_target' => $path,
                'index_state' => $indexable ? 'indexable' : 'noindex', 'index_eligible' => $indexable,
                'robots_policy' => $indexable ? 'index,follow' : 'noindex,follow',
                'reason_codes' => ['runtime_publish_projection', $traditional ? 'locale_index_hold' : 'release_gate_pass'],
                'metadata_fingerprint' => $page['source_content_sha256']],
            'career_page' => $page,
        ]);
    }

    public function traditionalTitle(string $slug): ?string
    {
        $projection = $this->publication->filePagePublication($slug, 'zh-CN');
        if (! $this->publication->jobDetailProjectionItemIsPublished($projection)
            || ! isset($this->content->authority()['entries'][$slug]['zh-TW'])) {
            return null;
        }

        return $this->content->subjectName($slug, 'zh-TW');
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
