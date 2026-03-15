<?php

declare(strict_types=1);

namespace App\CareerCms\Baseline;

use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerGuideRevision;
use App\Models\CareerGuideSeoMeta;
use App\Models\CareerJob;
use App\Models\PersonalityProfile;
use App\Services\Cms\CareerGuideSeoService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CareerGuideBaselineImporter
{
    public function __construct(
        private readonly CareerGuideSeoService $careerGuideSeoService,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $guides
     * @param  array{
     *   dry_run: bool,
     *   upsert: bool,
     *   status: 'draft'|'published'|null
     * }  $options
     * @return array<string, int|string|bool>
     */
    public function import(array $guides, array $options): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $upsert = (bool) ($options['upsert'] ?? false);
        $statusOverride = $options['status'] ?? null;
        $statusMode = is_string($statusOverride) && $statusOverride !== '' ? $statusOverride : 'baseline';

        $summary = [
            'guides_found' => count($guides),
            'will_create' => 0,
            'will_update' => 0,
            'will_skip' => 0,
            'revisions_to_create' => 0,
            'errors_count' => 0,
            'dry_run' => $dryRun,
            'upsert' => $upsert,
            'status_mode' => $statusMode,
        ];

        $plannedOperations = [];

        foreach ($guides as $guidePayload) {
            $operation = $this->planOperation($guidePayload, $upsert, is_string($statusOverride) ? $statusOverride : null);
            $plannedOperations[] = $operation;

            if ($operation['action'] === 'create') {
                $summary['will_create']++;
                $summary['revisions_to_create']++;

                continue;
            }

            if ($operation['action'] === 'update') {
                $summary['will_update']++;
                $summary['revisions_to_create']++;

                continue;
            }

            $summary['will_skip']++;
        }

        if ($dryRun) {
            return $summary;
        }

        foreach ($plannedOperations as $operation) {
            if ($operation['action'] === 'skip') {
                continue;
            }

            DB::transaction(function () use ($operation): void {
                $status = (string) $operation['status'];
                $guidePayload = (array) $operation['payload'];
                $resolvedRelations = (array) $operation['resolved_relations'];

                if ($operation['action'] === 'create') {
                    $guide = CareerGuide::query()->create(
                        $this->guideAttributes($guidePayload, $status)
                    );
                    $note = 'baseline import';
                } else {
                    /** @var CareerGuide $guide */
                    $guide = $operation['existing'];
                    $guide->fill($this->guideAttributes($guidePayload, $status, $guide));
                    $guide->save();
                    $note = 'baseline upsert';
                }

                $this->syncRelatedJobs($guide, (array) ($resolvedRelations['related_jobs'] ?? []));
                $this->syncRelatedArticles($guide, (array) ($resolvedRelations['related_articles'] ?? []));
                $this->syncRelatedPersonalityProfiles($guide, (array) ($resolvedRelations['related_personality_profiles'] ?? []));
                $this->syncSeoMeta($guide, $guidePayload);
                $this->createRevision($guide, $note);
            });
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $guidePayload
     * @return array<string, mixed>
     */
    private function planOperation(array $guidePayload, bool $upsert, ?string $statusOverride): array
    {
        $existing = $this->resolveExistingGuide($guidePayload);
        $status = $statusOverride ?? (string) $guidePayload['status'];
        $resolvedRelations = [
            'related_jobs' => $this->resolveRelatedJobs($guidePayload),
            'related_articles' => $this->resolveRelatedArticles($guidePayload),
            'related_personality_profiles' => $this->resolveRelatedPersonalityProfiles($guidePayload),
        ];
        $desiredState = $this->desiredComparableState($guidePayload, $status, $existing, $resolvedRelations);

        if (! $existing instanceof CareerGuide) {
            return [
                'action' => 'create',
                'status' => $status,
                'payload' => $guidePayload,
                'resolved_relations' => $resolvedRelations,
                'existing' => null,
                'desired_state' => $desiredState,
            ];
        }

        if (! $upsert) {
            return [
                'action' => 'skip',
                'status' => $status,
                'payload' => $guidePayload,
                'resolved_relations' => $resolvedRelations,
                'existing' => $existing,
                'desired_state' => $desiredState,
            ];
        }

        $currentState = $this->currentComparableState($existing);

        if ($desiredState === $currentState) {
            return [
                'action' => 'skip',
                'status' => $status,
                'payload' => $guidePayload,
                'resolved_relations' => $resolvedRelations,
                'existing' => $existing,
                'desired_state' => $desiredState,
            ];
        }

        return [
            'action' => 'update',
            'status' => $status,
            'payload' => $guidePayload,
            'resolved_relations' => $resolvedRelations,
            'existing' => $existing,
            'desired_state' => $desiredState,
        ];
    }

    private function guideQuery(): Builder
    {
        return CareerGuide::query()->withoutGlobalScopes();
    }

    /**
     * @param  array<string, mixed>  $guidePayload
     */
    private function resolveExistingGuide(array $guidePayload): ?CareerGuide
    {
        $guideCode = (string) $guidePayload['guide_code'];
        $slug = (string) $guidePayload['slug'];
        $locale = (string) $guidePayload['locale'];

        $existingByGuideCode = $this->guideQuery()
            ->where('org_id', 0)
            ->where('guide_code', $guideCode)
            ->where('locale', $locale)
            ->first();

        $existingBySlug = $this->guideQuery()
            ->where('org_id', 0)
            ->where('slug', $slug)
            ->where('locale', $locale)
            ->first();

        if ($existingByGuideCode instanceof CareerGuide && $existingBySlug instanceof CareerGuide && (int) $existingByGuideCode->id !== (int) $existingBySlug->id) {
            throw new RuntimeException(sprintf(
                'Career guide slug conflict for %s locale %s: target slug %s is already owned by guide_code=%s.',
                $guideCode,
                $locale,
                $slug,
                (string) $existingBySlug->guide_code,
            ));
        }

        if ($existingByGuideCode instanceof CareerGuide) {
            return $existingByGuideCode;
        }

        if ($existingBySlug instanceof CareerGuide) {
            if ((string) $existingBySlug->guide_code !== $guideCode) {
                throw new RuntimeException(sprintf(
                    'Career guide slug conflict for %s locale %s: target slug %s is already owned by guide_code=%s.',
                    $guideCode,
                    $locale,
                    $slug,
                    (string) $existingBySlug->guide_code,
                ));
            }

            return $existingBySlug;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $guidePayload
     * @return array<int, array<string, mixed>>
     */
    private function resolveRelatedJobs(array $guidePayload): array
    {
        $locale = (string) $guidePayload['locale'];
        $guideCode = (string) $guidePayload['guide_code'];
        $resolved = [];

        foreach ((array) $guidePayload['related_jobs'] as $index => $row) {
            $jobCode = (string) ($row['job_code'] ?? '');

            $job = CareerJob::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('job_code', $jobCode)
                ->where('locale', $locale)
                ->first();

            if (! $job instanceof CareerJob) {
                throw new RuntimeException(sprintf(
                    'Unable to resolve related_jobs job_code=%s for guide %s locale %s.',
                    $jobCode,
                    $guideCode,
                    $locale,
                ));
            }

            $resolved[] = [
                'career_job_id' => (int) $job->id,
                'job_code' => (string) $job->job_code,
                'slug' => (string) $job->slug,
                'locale' => (string) $job->locale,
                'sort_order' => ($index + 1) * 10,
            ];
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $guidePayload
     * @return array<int, array<string, mixed>>
     */
    private function resolveRelatedArticles(array $guidePayload): array
    {
        $locale = (string) $guidePayload['locale'];
        $guideCode = (string) $guidePayload['guide_code'];
        $resolved = [];

        foreach ((array) $guidePayload['related_articles'] as $index => $row) {
            $slug = (string) ($row['slug'] ?? '');

            $article = Article::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('slug', $slug)
                ->where('locale', $locale)
                ->first();

            if (! $article instanceof Article) {
                throw new RuntimeException(sprintf(
                    'Unable to resolve related_articles slug=%s for guide %s locale %s.',
                    $slug,
                    $guideCode,
                    $locale,
                ));
            }

            $resolved[] = [
                'article_id' => (int) $article->id,
                'slug' => (string) $article->slug,
                'locale' => (string) $article->locale,
                'sort_order' => ($index + 1) * 10,
            ];
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $guidePayload
     * @return array<int, array<string, mixed>>
     */
    private function resolveRelatedPersonalityProfiles(array $guidePayload): array
    {
        $locale = (string) $guidePayload['locale'];
        $guideCode = (string) $guidePayload['guide_code'];
        $resolved = [];

        foreach ((array) $guidePayload['related_personality_profiles'] as $index => $row) {
            $typeCode = (string) ($row['type_code'] ?? '');

            $profile = PersonalityProfile::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
                ->where('type_code', $typeCode)
                ->where('locale', $locale)
                ->first();

            if (! $profile instanceof PersonalityProfile) {
                throw new RuntimeException(sprintf(
                    'Unable to resolve related_personality_profiles type_code=%s for guide %s locale %s.',
                    $typeCode,
                    $guideCode,
                    $locale,
                ));
            }

            $resolved[] = [
                'personality_profile_id' => (int) $profile->id,
                'type_code' => (string) $profile->type_code,
                'slug' => (string) $profile->slug,
                'locale' => (string) $profile->locale,
                'sort_order' => ($index + 1) * 10,
            ];
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $guidePayload
     * @return array<string, mixed>
     */
    private function guideAttributes(
        array $guidePayload,
        string $status,
        ?CareerGuide $existing = null,
    ): array {
        return [
            'org_id' => 0,
            'guide_code' => (string) $guidePayload['guide_code'],
            'slug' => (string) $guidePayload['slug'],
            'locale' => (string) $guidePayload['locale'],
            'title' => (string) $guidePayload['title'],
            'excerpt' => $guidePayload['excerpt'],
            'category_slug' => $guidePayload['category_slug'],
            'body_md' => $guidePayload['body_md'],
            'body_html' => $guidePayload['body_html'],
            'related_industry_slugs_json' => $guidePayload['related_industry_slugs_json'],
            'status' => $status,
            'is_public' => (bool) $guidePayload['is_public'],
            'is_indexable' => (bool) $guidePayload['is_indexable'],
            'sort_order' => (int) ($guidePayload['sort_order'] ?? 0),
            'published_at' => $this->resolvePublishedAt($guidePayload, $status, $existing),
            'scheduled_at' => $status === CareerGuide::STATUS_DRAFT
                ? null
                : $this->normalizeNullableDate($guidePayload['scheduled_at'] ?? null),
            'schema_version' => (string) ($guidePayload['schema_version'] ?? 'v1'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $relations
     */
    private function syncRelatedJobs(CareerGuide $guide, array $relations): void
    {
        $guide->relatedJobs()->sync(
            collect($relations)
                ->mapWithKeys(static fn (array $item): array => [
                    (int) $item['career_job_id'] => ['sort_order' => (int) $item['sort_order']],
                ])
                ->all(),
        );

        $guide->unsetRelation('relatedJobs');
    }

    /**
     * @param  array<int, array<string, mixed>>  $relations
     */
    private function syncRelatedArticles(CareerGuide $guide, array $relations): void
    {
        $guide->relatedArticles()->sync(
            collect($relations)
                ->mapWithKeys(static fn (array $item): array => [
                    (int) $item['article_id'] => ['sort_order' => (int) $item['sort_order']],
                ])
                ->all(),
        );

        $guide->unsetRelation('relatedArticles');
    }

    /**
     * @param  array<int, array<string, mixed>>  $relations
     */
    private function syncRelatedPersonalityProfiles(CareerGuide $guide, array $relations): void
    {
        $guide->relatedPersonalityProfiles()->sync(
            collect($relations)
                ->mapWithKeys(static fn (array $item): array => [
                    (int) $item['personality_profile_id'] => ['sort_order' => (int) $item['sort_order']],
                ])
                ->all(),
        );

        $guide->unsetRelation('relatedPersonalityProfiles');
    }

    /**
     * @param  array<string, mixed>  $guidePayload
     */
    private function syncSeoMeta(CareerGuide $guide, array $guidePayload): void
    {
        $seoMeta = (array) $guidePayload['seo_meta'];

        if ($this->isEmptySeoMeta($seoMeta)) {
            $this->careerGuideSeoService->generateSeoMeta((int) $guide->id);
            $guide->unsetRelation('seoMeta');

            return;
        }

        CareerGuideSeoMeta::query()->updateOrCreate(
            [
                'career_guide_id' => (int) $guide->id,
            ],
            [
                'seo_title' => $seoMeta['seo_title'],
                'seo_description' => $seoMeta['seo_description'],
                'canonical_url' => $seoMeta['canonical_url'],
                'og_title' => $seoMeta['og_title'],
                'og_description' => $seoMeta['og_description'],
                'og_image_url' => $seoMeta['og_image_url'],
                'twitter_title' => $seoMeta['twitter_title'],
                'twitter_description' => $seoMeta['twitter_description'],
                'twitter_image_url' => $seoMeta['twitter_image_url'],
                'robots' => $seoMeta['robots'],
                'jsonld_overrides_json' => $seoMeta['jsonld_overrides_json'],
            ],
        );

        $guide->unsetRelation('seoMeta');
    }

    private function createRevision(CareerGuide $guide, string $note): void
    {
        CareerGuideRevision::query()->create([
            'career_guide_id' => (int) $guide->id,
            'revision_no' => ((int) CareerGuideRevision::query()
                ->where('career_guide_id', (int) $guide->id)
                ->max('revision_no')) + 1,
            'snapshot_json' => $this->snapshotPayload($guide->fresh([
                'seoMeta',
                'relatedJobs',
                'relatedArticles',
                'relatedPersonalityProfiles',
            ])),
            'note' => $note,
            'created_by_admin_user_id' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $guidePayload
     * @param  array<string, array<int, array<string, mixed>>>  $resolvedRelations
     * @return array<string, mixed>
     */
    private function desiredComparableState(
        array $guidePayload,
        string $status,
        ?CareerGuide $existing,
        array $resolvedRelations,
    ): array {
        return [
            'guide' => [
                'org_id' => 0,
                'guide_code' => (string) $guidePayload['guide_code'],
                'slug' => (string) $guidePayload['slug'],
                'locale' => (string) $guidePayload['locale'],
                'title' => (string) $guidePayload['title'],
                'excerpt' => $guidePayload['excerpt'],
                'category_slug' => $guidePayload['category_slug'],
                'body_md' => $guidePayload['body_md'],
                'body_html' => $guidePayload['body_html'],
                'related_industry_slugs_json' => $guidePayload['related_industry_slugs_json'],
                'status' => $status,
                'is_public' => (bool) $guidePayload['is_public'],
                'is_indexable' => (bool) $guidePayload['is_indexable'],
                'sort_order' => (int) ($guidePayload['sort_order'] ?? 0),
                'published_at' => $this->normalizeDateForComparison(
                    $this->resolvePublishedAt($guidePayload, $status, $existing)
                ),
                'scheduled_at' => $status === CareerGuide::STATUS_DRAFT
                    ? null
                    : $this->normalizeDateForComparison($this->normalizeNullableDate($guidePayload['scheduled_at'] ?? null)),
                'schema_version' => (string) ($guidePayload['schema_version'] ?? 'v1'),
            ],
            'related_jobs' => array_map(
                static fn (array $item): array => [
                    'job_code' => (string) $item['job_code'],
                    'locale' => (string) $item['locale'],
                    'sort_order' => (int) $item['sort_order'],
                ],
                (array) ($resolvedRelations['related_jobs'] ?? []),
            ),
            'related_articles' => array_map(
                static fn (array $item): array => [
                    'slug' => (string) $item['slug'],
                    'locale' => (string) $item['locale'],
                    'sort_order' => (int) $item['sort_order'],
                ],
                (array) ($resolvedRelations['related_articles'] ?? []),
            ),
            'related_personality_profiles' => array_map(
                static fn (array $item): array => [
                    'type_code' => (string) $item['type_code'],
                    'locale' => (string) $item['locale'],
                    'sort_order' => (int) $item['sort_order'],
                ],
                (array) ($resolvedRelations['related_personality_profiles'] ?? []),
            ),
            'seo_meta' => $this->desiredSeoMetaState($guidePayload, $status, $existing),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentComparableState(CareerGuide $guide): array
    {
        $guide->loadMissing('seoMeta', 'relatedJobs', 'relatedArticles', 'relatedPersonalityProfiles');

        return [
            'guide' => [
                'org_id' => (int) $guide->org_id,
                'guide_code' => (string) $guide->guide_code,
                'slug' => (string) $guide->slug,
                'locale' => (string) $guide->locale,
                'title' => (string) $guide->title,
                'excerpt' => $guide->excerpt,
                'category_slug' => $guide->category_slug,
                'body_md' => $guide->body_md,
                'body_html' => $guide->body_html,
                'related_industry_slugs_json' => is_array($guide->related_industry_slugs_json)
                    ? $guide->related_industry_slugs_json
                    : [],
                'status' => (string) $guide->status,
                'is_public' => (bool) $guide->is_public,
                'is_indexable' => (bool) $guide->is_indexable,
                'sort_order' => (int) $guide->sort_order,
                'published_at' => $this->normalizeDateForComparison($guide->published_at),
                'scheduled_at' => $this->normalizeDateForComparison($guide->scheduled_at),
                'schema_version' => (string) $guide->schema_version,
            ],
            'related_jobs' => $guide->relatedJobs
                ->map(static fn (CareerJob $job): array => [
                    'job_code' => (string) $job->job_code,
                    'locale' => (string) $job->locale,
                    'sort_order' => (int) ($job->pivot?->sort_order ?? 0),
                ])
                ->values()
                ->all(),
            'related_articles' => $guide->relatedArticles
                ->map(static fn (Article $article): array => [
                    'slug' => (string) $article->slug,
                    'locale' => (string) $article->locale,
                    'sort_order' => (int) ($article->pivot?->sort_order ?? 0),
                ])
                ->values()
                ->all(),
            'related_personality_profiles' => $guide->relatedPersonalityProfiles
                ->map(static fn (PersonalityProfile $profile): array => [
                    'type_code' => (string) $profile->type_code,
                    'locale' => (string) $profile->locale,
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
                : [
                    'seo_title' => null,
                    'seo_description' => null,
                    'canonical_url' => null,
                    'og_title' => null,
                    'og_description' => null,
                    'og_image_url' => null,
                    'twitter_title' => null,
                    'twitter_description' => null,
                    'twitter_image_url' => null,
                    'robots' => null,
                    'jsonld_overrides_json' => null,
                ],
        ];
    }

    /**
     * @param  array<string, mixed>  $guidePayload
     * @return array<string, mixed>
     */
    private function desiredSeoMetaState(array $guidePayload, string $status, ?CareerGuide $existing): array
    {
        $seoMeta = (array) $guidePayload['seo_meta'];

        if (! $this->isEmptySeoMeta($seoMeta)) {
            return $seoMeta;
        }

        $previewGuide = new CareerGuide(
            $this->guideAttributes($guidePayload, $status, $existing)
        );

        if ($existing instanceof CareerGuide) {
            $previewGuide->forceFill([
                'id' => (int) $existing->id,
                'created_at' => $existing->created_at,
                'updated_at' => $existing->updated_at,
            ]);
            $previewGuide->exists = true;
        }

        return $this->careerGuideSeoService->detailSeoMetaPayload($previewGuide) ?? $seoMeta;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotPayload(CareerGuide $guide): array
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

    /**
     * @param  array<string, mixed>  $seoMeta
     */
    private function isEmptySeoMeta(array $seoMeta): bool
    {
        foreach ($seoMeta as $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value) && $value === []) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $guidePayload
     */
    private function resolvePublishedAt(
        array $guidePayload,
        string $status,
        ?CareerGuide $existing = null,
    ): ?Carbon {
        if ($status === CareerGuide::STATUS_DRAFT) {
            return null;
        }

        return $this->normalizeNullableDate($guidePayload['published_at'] ?? null)
            ?? $existing?->published_at
            ?? now();
    }

    private function normalizeNullableDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        return Carbon::parse($normalized);
    }

    private function normalizeDateForComparison(mixed $value): ?string
    {
        $normalized = $this->normalizeNullableDate($value);

        return $normalized?->copy()->utc()->toIso8601String();
    }
}
