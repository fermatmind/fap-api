<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Sources;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ContentPage;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\ResearchReport;
use App\Services\Scale\ScaleRegistry;
use App\Services\SeoIntel\UrlTruthInventoryRecord;
use Illuminate\Support\Carbon;

final class BackendAuthorityUrlTruthSource implements UrlTruthInventorySource
{
    private bool $scaleCatalogAttempted = false;

    private bool $scaleCatalogAvailable = false;

    private ?string $scaleCatalogUnavailableReason = null;

    private bool $researchReportsAttempted = false;

    private bool $researchReportsAvailable = false;

    private ?string $researchReportsUnavailableReason = null;

    private bool $contentPagesAttempted = false;

    private bool $contentPagesAvailable = false;

    private ?string $contentPagesUnavailableReason = null;

    private bool $articlesAttempted = false;

    private bool $articlesAvailable = false;

    private ?string $articlesUnavailableReason = null;

    private bool $personalityProfilesAttempted = false;

    private bool $personalityProfilesAvailable = false;

    private ?string $personalityProfilesUnavailableReason = null;

    /**
     * @return list<UrlTruthInventoryRecord>
     */
    public function candidates(): array
    {
        return $this->uniqueRecords([
            ...$this->scaleCatalogCandidates(),
            ...$this->researchReportCandidates(),
            ...$this->contentPageCandidates(),
            ...$this->articleCandidates(),
            ...$this->personalityProfileCandidates(),
            ...$this->configuredBackendAuthorityCandidates(),
        ]);
    }

    public function metadata(): array
    {
        return [
            'source' => 'backend_authority_url_truth_candidate_source',
            'source_authority' => (string) config('seo_intel.url_truth_inventory.source_authority', 'backend_sitemap_source'),
            'backend_sitemap_source_available' => true,
            'scale_catalog_attempted' => $this->scaleCatalogAttempted,
            'scale_catalog_available' => $this->scaleCatalogAvailable,
            'scale_catalog_unavailable_reason' => $this->scaleCatalogUnavailableReason,
            'research_reports_attempted' => $this->researchReportsAttempted,
            'research_reports_available' => $this->researchReportsAvailable,
            'research_reports_unavailable_reason' => $this->researchReportsUnavailableReason,
            'content_pages_attempted' => $this->contentPagesAttempted,
            'content_pages_available' => $this->contentPagesAvailable,
            'content_pages_unavailable_reason' => $this->contentPagesUnavailableReason,
            'articles_attempted' => $this->articlesAttempted,
            'articles_available' => $this->articlesAvailable,
            'articles_unavailable_reason' => $this->articlesUnavailableReason,
            'personality_profiles_attempted' => $this->personalityProfilesAttempted,
            'personality_profiles_available' => $this->personalityProfilesAvailable,
            'personality_profiles_unavailable_reason' => $this->personalityProfilesUnavailableReason,
            'configured_backend_authority_canary_available' => $this->configuredBackendAuthorityCandidates() !== [],
            'fetches_public_html' => false,
            'external_api_calls' => false,
            'node2_local_laravel_data_source' => false,
            'node2_local_db_data_source' => false,
            'frontend_fallback_data_source' => false,
            'static_llms_fallback_graph_truth' => false,
            'synthetic_production_fixture' => false,
        ];
    }

    /**
     * @return list<UrlTruthInventoryRecord>
     */
    private function articleCandidates(): array
    {
        $this->articlesAttempted = true;

        try {
            $articles = Article::query()
                ->withoutGlobalScopes()
                ->with('seoMeta')
                ->where('org_id', 0)
                ->publiclySitemapEligible()
                ->where('llms_eligible', true)
                ->whereIn('locale', ['en', 'zh-CN'])
                ->orderBy('locale')
                ->orderBy('slug')
                ->get();
        } catch (\Throwable) {
            $this->articlesUnavailableReason = 'articles_unavailable';

            return [];
        }

        $records = [];
        foreach ($articles as $article) {
            if (! $article instanceof Article || ! $this->hasRequiredArticleFields($article)) {
                continue;
            }

            $seoMeta = $article->seoMeta instanceof ArticleSeoMeta ? $article->seoMeta : null;
            $path = $this->articleCanonicalPath($article, $seoMeta);
            if ($path === null) {
                continue;
            }

            $updatedAt = $article->updated_at instanceof Carbon ? $article->updated_at : null;
            $lastmodAt = $updatedAt
                ?? ($article->published_at instanceof Carbon ? $article->published_at : null);

            $records[] = new UrlTruthInventoryRecord(
                canonicalUrl: $this->canonicalUrl($path),
                locale: (string) $article->locale,
                pageEntityType: 'article',
                entityIdOrSlug: (string) $article->id,
                sourceAuthority: 'backend_cms',
                indexabilityState: 'indexable',
                lastmodAt: $lastmodAt,
                lastmodSource: 'articles.updated_at',
                cluster: 'articles',
                entitySource: 'articles',
                authorityStatus: 'published_approved',
                sourceUpdatedAt: $updatedAt,
                metadata: [
                    'source_table_hash' => hash('sha256', 'articles'),
                    'canonical_path_hash' => hash('sha256', $path),
                    'slug_hash' => hash('sha256', (string) $article->slug),
                    'translation_group_hash' => hash('sha256', (string) $article->translation_group_id),
                    'claim_boundary_state' => 'claim_safe',
                    'claim_safe' => true,
                    'sitemap_eligible' => true,
                    'llms_eligible' => true,
                    'publication_state' => 'published',
                    'robots' => 'index',
                ],
                attributes: [
                    'source_authority' => 'backend_cms',
                    'claim_safe' => true,
                    'article_id_hash' => hash('sha256', (string) $article->id),
                    'translation_group_hash' => hash('sha256', (string) $article->translation_group_id),
                ],
            );
        }

        $this->articlesAvailable = $records !== [];
        if (! $this->articlesAvailable) {
            $this->articlesUnavailableReason = 'articles_empty_or_ineligible';
        }

        return $records;
    }

    /**
     * @return list<UrlTruthInventoryRecord>
     */
    private function personalityProfileCandidates(): array
    {
        $this->personalityProfilesAttempted = true;

        try {
            $profiles = PersonalityProfile::query()
                ->withoutGlobalScopes()
                ->with(['variants' => static function ($query): void {
                    $query
                        ->withoutGlobalScopes()
                        ->where('is_published', true)
                        ->where(static function ($builder): void {
                            $builder->whereNull('published_at')
                                ->orWhere('published_at', '<=', now());
                        })
                        ->orderBy('variant_code');
                }])
                ->where('org_id', 0)
                ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
                ->whereIn('type_code', PersonalityProfile::BASE_TYPE_CODES)
                ->whereIn('locale', PersonalityProfile::SUPPORTED_LOCALES)
                ->where('status', 'published')
                ->where('is_public', true)
                ->where('is_indexable', true)
                ->where(static function ($builder): void {
                    $builder->whereNull('published_at')
                        ->orWhere('published_at', '<=', now());
                })
                ->orderBy('locale')
                ->orderBy('type_code')
                ->get();
        } catch (\Throwable) {
            $this->personalityProfilesUnavailableReason = 'personality_profiles_unavailable';

            return [];
        }

        $records = [];
        foreach ($profiles as $profile) {
            if (! $profile instanceof PersonalityProfile || ! $this->hasRequiredPersonalityProfileFields($profile)) {
                continue;
            }

            $variants = $profile->variants
                ->filter(static fn (PersonalityProfileVariant $variant): bool => trim((string) $variant->runtime_type_code) !== '')
                ->values();

            foreach ($variants as $variant) {
                $path = $this->personalityVariantCanonicalPath($profile, $variant);
                if ($path === null) {
                    continue;
                }

                $records[] = $this->personalityRecord(
                    canonicalPath: $path,
                    locale: (string) $profile->locale,
                    pageEntityType: 'personality_profile_variant',
                    entityIdOrSlug: (string) $variant->id,
                    entitySource: 'personality_profile_variants',
                    lastmodSource: 'personality_profile_variants.updated_at',
                    sourceUpdatedAt: $variant->updated_at instanceof Carbon ? $variant->updated_at : null,
                    lastmodAt: $variant->updated_at instanceof Carbon ? $variant->updated_at : null,
                    extraMetadata: [
                        'profile_id_hash' => hash('sha256', (string) $profile->id),
                        'variant_id_hash' => hash('sha256', (string) $variant->id),
                        'runtime_type_code_hash' => hash('sha256', (string) $variant->runtime_type_code),
                        'canonical_type_code_hash' => hash('sha256', (string) $variant->canonical_type_code),
                    ],
                    extraAttributes: [
                        'profile_id_hash' => hash('sha256', (string) $profile->id),
                        'variant_id_hash' => hash('sha256', (string) $variant->id),
                        'runtime_type_code_hash' => hash('sha256', (string) $variant->runtime_type_code),
                    ],
                );
            }

            $comparisonPath = $this->personalityComparisonCanonicalPath($profile, $variants);
            if ($comparisonPath !== null) {
                $updatedAt = $profile->updated_at instanceof Carbon ? $profile->updated_at : null;
                $records[] = $this->personalityRecord(
                    canonicalPath: $comparisonPath,
                    locale: (string) $profile->locale,
                    pageEntityType: 'personality_profile_comparison',
                    entityIdOrSlug: (string) $profile->id,
                    entitySource: 'personality_profiles',
                    lastmodSource: 'personality_profiles.updated_at',
                    sourceUpdatedAt: $updatedAt,
                    lastmodAt: $updatedAt,
                    extraMetadata: [
                        'profile_id_hash' => hash('sha256', (string) $profile->id),
                        'canonical_type_code_hash' => hash('sha256', (string) $profile->canonical_type_code),
                        'comparison_kind' => 'a_vs_t',
                    ],
                    extraAttributes: [
                        'profile_id_hash' => hash('sha256', (string) $profile->id),
                        'canonical_type_code_hash' => hash('sha256', (string) $profile->canonical_type_code),
                    ],
                );
            }
        }

        $this->personalityProfilesAvailable = $records !== [];
        if (! $this->personalityProfilesAvailable) {
            $this->personalityProfilesUnavailableReason = 'personality_profiles_empty_or_ineligible';
        }

        return $records;
    }

    /**
     * @return list<UrlTruthInventoryRecord>
     */
    private function contentPageCandidates(): array
    {
        $this->contentPagesAttempted = true;

        try {
            $pages = ContentPage::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->publiclyIndexable()
                ->whereIn('locale', ['en', 'zh-CN'])
                ->orderBy('locale')
                ->orderBy('slug')
                ->get();
        } catch (\Throwable) {
            $this->contentPagesUnavailableReason = 'content_pages_unavailable';

            return [];
        }

        $records = [];
        foreach ($pages as $page) {
            if (! $page instanceof ContentPage || ! $this->hasRequiredContentPageFields($page)) {
                continue;
            }

            $path = $this->contentPageCanonicalPath($page);
            if ($path === null) {
                continue;
            }

            $updatedAt = $page->updated_at instanceof Carbon ? $page->updated_at : null;
            $lastmodAt = $updatedAt
                ?? ($page->published_at instanceof Carbon ? $page->published_at : null)
                ?? ($page->source_updated_at instanceof Carbon ? $page->source_updated_at : null);

            $records[] = new UrlTruthInventoryRecord(
                canonicalUrl: $this->canonicalUrl($path),
                locale: (string) $page->locale,
                pageEntityType: 'content_page',
                entityIdOrSlug: (string) $page->slug,
                sourceAuthority: 'backend_cms',
                indexabilityState: 'indexable',
                lastmodAt: $lastmodAt,
                lastmodSource: 'content_pages.updated_at',
                cluster: 'content_pages',
                entitySource: 'content_pages',
                authorityStatus: 'published_approved',
                sourceUpdatedAt: $updatedAt,
                metadata: [
                    'source_table_hash' => hash('sha256', 'content_pages'),
                    'canonical_path_hash' => hash('sha256', $path),
                    'kind_hash' => hash('sha256', (string) $page->kind),
                    'page_type_hash' => hash('sha256', (string) ($page->page_type ?? '')),
                    'claim_boundary_state' => 'claim_safe',
                    'claim_safe' => true,
                    'publication_state' => 'published',
                    'robots' => 'index',
                    'frontend_fallback' => false,
                    'static_sitemap_fallback' => false,
                    'static_llms_fallback' => false,
                    'private_flow' => false,
                ],
                attributes: [
                    'source_authority' => 'backend_cms',
                    'claim_safe' => true,
                    'translation_group_hash' => hash('sha256', (string) ($page->translation_group_id ?? '')),
                ],
            );
        }

        $this->contentPagesAvailable = $records !== [];
        if (! $this->contentPagesAvailable) {
            $this->contentPagesUnavailableReason = 'content_pages_empty_or_ineligible';
        }

        return $records;
    }

    /**
     * @return list<UrlTruthInventoryRecord>
     */
    private function scaleCatalogCandidates(): array
    {
        $this->scaleCatalogAttempted = true;

        try {
            $rows = app(ScaleRegistry::class)->listActivePublic(0);
        } catch (\Throwable $exception) {
            $this->scaleCatalogUnavailableReason = 'scale_catalog_unavailable';

            return [];
        }

        $records = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = trim((string) ($row['primary_slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $updatedAt = $this->parseUpdatedAt($row['updated_at'] ?? null);

            foreach ($this->publicScaleCatalogLocales($row) as $locale) {
                $records[] = new UrlTruthInventoryRecord(
                    canonicalUrl: $this->canonicalUrl('/'.$this->publicLocaleSegment($locale).'/tests/'.$slug),
                    locale: $locale,
                    pageEntityType: 'test_detail',
                    entityIdOrSlug: $slug,
                    sourceAuthority: 'scale_catalog',
                    indexabilityState: 'indexable',
                    lastmodAt: $updatedAt,
                    lastmodSource: 'scales_registry.updated_at',
                    cluster: 'tests',
                    entitySource: 'scales_registry',
                    authorityStatus: 'observed',
                    sourceUpdatedAt: $updatedAt,
                    metadata: [
                        'source_table_hash' => hash('sha256', 'scales_registry'),
                        'scale_code_hash' => hash('sha256', (string) ($row['code'] ?? $slug)),
                    ],
                    attributes: [
                        'source_authority' => 'scale_catalog',
                    ],
                );
            }
        }

        $this->scaleCatalogAvailable = $records !== [];
        if (! $this->scaleCatalogAvailable) {
            $this->scaleCatalogUnavailableReason = 'scale_catalog_empty';
        }

        return $records;
    }

    /**
     * @return list<UrlTruthInventoryRecord>
     */
    private function researchReportCandidates(): array
    {
        $this->researchReportsAttempted = true;

        try {
            $reports = ResearchReport::query()
                ->publiclyReadable()
                ->orderBy('locale')
                ->orderBy('slug')
                ->limit(max(1, (int) config('seo_intel.url_truth_inventory.research_report_candidate_limit', 100)))
                ->get();
        } catch (\Throwable) {
            $this->researchReportsUnavailableReason = 'research_reports_unavailable';

            return [];
        }

        $records = [];
        foreach ($reports as $report) {
            if (! $report instanceof ResearchReport) {
                continue;
            }

            $path = $this->researchCanonicalPath($report);
            if ($path === null || ! $this->hasRequiredResearchSafetyFields($report)) {
                continue;
            }

            $updatedAt = $report->updated_at instanceof Carbon ? $report->updated_at : null;

            $records[] = new UrlTruthInventoryRecord(
                canonicalUrl: $this->canonicalUrl($path),
                locale: $report->locale,
                pageEntityType: ResearchReport::PAGE_ENTITY_TYPE,
                entityIdOrSlug: $report->slug,
                sourceAuthority: 'backend_cms',
                indexabilityState: 'indexable',
                lastmodAt: $updatedAt,
                lastmodSource: 'research_reports.updated_at',
                cluster: 'research',
                entitySource: 'research_reports',
                authorityStatus: 'published_approved',
                sourceUpdatedAt: $updatedAt,
                metadata: [
                    'source_table_hash' => hash('sha256', 'research_reports'),
                    'canonical_path_hash' => hash('sha256', $path),
                    'research_type_hash' => hash('sha256', (string) $report->research_type),
                ],
                attributes: [
                    'source_authority' => 'backend_cms',
                    'claim_safe' => true,
                    'research_type_hash' => hash('sha256', (string) $report->research_type),
                ],
            );
        }

        $this->researchReportsAvailable = $records !== [];
        if (! $this->researchReportsAvailable) {
            $this->researchReportsUnavailableReason = 'research_reports_empty_or_ineligible';
        }

        return $records;
    }

    /**
     * @return list<UrlTruthInventoryRecord>
     */
    private function configuredBackendAuthorityCandidates(): array
    {
        $records = [];
        $candidates = config('seo_intel.url_truth_inventory.backend_authority_canary_candidates', []);

        if (! is_array($candidates)) {
            return [];
        }

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $path = trim((string) ($candidate['path'] ?? ''));
            $pageEntityType = trim((string) ($candidate['page_entity_type'] ?? ''));
            $sourceAuthority = trim((string) ($candidate['source_authority'] ?? ''));

            if ($path === '' || $pageEntityType === '' || $sourceAuthority === '') {
                continue;
            }

            $records[] = new UrlTruthInventoryRecord(
                canonicalUrl: $this->canonicalUrl($path),
                locale: (string) ($candidate['locale'] ?? 'en'),
                pageEntityType: $pageEntityType,
                entityIdOrSlug: (string) ($candidate['entity_id_or_slug'] ?? $path),
                sourceAuthority: $sourceAuthority,
                indexabilityState: 'indexable',
                lastmodSource: 'backend_authority_canary_contract',
                cluster: (string) ($candidate['cluster'] ?? 'public_surface'),
                entitySource: 'backend_authority',
                authorityStatus: 'canary_contract',
                metadata: [
                    'source_contract' => 'backend_authority_canary_candidates',
                    'path_hash' => hash('sha256', $path),
                ],
                attributes: [
                    'source_authority' => $sourceAuthority,
                ],
            );
        }

        return $records;
    }

    private function researchCanonicalPath(ResearchReport $report): ?string
    {
        $slug = trim((string) $report->slug);
        if ($slug === '' || str_contains($slug, 'turnover-rate-report')) {
            return null;
        }

        $canonicalPath = trim((string) $report->canonical_path);
        if ($canonicalPath !== '') {
            if (! $this->isSafeResearchRoutePath($canonicalPath, $slug)) {
                return null;
            }

            return $canonicalPath;
        }

        $localeSegment = match ($report->locale) {
            'zh-CN', 'zh' => 'zh',
            'en' => 'en',
            default => null,
        };

        if ($localeSegment === null) {
            return null;
        }

        return '/'.$localeSegment.'/research/'.$slug;
    }

    private function articleCanonicalPath(Article $article, ?ArticleSeoMeta $seoMeta): ?string
    {
        $slug = trim((string) $article->slug);
        if ($slug === '') {
            return null;
        }

        $canonicalUrl = trim((string) ($seoMeta?->canonical_url ?? ''));
        if ($canonicalUrl !== '') {
            $path = (string) parse_url($canonicalUrl, PHP_URL_PATH);
            if ($this->isSafeArticleRoutePath($path, $slug, (string) $article->locale)) {
                return $path;
            }

            return null;
        }

        $localeSegment = match ((string) $article->locale) {
            'zh-CN', 'zh' => 'zh',
            'en' => 'en',
            default => null,
        };

        if ($localeSegment === null) {
            return null;
        }

        return '/'.$localeSegment.'/articles/'.$slug;
    }

    private function isSafeArticleRoutePath(string $path, string $slug, string $locale): bool
    {
        $normalized = '/'.ltrim($path, '/');
        $localeSegment = match ($locale) {
            'zh-CN', 'zh' => 'zh',
            'en' => 'en',
            default => null,
        };

        return $localeSegment !== null
            && (bool) preg_match('#^/'.preg_quote($localeSegment, '#').'/articles/'.preg_quote($slug, '#').'$#', $normalized);
    }

    private function isSafeResearchRoutePath(string $path, string $slug): bool
    {
        $normalized = '/'.ltrim($path, '/');

        return (bool) preg_match('#^/(en|zh)/research/'.preg_quote($slug, '#').'$#', $normalized)
            && ! str_contains($normalized, '/articles/')
            && ! str_contains($normalized, '/reports/')
            && ! str_contains($normalized, 'turnover-rate-report');
    }

    private function hasRequiredResearchSafetyFields(ResearchReport $report): bool
    {
        if (
            trim((string) $report->methodology) === ''
            || trim((string) $report->sample_disclaimer) === ''
            || trim((string) $report->claim_boundary) === ''
        ) {
            return false;
        }

        return is_array($report->references) && $report->references !== [];
    }

    private function hasRequiredArticleFields(Article $article): bool
    {
        return trim((string) $article->slug) !== ''
            && trim((string) $article->title) !== ''
            && (string) $article->status === 'published'
            && (bool) $article->is_public
            && (bool) $article->is_indexable
            && (bool) $article->sitemap_eligible
            && (bool) $article->llms_eligible
            && $article->published_revision_id !== null;
    }

    private function contentPageCanonicalPath(ContentPage $page): ?string
    {
        $localeSegment = match ($page->locale) {
            'zh-CN', 'zh' => 'zh',
            'en' => 'en',
            default => null,
        };

        if ($localeSegment === null) {
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

        if ($localeSegment === 'en' && $this->isRootLevelContentPagePath($path)) {
            return $path;
        }

        return '/'.$localeSegment.$path;
    }

    private function isRootLevelContentPagePath(string $path): bool
    {
        return preg_match('#^/[a-z0-9][a-z0-9_-]*$#', $path) === 1;
    }

    private function hasRequiredContentPageFields(ContentPage $page): bool
    {
        if (trim((string) $page->slug) === '' || trim((string) $page->title) === '') {
            return false;
        }

        return trim((string) $page->content_md) !== ''
            || trim((string) $page->content_html) !== '';
    }

    private function hasRequiredPersonalityProfileFields(PersonalityProfile $profile): bool
    {
        return trim((string) $profile->type_code) !== ''
            && trim((string) $profile->canonical_type_code) !== ''
            && in_array((string) $profile->canonical_type_code, PersonalityProfile::BASE_TYPE_CODES, true)
            && trim((string) $profile->title) !== ''
            && (string) $profile->status === 'published'
            && (bool) $profile->is_public
            && (bool) $profile->is_indexable;
    }

    private function personalityVariantCanonicalPath(
        PersonalityProfile $profile,
        PersonalityProfileVariant $variant
    ): ?string {
        $localeSegment = match ((string) $profile->locale) {
            'zh-CN', 'zh' => 'zh',
            'en' => 'en',
            default => null,
        };

        $runtimeTypeCode = strtolower(trim((string) $variant->runtime_type_code));

        if ($localeSegment === null || preg_match('/^[a-z]{4}-[at]$/', $runtimeTypeCode) !== 1) {
            return null;
        }

        return '/'.$localeSegment.'/personality/'.$runtimeTypeCode;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PersonalityProfileVariant>  $variants
     */
    private function personalityComparisonCanonicalPath(PersonalityProfile $profile, $variants): ?string
    {
        $localeSegment = match ((string) $profile->locale) {
            'zh-CN', 'zh' => 'zh',
            'en' => 'en',
            default => null,
        };

        $typeCode = strtolower(trim((string) $profile->canonical_type_code));
        if ($localeSegment === null || preg_match('/^[a-z]{4}$/', $typeCode) !== 1) {
            return null;
        }

        $variantCodes = $variants
            ->map(static fn (PersonalityProfileVariant $variant): string => strtoupper(trim((string) $variant->variant_code)))
            ->unique()
            ->values()
            ->all();

        if (! in_array('A', $variantCodes, true) || ! in_array('T', $variantCodes, true)) {
            return null;
        }

        return '/'.$localeSegment.'/personality/'.$typeCode.'-a-vs-'.$typeCode.'-t';
    }

    /**
     * @param  array<string, mixed>  $extraMetadata
     * @param  array<string, mixed>  $extraAttributes
     */
    private function personalityRecord(
        string $canonicalPath,
        string $locale,
        string $pageEntityType,
        string $entityIdOrSlug,
        string $entitySource,
        string $lastmodSource,
        ?Carbon $sourceUpdatedAt,
        ?Carbon $lastmodAt,
        array $extraMetadata = [],
        array $extraAttributes = [],
    ): UrlTruthInventoryRecord {
        return new UrlTruthInventoryRecord(
            canonicalUrl: $this->canonicalUrl($canonicalPath),
            locale: $locale,
            pageEntityType: $pageEntityType,
            entityIdOrSlug: $entityIdOrSlug,
            sourceAuthority: 'backend_cms',
            indexabilityState: 'indexable',
            lastmodAt: $lastmodAt,
            lastmodSource: $lastmodSource,
            cluster: 'personality',
            entitySource: $entitySource,
            authorityStatus: 'published_approved',
            sourceUpdatedAt: $sourceUpdatedAt,
            metadata: [
                'source_table_hash' => hash('sha256', $entitySource),
                'canonical_path_hash' => hash('sha256', $canonicalPath),
                'claim_boundary_state' => 'claim_safe',
                'claim_safe' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'publication_state' => 'published',
                'robots' => 'index',
                'frontend_fallback' => false,
                'static_sitemap_fallback' => false,
                'static_llms_fallback' => false,
                'private_flow' => false,
            ] + $extraMetadata,
            attributes: [
                'source_authority' => 'backend_cms',
                'claim_safe' => true,
            ] + $extraAttributes,
        );
    }

    /**
     * @param  list<UrlTruthInventoryRecord>  $records
     * @return list<UrlTruthInventoryRecord>
     */
    private function uniqueRecords(array $records): array
    {
        $unique = [];

        foreach ($records as $record) {
            $key = $record->locale.'|'.$record->canonicalUrlHash();
            $unique[$key] ??= $record;
        }

        return array_values($unique);
    }

    private function canonicalUrl(string $path): string
    {
        $baseUrl = rtrim((string) config('seo_intel.public_canonical_host', 'https://fermatmind.com'), '/');
        if ($baseUrl === '') {
            $baseUrl = 'https://fermatmind.com';
        }

        return $baseUrl.'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function publicScaleCatalogLocales(array $row): array
    {
        $locales = [];

        foreach (['en', 'zh-CN'] as $locale) {
            if ($this->hasCatalogMetadata($row, $locale)) {
                $locales[] = $locale;
            }
        }

        return $locales;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function hasCatalogMetadata(array $row, string $locale): bool
    {
        $contentI18n = $this->toArray($row['content_i18n_json'] ?? null);
        $language = $this->localeLanguage($locale);
        $localizedCatalog = $this->toArray($this->toArray($contentI18n[$language] ?? null)['catalog'] ?? null);
        $fallbackCatalog = $this->toArray($this->toArray($contentI18n['en'] ?? null)['catalog'] ?? null);

        return $this->positiveInt($localizedCatalog['questions_count'] ?? $fallbackCatalog['questions_count'] ?? null) > 0
            && $this->positiveInt($localizedCatalog['time_minutes'] ?? $fallbackCatalog['time_minutes'] ?? null) > 0;
    }

    private function publicLocaleSegment(string $locale): string
    {
        return $this->localeLanguage($locale) === 'zh' ? 'zh' : 'en';
    }

    private function localeLanguage(string $locale): string
    {
        $locale = strtolower(trim($locale));
        if ($locale === '') {
            return 'en';
        }

        $parts = explode('-', $locale);

        return strtolower((string) ($parts[0] ?? 'en'));
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function positiveInt(mixed $value): int
    {
        $int = (int) $value;

        return $int > 0 ? $int : 0;
    }

    private function parseUpdatedAt(mixed $value): ?Carbon
    {
        try {
            if ($value === null || $value === '') {
                return null;
            }

            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
