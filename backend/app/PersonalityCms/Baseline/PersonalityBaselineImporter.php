<?php

declare(strict_types=1);

namespace App\PersonalityCms\Baseline;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileSeoMeta;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use App\Support\Mbti\MbtiCanonicalSectionRegistry;
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
            'variants_found' => array_sum(array_map(
                static fn (array $profilePayload): int => count((array) ($profilePayload['variants'] ?? [])),
                $profiles,
            )),
            'will_create' => 0,
            'will_update' => 0,
            'will_skip' => 0,
            'revisions_to_create' => 0,
            'variant_will_create' => 0,
            'variant_will_update' => 0,
            'variant_will_skip' => 0,
            'variant_will_delete' => 0,
            'variant_revisions_to_create' => 0,
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
                $this->mergeVariantSummary($summary, $this->calculateVariantSummary(null, $profilePayload, $status, true));

                if (! $dryRun) {
                    DB::transaction(function () use ($profilePayload, $status): void {
                        $profile = PersonalityProfile::query()->create(
                            $this->profileAttributes($profilePayload, $status)
                        );

                        $this->syncSections($profile, $profilePayload, false);
                        $this->syncSeoMeta($profile, $profilePayload);
                        $this->createRevision($profile, 'baseline import');
                        $this->syncVariants($profile, $profilePayload, $status, true);
                    });
                }

                continue;
            }

            if (! $upsert) {
                $summary['will_skip']++;
                $this->mergeVariantSummary($summary, $this->calculateVariantSummary($existing, $profilePayload, $status, false));

                continue;
            }

            $desiredState = $this->desiredComparableState($profilePayload, $status, $existing);
            $currentState = $this->currentComparableState($existing);
            $baseChanged = $desiredState !== $currentState;
            $variantSummary = $this->calculateVariantSummary($existing, $profilePayload, $status, true);

            if (! $baseChanged
                && (int) $variantSummary['variant_will_create'] === 0
                && (int) $variantSummary['variant_will_update'] === 0
                && (int) $variantSummary['variant_will_delete'] === 0) {
                $summary['will_skip']++;
                $this->mergeVariantSummary($summary, $variantSummary);

                continue;
            }

            if ($baseChanged) {
                $summary['will_update']++;
                $summary['revisions_to_create']++;
            }

            $this->mergeVariantSummary($summary, $variantSummary);

            if (! $dryRun) {
                DB::transaction(function () use ($existing, $profilePayload, $status, $baseChanged): void {
                    if ($baseChanged) {
                        $existing->fill($this->profileAttributes($profilePayload, $status, $existing));
                        $existing->save();

                        $this->syncSections($existing, $profilePayload, true);
                        $this->syncSeoMeta($existing, $profilePayload);
                        $this->createRevision($existing, 'baseline upsert');
                    }

                    $this->syncVariants($existing, $profilePayload, $status, true);
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
            'canonical_type_code' => (string) ($profilePayload['canonical_type_code'] ?? $profilePayload['type_code']),
            'slug' => (string) $profilePayload['slug'],
            'locale' => (string) $profilePayload['locale'],
            'title' => (string) $profilePayload['title'],
            'type_name' => $profilePayload['type_name'],
            'nickname' => $profilePayload['nickname'],
            'rarity_text' => $profilePayload['rarity_text'],
            'keywords_json' => $profilePayload['keywords_json'],
            'subtitle' => $profilePayload['subtitle'],
            'excerpt' => $profilePayload['excerpt'],
            'hero_kicker' => $profilePayload['hero_kicker'],
            'hero_quote' => $profilePayload['hero_quote'],
            'hero_summary_md' => $profilePayload['hero_summary_md'],
            'hero_summary_html' => $profilePayload['hero_summary_html'],
            'hero_image_url' => $profilePayload['hero_image_url'],
            'status' => $status,
            'is_public' => (bool) $profilePayload['is_public'],
            'is_indexable' => (bool) $profilePayload['is_indexable'],
            'published_at' => $publishedAt,
            'scheduled_at' => $status === 'draft'
                ? null
                : $this->normalizeNullableDate($profilePayload['scheduled_at'] ?? null),
            'schema_version' => (string) ($profilePayload['schema_version'] ?? PersonalityProfile::SCHEMA_VERSION_V2),
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
            $managedKeys = $this->managedBaseSectionKeys();
            $keepKeys = array_values(array_intersect(array_keys($desiredSections), $managedKeys));

            $deleteQuery = PersonalityProfileSection::query()
                ->where('profile_id', (int) $profile->id)
                ->whereIn('section_key', $managedKeys);

            if ($keepKeys !== []) {
                $deleteQuery->whereNotIn('section_key', $keepKeys);
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
                'canonical_type_code' => (string) ($profilePayload['canonical_type_code'] ?? $profilePayload['type_code']),
                'slug' => (string) $profilePayload['slug'],
                'locale' => (string) $profilePayload['locale'],
                'title' => (string) $profilePayload['title'],
                'type_name' => $profilePayload['type_name'],
                'nickname' => $profilePayload['nickname'],
                'rarity_text' => $profilePayload['rarity_text'],
                'keywords_json' => $profilePayload['keywords_json'],
                'subtitle' => $profilePayload['subtitle'],
                'excerpt' => $profilePayload['excerpt'],
                'hero_kicker' => $profilePayload['hero_kicker'],
                'hero_quote' => $profilePayload['hero_quote'],
                'hero_summary_md' => $profilePayload['hero_summary_md'],
                'hero_summary_html' => $profilePayload['hero_summary_html'],
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
                'schema_version' => (string) ($profilePayload['schema_version'] ?? PersonalityProfile::SCHEMA_VERSION_V2),
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
                'canonical_type_code' => (string) ($profile->canonical_type_code ?: $profile->type_code),
                'slug' => (string) $profile->slug,
                'locale' => (string) $profile->locale,
                'title' => (string) $profile->title,
                'type_name' => $profile->type_name,
                'nickname' => $profile->nickname,
                'rarity_text' => $profile->rarity_text,
                'keywords_json' => is_array($profile->keywords_json) ? array_values($profile->keywords_json) : [],
                'subtitle' => $profile->subtitle,
                'excerpt' => $profile->excerpt,
                'hero_kicker' => $profile->hero_kicker,
                'hero_quote' => $profile->hero_quote,
                'hero_summary_md' => $profile->hero_summary_md,
                'hero_summary_html' => $profile->hero_summary_html,
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
                : $this->emptySeoMeta(),
        ];
    }

    /**
     * @param  array<string, mixed>  $profilePayload
     * @return array<string, int>
     */
    private function calculateVariantSummary(
        ?PersonalityProfile $profile,
        array $profilePayload,
        string $status,
        bool $allowUpdates,
    ): array {
        $summary = [
            'variant_will_create' => 0,
            'variant_will_update' => 0,
            'variant_will_skip' => 0,
            'variant_will_delete' => 0,
            'variant_revisions_to_create' => 0,
        ];

        $desiredVariants = (array) ($profilePayload['variants'] ?? []);
        if (! $profile instanceof PersonalityProfile) {
            $count = count($desiredVariants);

            return [
                'variant_will_create' => $count,
                'variant_will_update' => 0,
                'variant_will_skip' => 0,
                'variant_will_delete' => 0,
                'variant_revisions_to_create' => $count,
            ];
        }

        $existingVariants = PersonalityProfileVariant::query()
            ->where('personality_profile_id', (int) $profile->id)
            ->with(['sections', 'seoMeta'])
            ->get()
            ->keyBy(static fn (PersonalityProfileVariant $variant): string => (string) $variant->runtime_type_code);

        $desiredRuntimeCodes = [];

        foreach ($desiredVariants as $variantPayload) {
            $runtimeTypeCode = (string) $variantPayload['runtime_type_code'];
            $desiredRuntimeCodes[] = $runtimeTypeCode;
            $existing = $existingVariants->get($runtimeTypeCode);

            if (! $existing instanceof PersonalityProfileVariant) {
                $summary['variant_will_create']++;
                $summary['variant_revisions_to_create']++;

                continue;
            }

            if (! $allowUpdates) {
                $summary['variant_will_skip']++;

                continue;
            }

            $desiredState = $this->desiredComparableVariantState($variantPayload, $status, $existing);
            $currentState = $this->currentComparableVariantState($existing);

            if ($desiredState === $currentState) {
                $summary['variant_will_skip']++;

                continue;
            }

            $summary['variant_will_update']++;
            $summary['variant_revisions_to_create']++;
        }

        if ($allowUpdates) {
            $summary['variant_will_delete'] += $existingVariants
                ->keys()
                ->diff($desiredRuntimeCodes)
                ->count();
        }

        return $summary;
    }

    /**
     * @param  array<string, int|string|bool>  $summary
     * @param  array<string, int>  $variantSummary
     */
    private function mergeVariantSummary(array &$summary, array $variantSummary): void
    {
        foreach ($variantSummary as $key => $value) {
            $summary[$key] = (int) $summary[$key] + (int) $value;
        }
    }

    /**
     * @param  array<string, mixed>  $profilePayload
     */
    private function syncVariants(
        PersonalityProfile $profile,
        array $profilePayload,
        string $status,
        bool $allowUpdates,
    ): void {
        $desiredVariants = (array) ($profilePayload['variants'] ?? []);
        $existingVariants = PersonalityProfileVariant::query()
            ->where('personality_profile_id', (int) $profile->id)
            ->with(['sections', 'seoMeta'])
            ->get()
            ->keyBy(static fn (PersonalityProfileVariant $variant): string => (string) $variant->runtime_type_code);

        $desiredRuntimeCodes = [];

        foreach ($desiredVariants as $variantPayload) {
            $runtimeTypeCode = (string) $variantPayload['runtime_type_code'];
            $desiredRuntimeCodes[] = $runtimeTypeCode;
            $existing = $existingVariants->get($runtimeTypeCode);

            if (! $existing instanceof PersonalityProfileVariant) {
                $variant = PersonalityProfileVariant::query()->create(
                    $this->variantAttributes($profile, $variantPayload, $status)
                );

                $this->syncVariantSections($variant, $variantPayload, false);
                $this->syncVariantSeoMeta($variant, $variantPayload);
                $this->createVariantRevision($variant, 'baseline import');

                continue;
            }

            if (! $allowUpdates) {
                continue;
            }

            $desiredState = $this->desiredComparableVariantState($variantPayload, $status, $existing);
            $currentState = $this->currentComparableVariantState($existing);

            if ($desiredState === $currentState) {
                continue;
            }

            $existing->fill($this->variantAttributes($profile, $variantPayload, $status, $existing));
            $existing->save();

            $this->syncVariantSections($existing, $variantPayload, true);
            $this->syncVariantSeoMeta($existing, $variantPayload);
            $this->createVariantRevision($existing, 'baseline upsert');
        }

        if ($allowUpdates) {
            PersonalityProfileVariant::query()
                ->where('personality_profile_id', (int) $profile->id)
                ->whereNotIn('runtime_type_code', $desiredRuntimeCodes === [] ? ['__none__'] : $desiredRuntimeCodes)
                ->delete();
        }

        $profile->unsetRelation('variants');
    }

    /**
     * @param  array<string, mixed>  $variantPayload
     * @return array<string, mixed>
     */
    private function variantAttributes(
        PersonalityProfile $profile,
        array $variantPayload,
        string $status,
        ?PersonalityProfileVariant $existing = null,
    ): array {
        $profileOverrides = (array) ($variantPayload['profile_overrides'] ?? []);

        return [
            'personality_profile_id' => (int) $profile->id,
            'canonical_type_code' => (string) $variantPayload['canonical_type_code'],
            'variant_code' => (string) $variantPayload['variant_code'],
            'runtime_type_code' => (string) $variantPayload['runtime_type_code'],
            'type_name' => $profileOverrides['type_name'],
            'nickname' => $profileOverrides['nickname'],
            'rarity_text' => $profileOverrides['rarity_text'],
            'keywords_json' => $profileOverrides['keywords_json'],
            'hero_summary_md' => $profileOverrides['hero_summary_md'],
            'hero_summary_html' => $profileOverrides['hero_summary_html'],
            'schema_version' => (string) ($variantPayload['schema_version'] ?? PersonalityProfile::SCHEMA_VERSION_V2),
            'is_published' => $this->resolveVariantPublishedState($variantPayload, $status),
            'published_at' => $this->resolveVariantPublishedAt($variantPayload, $status, $existing),
        ];
    }

    /**
     * @param  array<string, mixed>  $variantPayload
     */
    private function syncVariantSections(
        PersonalityProfileVariant $variant,
        array $variantPayload,
        bool $fullSync,
    ): void {
        $desiredSections = [];

        foreach ((array) ($variantPayload['section_overrides'] ?? []) as $section) {
            $desiredSections[(string) $section['section_key']] = [
                'render_variant' => (string) $section['render_variant'],
                'body_md' => $section['body_md'],
                'body_html' => $section['body_html'],
                'payload_json' => $section['payload_json'],
                'sort_order' => (int) $section['sort_order'],
                'is_enabled' => (bool) $section['is_enabled'],
            ];
        }

        foreach ($desiredSections as $sectionKey => $attributes) {
            PersonalityProfileVariantSection::query()->updateOrCreate(
                [
                    'personality_profile_variant_id' => (int) $variant->id,
                    'section_key' => $sectionKey,
                ],
                $attributes,
            );
        }

        if ($fullSync) {
            $managedKeys = MbtiCanonicalSectionRegistry::keys();
            $keepKeys = array_values(array_intersect(array_keys($desiredSections), $managedKeys));

            $deleteQuery = PersonalityProfileVariantSection::query()
                ->where('personality_profile_variant_id', (int) $variant->id)
                ->whereIn('section_key', $managedKeys);

            if ($keepKeys !== []) {
                $deleteQuery->whereNotIn('section_key', $keepKeys);
            }

            $deleteQuery->delete();
        }

        $variant->unsetRelation('sections');
    }

    /**
     * @param  array<string, mixed>  $variantPayload
     */
    private function syncVariantSeoMeta(PersonalityProfileVariant $variant, array $variantPayload): void
    {
        $seoMeta = (array) ($variantPayload['seo_overrides'] ?? []);

        PersonalityProfileVariantSeoMeta::query()->updateOrCreate(
            [
                'personality_profile_variant_id' => (int) $variant->id,
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

        $variant->unsetRelation('seoMeta');
    }

    private function createVariantRevision(PersonalityProfileVariant $variant, string $note): void
    {
        PersonalityProfileVariantRevision::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'revision_no' => ((int) PersonalityProfileVariantRevision::query()
                ->where('personality_profile_variant_id', (int) $variant->id)
                ->max('revision_no')) + 1,
            'snapshot_json' => $this->currentComparableVariantState($variant->fresh(['sections', 'seoMeta'])),
            'note' => $note,
            'created_by_admin_user_id' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $variantPayload
     * @return array<string, mixed>
     */
    private function desiredComparableVariantState(
        array $variantPayload,
        string $status,
        ?PersonalityProfileVariant $existing = null,
    ): array {
        $profileOverrides = (array) ($variantPayload['profile_overrides'] ?? []);
        $sections = collect((array) ($variantPayload['section_overrides'] ?? []))
            ->map(static fn (array $section): array => [
                'section_key' => (string) $section['section_key'],
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
            'variant_profile' => [
                'canonical_type_code' => (string) $variantPayload['canonical_type_code'],
                'variant_code' => (string) $variantPayload['variant_code'],
                'runtime_type_code' => (string) $variantPayload['runtime_type_code'],
                'type_name' => $profileOverrides['type_name'],
                'nickname' => $profileOverrides['nickname'],
                'rarity_text' => $profileOverrides['rarity_text'],
                'keywords_json' => $profileOverrides['keywords_json'],
                'hero_summary_md' => $profileOverrides['hero_summary_md'],
                'hero_summary_html' => $profileOverrides['hero_summary_html'],
                'schema_version' => (string) ($variantPayload['schema_version'] ?? PersonalityProfile::SCHEMA_VERSION_V2),
                'is_published' => $this->resolveVariantPublishedState($variantPayload, $status),
                'published_at' => $this->normalizeDateForComparison(
                    $this->resolveVariantPublishedAt($variantPayload, $status, $existing)
                ),
            ],
            'variant_sections' => $sections,
            'variant_seo_meta' => (array) ($variantPayload['seo_overrides'] ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentComparableVariantState(PersonalityProfileVariant $variant): array
    {
        $variant->loadMissing('sections', 'seoMeta');

        return [
            'variant_profile' => [
                'canonical_type_code' => (string) $variant->canonical_type_code,
                'variant_code' => (string) $variant->variant_code,
                'runtime_type_code' => (string) $variant->runtime_type_code,
                'type_name' => $variant->type_name,
                'nickname' => $variant->nickname,
                'rarity_text' => $variant->rarity_text,
                'keywords_json' => is_array($variant->keywords_json) ? array_values($variant->keywords_json) : [],
                'hero_summary_md' => $variant->hero_summary_md,
                'hero_summary_html' => $variant->hero_summary_html,
                'schema_version' => (string) $variant->schema_version,
                'is_published' => (bool) $variant->is_published,
                'published_at' => $this->normalizeDateForComparison($variant->published_at),
            ],
            'variant_sections' => $variant->sections
                ->map(static fn (PersonalityProfileVariantSection $section): array => [
                    'section_key' => (string) $section->section_key,
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
            'variant_seo_meta' => $variant->seoMeta instanceof PersonalityProfileVariantSeoMeta
                ? [
                    'seo_title' => $variant->seoMeta->seo_title,
                    'seo_description' => $variant->seoMeta->seo_description,
                    'canonical_url' => $variant->seoMeta->canonical_url,
                    'og_title' => $variant->seoMeta->og_title,
                    'og_description' => $variant->seoMeta->og_description,
                    'og_image_url' => $variant->seoMeta->og_image_url,
                    'twitter_title' => $variant->seoMeta->twitter_title,
                    'twitter_description' => $variant->seoMeta->twitter_description,
                    'twitter_image_url' => $variant->seoMeta->twitter_image_url,
                    'robots' => $variant->seoMeta->robots,
                    'jsonld_overrides_json' => $variant->seoMeta->jsonld_overrides_json,
                ]
                : $this->emptySeoMeta(),
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

    /**
     * @param  array<string, mixed>  $variantPayload
     */
    private function resolveVariantPublishedState(array $variantPayload, string $status): bool
    {
        if ($status === 'draft') {
            return false;
        }

        return (bool) ($variantPayload['is_published'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $variantPayload
     */
    private function resolveVariantPublishedAt(
        array $variantPayload,
        string $status,
        ?PersonalityProfileVariant $existing = null,
    ): ?Carbon {
        if (! $this->resolveVariantPublishedState($variantPayload, $status)) {
            return null;
        }

        return $this->normalizeNullableDate($variantPayload['published_at'] ?? null)
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

    /**
     * @return array<int, string>
     */
    private function managedBaseSectionKeys(): array
    {
        return array_values(array_unique(array_merge(
            PersonalityProfileSection::SECTION_KEYS,
            MbtiCanonicalSectionRegistry::keys(),
        )));
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySeoMeta(): array
    {
        return [
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
        ];
    }
}
