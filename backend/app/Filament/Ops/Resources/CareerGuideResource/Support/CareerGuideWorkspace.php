<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\CareerGuideResource\Support;

use App\Filament\Ops\Support\StatusBadge;
use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerGuideRevision;
use App\Models\CareerGuideSeoMeta;
use App\Models\CareerJob;
use App\Models\PersonalityProfile;
use App\Services\Cms\CareerGuideSeoService;
use Filament\Forms\Get;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CareerGuideWorkspace
{
    /**
     * @return array<string, mixed>
     */
    public static function defaultFormState(): array
    {
        return [
            'org_id' => 0,
            'schema_version' => 'v1',
            'guide_code' => '',
            'slug' => '',
            'locale' => 'en',
            'title' => '',
            'excerpt' => '',
            'category_slug' => null,
            'body_md' => '',
            'related_industry_slugs_json' => [],
            'status' => CareerGuide::STATUS_DRAFT,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => null,
            'scheduled_at' => null,
            'sort_order' => 0,
            'workspace_related_jobs' => [],
            'workspace_related_articles' => [],
            'workspace_related_personality_profiles' => [],
            'workspace_seo' => self::defaultWorkspaceSeoState(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultWorkspaceSeoState(): array
    {
        return [
            'seo_title' => '',
            'seo_description' => '',
            'canonical_url' => '',
            'og_title' => '',
            'og_description' => '',
            'og_image_url' => '',
            'twitter_title' => '',
            'twitter_description' => '',
            'twitter_image_url' => '',
            'robots' => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function localeOptions(): array
    {
        return collect(CareerGuide::SUPPORTED_LOCALES)
            ->mapWithKeys(static fn (string $locale): array => [$locale => $locale])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            CareerGuide::STATUS_DRAFT => CareerGuide::STATUS_DRAFT,
            CareerGuide::STATUS_PUBLISHED => CareerGuide::STATUS_PUBLISHED,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        return collect([
            'assessment-usage',
            'career-planning',
            'career-transition',
            'education-decision',
            'job-search',
            'onboarding',
            'skill-growth',
            'wellbeing',
            'workplace-communication',
        ])->mapWithKeys(static fn (string $slug): array => [
            $slug => Str::of($slug)->replace('-', ' ')->headline()->value(),
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function workspaceSeoFromRecord(?CareerGuide $guide): array
    {
        $state = self::defaultWorkspaceSeoState();

        if (! $guide instanceof CareerGuide) {
            return $state;
        }

        $guide->loadMissing('seoMeta');
        $seoMeta = $guide->seoMeta;

        if (! $seoMeta instanceof CareerGuideSeoMeta) {
            return $state;
        }

        return [
            'seo_title' => (string) ($seoMeta->seo_title ?? ''),
            'seo_description' => (string) ($seoMeta->seo_description ?? ''),
            'canonical_url' => (string) ($seoMeta->canonical_url ?? ''),
            'og_title' => (string) ($seoMeta->og_title ?? ''),
            'og_description' => (string) ($seoMeta->og_description ?? ''),
            'og_image_url' => (string) ($seoMeta->og_image_url ?? ''),
            'twitter_title' => (string) ($seoMeta->twitter_title ?? ''),
            'twitter_description' => (string) ($seoMeta->twitter_description ?? ''),
            'twitter_image_url' => (string) ($seoMeta->twitter_image_url ?? ''),
            'robots' => (string) ($seoMeta->robots ?? ''),
        ];
    }

    /**
     * @return array<int, array{career_job_id: int}>
     */
    public static function workspaceRelatedJobsFromRecord(?CareerGuide $guide): array
    {
        if (! $guide instanceof CareerGuide) {
            return [];
        }

        $guide->loadMissing('relatedJobs');

        return $guide->relatedJobs
            ->map(static fn (CareerJob $job): array => [
                'career_job_id' => (int) $job->id,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{article_id: int}>
     */
    public static function workspaceRelatedArticlesFromRecord(?CareerGuide $guide): array
    {
        if (! $guide instanceof CareerGuide) {
            return [];
        }

        $guide->loadMissing('relatedArticles');

        return $guide->relatedArticles
            ->map(static fn (Article $article): array => [
                'article_id' => (int) $article->id,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{personality_profile_id: int}>
     */
    public static function workspaceRelatedPersonalityProfilesFromRecord(?CareerGuide $guide): array
    {
        if (! $guide instanceof CareerGuide) {
            return [];
        }

        $guide->loadMissing('relatedPersonalityProfiles');

        return $guide->relatedPersonalityProfiles
            ->map(static fn (PersonalityProfile $profile): array => [
                'personality_profile_id' => (int) $profile->id,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function syncWorkspaceSeo(CareerGuide $guide, array $state): void
    {
        CareerGuideSeoMeta::query()->updateOrCreate(
            [
                'career_guide_id' => (int) $guide->id,
            ],
            [
                'seo_title' => self::normalizeNullableText((string) ($state['seo_title'] ?? '')),
                'seo_description' => self::normalizeNullableText((string) ($state['seo_description'] ?? '')),
                'canonical_url' => self::normalizeNullableText((string) ($state['canonical_url'] ?? '')),
                'og_title' => self::normalizeNullableText((string) ($state['og_title'] ?? '')),
                'og_description' => self::normalizeNullableText((string) ($state['og_description'] ?? '')),
                'og_image_url' => self::normalizeNullableText((string) ($state['og_image_url'] ?? '')),
                'twitter_title' => self::normalizeNullableText((string) ($state['twitter_title'] ?? '')),
                'twitter_description' => self::normalizeNullableText((string) ($state['twitter_description'] ?? '')),
                'twitter_image_url' => self::normalizeNullableText((string) ($state['twitter_image_url'] ?? '')),
                'robots' => self::normalizeNullableText((string) ($state['robots'] ?? '')),
            ],
        );

        $guide->unsetRelation('seoMeta');
    }

    /**
     * @param  array<int, array{career_job_id: int}>  $state
     */
    public static function syncRelatedJobs(CareerGuide $guide, array $state): void
    {
        $guide->relatedJobs()->sync(
            collect($state)
                ->values()
                ->mapWithKeys(static fn (array $item, int $index): array => [
                    (int) $item['career_job_id'] => ['sort_order' => ($index + 1) * 10],
                ])
                ->all()
        );

        $guide->unsetRelation('relatedJobs');
    }

    /**
     * @param  array<int, array{article_id: int}>  $state
     */
    public static function syncRelatedArticles(CareerGuide $guide, array $state): void
    {
        $guide->relatedArticles()->sync(
            collect($state)
                ->values()
                ->mapWithKeys(static fn (array $item, int $index): array => [
                    (int) $item['article_id'] => ['sort_order' => ($index + 1) * 10],
                ])
                ->all()
        );

        $guide->unsetRelation('relatedArticles');
    }

    /**
     * @param  array<int, array{personality_profile_id: int}>  $state
     */
    public static function syncRelatedPersonalityProfiles(CareerGuide $guide, array $state): void
    {
        $guide->relatedPersonalityProfiles()->sync(
            collect($state)
                ->values()
                ->mapWithKeys(static fn (array $item, int $index): array => [
                    (int) $item['personality_profile_id'] => ['sort_order' => ($index + 1) * 10],
                ])
                ->all()
        );

        $guide->unsetRelation('relatedPersonalityProfiles');
    }

    public static function nextRevisionNo(CareerGuide $guide): int
    {
        return (int) CareerGuideRevision::query()
            ->where('career_guide_id', (int) $guide->id)
            ->max('revision_no') + 1;
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshotPayload(CareerGuide $guide): array
    {
        $guide->loadMissing('seoMeta', 'relatedJobs', 'relatedArticles', 'relatedPersonalityProfiles');

        return [
            'guide' => [
                'id' => (int) $guide->id,
                'org_id' => (int) $guide->org_id,
                'guide_code' => (string) $guide->guide_code,
                'slug' => (string) $guide->slug,
                'locale' => (string) $guide->locale,
                'title' => (string) $guide->title,
                'excerpt' => $guide->excerpt,
                'category_slug' => $guide->category_slug,
                'body_md' => $guide->body_md,
                'body_html' => $guide->body_html,
                'related_industry_slugs_json' => $guide->related_industry_slugs_json,
                'status' => (string) $guide->status,
                'is_public' => (bool) $guide->is_public,
                'is_indexable' => (bool) $guide->is_indexable,
                'published_at' => $guide->published_at?->toIso8601String(),
                'scheduled_at' => $guide->scheduled_at?->toIso8601String(),
                'schema_version' => (string) $guide->schema_version,
                'sort_order' => (int) $guide->sort_order,
            ],
            'related_jobs' => $guide->relatedJobs
                ->map(static fn (CareerJob $job): array => [
                    'career_job_id' => (int) $job->id,
                    'job_code' => (string) $job->job_code,
                    'slug' => (string) $job->slug,
                    'locale' => (string) $job->locale,
                    'title' => (string) $job->title,
                    'sort_order' => (int) ($job->pivot?->sort_order ?? 0),
                ])
                ->values()
                ->all(),
            'related_articles' => $guide->relatedArticles
                ->map(static fn (Article $article): array => [
                    'article_id' => (int) $article->id,
                    'slug' => (string) $article->slug,
                    'locale' => (string) $article->locale,
                    'title' => (string) $article->title,
                    'sort_order' => (int) ($article->pivot?->sort_order ?? 0),
                ])
                ->values()
                ->all(),
            'related_personality_profiles' => $guide->relatedPersonalityProfiles
                ->map(static fn (PersonalityProfile $profile): array => [
                    'personality_profile_id' => (int) $profile->id,
                    'type_code' => (string) $profile->type_code,
                    'slug' => (string) $profile->slug,
                    'locale' => (string) $profile->locale,
                    'title' => (string) $profile->title,
                    'sort_order' => (int) ($profile->pivot?->sort_order ?? 0),
                ])
                ->values()
                ->all(),
            'seo_meta' => $guide->seoMeta instanceof CareerGuideSeoMeta
                ? [
                    'seo_title' => $guide->seoMeta->seo_title,
                    'seo_description' => $guide->seoMeta->seo_description,
                    'canonical_url' => $guide->seoMeta->canonical_url,
                    'og_title' => $guide->seoMeta->og_title,
                    'og_description' => $guide->seoMeta->og_description,
                    'og_image_url' => $guide->seoMeta->og_image_url,
                    'twitter_title' => $guide->seoMeta->twitter_title,
                    'twitter_description' => $guide->seoMeta->twitter_description,
                    'twitter_image_url' => $guide->seoMeta->twitter_image_url,
                    'robots' => $guide->seoMeta->robots,
                    'jsonld_overrides_json' => $guide->seoMeta->jsonld_overrides_json,
                ]
                : null,
        ];
    }

    public static function createRevision(CareerGuide $guide, string $note, ?object $adminUser = null): void
    {
        CareerGuideRevision::query()->create([
            'career_guide_id' => (int) $guide->id,
            'revision_no' => self::nextRevisionNo($guide),
            'snapshot_json' => self::snapshotPayload($guide),
            'note' => $note,
            'created_by_admin_user_id' => self::resolveAdminUserId($adminUser),
            'created_at' => now(),
        ]);
    }

    public static function plannedPublicUrl(CareerGuide|string $guideOrSlug, string $locale): ?string
    {
        $guide = $guideOrSlug instanceof CareerGuide
            ? $guideOrSlug
            : new CareerGuide([
                'org_id' => 0,
                'guide_code' => self::normalizeGuideCode(null, (string) $guideOrSlug),
                'slug' => self::normalizeSlug((string) $guideOrSlug),
                'locale' => self::normalizeLocale($locale),
            ]);

        return app(CareerGuideSeoService::class)->buildCanonicalUrl($guide);
    }

    public static function relationLocaleFromGet(Get $get, ?CareerGuide $record = null): string
    {
        foreach (['../../locale', '../../../locale', '../locale', 'locale'] as $path) {
            $state = $get($path);

            if (filled($state)) {
                return self::normalizeLocale((string) $state);
            }
        }

        return self::normalizeLocale((string) ($record?->locale ?? 'en'));
    }

    public static function renderEditorialCues(Get $get, ?CareerGuide $record = null): Htmlable
    {
        $status = trim((string) ($get('status') ?? $record?->status ?? CareerGuide::STATUS_DRAFT));
        $isPublic = StatusBadge::isTruthy($get('is_public') ?? $record?->is_public ?? false);
        $isIndexable = StatusBadge::isTruthy($get('is_indexable') ?? $record?->is_indexable ?? true);
        $plannedUrl = self::plannedPublicUrl(
            (string) ($get('slug') ?? $record?->slug ?? ''),
            (string) ($get('locale') ?? $record?->locale ?? 'en'),
        );

        return new HtmlString((string) view('filament.ops.career-guides.partials.editorial-cues', [
            'facts' => array_values(array_filter([
                [
                    'label' => 'Published',
                    'value' => self::formatTimestamp($get('published_at') ?? $record?->published_at),
                ],
                [
                    'label' => 'Scheduled',
                    'value' => self::formatTimestamp($get('scheduled_at') ?? $record?->scheduled_at),
                ],
                [
                    'label' => 'Planned public URL',
                    'value' => $plannedUrl,
                ],
                [
                    'label' => 'Relations',
                    'value' => self::relationSummary($get, $record),
                ],
                [
                    'label' => 'Revisions',
                    'value' => $record instanceof CareerGuide ? (string) self::revisionCount($record) : null,
                ],
            ], static fn (array $fact): bool => filled($fact['value'] ?? null))),
            'pills' => [
                [
                    'label' => self::statusLabel($status),
                    'state' => $status,
                ],
                [
                    'label' => $isPublic ? 'Public' : 'Private',
                    'state' => $isPublic ? 'public' : 'inactive',
                ],
                [
                    'label' => $isIndexable ? 'Indexable' : 'Noindex',
                    'state' => $isIndexable ? 'indexable' : 'noindex',
                ],
            ],
        ])->render());
    }

    public static function renderSeoSnapshot(Get $get, ?CareerGuide $record = null): Htmlable
    {
        return new HtmlString((string) view('filament.ops.career-guides.partials.seo-snapshot', [
            'rows' => self::seoSnapshotRows($get, $record),
        ])->render());
    }

    public static function formatTimestamp(mixed $value, string $fallback = 'Not set yet'): string
    {
        $formatted = self::normalizeTimestamp($value);

        return $formatted ?? $fallback;
    }

    public static function titleMeta(CareerGuide $guide): string
    {
        return collect([
            filled($guide->guide_code) ? Str::lower((string) $guide->guide_code) : null,
            filled($guide->slug) ? '/'.trim((string) $guide->slug, '/') : null,
            filled($guide->locale) ? Str::upper((string) $guide->locale) : null,
        ])->filter(static fn (?string $value): bool => filled($value))->implode(' · ');
    }

    public static function visibilityMeta(CareerGuide $guide): string
    {
        return implode(' · ', [
            StatusBadge::booleanLabel($guide->is_public, 'Public', 'Private'),
            StatusBadge::booleanLabel($guide->is_indexable, 'Indexable', 'Noindex'),
        ]);
    }

    public static function normalizeGuideCode(?string $guideCode, ?string $slug = null): string
    {
        $candidate = trim((string) $guideCode);

        if ($candidate === '' && filled($slug)) {
            $candidate = (string) $slug;
        }

        return Str::of($candidate)
            ->lower()
            ->replace('_', '-')
            ->slug('-')
            ->value();
    }

    public static function normalizeSlug(?string $slug, ?string $guideCode = null): string
    {
        $candidate = trim((string) $slug);

        if ($candidate === '' && filled($guideCode)) {
            $candidate = (string) $guideCode;
        }

        return Str::of($candidate)
            ->lower()
            ->replace('_', '-')
            ->slug('-')
            ->value();
    }

    public static function normalizeLocale(?string $locale): string
    {
        $normalized = trim((string) $locale);

        return in_array($normalized, CareerGuide::SUPPORTED_LOCALES, true) ? $normalized : 'en';
    }

    /**
     * @return array<int, string>
     */
    public static function normalizeIndustrySlugs(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        $normalized = [];

        foreach ($state as $value) {
            $slug = Str::lower(trim((string) $value));

            if ($slug === '' || in_array($slug, $normalized, true)) {
                continue;
            }

            $normalized[] = $slug;
        }

        return $normalized;
    }

    /**
     * @param  array<int, mixed>  $state
     * @return array<int, array{career_job_id: int}>
     */
    public static function normalizeRelatedJobRows(array $state, string $locale): array
    {
        return self::normalizeRelationRows(
            $state,
            'career_job_id',
            self::careerJobQueryForLocale($locale)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
            'workspace_related_jobs',
            'Only global career jobs in the selected locale can be attached.',
        );
    }

    /**
     * @param  array<int, mixed>  $state
     * @return array<int, array{article_id: int}>
     */
    public static function normalizeRelatedArticleRows(array $state, string $locale): array
    {
        return self::normalizeRelationRows(
            $state,
            'article_id',
            self::articleQueryForLocale($locale)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
            'workspace_related_articles',
            'Only global articles in the selected locale can be attached.',
        );
    }

    /**
     * @param  array<int, mixed>  $state
     * @return array<int, array{personality_profile_id: int}>
     */
    public static function normalizeRelatedPersonalityRows(array $state, string $locale): array
    {
        return self::normalizeRelationRows(
            $state,
            'personality_profile_id',
            self::personalityQueryForLocale($locale)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
            'workspace_related_personality_profiles',
            'Only global MBTI personality profiles in the selected locale can be attached.',
        );
    }

    /**
     * @return array<int, string>
     */
    public static function careerJobSearchResults(string $search, string $locale): array
    {
        return self::careerJobQueryForLocale($locale)
            ->when(trim($search) !== '', function ($query) use ($search): void {
                $query->where(function ($nestedQuery) use ($search): void {
                    $nestedQuery->where('title', 'like', '%'.$search.'%')
                        ->orWhere('job_code', 'like', '%'.$search.'%')
                        ->orWhere('slug', 'like', '%'.$search.'%');
                });
            })
            ->limit(50)
            ->get()
            ->mapWithKeys(static fn (CareerJob $job): array => [
                (int) $job->id => self::careerJobOptionLabel($job),
            ])
            ->all();
    }

    public static function careerJobOptionLabelById(int|string|null $id): ?string
    {
        $resolvedId = (int) $id;

        if ($resolvedId <= 0) {
            return null;
        }

        $job = CareerJob::query()
            ->withoutGlobalScopes()
            ->find($resolvedId);

        return $job instanceof CareerJob ? self::careerJobOptionLabel($job) : null;
    }

    /**
     * @return array<int, string>
     */
    public static function articleSearchResults(string $search, string $locale): array
    {
        return self::articleQueryForLocale($locale)
            ->when(trim($search) !== '', function ($query) use ($search): void {
                $query->where(function ($nestedQuery) use ($search): void {
                    $nestedQuery->where('title', 'like', '%'.$search.'%')
                        ->orWhere('slug', 'like', '%'.$search.'%');
                });
            })
            ->limit(50)
            ->get()
            ->mapWithKeys(static fn (Article $article): array => [
                (int) $article->id => self::articleOptionLabel($article),
            ])
            ->all();
    }

    public static function articleOptionLabelById(int|string|null $id): ?string
    {
        $resolvedId = (int) $id;

        if ($resolvedId <= 0) {
            return null;
        }

        $article = Article::query()
            ->withoutGlobalScopes()
            ->find($resolvedId);

        return $article instanceof Article ? self::articleOptionLabel($article) : null;
    }

    public static function legacyRelatedArticleSummary(?CareerGuide $record): string
    {
        if (! $record instanceof CareerGuide || ! $record->exists) {
            return 'Legacy global article links can still render at runtime, but new links are intentionally excluded from the production CMS bootstrap.';
        }

        $labels = $record->relatedArticles()
            ->withoutGlobalScopes()
            ->get(['articles.title', 'articles.slug'])
            ->map(static function (Article $article): string {
                $title = trim((string) $article->title);
                $slug = trim((string) $article->slug);

                return $title !== '' ? $title.' ('.$slug.')' : $slug;
            })
            ->filter()
            ->values()
            ->all();

        if ($labels === []) {
            return 'No legacy global article links are attached to this guide.';
        }

        return 'Existing runtime links: '.implode(' | ', $labels);
    }

    /**
     * @return array<int, string>
     */
    public static function personalitySearchResults(string $search, string $locale): array
    {
        return self::personalityQueryForLocale($locale)
            ->when(trim($search) !== '', function ($query) use ($search): void {
                $query->where(function ($nestedQuery) use ($search): void {
                    $nestedQuery->where('title', 'like', '%'.$search.'%')
                        ->orWhere('type_code', 'like', '%'.$search.'%')
                        ->orWhere('slug', 'like', '%'.$search.'%');
                });
            })
            ->limit(50)
            ->get()
            ->mapWithKeys(static fn (PersonalityProfile $profile): array => [
                (int) $profile->id => self::personalityOptionLabel($profile),
            ])
            ->all();
    }

    public static function personalityOptionLabelById(int|string|null $id): ?string
    {
        $resolvedId = (int) $id;

        if ($resolvedId <= 0) {
            return null;
        }

        $profile = PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->find($resolvedId);

        return $profile instanceof PersonalityProfile ? self::personalityOptionLabel($profile) : null;
    }

    private static function previewGuide(Get $get, ?CareerGuide $record = null): CareerGuide
    {
        $guideCode = self::normalizeGuideCode(
            (string) ($get('guide_code') ?? $record?->guide_code ?? ''),
            (string) ($get('slug') ?? $record?->slug ?? ''),
        );
        $slug = self::normalizeSlug(
            (string) ($get('slug') ?? $record?->slug ?? ''),
            $guideCode,
        );
        $locale = self::normalizeLocale((string) ($get('locale') ?? $record?->locale ?? 'en'));

        $guide = new CareerGuide;

        $guide->forceFill([
            'id' => (int) ($record?->id ?? 0),
            'org_id' => (int) ($record?->org_id ?? 0),
            'guide_code' => $guideCode,
            'slug' => $slug,
            'locale' => $locale,
            'title' => (string) ($get('title') ?? $record?->title ?? ''),
            'excerpt' => self::normalizeNullableText((string) ($get('excerpt') ?? $record?->excerpt ?? '')),
            'category_slug' => self::normalizeNullableText((string) ($get('category_slug') ?? $record?->category_slug ?? '')),
            'body_md' => self::normalizeNullableText((string) ($get('body_md') ?? $record?->body_md ?? '')),
            'status' => (string) ($get('status') ?? $record?->status ?? CareerGuide::STATUS_DRAFT),
            'is_public' => StatusBadge::isTruthy($get('is_public') ?? $record?->is_public ?? false),
            'is_indexable' => StatusBadge::isTruthy($get('is_indexable') ?? $record?->is_indexable ?? true),
        ]);
        $guide->exists = $record instanceof CareerGuide;

        $guide->setRelation('seoMeta', new CareerGuideSeoMeta([
            'seo_title' => self::normalizeNullableText((string) ($get('workspace_seo.seo_title') ?? $record?->seoMeta?->seo_title ?? '')),
            'seo_description' => self::normalizeNullableText((string) ($get('workspace_seo.seo_description') ?? $record?->seoMeta?->seo_description ?? '')),
            'canonical_url' => self::normalizeNullableText((string) ($get('workspace_seo.canonical_url') ?? $record?->seoMeta?->canonical_url ?? '')),
            'og_title' => self::normalizeNullableText((string) ($get('workspace_seo.og_title') ?? $record?->seoMeta?->og_title ?? '')),
            'og_description' => self::normalizeNullableText((string) ($get('workspace_seo.og_description') ?? $record?->seoMeta?->og_description ?? '')),
            'og_image_url' => self::normalizeNullableText((string) ($get('workspace_seo.og_image_url') ?? $record?->seoMeta?->og_image_url ?? '')),
            'twitter_title' => self::normalizeNullableText((string) ($get('workspace_seo.twitter_title') ?? $record?->seoMeta?->twitter_title ?? '')),
            'twitter_description' => self::normalizeNullableText((string) ($get('workspace_seo.twitter_description') ?? $record?->seoMeta?->twitter_description ?? '')),
            'twitter_image_url' => self::normalizeNullableText((string) ($get('workspace_seo.twitter_image_url') ?? $record?->seoMeta?->twitter_image_url ?? '')),
            'robots' => self::normalizeNullableText((string) ($get('workspace_seo.robots') ?? $record?->seoMeta?->robots ?? '')),
            'jsonld_overrides_json' => $record?->seoMeta?->jsonld_overrides_json,
        ]));

        return $guide;
    }

    /**
     * @return array<int, array{label: string, value: string, state: string, state_label: string}>
     */
    private static function seoSnapshotRows(Get $get, ?CareerGuide $record = null): array
    {
        $guide = self::previewGuide($get, $record);
        $service = app(CareerGuideSeoService::class);
        $resolved = $service->detailSeoMetaPayload($guide) ?? [];
        $payload = $service->buildSeoPayload($guide);

        $alternates = collect($payload['alternates'] ?? [])
            ->map(static fn (string $url, string $locale): string => $locale.' -> '.$url)
            ->implode(' | ');

        return [
            self::seoRow('Resolved title', (string) ($resolved['seo_title'] ?? ''), 'Derived from SEO title or guide title.'),
            self::seoRow('Resolved description', (string) ($resolved['seo_description'] ?? ''), 'Derived from SEO description, excerpt, then guide body.'),
            self::seoRow('Planned canonical', (string) ($resolved['canonical_url'] ?? ''), 'Preview only. No live runtime authority is exposed here.'),
            self::seoRow('Robots', (string) ($resolved['robots'] ?? ''), 'Falls back from the current indexability toggle when the SEO field is blank.', true),
            self::seoRow('Alternates', $alternates, 'Only published public locale variants are listed here.'),
        ];
    }

    /**
     * @return array{label: string, value: string, state: string, state_label: string}
     */
    private static function seoRow(string $label, string $value, string $fallback, bool $alwaysReady = false): array
    {
        $normalized = trim($value);

        return [
            'label' => $label,
            'value' => $normalized !== '' ? $normalized : $fallback,
            'state' => $alwaysReady || $normalized !== '' ? 'ready' : 'draft',
            'state_label' => $alwaysReady || $normalized !== '' ? 'Ready' : 'Pending',
        ];
    }

    private static function relationSummary(Get $get, ?CareerGuide $record = null): ?string
    {
        $jobCount = is_array($get('workspace_related_jobs') ?? null)
            ? count(array_filter($get('workspace_related_jobs')))
            : ($record instanceof CareerGuide ? $record->relatedJobs()->count() : 0);
        $industryCount = is_array($get('related_industry_slugs_json') ?? null)
            ? count(array_filter($get('related_industry_slugs_json')))
            : count($record?->related_industry_slugs_json ?? []);
        $articleCount = is_array($get('workspace_related_articles') ?? null)
            ? count(array_filter($get('workspace_related_articles')))
            : ($record instanceof CareerGuide ? $record->relatedArticles()->count() : 0);
        $personalityCount = is_array($get('workspace_related_personality_profiles') ?? null)
            ? count(array_filter($get('workspace_related_personality_profiles')))
            : ($record instanceof CareerGuide ? $record->relatedPersonalityProfiles()->count() : 0);

        $parts = array_values(array_filter([
            $jobCount > 0 ? $jobCount.' jobs' : null,
            $industryCount > 0 ? $industryCount.' industries' : null,
            $articleCount > 0 ? $articleCount.' articles' : null,
            $personalityCount > 0 ? $personalityCount.' profiles' : null,
        ]));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * @return array<int, array<string, int>>
     */
    private static function normalizeRelationRows(array $state, string $key, array $allowedIds, string $field, string $message): array
    {
        $normalized = [];

        foreach ($state as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (int) ($row[$key] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (array_key_exists($id, $normalized)) {
                continue;
            }

            $normalized[$id] = [$key => $id];
        }

        $ids = array_map(static fn (array $row): int => (int) $row[$key], array_values($normalized));
        $invalid = array_values(array_diff($ids, $allowedIds));

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                $field => $message,
            ]);
        }

        return array_values($normalized);
    }

    private static function careerJobQueryForLocale(string $locale)
    {
        return CareerJob::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', self::normalizeLocale($locale))
            ->orderBy('title')
            ->orderBy('id');
    }

    private static function articleQueryForLocale(string $locale)
    {
        return Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', self::normalizeLocale($locale))
            ->orderBy('title')
            ->orderBy('id');
    }

    private static function personalityQueryForLocale(string $locale)
    {
        return PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->where('locale', self::normalizeLocale($locale))
            ->orderBy('type_code')
            ->orderBy('id');
    }

    private static function careerJobOptionLabel(CareerJob $job): string
    {
        return implode(' · ', array_filter([
            $job->title,
            filled($job->job_code) ? Str::lower((string) $job->job_code) : null,
            filled($job->slug) ? '/'.trim((string) $job->slug, '/') : null,
            filled($job->locale) ? Str::upper((string) $job->locale) : null,
            filled($job->status) ? Str::of((string) $job->status)->headline()->value() : null,
        ]));
    }

    private static function articleOptionLabel(Article $article): string
    {
        return implode(' · ', array_filter([
            $article->title,
            filled($article->slug) ? '/'.trim((string) $article->slug, '/') : null,
            filled($article->locale) ? Str::upper((string) $article->locale) : null,
            filled($article->status) ? Str::of((string) $article->status)->headline()->value() : null,
        ]));
    }

    private static function personalityOptionLabel(PersonalityProfile $profile): string
    {
        return implode(' · ', array_filter([
            $profile->title,
            filled($profile->type_code) ? Str::upper((string) $profile->type_code) : null,
            filled($profile->slug) ? '/'.trim((string) $profile->slug, '/') : null,
            filled($profile->locale) ? Str::upper((string) $profile->locale) : null,
            filled($profile->status) ? Str::of((string) $profile->status)->headline()->value() : null,
        ]));
    }

    private static function revisionCount(CareerGuide $guide): int
    {
        return CareerGuideRevision::query()
            ->where('career_guide_id', (int) $guide->id)
            ->count();
    }

    private static function resolveAdminUserId(?object $adminUser = null): ?int
    {
        if (is_object($adminUser) && method_exists($adminUser, 'getAuthIdentifier')) {
            return (int) $adminUser->getAuthIdentifier();
        }

        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        if (! is_object($user) || ! method_exists($user, 'getAuthIdentifier')) {
            return null;
        }

        return (int) $user->getAuthIdentifier();
    }

    private static function normalizeNullableText(string $value): ?string
    {
        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private static function normalizeTimestamp(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->timezone(config('app.timezone'))->format('M j, Y H:i');
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->timezone(config('app.timezone'))->format('M j, Y H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function statusLabel(string $status): string
    {
        $normalized = trim($status);

        if ($normalized === '') {
            return 'Draft';
        }

        return Str::of($normalized)
            ->replace(['_', '-'], ' ')
            ->headline()
            ->value();
    }
}
