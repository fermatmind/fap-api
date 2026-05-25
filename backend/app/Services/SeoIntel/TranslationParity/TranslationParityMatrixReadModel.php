<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\TranslationParity;

use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\ContentPage;
use App\Models\LandingSurface;
use App\Models\MediaAsset;
use App\Models\PersonalityProfile;
use App\Models\ResearchReport;
use App\Models\TopicProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

final class TranslationParityMatrixReadModel
{
    private const TARGET_LOCALES = ['en', 'zh-CN'];

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $rows = [
            ...$this->contentPageRows(),
            ...$this->articleRows(),
            ...$this->careerGuideRows(),
            ...$this->researchReportRows(),
            ...$this->topicRows(),
            ...$this->personalityRows(),
            ...$this->landingSurfaceRows(),
            ...$this->mediaAssetRows(),
            ...$this->configuredPublicSurfaceRows(),
        ];

        usort($rows, static fn (array $left, array $right): int => [
            $left['entity_type'],
            $left['translation_group_id'],
            $left['locale'],
            $left['slug'],
        ] <=> [
            $right['entity_type'],
            $right['translation_group_id'],
            $right['locale'],
            $right['slug'],
        ]);

        $groups = $this->groups($rows);
        $rows = $this->attachCounterparts($rows, $groups);
        $missing = $this->missingCounterparts($groups);

        return [
            'schema_version' => 'en-parity-02-translation-group-read-model.v1',
            'task' => 'EN-PARITY-02',
            'generated_at' => now()->toIso8601String(),
            'source_authority' => 'backend_cms_url_truth',
            'frontend_fallback_authority_used' => false,
            'cms_mutation_performed' => false,
            'production_migration_performed' => false,
            'deploy_performed' => false,
            'search_channel_action_performed' => false,
            'target_locales' => self::TARGET_LOCALES,
            'covered_entity_families' => $this->coveredEntityFamilies(),
            'summary' => [
                'row_count' => count($rows),
                'group_count' => count($groups),
                'missing_counterpart_count' => count($missing),
                'counterpart_lookup_uses_slug_guessing_only' => false,
            ],
            'rows' => $rows,
            'missing_counterparts' => $missing,
            'read_model_contract' => [
                'required_fields' => [
                    'entity_type',
                    'entity_key',
                    'translation_group_id',
                    'locale',
                    'slug',
                    'canonical_url',
                    'publication_state',
                    'source_of_truth',
                    'counterpart_locale',
                    'counterpart_canonical_url',
                    'counterpart_status',
                ],
                'pairing_preference_order' => [
                    'translation_group_id',
                    'entity_key',
                    'stable business key such as guide_code/topic_code/surface_key/type_code',
                    'slug only as a last-resort transitional signal',
                ],
            ],
            'next_task' => 'EN-PARITY-03 content pages EN/ZH parity',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contentPageRows(): array
    {
        if (! Schema::hasTable('content_pages')) {
            return [];
        }

        return ContentPage::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->whereIn('locale', self::TARGET_LOCALES)
            ->orderBy('translation_group_id')
            ->orderBy('locale')
            ->get()
            ->map(fn (ContentPage $page): array => $this->row(
                entityType: 'content_page',
                entityKey: 'content_page:'.$this->stableString($page->translation_group_id, (string) $page->slug),
                translationGroupId: $this->stableString($page->translation_group_id, 'content_page:'.$page->id),
                locale: (string) $page->locale,
                slug: (string) $page->slug,
                canonicalUrl: $this->localizedUrl($page->locale, (string) ($page->canonical_path ?: $page->path ?: $page->slug)),
                publicationState: $this->publicationState($page, ContentPage::STATUS_PUBLISHED),
                sourceOfTruth: 'content_pages',
                sourceId: (int) $page->id,
                sourceUpdatedAt: $page->updated_at,
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function articleRows(): array
    {
        if (! Schema::hasTable('articles')) {
            return [];
        }

        return Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->whereIn('locale', self::TARGET_LOCALES)
            ->whereNull('deleted_at')
            ->orderBy('translation_group_id')
            ->orderBy('locale')
            ->get()
            ->map(fn (Article $article): array => $this->row(
                entityType: 'article',
                entityKey: 'article:'.$this->stableString($article->translation_group_id, (string) $article->slug),
                translationGroupId: $this->stableString($article->translation_group_id, 'article:'.$article->id),
                locale: (string) $article->locale,
                slug: (string) $article->slug,
                canonicalUrl: $this->localizedUrl($article->locale, '/articles/'.$article->slug),
                publicationState: $this->publicationState($article, 'published'),
                sourceOfTruth: 'articles',
                sourceId: (int) $article->id,
                sourceUpdatedAt: $article->updated_at,
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function careerGuideRows(): array
    {
        if (! Schema::hasTable('career_guides')) {
            return [];
        }

        return CareerGuide::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->whereIn('locale', self::TARGET_LOCALES)
            ->orderBy('guide_code')
            ->orderBy('locale')
            ->get()
            ->map(fn (CareerGuide $guide): array => $this->row(
                entityType: 'career_guide',
                entityKey: 'career_guide:'.$this->stableString($guide->guide_code, (string) $guide->slug),
                translationGroupId: 'career_guide:'.$this->stableString($guide->guide_code, (string) $guide->slug),
                locale: (string) $guide->locale,
                slug: (string) $guide->slug,
                canonicalUrl: $this->localizedUrl($guide->locale, '/career/guides/'.$guide->slug),
                publicationState: $this->publicationState($guide, CareerGuide::STATUS_PUBLISHED),
                sourceOfTruth: 'career_guides.guide_code',
                sourceId: (int) $guide->id,
                sourceUpdatedAt: $guide->updated_at,
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function researchReportRows(): array
    {
        if (! Schema::hasTable('research_reports')) {
            return [];
        }

        return ResearchReport::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->whereIn('locale', self::TARGET_LOCALES)
            ->orderBy('research_type')
            ->orderBy('slug')
            ->orderBy('locale')
            ->get()
            ->map(fn (ResearchReport $report): array => $this->row(
                entityType: 'research_report',
                entityKey: 'research_report:'.$this->stableString($report->research_type, 'report').':'.$report->slug,
                translationGroupId: 'research_report:'.$this->stableString($report->research_type, 'report').':'.$report->slug,
                locale: (string) $report->locale,
                slug: (string) $report->slug,
                canonicalUrl: $this->localizedUrl($report->locale, (string) ($report->canonical_path ?: '/research/'.$report->slug)),
                publicationState: $this->publicationState($report, ResearchReport::STATUS_PUBLISHED),
                sourceOfTruth: 'research_reports.research_type_slug',
                sourceId: (int) $report->id,
                sourceUpdatedAt: $report->updated_at,
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topicRows(): array
    {
        if (! Schema::hasTable('topic_profiles')) {
            return [];
        }

        return TopicProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->whereIn('locale', self::TARGET_LOCALES)
            ->orderBy('topic_code')
            ->orderBy('locale')
            ->get()
            ->map(fn (TopicProfile $topic): array => $this->row(
                entityType: 'topic',
                entityKey: 'topic:'.$this->stableString($topic->topic_code, (string) $topic->slug),
                translationGroupId: 'topic:'.$this->stableString($topic->topic_code, (string) $topic->slug),
                locale: (string) $topic->locale,
                slug: (string) $topic->slug,
                canonicalUrl: $this->localizedUrl($topic->locale, '/topics/'.$topic->slug),
                publicationState: $this->publicationState($topic, TopicProfile::STATUS_PUBLISHED),
                sourceOfTruth: 'topic_profiles.topic_code',
                sourceId: (int) $topic->id,
                sourceUpdatedAt: $topic->updated_at,
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function personalityRows(): array
    {
        if (! Schema::hasTable('personality_profiles')) {
            return [];
        }

        return PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->whereIn('locale', self::TARGET_LOCALES)
            ->orderBy('scale_code')
            ->orderBy('canonical_type_code')
            ->orderBy('locale')
            ->get()
            ->map(fn (PersonalityProfile $profile): array => $this->row(
                entityType: 'personality',
                entityKey: 'personality:'.strtolower((string) $profile->scale_code).':'.strtolower((string) $profile->canonical_type_code),
                translationGroupId: 'personality:'.strtolower((string) $profile->scale_code).':'.strtolower((string) $profile->canonical_type_code),
                locale: (string) $profile->locale,
                slug: (string) $profile->slug,
                canonicalUrl: $this->localizedUrl($profile->locale, '/personality/'.$profile->slug),
                publicationState: $this->publicationState($profile, 'published'),
                sourceOfTruth: 'personality_profiles.scale_code_canonical_type_code',
                sourceId: (int) $profile->id,
                sourceUpdatedAt: $profile->updated_at,
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function landingSurfaceRows(): array
    {
        if (! Schema::hasTable('landing_surfaces')) {
            return [];
        }

        return LandingSurface::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->whereIn('locale', self::TARGET_LOCALES)
            ->orderBy('surface_key')
            ->orderBy('locale')
            ->get()
            ->map(fn (LandingSurface $surface): array => $this->row(
                entityType: 'landing_surface',
                entityKey: 'landing_surface:'.$this->stableString($surface->surface_key, (string) $surface->id),
                translationGroupId: 'landing_surface:'.$this->stableString($surface->surface_key, (string) $surface->id),
                locale: (string) $surface->locale,
                slug: (string) $surface->surface_key,
                canonicalUrl: $this->surfaceUrl((string) $surface->locale, (string) $surface->surface_key),
                publicationState: $this->publicationState($surface, LandingSurface::STATUS_PUBLISHED),
                sourceOfTruth: 'landing_surfaces.surface_key',
                sourceId: (int) $surface->id,
                sourceUpdatedAt: $surface->updated_at,
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mediaAssetRows(): array
    {
        if (! Schema::hasTable('media_assets')) {
            return [];
        }

        return MediaAsset::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->orderBy('asset_key')
            ->get()
            ->map(function (MediaAsset $asset): array {
                $payload = is_array($asset->payload_json) ? $asset->payload_json : [];
                $locale = $this->normalizeLocale((string) ($payload['locale'] ?? 'und'));
                $variantGroup = $this->stableString(
                    $payload['locale_variant_group_id'] ?? null,
                    (string) $asset->asset_key
                );

                return $this->row(
                    entityType: 'media_asset',
                    entityKey: 'media_asset:'.$variantGroup,
                    translationGroupId: 'media_asset:'.$variantGroup,
                    locale: $locale,
                    slug: (string) $asset->asset_key,
                    canonicalUrl: (string) ($asset->url ?? ''),
                    publicationState: $this->publicationState($asset, MediaAsset::STATUS_PUBLISHED),
                    sourceOfTruth: 'media_assets.asset_key_payload_locale_variant_group_id',
                    sourceId: (int) $asset->id,
                    sourceUpdatedAt: $asset->updated_at,
                );
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function configuredPublicSurfaceRows(): array
    {
        $rows = [];
        $candidates = config('seo_intel.url_truth_inventory.backend_authority_canary_candidates', []);
        if (! is_array($candidates)) {
            return [];
        }

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $entityType = (string) ($candidate['page_entity_type'] ?? '');
            $entityId = (string) ($candidate['entity_id_or_slug'] ?? '');
            $locale = (string) ($candidate['locale'] ?? '');
            $path = (string) ($candidate['path'] ?? '');
            if ($entityType === '' || $entityId === '' || $locale === '' || $path === '') {
                continue;
            }

            $rows[] = $this->row(
                entityType: $entityType,
                entityKey: $entityType.':'.$entityId,
                translationGroupId: $this->configuredTranslationGroupId($entityType, $entityId),
                locale: $locale,
                slug: $entityId,
                canonicalUrl: $this->absoluteUrl($path),
                publicationState: 'published_contract',
                sourceOfTruth: 'seo_intel.backend_authority_canary_candidates',
                sourceId: null,
                sourceUpdatedAt: null,
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $groups
     * @return list<array<string, mixed>>
     */
    private function attachCounterparts(array $rows, array $groups): array
    {
        return array_map(function (array $row) use ($groups): array {
            $counterpartLocale = $row['locale'] === 'en' ? 'zh-CN' : 'en';
            $group = $groups[$row['entity_type'].'|'.$row['translation_group_id']] ?? [];
            $counterpart = $group[$counterpartLocale] ?? null;

            $row['counterpart_locale'] = $counterpartLocale;
            $row['counterpart_canonical_url'] = is_array($counterpart) ? ($counterpart['canonical_url'] ?? null) : null;
            $row['counterpart_status'] = is_array($counterpart)
                ? (string) ($counterpart['publication_state'] ?? 'present')
                : 'missing';

            return $row;
        }, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function groups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = $row['entity_type'].'|'.$row['translation_group_id'];
            $groups[$key][$row['locale']] = $row;
        }

        return $groups;
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $groups
     * @return list<array<string, mixed>>
     */
    private function missingCounterparts(array $groups): array
    {
        $missing = [];
        foreach ($groups as $key => $localizedRows) {
            [$entityType, $translationGroupId] = explode('|', $key, 2);
            foreach (self::TARGET_LOCALES as $locale) {
                if (isset($localizedRows[$locale])) {
                    continue;
                }

                $source = $localizedRows[$locale === 'en' ? 'zh-CN' : 'en'] ?? reset($localizedRows);
                if (! is_array($source)) {
                    continue;
                }

                $missing[] = [
                    'entity_type' => $entityType,
                    'translation_group_id' => $translationGroupId,
                    'missing_locale' => $locale,
                    'source_locale' => $source['locale'] ?? null,
                    'source_slug' => $source['slug'] ?? null,
                    'source_of_truth' => $source['source_of_truth'] ?? null,
                    'classification' => 'missing_counterpart_explicit',
                ];
            }
        }

        usort($missing, static fn (array $left, array $right): int => [
            $left['entity_type'],
            $left['translation_group_id'],
            $left['missing_locale'],
        ] <=> [
            $right['entity_type'],
            $right['translation_group_id'],
            $right['missing_locale'],
        ]);

        return $missing;
    }

    private function row(
        string $entityType,
        string $entityKey,
        string $translationGroupId,
        string $locale,
        string $slug,
        ?string $canonicalUrl,
        string $publicationState,
        string $sourceOfTruth,
        ?int $sourceId,
        mixed $sourceUpdatedAt,
    ): array {
        return [
            'entity_type' => $entityType,
            'entity_key' => $entityKey,
            'translation_group_id' => $translationGroupId,
            'locale' => $this->normalizeLocale($locale),
            'slug' => $slug,
            'canonical_url' => $canonicalUrl,
            'publication_state' => $publicationState,
            'source_of_truth' => $sourceOfTruth,
            'source_id' => $sourceId,
            'source_updated_at' => $sourceUpdatedAt instanceof Carbon ? $sourceUpdatedAt->toIso8601String() : null,
        ];
    }

    private function publicationState(Model $model, string $publishedStatus): string
    {
        $status = trim((string) ($model->getAttribute('status') ?? ''));
        $isPublic = (bool) ($model->getAttribute('is_public') ?? false);
        $isIndexable = (bool) ($model->getAttribute('is_indexable') ?? true);
        $publishedAt = $model->getAttribute('published_at');
        $isPublishedAtReady = ! $publishedAt instanceof Carbon || $publishedAt->lessThanOrEqualTo(now());

        if ($status === $publishedStatus && $isPublic && $isIndexable && $isPublishedAtReady) {
            return 'published_indexable';
        }

        return $status !== '' ? $status : 'unknown';
    }

    private function configuredTranslationGroupId(string $entityType, string $entityId): string
    {
        if (str_starts_with($entityId, $entityType.':')) {
            return $entityId;
        }

        if ($entityType === 'home' && str_starts_with($entityId, 'home:')) {
            return 'home';
        }

        if ($entityType === 'test_hub' && str_starts_with($entityId, 'test_hub:')) {
            return 'test_hub';
        }

        return $entityType.':'.$entityId;
    }

    private function surfaceUrl(string $locale, string $surfaceKey): string
    {
        return match ($surfaceKey) {
            'home' => $this->localizedUrl($locale, '/'),
            'tests' => $this->localizedUrl($locale, '/tests'),
            default => $this->localizedUrl($locale, '/'.$surfaceKey),
        };
    }

    private function localizedUrl(string $locale, string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $locale = $this->normalizeLocale($locale);

        if (preg_match('#^/(en|zh)(?:/|$)#', $path) === 1) {
            return $this->absoluteUrl($path);
        }

        if ($path === '/') {
            return $locale === 'zh-CN'
                ? $this->absoluteUrl('/')
                : $this->absoluteUrl('/en');
        }

        return $this->absoluteUrl('/'.$this->localeSegment($locale).$path);
    }

    private function absoluteUrl(string $path): string
    {
        $baseUrl = rtrim((string) config('seo_intel.public_canonical_host', 'https://fermatmind.com'), '/');
        if ($baseUrl === '') {
            $baseUrl = 'https://fermatmind.com';
        }

        return $baseUrl.'/'.ltrim($path, '/');
    }

    private function localeSegment(string $locale): string
    {
        return $this->normalizeLocale($locale) === 'zh-CN' ? 'zh' : 'en';
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = trim($locale);
        if (in_array($locale, ['zh', 'zh-CN', 'zh-Hans'], true)) {
            return 'zh-CN';
        }

        if ($locale === 'en') {
            return 'en';
        }

        return $locale !== '' ? $locale : 'und';
    }

    private function stableString(mixed $preferred, string $fallback): string
    {
        $value = trim((string) $preferred);

        return $value !== '' ? $value : $fallback;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function coveredEntityFamilies(): array
    {
        return [
            'content_pages' => [
                'entity_type' => 'content_page',
                'authority_key' => 'translation_group_id',
            ],
            'articles' => [
                'entity_type' => 'article',
                'authority_key' => 'translation_group_id',
            ],
            'career_guides' => [
                'entity_type' => 'career_guide',
                'authority_key' => 'guide_code',
            ],
            'research_reports' => [
                'entity_type' => 'research_report',
                'authority_key' => 'research_type + slug',
            ],
            'topics' => [
                'entity_type' => 'topic',
                'authority_key' => 'topic_code',
            ],
            'personality' => [
                'entity_type' => 'personality',
                'authority_key' => 'scale_code + canonical_type_code',
            ],
            'tests' => [
                'entity_type' => 'test_detail',
                'authority_key' => 'scale_catalog primary slug',
            ],
            'landing_surfaces_page_blocks' => [
                'entity_type' => 'landing_surface',
                'authority_key' => 'surface_key',
            ],
            'media_assets' => [
                'entity_type' => 'media_asset',
                'authority_key' => 'asset_key + payload.locale_variant_group_id',
            ],
        ];
    }
}
