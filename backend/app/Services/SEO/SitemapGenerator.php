<?php

namespace App\Services\SEO;

use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Models\CareerJobDisplayAsset;
use App\Models\ContentPage;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityPublicContentAsset;
use App\Models\TopicProfile;
use App\Services\Career\CareerDirectoryAuthorityService;
use App\Services\Career\Dataset\CareerDatasetPublicationMetadataService;
use App\Services\Cms\ArticleSeoService;
use App\Services\Cms\CareerGuideSeoService;
use App\Services\Cms\CareerJobSeoService;
use App\Services\Cms\Mbti64CrossTypeComparisonPublicReadModel;
use App\Services\Cms\PersonalityProfileSeoService;
use App\Services\Cms\PersonalityProfileService;
use App\Services\Cms\TopicProfileSeoService;
use App\Services\Scale\ScaleRegistry;
use Illuminate\Support\Carbon;

class SitemapGenerator
{
    private const CAREER_DISPLAY_SURFACE_VERSION = 'display.surface.v1';

    private const CAREER_DISPLAY_ASSET_VERSION = 'v4.2';

    private const CAREER_DISPLAY_ASSET_TYPE = 'career_job_public_display';

    private const CAREER_DISPLAY_READY_STATUS = 'ready_for_pilot';

    private const CAREER_DISPLAY_COMPONENT_ORDER_COUNT = 24;

    private const CAREER_DISPLAY_MANUAL_HOLD_SLUGS = [
        'software-developers',
    ];

    private const SCALE_FRONTEND_LOCALE_SEGMENTS = [
        'en' => 'en',
        'zh-CN' => 'zh',
    ];

    public function __construct(
        private readonly ArticleSeoService $articleSeoService,
        private readonly CareerGuideSeoService $careerGuideSeoService,
        private readonly CareerJobSeoService $careerJobSeoService,
        private readonly PersonalityProfileService $personalityProfileService,
        private readonly PersonalityProfileSeoService $personalityProfileSeoService,
        private readonly Mbti64CrossTypeComparisonPublicReadModel $crossTypeComparisonReadModel,
        private readonly TopicProfileSeoService $topicProfileSeoService,
        private readonly ScaleRegistry $scaleRegistry,
        private readonly CareerDatasetPublicationMetadataService $datasetPublicationMetadataService,
        private readonly CareerDirectoryAuthorityService $careerDirectoryAuthorityService,
    ) {}

    public function generate(): array
    {
        $urls = $this->generateUrls();

        $slugList = [];
        $maxUpdatedAt = null;

        foreach ($urls as $row) {
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug !== '') {
                $slugList[] = $slug;
            }

            $updatedValue = (string) ($row['updated_at'] ?? '');
            if ($updatedValue === '') {
                $updatedValue = (string) ($row['lastmod'] ?? '');
            }

            $updatedAt = $this->parseUpdatedAt($updatedValue);
            if ($updatedAt && ($maxUpdatedAt === null || $updatedAt->gt($maxUpdatedAt))) {
                $maxUpdatedAt = $updatedAt;
            }
        }

        $xml = $this->buildXml($urls);

        return [
            'xml' => $xml,
            'slug_list' => $slugList,
            'slug_count' => count($slugList),
            'max_updated_at' => $maxUpdatedAt ? $maxUpdatedAt->toDateTimeString() : '',
        ];
    }

    public function generateUrls(): array
    {
        $urls = array_merge(
            $this->getScaleUrls(),
            $this->getArticleUrls(),
            $this->getCareerJobUrls(),
            $this->getCareerGuideUrls(),
            $this->getPersonalityUrls(),
            $this->getPersonalityPublicContentAssetUrls(),
            $this->getPersonalityComparisonUrls(),
            $this->getTopicUrls(),
            $this->getContentPageUrls(),
            $this->getStaticIndexUrls()
        );

        $urls = collect($urls)
            ->unique('loc')
            ->values()
            ->all();

        usort($urls, static function (array $a, array $b): int {
            return strcmp((string) ($a['loc'] ?? ''), (string) ($b['loc'] ?? ''));
        });

        return $urls;
    }

    public function generateApprovedCareerJobDetailUrls(): array
    {
        return $this->getDirectoryAuthorityCareerJobDetailUrls();
    }

    private function getScaleUrls(): array
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        if ($baseUrl === '') {
            return [];
        }

        $rows = $this->scaleRegistry->listActivePublic(0);

        $slugDates = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (array_key_exists('is_indexable', $row) && ! (bool) ($row['is_indexable'] ?? true)) {
                continue;
            }

            if (! $this->isIndexablePublic($row['view_policy_json'] ?? null)) {
                continue;
            }

            $updatedAt = $this->parseUpdatedAt($row['updated_at'] ?? null);

            $slug = trim((string) ($row['primary_slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            if (! array_key_exists($slug, $slugDates)) {
                $slugDates[$slug] = $updatedAt;

                continue;
            }

            if ($updatedAt && (! $slugDates[$slug] || $updatedAt->gt($slugDates[$slug]))) {
                $slugDates[$slug] = $updatedAt;
            }
        }

        $slugList = array_keys($slugDates);
        sort($slugList, SORT_STRING);

        $urls = [];
        foreach ($slugList as $slug) {
            foreach (self::SCALE_FRONTEND_LOCALE_SEGMENTS as $segment) {
                $urls[] = [
                    'loc' => $baseUrl.'/'.$segment.'/tests/'.rawurlencode($slug),
                    'lastmod' => $this->formatLastmod($slugDates[$slug] ?? null),
                    'slug' => 'tests:'.$segment.':'.$slug,
                    'updated_at' => ($slugDates[$slug] ?? null)?->toDateTimeString(),
                ];
            }
        }

        return $urls;
    }

    private function getArticleUrls(): array
    {
        $rows = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->publiclySitemapEligible()
            ->whereIn('locale', ArticleSeoService::SUPPORTED_LOCALES)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->select(['slug', 'locale', 'updated_at', 'published_at'])
            ->orderBy('locale')
            ->orderBy('slug')
            ->get();

        $urls = [];
        $listLastModified = [];

        foreach ($rows as $row) {
            $slug = trim((string) $row->slug);
            $locale = trim((string) $row->locale);

            if ($slug === '' || $locale === '') {
                continue;
            }

            $lastmod = $row->updated_at
                ?? $row->published_at
                ?? now();

            $segment = $this->articleSeoService->mapBackendLocaleToFrontendSegment($locale);
            $url = $this->articleSeoService->buildCanonicalUrl($slug, $locale);

            if ($url === null) {
                continue;
            }

            $urls[] = [
                'loc' => $url,
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'articles:'.$segment.':'.$slug,
                'updated_at' => $lastmod->toDateTimeString(),
            ];

            if (! isset($listLastModified[$locale]) || $lastmod->gt($listLastModified[$locale])) {
                $listLastModified[$locale] = $lastmod;
            }
        }

        foreach ($listLastModified as $locale => $lastmod) {
            $url = $this->articleSeoService->buildListUrl((string) $locale);
            if ($url === null) {
                continue;
            }

            $urls[] = [
                'loc' => $url,
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'articles-list:'.$this->articleSeoService->mapBackendLocaleToFrontendSegment((string) $locale),
                'updated_at' => $lastmod->toDateTimeString(),
            ];
        }

        return $urls;
    }

    private function getPersonalityUrls(): array
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        if ($baseUrl === '') {
            return [];
        }

        $rows = $this->personalityProfileService->getSitemapPublicProfiles();

        $urls = [];
        $listLastModified = [];

        foreach ($rows as $row) {
            $locale = trim((string) $row->locale);
            $segment = $this->personalityProfileSeoService->mapBackendLocaleToFrontendSegment($locale);
            if ($locale === '') {
                continue;
            }

            foreach ($row->variants as $variant) {
                if (! $variant instanceof PersonalityProfileVariant) {
                    continue;
                }

                $canonical = trim((string) data_get(
                    $this->personalityProfileSeoService->buildMeta($row, $variant),
                    'canonical',
                    ''
                ));

                if ($canonical === '') {
                    continue;
                }

                $lastmod = $variant->updated_at
                    ?? $variant->published_at
                    ?? $row->updated_at
                    ?? $row->published_at
                    ?? now();

                $urls[] = [
                    'loc' => $canonical,
                    'lastmod' => $lastmod->toAtomString(),
                    'slug' => 'personality:'.$segment.':'.strtolower((string) $variant->runtime_type_code),
                    'updated_at' => $lastmod->toDateTimeString(),
                ];

                if (! isset($listLastModified[$segment]) || $lastmod->gt($listLastModified[$segment])) {
                    $listLastModified[$segment] = $lastmod;
                }
            }
        }

        foreach ($listLastModified as $segment => $lastmod) {
            $urls[] = [
                'loc' => $baseUrl.'/'.$segment.'/personality',
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'personality-list:'.$segment,
                'updated_at' => $lastmod->toDateTimeString(),
            ];
        }

        return $urls;
    }

    private function getPersonalityPublicContentAssetUrls(): array
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        if ($baseUrl === '') {
            return [];
        }

        $rows = PersonalityPublicContentAsset::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->whereIn('framework', [
                PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
                PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            ])
            ->where(static function ($query): void {
                $query
                    ->where(static function ($query): void {
                        $query
                            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
                            ->where('locale', 'zh-CN');
                    })
                    ->orWhere(static function ($query): void {
                        $query
                            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM)
                            ->whereIn('locale', ['zh-CN', 'en']);
                    });
            })
            ->whereIn('entity_type', [
                PersonalityPublicContentAsset::ENTITY_HUB,
                PersonalityPublicContentAsset::ENTITY_DOMAIN,
                PersonalityPublicContentAsset::ENTITY_POLARITY,
                PersonalityPublicContentAsset::ENTITY_CENTER,
                PersonalityPublicContentAsset::ENTITY_CORE_TYPE,
            ])
            ->where('is_public', true)
            ->where('launch_state', PersonalityPublicContentAsset::LAUNCH_PUBLISHED)
            ->where('robots', PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW)
            ->where('index_eligible', true)
            ->where('sitemap_eligible', true)
            ->select(['entity_key', 'framework', 'locale', 'canonical_json', 'updated_at', 'published_at'])
            ->orderBy('entity_type')
            ->orderBy('entity_key')
            ->get();

        $urls = [];

        foreach ($rows as $row) {
            $path = trim((string) data_get($row->canonical_json, 'path', ''));
            $framework = $row->framework;

            // Accept only Big Five or Enneagram personality paths
            $isBigFive = str_starts_with($path, '/zh/personality/big-five/')
                || $path === '/zh/personality/big-five';
            $isEnneagram = str_starts_with($path, '/zh/personality/enneagram/')
                || $path === '/zh/personality/enneagram'
                || str_starts_with($path, '/en/personality/enneagram/')
                || $path === '/en/personality/enneagram';

            if (! $isBigFive && ! $isEnneagram) {
                continue;
            }

            $frameworkKey = $framework === 'enneagram' ? 'enneagram' : 'big-five';

            $lastmod = $row->updated_at
                ?? $row->published_at
                ?? now();

            $urls[] = [
                'loc' => $baseUrl.$path,
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'personality-public-content:'.$frameworkKey.':'.($row->locale === 'en' ? 'en' : 'zh').':'.strtolower((string) $row->entity_key),
                'updated_at' => $lastmod->toDateTimeString(),
            ];
        }

        return $urls;
    }

    private function getPersonalityComparisonUrls(): array
    {
        return array_merge(
            $this->getPersonalityAtComparisonUrls(),
            $this->getPersonalityCrossTypeComparisonUrls()
        );
    }

    private function getPersonalityAtComparisonUrls(): array
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        if ($baseUrl === '') {
            return [];
        }

        $rows = $this->personalityProfileService->getSitemapPublicProfiles();
        $urls = [];

        foreach ($rows as $row) {
            $locale = trim((string) $row->locale);
            $segment = $this->personalityProfileSeoService->mapBackendLocaleToFrontendSegment($locale);
            $baseTypeCode = strtoupper(trim((string) $row->canonical_type_code ?: (string) $row->type_code));
            if ($segment === '' || ! in_array($baseTypeCode, PersonalityProfile::BASE_TYPE_CODES, true)) {
                continue;
            }

            $variants = [];
            foreach ($row->variants as $variant) {
                if (! $variant instanceof PersonalityProfileVariant || ! (bool) $variant->is_published) {
                    continue;
                }

                $variantCode = strtoupper(trim((string) $variant->variant_code));
                if (! in_array($variantCode, ['A', 'T'], true)) {
                    continue;
                }

                $variants[$variantCode] = $variant;
            }

            if (! isset($variants['A'], $variants['T'])) {
                continue;
            }

            $slug = strtolower($baseTypeCode).'-a-vs-'.strtolower($baseTypeCode).'-t';
            $lastmod = $this->latestCarbon([
                $variants['A']->updated_at,
                $variants['A']->published_at,
                $variants['T']->updated_at,
                $variants['T']->published_at,
                $row->updated_at,
                $row->published_at,
            ]) ?? now();

            $urls[] = [
                'loc' => $baseUrl.'/'.$segment.'/personality/'.$slug,
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'personality-comparison:at:'.$segment.':'.$slug,
                'updated_at' => $lastmod->toDateTimeString(),
            ];
        }

        return $urls;
    }

    private function getPersonalityCrossTypeComparisonUrls(): array
    {
        $urls = [];
        $updatedAt = now();

        foreach (PersonalityProfile::SUPPORTED_LOCALES as $locale) {
            $segment = $this->personalityProfileSeoService->mapBackendLocaleToFrontendSegment((string) $locale);
            if ($segment === '') {
                continue;
            }

            foreach ($this->crossTypeComparisonReadModel->list((string) $locale) as $item) {
                if (($item['is_public'] ?? false) !== true || ($item['is_indexable'] ?? false) !== true) {
                    continue;
                }

                $canonicalUrl = trim((string) ($item['canonical_url'] ?? $item['public_url'] ?? ''));
                $slug = trim((string) ($item['slug'] ?? ''));
                if ($canonicalUrl === '' || $slug === '') {
                    continue;
                }

                $urls[] = [
                    'loc' => $canonicalUrl,
                    'lastmod' => $updatedAt->toAtomString(),
                    'slug' => 'personality-comparison:cross-type:'.$segment.':'.$slug,
                    'updated_at' => $updatedAt->toDateTimeString(),
                ];
            }
        }

        return $urls;
    }

    private function getCareerJobUrls(): array
    {
        return $this->getCareerJobDetailUrls();
    }

    private function getCareerJobListUrls(): array
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        if ($baseUrl === '') {
            return [];
        }

        $rows = CareerJob::query()
            ->withoutGlobalScopes()
            ->with('seoMeta')
            ->where('org_id', 0)
            ->where('status', CareerJob::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->where('is_indexable', true)
            ->whereIn('locale', CareerJob::SUPPORTED_LOCALES)
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->select(['id', 'slug', 'locale', 'title', 'is_indexable', 'updated_at', 'published_at'])
            ->orderBy('locale')
            ->get();

        $listLastModified = [];

        foreach ($rows as $row) {
            $locale = trim((string) $row->locale);
            if ($locale === '') {
                continue;
            }

            if (! $this->careerJobSitemapIndexable($row, $locale)) {
                continue;
            }

            $segment = $this->careerJobSeoService->mapBackendLocaleToFrontendSegment($locale);
            $lastmod = $row->updated_at
                ?? $row->published_at
                ?? now();

            if (! isset($listLastModified[$segment]) || $lastmod->gt($listLastModified[$segment])) {
                $listLastModified[$segment] = $lastmod;
            }
        }

        $displayAssetLastmod = CareerJobDisplayAsset::query()
            ->where('surface_version', self::CAREER_DISPLAY_SURFACE_VERSION)
            ->where('asset_version', self::CAREER_DISPLAY_ASSET_VERSION)
            ->where('template_version', self::CAREER_DISPLAY_ASSET_VERSION)
            ->where('status', self::CAREER_DISPLAY_READY_STATUS)
            ->where('asset_type', self::CAREER_DISPLAY_ASSET_TYPE)
            ->max('updated_at');
        $displayAssetUpdatedAt = $this->parseUpdatedAt($displayAssetLastmod);
        if ($displayAssetUpdatedAt) {
            foreach (CareerJob::SUPPORTED_LOCALES as $locale) {
                $segment = $this->careerJobSeoService->mapBackendLocaleToFrontendSegment((string) $locale);
                if (! isset($listLastModified[$segment]) || $displayAssetUpdatedAt->gt($listLastModified[$segment])) {
                    $listLastModified[$segment] = $displayAssetUpdatedAt;
                }
            }
        }

        $urls = [];
        foreach ($listLastModified as $segment => $lastmod) {
            $urls[] = [
                'loc' => $baseUrl.'/'.$segment.'/career/jobs',
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'career-jobs-list:'.$segment,
                'updated_at' => $lastmod->toDateTimeString(),
            ];
        }

        return $urls;
    }

    private function getCareerJobDetailUrls(): array
    {
        return $this->getDirectoryAuthorityCareerJobDetailUrls();
    }

    private function getDirectoryAuthorityCareerJobDetailUrls(): array
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        if ($baseUrl === '') {
            return [];
        }

        $urls = [];
        foreach ([
            'en' => 'en',
            'zh-CN' => 'zh',
        ] as $locale => $segment) {
            foreach ($this->careerDirectoryAuthorityService->indexableItems($locale) as $item) {
                $slug = strtolower(trim((string) ($item['slug'] ?? '')));
                $path = trim((string) ($item['canonical_path'] ?? ''));
                if ($slug === '' || $path === '') {
                    continue;
                }

                $updatedAt = $this->parseUpdatedAt((string) ($item['updated_at'] ?? '')) ?? now('UTC');

                $urls[] = [
                    'loc' => $baseUrl.$path,
                    'lastmod' => $updatedAt->toAtomString(),
                    'slug' => 'career-jobs:'.$segment.':'.$slug,
                    'updated_at' => $updatedAt->toDateTimeString(),
                ];
            }
        }

        return $urls;
    }

    private function getCmsCareerJobDetailUrls(): array
    {
        $rows = CareerJob::query()
            ->withoutGlobalScopes()
            ->with('seoMeta')
            ->where('org_id', 0)
            ->where('status', CareerJob::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->where('is_indexable', true)
            ->whereIn('locale', CareerJob::SUPPORTED_LOCALES)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->whereNotIn('slug', self::CAREER_DISPLAY_MANUAL_HOLD_SLUGS)
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->select(['id', 'slug', 'locale', 'title', 'is_indexable', 'updated_at', 'published_at'])
            ->orderBy('locale')
            ->orderBy('slug')
            ->get();

        $urls = [];

        foreach ($rows as $row) {
            $slug = trim((string) $row->slug);
            $locale = trim((string) $row->locale);

            if ($slug === '' || $locale === '') {
                continue;
            }

            $frontendDetailAvailable = $this->careerJobSeoService->isFrontendDetailAvailable($row, $locale);
            if (! $this->careerJobSeoService->isPublicIndexable($row, $locale, $frontendDetailAvailable)) {
                continue;
            }

            $lastmod = $row->updated_at
                ?? $row->published_at
                ?? now();
            $meta = $this->careerJobSeoService->buildMeta($row, $locale, $frontendDetailAvailable);
            $canonical = trim((string) ($meta['canonical'] ?? ''));

            if ($canonical === '') {
                continue;
            }

            $urls[] = [
                'loc' => $canonical,
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'career-jobs:'.$this->careerJobSeoService->mapBackendLocaleToFrontendSegment($locale).':'.$slug,
                'updated_at' => $lastmod->toDateTimeString(),
            ];
        }

        return array_values(array_filter($urls, static fn (array $row): bool => ! empty($row['loc'])));
    }

    private function careerJobSitemapIndexable(CareerJob $job, string $locale): bool
    {
        $frontendDetailAvailable = $this->careerJobSeoService->isFrontendDetailAvailable($job, $locale);

        return $this->careerJobSeoService->isPublicIndexable($job, $locale, $frontendDetailAvailable);
    }

    private function getDisplayAssetCareerJobDetailUrls(): array
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        if ($baseUrl === '') {
            return [];
        }

        $assets = CareerJobDisplayAsset::query()
            ->with('occupation')
            ->where('surface_version', self::CAREER_DISPLAY_SURFACE_VERSION)
            ->where('asset_version', self::CAREER_DISPLAY_ASSET_VERSION)
            ->where('template_version', self::CAREER_DISPLAY_ASSET_VERSION)
            ->where('status', self::CAREER_DISPLAY_READY_STATUS)
            ->where('asset_type', self::CAREER_DISPLAY_ASSET_TYPE)
            ->orderBy('canonical_slug')
            ->get();

        $urls = [];

        foreach ($assets as $asset) {
            if (! $this->isSitemapEligibleCareerDisplayAsset($asset)) {
                continue;
            }

            $slug = strtolower(trim((string) $asset->canonical_slug));
            $lastmod = $asset->updated_at ?? $asset->created_at ?? now();

            foreach (CareerJob::SUPPORTED_LOCALES as $locale) {
                $segment = $this->careerJobSeoService->mapBackendLocaleToFrontendSegment((string) $locale);

                $urls[] = [
                    'loc' => $baseUrl.'/'.$segment.'/career/jobs/'.rawurlencode($slug),
                    'lastmod' => $lastmod->toAtomString(),
                    'slug' => 'career-jobs:'.$segment.':'.$slug,
                    'updated_at' => $lastmod->toDateTimeString(),
                ];
            }
        }

        return $urls;
    }

    private function isSitemapEligibleCareerDisplayAsset(CareerJobDisplayAsset $asset): bool
    {
        $slug = strtolower(trim((string) $asset->canonical_slug));
        if ($slug === '' || in_array($slug, self::CAREER_DISPLAY_MANUAL_HOLD_SLUGS, true)) {
            return false;
        }

        $occupation = $asset->occupation;
        if (! $occupation || strtolower(trim((string) $occupation->canonical_slug)) !== $slug) {
            return false;
        }

        $componentOrder = is_array($asset->component_order_json) ? array_values($asset->component_order_json) : [];
        if (count($componentOrder) !== self::CAREER_DISPLAY_COMPONENT_ORDER_COUNT) {
            return false;
        }

        $pages = is_array($asset->page_payload_json) ? $asset->page_payload_json : [];
        $localizedPages = is_array($pages['page'] ?? null) ? $pages['page'] : $pages;

        return is_array($localizedPages['zh'] ?? null) && is_array($localizedPages['en'] ?? null);
    }

    private function getCareerGuideUrls(): array
    {
        return array_merge(
            $this->getCareerGuideListUrls(),
            $this->getCareerGuideDetailUrls()
        );
    }

    private function getCareerGuideListUrls(): array
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        if ($baseUrl === '') {
            return [];
        }

        $rows = CareerGuide::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('status', CareerGuide::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->where('is_indexable', true)
            ->whereIn('locale', CareerGuide::SUPPORTED_LOCALES)
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->select(['locale', 'created_at', 'updated_at', 'published_at'])
            ->orderBy('locale')
            ->get();

        $listLastModified = [];

        foreach ($rows as $row) {
            $locale = trim((string) $row->locale);
            if ($locale === '') {
                continue;
            }

            $segment = $this->careerGuideSeoService->mapBackendLocaleToFrontendSegment($locale);
            $lastmod = $this->resolveCareerGuideLastmod($row);

            if (! isset($listLastModified[$segment]) || $lastmod->gt($listLastModified[$segment])) {
                $listLastModified[$segment] = $lastmod;
            }
        }

        $urls = [];
        foreach ($listLastModified as $segment => $lastmod) {
            $urls[] = [
                'loc' => $baseUrl.'/'.$segment.'/career/guides',
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'career-guides-list:'.$segment,
                'updated_at' => $lastmod->toDateTimeString(),
            ];
        }

        return $urls;
    }

    private function getCareerGuideDetailUrls(): array
    {
        $rows = CareerGuide::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('status', CareerGuide::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->where('is_indexable', true)
            ->whereIn('locale', CareerGuide::SUPPORTED_LOCALES)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->select(['slug', 'locale', 'created_at', 'updated_at', 'published_at'])
            ->orderBy('locale')
            ->orderBy('slug')
            ->get();

        $urls = [];

        foreach ($rows as $row) {
            $slug = trim((string) $row->slug);
            $locale = trim((string) $row->locale);

            if ($slug === '' || $locale === '') {
                continue;
            }

            $lastmod = $this->resolveCareerGuideLastmod($row);

            $urls[] = [
                'loc' => $this->careerGuideSeoService->buildCanonicalUrl($row, $locale),
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'career-guides:'.$this->careerGuideSeoService->mapBackendLocaleToFrontendSegment($locale).':'.$slug,
                'updated_at' => $lastmod->toDateTimeString(),
            ];
        }

        return array_values(array_filter($urls, static fn (array $row): bool => ! empty($row['loc'])));
    }

    private function getTopicUrls(): array
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        if ($baseUrl === '') {
            return [];
        }

        $rows = TopicProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('status', TopicProfile::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->where('is_indexable', true)
            ->whereIn('locale', TopicProfile::SUPPORTED_LOCALES)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->select(['slug', 'locale', 'updated_at', 'published_at'])
            ->orderBy('locale')
            ->orderBy('slug')
            ->get();

        $urls = [];
        $listLastModified = [];

        foreach ($rows as $row) {
            $slug = trim((string) $row->slug);
            $locale = trim((string) $row->locale);

            if ($slug === '' || $locale === '') {
                continue;
            }

            $segment = $this->topicProfileSeoService->mapBackendLocaleToFrontendSegment($locale);
            $lastmod = $row->updated_at
                ?? $row->published_at
                ?? now();

            $urls[] = [
                'loc' => $baseUrl.'/'.$segment.'/topics/'.rawurlencode($slug),
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'topics:'.$segment.':'.$slug,
                'updated_at' => $lastmod->toDateTimeString(),
            ];

            if (! isset($listLastModified[$segment]) || $lastmod->gt($listLastModified[$segment])) {
                $listLastModified[$segment] = $lastmod;
            }
        }

        foreach ($listLastModified as $segment => $lastmod) {
            $urls[] = [
                'loc' => $baseUrl.'/'.$segment.'/topics',
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'topics-list:'.$segment,
                'updated_at' => $lastmod->toDateTimeString(),
            ];
        }

        return $urls;
    }

    private function getContentPageUrls(): array
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        if ($baseUrl === '') {
            return [];
        }

        $rows = ContentPage::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->publiclyIndexable()
            ->whereIn('locale', ['en', 'zh-CN'])
            ->orderBy('locale')
            ->orderBy('slug')
            ->get();

        $urls = [];
        foreach ($rows as $row) {
            if (! $row instanceof ContentPage || ! $this->hasRequiredContentPageFields($row)) {
                continue;
            }

            if ((string) $row->kind === ContentPage::KIND_HELP) {
                continue;
            }

            $path = $this->contentPageCanonicalPath($row);
            if ($path === null) {
                continue;
            }

            $lastmod = $row->updated_at
                ?? $row->published_at
                ?? $row->source_updated_at
                ?? now();

            $urls[] = [
                'loc' => $baseUrl.$path,
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'content-pages:'.$this->contentPageLocaleSegment((string) $row->locale).':'.(string) $row->slug,
                'updated_at' => $lastmod->toDateTimeString(),
            ];
        }

        return $urls;
    }

    private function getStaticIndexUrls(): array
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        if ($baseUrl === '') {
            return [];
        }

        $updatedAt = now('UTC');
        $paths = [
            '/',
            '/en',
            '/en/business',
            '/en/career',
            '/en/career/guides',
            '/en/career/recommendations',
            '/en/career/tests',
            '/en/support',
            '/en/tests',
            '/en/tests/category/career',
            '/en/tests/category/personality',
            '/zh/business',
            '/zh/career',
            '/zh/career/guides',
            '/zh/career/recommendations',
            '/zh/career/tests',
            '/zh/support',
            '/zh/tests',
            '/zh/tests/category/career',
            '/zh/tests/category/personality',
        ];

        return array_map(static fn (string $path): array => [
            'loc' => $baseUrl.$path,
            'lastmod' => $updatedAt->toAtomString(),
            'slug' => 'static-index:'.($path === '/' ? 'root' : trim(str_replace('/', ':', $path), ':')),
            'updated_at' => $updatedAt->toDateTimeString(),
        ], $paths);
    }

    private function contentPageCanonicalPath(ContentPage $page): ?string
    {
        $localeSegment = $this->contentPageLocaleSegment((string) $page->locale);
        if ($localeSegment === '') {
            return null;
        }

        $path = trim((string) ($page->canonical_path ?: $page->path));
        if ($path === '') {
            $slug = trim((string) $page->slug);
            if ($slug === '') {
                return null;
            }

            $path = str_starts_with($slug, 'help-') && (string) $page->kind === ContentPage::KIND_HELP
                ? '/help/'.substr($slug, 5)
                : '/'.$slug;
        }

        $path = '/'.ltrim($path, '/');

        if (preg_match('#^/(en|zh)(?:/|$)#', $path) === 1) {
            return str_starts_with($path, '/'.$localeSegment.'/') || $path === '/'.$localeSegment
                ? $path
                : null;
        }

        if ($path === '/') {
            return $localeSegment === 'zh' ? '/' : '/en';
        }

        return '/'.$localeSegment.$path;
    }

    private function contentPageLocaleSegment(string $locale): string
    {
        return match ($locale) {
            'zh-CN', 'zh' => 'zh',
            'en' => 'en',
            default => '',
        };
    }

    private function hasRequiredContentPageFields(ContentPage $page): bool
    {
        if (trim((string) $page->slug) === '' || trim((string) $page->title) === '') {
            return false;
        }

        return trim((string) $page->content_md) !== ''
            || trim((string) $page->content_html) !== '';
    }

    private function parseUpdatedAt($updatedAt): ?Carbon
    {
        if ($updatedAt === null || $updatedAt === '') {
            return null;
        }

        try {
            return Carbon::parse($updatedAt);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function latestCarbon(array $values): ?Carbon
    {
        $latest = null;

        foreach ($values as $value) {
            $candidate = $value instanceof Carbon ? $value : $this->parseUpdatedAt($value);
            if ($candidate instanceof Carbon && ($latest === null || $candidate->gt($latest))) {
                $latest = $candidate;
            }
        }

        return $latest;
    }

    private function isIndexablePublic(mixed $viewPolicyJson): bool
    {
        $policy = [];
        if (is_array($viewPolicyJson)) {
            $policy = $viewPolicyJson;
        } elseif (is_string($viewPolicyJson) && trim($viewPolicyJson) !== '') {
            $decoded = json_decode($viewPolicyJson, true);
            if (is_array($decoded)) {
                $policy = $decoded;
            }
        }

        $isPublic = $policy['public'] ?? $policy['is_public'] ?? $policy['visibility'] ?? null;
        if (is_string($isPublic)) {
            $normalizedVisibility = strtolower(trim($isPublic));
            if (in_array($normalizedVisibility, ['private', 'internal', 'hidden'], true)) {
                return false;
            }
        } elseif (is_bool($isPublic) && $isPublic === false) {
            return false;
        }

        $indexable = $policy['indexable'] ?? null;
        if (is_bool($indexable) && $indexable === false) {
            return false;
        }

        $robots = $policy['robots'] ?? null;
        if (is_string($robots) && str_contains(strtolower($robots), 'noindex')) {
            return false;
        }

        return true;
    }

    private function resolveCareerGuideLastmod(CareerGuide $guide): Carbon
    {
        return $guide->updated_at
            ?? $guide->published_at
            ?? $guide->created_at
            ?? now();
    }

    private function buildXml(array $urls): string
    {
        $lines = [];
        $lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($urls as $row) {
            $loc = trim((string) ($row['loc'] ?? ''));
            if ($loc === '') {
                continue;
            }

            $lastmod = trim((string) ($row['lastmod'] ?? ''));
            if ($lastmod === '') {
                $lastmod = '1970-01-01';
            }

            $lines[] = '  <url>';
            $lines[] = '    <loc>'.htmlspecialchars($loc, ENT_XML1).'</loc>';
            $lines[] = '    <lastmod>'.htmlspecialchars($lastmod, ENT_XML1).'</lastmod>';
            $lines[] = '    <changefreq>weekly</changefreq>';
            $lines[] = '    <priority>0.7</priority>';
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines)."\n";
    }

    private function formatLastmod(?Carbon $updatedAt): string
    {
        if (! $updatedAt) {
            return '1970-01-01';
        }

        return $updatedAt->toDateString();
    }
}
