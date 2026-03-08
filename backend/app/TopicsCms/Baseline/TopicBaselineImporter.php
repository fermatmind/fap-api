<?php

declare(strict_types=1);

namespace App\TopicsCms\Baseline;

use App\Models\TopicProfile;
use App\Models\TopicProfileEntry;
use App\Models\TopicProfileRevision;
use App\Models\TopicProfileSection;
use App\Models\TopicProfileSeoMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class TopicBaselineImporter
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

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
        $status = (string) ($options['status'] ?? self::STATUS_DRAFT);

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
                ->where('topic_code', (string) $profilePayload['topic_code'])
                ->where('locale', (string) $profilePayload['locale'])
                ->first();

            if (! $existing instanceof TopicProfile) {
                $summary['will_create']++;
                $summary['revisions_to_create']++;

                if (! $dryRun) {
                    DB::transaction(function () use ($profilePayload, $status): void {
                        $profile = TopicProfile::query()->create(
                            $this->profileAttributes($profilePayload, $status)
                        );

                        $this->syncSections($profile, $profilePayload);
                        $this->syncEntries($profile, $profilePayload);
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

                    $this->syncSections($existing, $profilePayload);
                    $this->syncEntries($existing, $profilePayload);
                    $this->syncSeoMeta($existing, $profilePayload);
                    $this->createRevision($existing, 'baseline upsert');
                });
            }
        }

        return $summary;
    }

    private function profileQuery(): Builder
    {
        return TopicProfile::query()->withoutGlobalScopes();
    }

    /**
     * @param  array<string, mixed>  $profilePayload
     * @return array<string, mixed>
     */
    private function profileAttributes(
        array $profilePayload,
        string $status,
        ?TopicProfile $existing = null,
    ): array {
        return [
            'org_id' => 0,
            'topic_code' => (string) $profilePayload['topic_code'],
            'slug' => (string) $profilePayload['slug'],
            'locale' => (string) $profilePayload['locale'],
            'title' => (string) $profilePayload['title'],
            'subtitle' => $profilePayload['subtitle'],
            'excerpt' => $profilePayload['excerpt'],
            'hero_kicker' => $profilePayload['hero_kicker'],
            'hero_quote' => $profilePayload['hero_quote'],
            'cover_image_url' => $profilePayload['cover_image_url'],
            'status' => $status,
            'is_public' => (bool) $profilePayload['is_public'],
            'is_indexable' => (bool) $profilePayload['is_indexable'],
            'published_at' => $this->resolvePublishedAt($profilePayload, $status, $existing),
            'scheduled_at' => $status === self::STATUS_DRAFT
                ? null
                : $this->normalizeNullableDate($profilePayload['scheduled_at'] ?? null),
            'schema_version' => (string) ($profilePayload['schema_version'] ?? 'v1'),
            'sort_order' => (int) ($profilePayload['sort_order'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $profilePayload
     */
    private function syncSections(TopicProfile $profile, array $profilePayload): void
    {
        TopicProfileSection::query()
            ->where('profile_id', (int) $profile->id)
            ->delete();

        foreach ((array) $profilePayload['sections'] as $section) {
            TopicProfileSection::query()->create([
                'profile_id' => (int) $profile->id,
                'section_key' => (string) $section['section_key'],
                'title' => $section['title'],
                'render_variant' => (string) $section['render_variant'],
                'body_md' => $section['body_md'],
                'body_html' => $section['body_html'],
                'payload_json' => $section['payload_json'],
                'sort_order' => (int) $section['sort_order'],
                'is_enabled' => (bool) $section['is_enabled'],
            ]);
        }

        $profile->unsetRelation('sections');
    }

    /**
     * @param  array<string, mixed>  $profilePayload
     */
    private function syncEntries(TopicProfile $profile, array $profilePayload): void
    {
        TopicProfileEntry::query()
            ->where('profile_id', (int) $profile->id)
            ->delete();

        foreach ((array) $profilePayload['entries'] as $entry) {
            TopicProfileEntry::query()->create([
                'profile_id' => (int) $profile->id,
                'entry_type' => (string) $entry['entry_type'],
                'group_key' => (string) $entry['group_key'],
                'target_key' => (string) $entry['target_key'],
                'target_locale' => $entry['target_locale'],
                'title_override' => $entry['title_override'],
                'excerpt_override' => $entry['excerpt_override'],
                'badge_label' => $entry['badge_label'],
                'cta_label' => $entry['cta_label'],
                'target_url_override' => $entry['target_url_override'],
                'payload_json' => $entry['payload_json'],
                'sort_order' => (int) $entry['sort_order'],
                'is_featured' => (bool) $entry['is_featured'],
                'is_enabled' => (bool) $entry['is_enabled'],
            ]);
        }

        $profile->unsetRelation('entries');
    }

    /**
     * @param  array<string, mixed>  $profilePayload
     */
    private function syncSeoMeta(TopicProfile $profile, array $profilePayload): void
    {
        $seoMeta = (array) $profilePayload['seo_meta'];

        TopicProfileSeoMeta::query()->updateOrCreate(
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

    private function createRevision(TopicProfile $profile, string $note): void
    {
        TopicProfileRevision::query()->create([
            'profile_id' => (int) $profile->id,
            'revision_no' => ((int) TopicProfileRevision::query()
                ->where('profile_id', (int) $profile->id)
                ->max('revision_no')) + 1,
            'snapshot_json' => $this->currentComparableState($profile->fresh(['sections', 'entries', 'seoMeta'])),
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
        ?TopicProfile $existing = null,
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

        $entries = collect((array) $profilePayload['entries'])
            ->map(static fn (array $entry): array => [
                'entry_type' => (string) $entry['entry_type'],
                'group_key' => (string) $entry['group_key'],
                'target_key' => (string) $entry['target_key'],
                'target_locale' => $entry['target_locale'],
                'title_override' => $entry['title_override'],
                'excerpt_override' => $entry['excerpt_override'],
                'badge_label' => $entry['badge_label'],
                'cta_label' => $entry['cta_label'],
                'target_url_override' => $entry['target_url_override'],
                'payload_json' => $entry['payload_json'],
                'sort_order' => (int) $entry['sort_order'],
                'is_featured' => (bool) $entry['is_featured'],
                'is_enabled' => (bool) $entry['is_enabled'],
            ])
            ->sortBy([
                ['group_key', 'asc'],
                ['sort_order', 'asc'],
                ['entry_type', 'asc'],
                ['target_key', 'asc'],
            ])
            ->values()
            ->all();

        return [
            'profile' => [
                'org_id' => 0,
                'topic_code' => (string) $profilePayload['topic_code'],
                'slug' => (string) $profilePayload['slug'],
                'locale' => (string) $profilePayload['locale'],
                'title' => (string) $profilePayload['title'],
                'subtitle' => $profilePayload['subtitle'],
                'excerpt' => $profilePayload['excerpt'],
                'hero_kicker' => $profilePayload['hero_kicker'],
                'hero_quote' => $profilePayload['hero_quote'],
                'cover_image_url' => $profilePayload['cover_image_url'],
                'status' => $status,
                'is_public' => (bool) $profilePayload['is_public'],
                'is_indexable' => (bool) $profilePayload['is_indexable'],
                'published_at' => $this->normalizeDateForComparison(
                    $this->resolvePublishedAt($profilePayload, $status, $existing)
                ),
                'scheduled_at' => $status === self::STATUS_DRAFT
                    ? null
                    : $this->normalizeDateForComparison($this->normalizeNullableDate($profilePayload['scheduled_at'] ?? null)),
                'schema_version' => (string) ($profilePayload['schema_version'] ?? 'v1'),
                'sort_order' => (int) ($profilePayload['sort_order'] ?? 0),
            ],
            'sections' => $sections,
            'entries' => $entries,
            'seo_meta' => (array) $profilePayload['seo_meta'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentComparableState(TopicProfile $profile): array
    {
        $profile->loadMissing('sections', 'entries', 'seoMeta');

        return [
            'profile' => [
                'org_id' => (int) $profile->org_id,
                'topic_code' => (string) $profile->topic_code,
                'slug' => (string) $profile->slug,
                'locale' => (string) $profile->locale,
                'title' => (string) $profile->title,
                'subtitle' => $profile->subtitle,
                'excerpt' => $profile->excerpt,
                'hero_kicker' => $profile->hero_kicker,
                'hero_quote' => $profile->hero_quote,
                'cover_image_url' => $profile->cover_image_url,
                'status' => (string) $profile->status,
                'is_public' => (bool) $profile->is_public,
                'is_indexable' => (bool) $profile->is_indexable,
                'published_at' => $this->normalizeDateForComparison($profile->published_at),
                'scheduled_at' => $this->normalizeDateForComparison($profile->scheduled_at),
                'schema_version' => (string) $profile->schema_version,
                'sort_order' => (int) $profile->sort_order,
            ],
            'sections' => $profile->sections
                ->map(static fn (TopicProfileSection $section): array => [
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
            'entries' => $profile->entries
                ->map(static fn (TopicProfileEntry $entry): array => [
                    'entry_type' => (string) $entry->entry_type,
                    'group_key' => (string) $entry->group_key,
                    'target_key' => (string) $entry->target_key,
                    'target_locale' => $entry->target_locale,
                    'title_override' => $entry->title_override,
                    'excerpt_override' => $entry->excerpt_override,
                    'badge_label' => $entry->badge_label,
                    'cta_label' => $entry->cta_label,
                    'target_url_override' => $entry->target_url_override,
                    'payload_json' => $entry->payload_json,
                    'sort_order' => (int) $entry->sort_order,
                    'is_featured' => (bool) $entry->is_featured,
                    'is_enabled' => (bool) $entry->is_enabled,
                ])
                ->sortBy([
                    ['group_key', 'asc'],
                    ['sort_order', 'asc'],
                    ['entry_type', 'asc'],
                    ['target_key', 'asc'],
                ])
                ->values()
                ->all(),
            'seo_meta' => $profile->seoMeta instanceof TopicProfileSeoMeta
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
    private function resolvePublishedAt(array $profilePayload, string $status, ?TopicProfile $existing = null): ?Carbon
    {
        if ($status === self::STATUS_DRAFT) {
            return null;
        }

        $baselinePublishedAt = $this->normalizeNullableDate($profilePayload['published_at'] ?? null);
        if ($baselinePublishedAt instanceof Carbon) {
            return $baselinePublishedAt;
        }

        return $existing?->published_at;
    }

    private function normalizeNullableDate(mixed $value): ?Carbon
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse((string) $value);
    }

    private function normalizeDateForComparison(?Carbon $value): ?string
    {
        return $value?->copy()->utc()->toIso8601String();
    }
}
