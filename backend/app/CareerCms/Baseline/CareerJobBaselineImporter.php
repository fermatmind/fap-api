<?php

declare(strict_types=1);

namespace App\CareerCms\Baseline;

use App\Models\CareerJob;
use App\Models\CareerJobRevision;
use App\Models\CareerJobSection;
use App\Models\CareerJobSeoMeta;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CareerJobBaselineImporter
{
    /**
     * @param  array<int, array<string, mixed>>  $jobs
     * @param  array{
     *   dry_run: bool,
     *   upsert: bool,
     *   status: 'draft'|'published'
     * }  $options
     * @return array<string, int|string|bool>
     */
    public function import(array $jobs, array $options): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $upsert = (bool) ($options['upsert'] ?? false);
        $status = (string) ($options['status'] ?? CareerJob::STATUS_DRAFT);

        $summary = [
            'jobs_found' => count($jobs),
            'will_create' => 0,
            'will_update' => 0,
            'will_skip' => 0,
            'revisions_to_create' => 0,
            'errors_count' => 0,
            'dry_run' => $dryRun,
            'upsert' => $upsert,
            'status_mode' => $status,
        ];

        foreach ($jobs as $jobPayload) {
            $existing = $this->jobQuery()
                ->where('org_id', 0)
                ->where('job_code', (string) $jobPayload['job_code'])
                ->where('locale', (string) $jobPayload['locale'])
                ->first();

            if (! $existing instanceof CareerJob) {
                $summary['will_create']++;
                $summary['revisions_to_create']++;

                if (! $dryRun) {
                    DB::transaction(function () use ($jobPayload, $status): void {
                        $job = CareerJob::query()->create(
                            $this->jobAttributes($jobPayload, $status)
                        );

                        $this->syncSections($job, $jobPayload, false);
                        $this->syncSeoMeta($job, $jobPayload);
                        $this->createRevision($job, 'baseline import');
                    });
                }

                continue;
            }

            if (! $upsert) {
                $summary['will_skip']++;

                continue;
            }

            $desiredState = $this->desiredComparableState($jobPayload, $status, $existing);
            $currentState = $this->currentComparableState($existing);

            if ($desiredState === $currentState) {
                $summary['will_skip']++;

                continue;
            }

            $summary['will_update']++;
            $summary['revisions_to_create']++;

            if (! $dryRun) {
                DB::transaction(function () use ($existing, $jobPayload, $status): void {
                    $existing->fill($this->jobAttributes($jobPayload, $status, $existing));
                    $existing->save();

                    $this->syncSections($existing, $jobPayload, true);
                    $this->syncSeoMeta($existing, $jobPayload);
                    $this->createRevision($existing, 'baseline upsert');
                });
            }
        }

        return $summary;
    }

    private function jobQuery(): Builder
    {
        return CareerJob::query()->withoutGlobalScope(TenantScope::class);
    }

    /**
     * @param  array<string, mixed>  $jobPayload
     * @return array<string, mixed>
     */
    private function jobAttributes(
        array $jobPayload,
        string $status,
        ?CareerJob $existing = null,
    ): array {
        $publishedAt = $this->resolvePublishedAt($jobPayload, $status, $existing);

        return [
            'org_id' => 0,
            'job_code' => (string) $jobPayload['job_code'],
            'slug' => (string) $jobPayload['slug'],
            'locale' => (string) $jobPayload['locale'],
            'title' => (string) $jobPayload['title'],
            'subtitle' => $jobPayload['subtitle'],
            'excerpt' => $jobPayload['excerpt'],
            'hero_kicker' => $jobPayload['hero_kicker'],
            'hero_quote' => $jobPayload['hero_quote'],
            'cover_image_url' => $jobPayload['cover_image_url'],
            'industry_slug' => $jobPayload['industry_slug'],
            'industry_label' => $jobPayload['industry_label'],
            'body_md' => $jobPayload['body_md'],
            'body_html' => $jobPayload['body_html'],
            'salary_json' => $jobPayload['salary_json'],
            'outlook_json' => $jobPayload['outlook_json'],
            'skills_json' => $jobPayload['skills_json'],
            'work_contents_json' => $jobPayload['work_contents_json'],
            'growth_path_json' => $jobPayload['growth_path_json'],
            'fit_personality_codes_json' => $jobPayload['fit_personality_codes_json'],
            'mbti_primary_codes_json' => $jobPayload['mbti_primary_codes_json'],
            'mbti_secondary_codes_json' => $jobPayload['mbti_secondary_codes_json'],
            'riasec_profile_json' => $jobPayload['riasec_profile_json'],
            'big5_targets_json' => $jobPayload['big5_targets_json'],
            'iq_eq_notes_json' => $jobPayload['iq_eq_notes_json'],
            'market_demand_json' => $jobPayload['market_demand_json'],
            'status' => $status,
            'is_public' => (bool) $jobPayload['is_public'],
            'is_indexable' => (bool) $jobPayload['is_indexable'],
            'published_at' => $publishedAt,
            'scheduled_at' => $status === CareerJob::STATUS_DRAFT
                ? null
                : $this->normalizeNullableDate($jobPayload['scheduled_at'] ?? null),
            'schema_version' => (string) ($jobPayload['schema_version'] ?? 'v1'),
            'sort_order' => (int) ($jobPayload['sort_order'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $jobPayload
     */
    private function syncSections(CareerJob $job, array $jobPayload, bool $fullSync): void
    {
        $desiredSections = [];

        foreach ((array) $jobPayload['sections'] as $section) {
            $desiredSections[(string) $section['section_key']] = [
                'title' => $section['title'],
                'render_variant' => (string) $section['render_variant'],
                'body_md' => $section['body_md'],
                'body_html' => $section['body_html'],
                'payload_json' => $section['payload_json'],
                'sort_order' => (int) $section['sort_order'],
                'is_enabled' => (bool) $section['is_enabled'],
            ];
        }

        foreach ($desiredSections as $sectionKey => $attributes) {
            CareerJobSection::query()->updateOrCreate(
                [
                    'job_id' => (int) $job->id,
                    'section_key' => $sectionKey,
                ],
                $attributes,
            );
        }

        if ($fullSync) {
            $managedKeys = array_values(array_intersect(
                array_keys($desiredSections),
                CareerJobSection::SECTION_KEYS,
            ));

            $deleteQuery = CareerJobSection::query()
                ->where('job_id', (int) $job->id)
                ->whereIn('section_key', CareerJobSection::SECTION_KEYS);

            if ($managedKeys !== []) {
                $deleteQuery->whereNotIn('section_key', $managedKeys);
            }

            $deleteQuery->delete();
        }

        $job->unsetRelation('sections');
    }

    /**
     * @param  array<string, mixed>  $jobPayload
     */
    private function syncSeoMeta(CareerJob $job, array $jobPayload): void
    {
        $seoMeta = (array) $jobPayload['seo_meta'];

        CareerJobSeoMeta::query()->updateOrCreate(
            [
                'job_id' => (int) $job->id,
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

        $job->unsetRelation('seoMeta');
    }

    private function createRevision(CareerJob $job, string $note): void
    {
        CareerJobRevision::query()->create([
            'job_id' => (int) $job->id,
            'revision_no' => ((int) CareerJobRevision::query()
                ->where('job_id', (int) $job->id)
                ->max('revision_no')) + 1,
            'snapshot_json' => $this->currentComparableState($job->fresh(['sections', 'seoMeta'])),
            'note' => $note,
            'created_by_admin_user_id' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $jobPayload
     * @return array<string, mixed>
     */
    private function desiredComparableState(
        array $jobPayload,
        string $status,
        ?CareerJob $existing = null,
    ): array {
        $sections = collect((array) $jobPayload['sections'])
            ->map(static fn (array $section): array => [
                'section_key' => (string) $section['section_key'],
                'title' => $section['title'],
                'render_variant' => (string) $section['render_variant'],
                'body_md' => $section['body_md'],
                'body_html' => $section['body_html'],
                'payload_json' => $section['payload_json'],
                'sort_order' => (int) $section['sort_order'],
                'is_enabled' => (bool) $section['is_enabled'],
            ])
            ->sortBy([
                ['sort_order', 'asc'],
                ['section_key', 'asc'],
            ])
            ->values()
            ->all();

        return [
            'job' => [
                'org_id' => 0,
                'job_code' => (string) $jobPayload['job_code'],
                'slug' => (string) $jobPayload['slug'],
                'locale' => (string) $jobPayload['locale'],
                'title' => (string) $jobPayload['title'],
                'subtitle' => $jobPayload['subtitle'],
                'excerpt' => $jobPayload['excerpt'],
                'hero_kicker' => $jobPayload['hero_kicker'],
                'hero_quote' => $jobPayload['hero_quote'],
                'cover_image_url' => $jobPayload['cover_image_url'],
                'industry_slug' => $jobPayload['industry_slug'],
                'industry_label' => $jobPayload['industry_label'],
                'body_md' => $jobPayload['body_md'],
                'body_html' => $jobPayload['body_html'],
                'salary_json' => $jobPayload['salary_json'],
                'outlook_json' => $jobPayload['outlook_json'],
                'skills_json' => $jobPayload['skills_json'],
                'work_contents_json' => $jobPayload['work_contents_json'],
                'growth_path_json' => $jobPayload['growth_path_json'],
                'fit_personality_codes_json' => $jobPayload['fit_personality_codes_json'],
                'mbti_primary_codes_json' => $jobPayload['mbti_primary_codes_json'],
                'mbti_secondary_codes_json' => $jobPayload['mbti_secondary_codes_json'],
                'riasec_profile_json' => $jobPayload['riasec_profile_json'],
                'big5_targets_json' => $jobPayload['big5_targets_json'],
                'iq_eq_notes_json' => $jobPayload['iq_eq_notes_json'],
                'market_demand_json' => $jobPayload['market_demand_json'],
                'status' => $status,
                'is_public' => (bool) $jobPayload['is_public'],
                'is_indexable' => (bool) $jobPayload['is_indexable'],
                'published_at' => $this->normalizeDateForComparison(
                    $this->resolvePublishedAt($jobPayload, $status, $existing)
                ),
                'scheduled_at' => $status === CareerJob::STATUS_DRAFT
                    ? null
                    : $this->normalizeDateForComparison($this->normalizeNullableDate($jobPayload['scheduled_at'] ?? null)),
                'schema_version' => (string) ($jobPayload['schema_version'] ?? 'v1'),
                'sort_order' => (int) ($jobPayload['sort_order'] ?? 0),
            ],
            'sections' => $sections,
            'seo_meta' => (array) $jobPayload['seo_meta'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentComparableState(CareerJob $job): array
    {
        $job->loadMissing('sections', 'seoMeta');

        return [
            'job' => [
                'org_id' => (int) $job->org_id,
                'job_code' => (string) $job->job_code,
                'slug' => (string) $job->slug,
                'locale' => (string) $job->locale,
                'title' => (string) $job->title,
                'subtitle' => $job->subtitle,
                'excerpt' => $job->excerpt,
                'hero_kicker' => $job->hero_kicker,
                'hero_quote' => $job->hero_quote,
                'cover_image_url' => $job->cover_image_url,
                'industry_slug' => $job->industry_slug,
                'industry_label' => $job->industry_label,
                'body_md' => $job->body_md,
                'body_html' => $job->body_html,
                'salary_json' => $job->salary_json,
                'outlook_json' => $job->outlook_json,
                'skills_json' => $job->skills_json,
                'work_contents_json' => $job->work_contents_json,
                'growth_path_json' => $job->growth_path_json,
                'fit_personality_codes_json' => $job->fit_personality_codes_json,
                'mbti_primary_codes_json' => $job->mbti_primary_codes_json,
                'mbti_secondary_codes_json' => $job->mbti_secondary_codes_json,
                'riasec_profile_json' => $job->riasec_profile_json,
                'big5_targets_json' => $job->big5_targets_json,
                'iq_eq_notes_json' => $job->iq_eq_notes_json,
                'market_demand_json' => $job->market_demand_json,
                'status' => (string) $job->status,
                'is_public' => (bool) $job->is_public,
                'is_indexable' => (bool) $job->is_indexable,
                'published_at' => $this->normalizeDateForComparison($job->published_at),
                'scheduled_at' => $this->normalizeDateForComparison($job->scheduled_at),
                'schema_version' => (string) $job->schema_version,
                'sort_order' => (int) $job->sort_order,
            ],
            'sections' => $job->sections
                ->map(static fn (CareerJobSection $section): array => [
                    'section_key' => (string) $section->section_key,
                    'title' => $section->title,
                    'render_variant' => (string) $section->render_variant,
                    'body_md' => $section->body_md,
                    'body_html' => $section->body_html,
                    'payload_json' => $section->payload_json,
                    'sort_order' => (int) $section->sort_order,
                    'is_enabled' => (bool) $section->is_enabled,
                ])
                ->sortBy([
                    ['sort_order', 'asc'],
                    ['section_key', 'asc'],
                ])
                ->values()
                ->all(),
            'seo_meta' => $job->seoMeta instanceof CareerJobSeoMeta
                ? [
                    'seo_title' => $job->seoMeta->seo_title,
                    'seo_description' => $job->seoMeta->seo_description,
                    'canonical_url' => $job->seoMeta->canonical_url,
                    'og_title' => $job->seoMeta->og_title,
                    'og_description' => $job->seoMeta->og_description,
                    'og_image_url' => $job->seoMeta->og_image_url,
                    'twitter_title' => $job->seoMeta->twitter_title,
                    'twitter_description' => $job->seoMeta->twitter_description,
                    'twitter_image_url' => $job->seoMeta->twitter_image_url,
                    'robots' => $job->seoMeta->robots,
                    'jsonld_overrides_json' => $job->seoMeta->jsonld_overrides_json,
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
     * @param  array<string, mixed>  $jobPayload
     */
    private function resolvePublishedAt(
        array $jobPayload,
        string $status,
        ?CareerJob $existing = null,
    ): ?Carbon {
        if ($status === CareerJob::STATUS_DRAFT) {
            return null;
        }

        return $this->normalizeNullableDate($jobPayload['published_at'] ?? null)
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

        return $normalized?->toIso8601String();
    }
}
