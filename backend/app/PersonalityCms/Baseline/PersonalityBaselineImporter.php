<?php

declare(strict_types=1);

namespace App\PersonalityCms\Baseline;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileSeoMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PersonalityBaselineImporter
{
    /**
     * @param  array<int, array<string, mixed>>  $profiles
     * @param  array{
     *   dry_run: bool,
     *   upsert: bool,
     *   status: 'draft'|'published'
     * }  $options
     * @return array<string, int|string|bool>
     */
    public function import(array $profiles, array $options): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $upsert = (bool) ($options['upsert'] ?? false);
        $status = (string) ($options['status'] ?? 'draft');

        $summary = [
            'profiles_found' => count($profiles),
            'will_create' => 0,
            'will_update' => 0,
            'will_skip' => 0,
            'revisions_to_create' => 0,
            'errors_count' => 0,
            'dry_run' => $dryRun,
            'upsert' => $upsert,
            'status_mode' => $status,
        ];

        foreach ($profiles as $profilePayload) {
            $existing = $this->profileQuery()
                ->where('org_id', 0)
                ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
                ->where('type_code', (string) $profilePayload['type_code'])
                ->where('locale', (string) $profilePayload['locale'])
                ->first();

            if (! $existing instanceof PersonalityProfile) {
                $summary['will_create']++;
                $summary['revisions_to_create']++;

                if (! $dryRun) {
                    DB::transaction(function () use ($profilePayload, $status): void {
                        $profile = PersonalityProfile::query()->create(
                            $this->profileAttributes($profilePayload, $status)
                        );

                        $this->syncSections($profile, $profilePayload, false);
                        $this->syncSeoMeta($profile, $profilePayload);
                        $this->createRevision($profile, 'baseline import');
                    });
                }

                continue;
            }

            if (! $upsert) {
                $summary['will_skip']++;

                continue;
            }

            $desiredState = $this->desiredComparableState($profilePayload, $status, $existing);
            $currentState = $this->currentComparableState($existing);

            if ($desiredState === $currentState) {
                $summary['will_skip']++;

                continue;
            }

            $summary['will_update']++;
            $summary['revisions_to_create']++;

            if (! $dryRun) {
                DB::transaction(function () use ($existing, $profilePayload, $status): void {
                    $existing->fill($this->profileAttributes($profilePayload, $status, $existing));
                    $existing->save();

                    $this->syncSections($existing, $profilePayload, true);
                    $this->syncSeoMeta($existing, $profilePayload);
                    $this->createRevision($existing, 'baseline upsert');
                });
            }
        }

        return $summary;
    }

    private function profileQuery(): Builder
    {
        return PersonalityProfile::query()->withoutGlobalScopes();
    }

    /**
     * @param  array<string, mixed>  $profilePayload
     * @return array<string, mixed>
     */
    private function profileAttributes(
        array $profilePayload,
        string $status,
        ?PersonalityProfile $existing = null,
    ): array {
        $publishedAt = $this->resolvePublishedAt($profilePayload, $status, $existing);

        return [
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => (string) $profilePayload['type_code'],
            'slug' => (string) $profilePayload['slug'],
            'locale' => (string) $profilePayload['locale'],
            'title' => (string) $profilePayload['title'],
            'subtitle' => $profilePayload['subtitle'],
            'excerpt' => $profilePayload['excerpt'],
            'hero_kicker' => $profilePayload['hero_kicker'],
            'hero_quote' => $profilePayload['hero_quote'],
            'hero_image_url' => $profilePayload['hero_image_url'],
            'status' => $status,
            'is_public' => (bool) $profilePayload['is_public'],
            'is_indexable' => (bool) $profilePayload['is_indexable'],
            'published_at' => $publishedAt,
            'scheduled_at' => $status === 'draft'
                ? null
                : $this->normalizeNullableDate($profilePayload['scheduled_at'] ?? null),
            'schema_version' => (string) ($profilePayload['schema_version'] ?? 'v1'),
        ];
    }

    /**
     * @param  array<string, mixed>  $profilePayload
     */
    private function syncSections(PersonalityProfile $profile, array $profilePayload, bool $fullSync): void
    {
        $desiredSections = [];

        foreach ((array) $profilePayload['sections'] as $section) {
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
            PersonalityProfileSection::query()->updateOrCreate(
                [
                    'profile_id' => (int) $profile->id,
                    'section_key' => $sectionKey,
                ],
                $attributes,
            );
        }

        if ($fullSync) {
            $managedKeys = array_values(array_intersect(
                array_keys($desiredSections),
                PersonalityProfileSection::SECTION_KEYS,
            ));

            $deleteQuery = PersonalityProfileSection::query()
                ->where('profile_id', (int) $profile->id)
                ->whereIn('section_key', PersonalityProfileSection::SECTION_KEYS);

            if ($managedKeys !== []) {
                $deleteQuery->whereNotIn('section_key', $managedKeys);
            }

            $deleteQuery->delete();
        }

        $profile->unsetRelation('sections');
    }

    /**
     * @param  array<string, mixed>  $profilePayload
     */
    private function syncSeoMeta(PersonalityProfile $profile, array $profilePayload): void
    {
        $seoMeta = (array) $profilePayload['seo_meta'];

        PersonalityProfileSeoMeta::query()->updateOrCreate(
            [
                'profile_id' => (int) $profile->id,
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

        $profile->unsetRelation('seoMeta');
    }

    private function createRevision(PersonalityProfile $profile, string $note): void
    {
        PersonalityProfileRevision::query()->create([
            'profile_id' => (int) $profile->id,
            'revision_no' => ((int) PersonalityProfileRevision::query()
                ->where('profile_id', (int) $profile->id)
                ->max('revision_no')) + 1,
            'snapshot_json' => $this->currentComparableState($profile->fresh(['sections', 'seoMeta'])),
            'note' => $note,
            'created_by_admin_user_id' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $profilePayload
     * @return array<string, mixed>
     */
    private function desiredComparableState(
        array $profilePayload,
        string $status,
        ?PersonalityProfile $existing = null,
    ): array {
        $sections = collect((array) $profilePayload['sections'])
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
            'profile' => [
                'org_id' => 0,
                'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
                'type_code' => (string) $profilePayload['type_code'],
                'slug' => (string) $profilePayload['slug'],
                'locale' => (string) $profilePayload['locale'],
                'title' => (string) $profilePayload['title'],
                'subtitle' => $profilePayload['subtitle'],
                'excerpt' => $profilePayload['excerpt'],
                'hero_kicker' => $profilePayload['hero_kicker'],
                'hero_quote' => $profilePayload['hero_quote'],
                'hero_image_url' => $profilePayload['hero_image_url'],
                'status' => $status,
                'is_public' => (bool) $profilePayload['is_public'],
                'is_indexable' => (bool) $profilePayload['is_indexable'],
                'published_at' => $this->normalizeDateForComparison(
                    $this->resolvePublishedAt($profilePayload, $status, $existing)
                ),
                'scheduled_at' => $status === 'draft'
                    ? null
                    : $this->normalizeDateForComparison($this->normalizeNullableDate($profilePayload['scheduled_at'] ?? null)),
                'schema_version' => (string) ($profilePayload['schema_version'] ?? 'v1'),
            ],
            'sections' => $sections,
            'seo_meta' => (array) $profilePayload['seo_meta'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentComparableState(PersonalityProfile $profile): array
    {
        $profile->loadMissing('sections', 'seoMeta');

        return [
            'profile' => [
                'org_id' => (int) $profile->org_id,
                'scale_code' => (string) $profile->scale_code,
                'type_code' => (string) $profile->type_code,
                'slug' => (string) $profile->slug,
                'locale' => (string) $profile->locale,
                'title' => (string) $profile->title,
                'subtitle' => $profile->subtitle,
                'excerpt' => $profile->excerpt,
                'hero_kicker' => $profile->hero_kicker,
                'hero_quote' => $profile->hero_quote,
                'hero_image_url' => $profile->hero_image_url,
                'status' => (string) $profile->status,
                'is_public' => (bool) $profile->is_public,
                'is_indexable' => (bool) $profile->is_indexable,
                'published_at' => $this->normalizeDateForComparison($profile->published_at),
                'scheduled_at' => $this->normalizeDateForComparison($profile->scheduled_at),
                'schema_version' => (string) $profile->schema_version,
            ],
            'sections' => $profile->sections
                ->map(static fn (PersonalityProfileSection $section): array => [
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
            'seo_meta' => $profile->seoMeta instanceof PersonalityProfileSeoMeta
                ? [
                    'seo_title' => $profile->seoMeta->seo_title,
                    'seo_description' => $profile->seoMeta->seo_description,
                    'canonical_url' => $profile->seoMeta->canonical_url,
                    'og_title' => $profile->seoMeta->og_title,
                    'og_description' => $profile->seoMeta->og_description,
                    'og_image_url' => $profile->seoMeta->og_image_url,
                    'twitter_title' => $profile->seoMeta->twitter_title,
                    'twitter_description' => $profile->seoMeta->twitter_description,
                    'twitter_image_url' => $profile->seoMeta->twitter_image_url,
                    'robots' => $profile->seoMeta->robots,
                    'jsonld_overrides_json' => $profile->seoMeta->jsonld_overrides_json,
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
     * @param  array<string, mixed>  $profilePayload
     */
    private function resolvePublishedAt(
        array $profilePayload,
        string $status,
        ?PersonalityProfile $existing = null,
    ): ?Carbon {
        if ($status === 'draft') {
            return null;
        }

        return $this->normalizeNullableDate($profilePayload['published_at'] ?? null)
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
