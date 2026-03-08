<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\TopicProfileResource\Support;

use App\Filament\Ops\Support\StatusBadge;
use App\Models\TopicProfile;
use App\Models\TopicProfileEntry;
use App\Models\TopicProfileRevision;
use App\Models\TopicProfileSection;
use App\Models\TopicProfileSeoMeta;
use App\Services\Cms\TopicProfileSeoService;
use Filament\Forms\Get;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TopicWorkspace
{
    /**
     * @return array<string, array{label: string, title: string, description: string, render_variant: string, sort_order: int, enabled: bool}>
     */
    public static function sectionDefinitions(): array
    {
        return [
            'overview' => [
                'label' => 'Overview',
                'title' => 'Overview',
                'description' => 'Core framing for what this topic is and how readers should orient to it.',
                'render_variant' => 'rich_text',
                'sort_order' => 10,
                'enabled' => true,
            ],
            'key_concepts' => [
                'label' => 'Key Concepts',
                'title' => 'Key concepts',
                'description' => 'Definitions, distinctions, and foundational concepts readers need first.',
                'render_variant' => 'cards',
                'sort_order' => 20,
                'enabled' => true,
            ],
            'why_it_matters' => [
                'label' => 'Why It Matters',
                'title' => 'Why it matters',
                'description' => 'Editorial explanation of why this topic matters in practice.',
                'render_variant' => 'rich_text',
                'sort_order' => 30,
                'enabled' => true,
            ],
            'who_should_read' => [
                'label' => 'Who Should Read',
                'title' => 'Who should read',
                'description' => 'Audience guidance and decision cues for this topic hub.',
                'render_variant' => 'bullets',
                'sort_order' => 40,
                'enabled' => true,
            ],
            'faq' => [
                'label' => 'FAQ',
                'title' => 'Frequently asked questions',
                'description' => 'Optional FAQ block for repeat questions the topic hub should answer directly.',
                'render_variant' => 'faq',
                'sort_order' => 50,
                'enabled' => false,
            ],
            'related_topics_intro' => [
                'label' => 'Related Topics Intro',
                'title' => 'Related topics intro',
                'description' => 'Lead-in copy for the related topics or related resources section.',
                'render_variant' => 'callout',
                'sort_order' => 60,
                'enabled' => false,
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, description: string, allowed_entry_types: list<string>, add_label: string, sort_order: int, force_featured: bool}>
     */
    public static function entryGroupDefinitions(): array
    {
        return [
            'featured' => [
                'label' => 'Featured entries',
                'description' => 'High-signal hero cards for the topic hub. Use sparingly and keep them editorially intentional.',
                'allowed_entry_types' => ['article', 'personality_profile', 'scale', 'custom_link'],
                'add_label' => 'Add featured entry',
                'sort_order' => 10,
                'force_featured' => true,
            ],
            'articles' => [
                'label' => 'Article entries',
                'description' => 'Link supporting articles by slug and locale, with optional copy overrides.',
                'allowed_entry_types' => ['article', 'custom_link'],
                'add_label' => 'Add article entry',
                'sort_order' => 20,
                'force_featured' => false,
            ],
            'personalities' => [
                'label' => 'Personality entries',
                'description' => 'Link personality profiles by type code or slug. Defaults to the current topic locale unless overridden.',
                'allowed_entry_types' => ['personality_profile', 'custom_link'],
                'add_label' => 'Add personality entry',
                'sort_order' => 30,
                'force_featured' => false,
            ],
            'tests' => [
                'label' => 'Test entries',
                'description' => 'Link assessment scales or a custom relative path while scale routing stabilizes.',
                'allowed_entry_types' => ['scale', 'custom_link'],
                'add_label' => 'Add test entry',
                'sort_order' => 40,
                'force_featured' => false,
            ],
            'related' => [
                'label' => 'Related entries',
                'description' => 'Catch-all related resources group for mixed supporting links that stay within the site.',
                'allowed_entry_types' => ['article', 'personality_profile', 'scale', 'custom_link'],
                'add_label' => 'Add related entry',
                'sort_order' => 50,
                'force_featured' => false,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function renderVariantOptions(): array
    {
        return collect(TopicProfileSection::RENDER_VARIANTS)
            ->mapWithKeys(static fn (string $variant): array => [
                $variant => Str::of($variant)->replace('_', ' ')->headline()->value(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultFormState(): array
    {
        return [
            'org_id' => 0,
            'topic_code' => '',
            'slug' => '',
            'locale' => 'en',
            'title' => '',
            'subtitle' => '',
            'excerpt' => '',
            'hero_kicker' => '',
            'hero_quote' => '',
            'cover_image_url' => '',
            'status' => TopicProfile::STATUS_DRAFT,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => null,
            'scheduled_at' => null,
            'sort_order' => 0,
            'workspace_sections' => self::defaultWorkspaceSectionsState(),
            'workspace_entries' => self::defaultWorkspaceEntriesState(),
            'workspace_seo' => self::defaultWorkspaceSeoState(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function defaultWorkspaceSectionsState(): array
    {
        $state = [];

        foreach (self::sectionDefinitions() as $sectionKey => $definition) {
            $state[$sectionKey] = [
                'title' => $definition['title'],
                'render_variant' => $definition['render_variant'],
                'body_md' => '',
                'payload_json_text' => '',
                'sort_order' => $definition['sort_order'],
                'is_enabled' => $definition['enabled'],
            ];
        }

        return $state;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public static function defaultWorkspaceEntriesState(): array
    {
        $state = [];

        foreach (self::entryGroupDefinitions() as $groupKey => $_definition) {
            $state[$groupKey] = [];
        }

        return $state;
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
            'jsonld_overrides_json_text' => '',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function workspaceSectionsFromRecord(?TopicProfile $profile): array
    {
        $state = self::defaultWorkspaceSectionsState();

        if (! $profile instanceof TopicProfile) {
            return $state;
        }

        $profile->loadMissing('sections');

        foreach ($profile->sections as $section) {
            $sectionKey = (string) $section->section_key;

            if (! array_key_exists($sectionKey, $state)) {
                continue;
            }

            $state[$sectionKey] = [
                'title' => filled($section->title) ? (string) $section->title : (string) $state[$sectionKey]['title'],
                'render_variant' => filled($section->render_variant) ? (string) $section->render_variant : (string) $state[$sectionKey]['render_variant'],
                'body_md' => (string) ($section->body_md ?? ''),
                'payload_json_text' => self::encodeJson($section->payload_json),
                'sort_order' => (int) ($section->sort_order ?? $state[$sectionKey]['sort_order']),
                'is_enabled' => (bool) $section->is_enabled,
            ];
        }

        return $state;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public static function workspaceEntriesFromRecord(?TopicProfile $profile): array
    {
        $state = self::defaultWorkspaceEntriesState();

        if (! $profile instanceof TopicProfile) {
            return $state;
        }

        $profile->loadMissing('entries');

        foreach ($profile->entries as $entry) {
            $groupKey = (string) $entry->group_key;

            if (! array_key_exists($groupKey, $state)) {
                continue;
            }

            $state[$groupKey][] = [
                'entry_type' => (string) $entry->entry_type,
                'target_key' => (string) ($entry->target_key ?? ''),
                'target_locale' => (string) ($entry->target_locale ?? ''),
                'title_override' => (string) ($entry->title_override ?? ''),
                'excerpt_override' => (string) ($entry->excerpt_override ?? ''),
                'badge_label' => (string) ($entry->badge_label ?? ''),
                'cta_label' => (string) ($entry->cta_label ?? ''),
                'target_url_override' => (string) ($entry->target_url_override ?? ''),
                'payload_json_text' => self::encodeJson($entry->payload_json),
                'sort_order' => (int) ($entry->sort_order ?? 0),
                'is_featured' => (bool) $entry->is_featured,
                'is_enabled' => (bool) $entry->is_enabled,
            ];
        }

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    public static function workspaceSeoFromRecord(?TopicProfile $profile): array
    {
        $state = self::defaultWorkspaceSeoState();

        if (! $profile instanceof TopicProfile) {
            return $state;
        }

        $profile->loadMissing('seoMeta');
        $seoMeta = $profile->seoMeta;

        if (! $seoMeta instanceof TopicProfileSeoMeta) {
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
            'jsonld_overrides_json_text' => self::encodeJson($seoMeta->jsonld_overrides_json),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function syncWorkspaceSections(TopicProfile $profile, array $state): void
    {
        $defaults = self::defaultWorkspaceSectionsState();

        foreach (self::sectionDefinitions() as $sectionKey => $definition) {
            $sectionState = array_merge(
                $defaults[$sectionKey],
                is_array($state[$sectionKey] ?? null) ? $state[$sectionKey] : [],
            );

            TopicProfileSection::query()->updateOrCreate(
                [
                    'profile_id' => (int) $profile->id,
                    'section_key' => $sectionKey,
                ],
                [
                    'title' => self::normalizeNullableText((string) ($sectionState['title'] ?? '')) ?? $definition['title'],
                    'render_variant' => self::normalizeRenderVariant((string) ($sectionState['render_variant'] ?? $definition['render_variant']), $definition['render_variant']),
                    'body_md' => self::normalizeNullableText((string) ($sectionState['body_md'] ?? '')),
                    'body_html' => null,
                    'payload_json' => self::decodeJsonText(
                        $sectionState['payload_json_text'] ?? null,
                        "workspace_sections.{$sectionKey}.payload_json_text",
                    ),
                    'sort_order' => (int) ($sectionState['sort_order'] ?? $definition['sort_order']),
                    'is_enabled' => StatusBadge::isTruthy($sectionState['is_enabled'] ?? $definition['enabled']),
                ],
            );
        }

        $profile->unsetRelation('sections');
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function syncWorkspaceEntries(TopicProfile $profile, array $state): void
    {
        foreach (self::entryGroupDefinitions() as $groupKey => $definition) {
            TopicProfileEntry::query()
                ->where('profile_id', (int) $profile->id)
                ->where('group_key', $groupKey)
                ->delete();

            $groupItems = is_array($state[$groupKey] ?? null) ? $state[$groupKey] : [];

            foreach (array_values($groupItems) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $entryType = trim((string) ($item['entry_type'] ?? ''));
                if (! in_array($entryType, $definition['allowed_entry_types'], true)) {
                    continue;
                }

                $targetLocale = $entryType === 'custom_link'
                    ? null
                    : self::normalizeNullableLocale((string) ($item['target_locale'] ?? ''), (string) $profile->locale);
                $targetKey = $entryType === 'custom_link'
                    ? self::normalizeNullableText((string) ($item['target_key'] ?? ''))
                    : self::normalizeTargetKey((string) ($item['target_key'] ?? ''), $entryType);
                $targetUrlOverride = $entryType === 'custom_link'
                    ? self::normalizeRelativePath((string) ($item['target_url_override'] ?? ''))
                    : null;
                $titleOverride = self::normalizeNullableText((string) ($item['title_override'] ?? ''));

                if ($entryType === 'custom_link') {
                    if ($targetUrlOverride === null || $titleOverride === null) {
                        continue;
                    }
                } elseif ($targetKey === null) {
                    continue;
                }

                TopicProfileEntry::query()->create([
                    'profile_id' => (int) $profile->id,
                    'entry_type' => $entryType,
                    'group_key' => $groupKey,
                    'target_key' => $targetKey ?? '',
                    'target_locale' => $targetLocale,
                    'title_override' => $titleOverride,
                    'excerpt_override' => self::normalizeNullableText((string) ($item['excerpt_override'] ?? '')),
                    'badge_label' => self::normalizeNullableText((string) ($item['badge_label'] ?? '')),
                    'cta_label' => self::normalizeNullableText((string) ($item['cta_label'] ?? '')),
                    'target_url_override' => $targetUrlOverride,
                    'payload_json' => self::decodeJsonText(
                        $item['payload_json_text'] ?? null,
                        "workspace_entries.{$groupKey}.{$index}.payload_json_text",
                    ),
                    'sort_order' => self::normalizeSortOrder($item['sort_order'] ?? null, ($index + 1) * 10),
                    'is_featured' => (bool) ($definition['force_featured'] || StatusBadge::isTruthy($item['is_featured'] ?? false)),
                    'is_enabled' => StatusBadge::isTruthy($item['is_enabled'] ?? true),
                ]);
            }
        }

        $profile->unsetRelation('entries');
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function syncWorkspaceSeo(TopicProfile $profile, array $state): void
    {
        TopicProfileSeoMeta::query()->updateOrCreate(
            [
                'profile_id' => (int) $profile->id,
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
                'jsonld_overrides_json' => self::decodeJsonText(
                    $state['jsonld_overrides_json_text'] ?? null,
                    'workspace_seo.jsonld_overrides_json_text',
                ),
            ],
        );

        $profile->unsetRelation('seoMeta');
    }

    public static function nextRevisionNo(TopicProfile $profile): int
    {
        return (int) TopicProfileRevision::query()
            ->where('profile_id', (int) $profile->id)
            ->max('revision_no') + 1;
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshotPayload(TopicProfile $profile): array
    {
        $profile->loadMissing('sections', 'entries', 'seoMeta');

        return [
            'profile' => [
                'id' => (int) $profile->id,
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
                'published_at' => $profile->published_at?->toIso8601String(),
                'scheduled_at' => $profile->scheduled_at?->toIso8601String(),
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
                : null,
        ];
    }

    public static function createRevision(TopicProfile $profile, string $note): void
    {
        TopicProfileRevision::query()->create([
            'profile_id' => (int) $profile->id,
            'revision_no' => self::nextRevisionNo($profile),
            'snapshot_json' => self::snapshotPayload($profile),
            'note' => $note,
            'created_by_admin_user_id' => self::currentAdminUserId(),
            'created_at' => now(),
        ]);
    }

    public static function plannedPublicUrl(?string $slug, string $locale): ?string
    {
        $resolvedSlug = trim((string) $slug);
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');

        if ($resolvedSlug === '' || $baseUrl === '') {
            return null;
        }

        $segment = app(TopicProfileSeoService::class)->mapBackendLocaleToFrontendSegment($locale);

        return $baseUrl.'/'.trim($segment, '/').'/topics/'.rawurlencode(Str::lower($resolvedSlug));
    }

    public static function renderEditorialCues(Get $get, ?TopicProfile $record = null): Htmlable
    {
        $status = trim((string) ($get('status') ?? $record?->status ?? TopicProfile::STATUS_DRAFT));
        $isPublic = StatusBadge::isTruthy($get('is_public') ?? $record?->is_public ?? false);
        $isIndexable = StatusBadge::isTruthy($get('is_indexable') ?? $record?->is_indexable ?? true);
        $plannedUrl = self::plannedPublicUrl(
            (string) ($get('slug') ?? $record?->slug ?? ''),
            (string) ($get('locale') ?? $record?->locale ?? 'en'),
        );

        return new HtmlString((string) view('filament.ops.topics.partials.editorial-cues', [
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
                    'label' => 'Entry groups',
                    'value' => self::entrySummary(is_array($get('workspace_entries') ?? null) ? $get('workspace_entries') : [], $record),
                ],
                [
                    'label' => 'Revisions',
                    'value' => $record instanceof TopicProfile ? (string) self::revisionCount($record) : null,
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

    public static function renderSeoSnapshot(Get $get, ?TopicProfile $record = null): Htmlable
    {
        $plannedCanonical = self::plannedPublicUrl(
            (string) ($get('slug') ?? $record?->slug ?? ''),
            (string) ($get('locale') ?? $record?->locale ?? 'en'),
        );

        return new HtmlString((string) view('filament.ops.topics.partials.seo-snapshot', [
            'checks' => self::seoCompleteness([
                'seo_title' => $get('workspace_seo.seo_title') ?? $record?->seoMeta?->seo_title,
                'seo_description' => $get('workspace_seo.seo_description') ?? $record?->seoMeta?->seo_description,
                'og_title' => $get('workspace_seo.og_title') ?? $record?->seoMeta?->og_title,
                'og_description' => $get('workspace_seo.og_description') ?? $record?->seoMeta?->og_description,
                'og_image_url' => $get('workspace_seo.og_image_url') ?? $record?->seoMeta?->og_image_url,
                'twitter_title' => $get('workspace_seo.twitter_title') ?? $record?->seoMeta?->twitter_title,
                'twitter_description' => $get('workspace_seo.twitter_description') ?? $record?->seoMeta?->twitter_description,
                'twitter_image_url' => $get('workspace_seo.twitter_image_url') ?? $record?->seoMeta?->twitter_image_url,
                'robots' => $get('workspace_seo.robots') ?? $record?->seoMeta?->robots,
            ], $plannedCanonical, StatusBadge::isTruthy($get('is_indexable') ?? $record?->is_indexable ?? true)),
            'plannedCanonical' => $plannedCanonical,
        ])->render());
    }

    public static function formatTimestamp(mixed $value, string $fallback = 'Not set yet'): string
    {
        $formatted = self::normalizeTimestamp($value);

        return $formatted ?? $fallback;
    }

    public static function titleMeta(TopicProfile $record): string
    {
        return collect([
            filled($record->topic_code) ? Str::lower((string) $record->topic_code) : null,
            filled($record->slug) ? '/'.trim((string) $record->slug, '/') : null,
            filled($record->locale) ? Str::upper((string) $record->locale) : null,
            isset($record->entries_count) ? ((int) $record->entries_count).' entries' : null,
        ])->filter(static fn (?string $value): bool => filled($value))->implode(' · ');
    }

    public static function visibilityMeta(TopicProfile $record): string
    {
        return implode(' · ', [
            StatusBadge::booleanLabel($record->is_public, 'Public', 'Private'),
            StatusBadge::booleanLabel($record->is_indexable, 'Indexable', 'Noindex'),
        ]);
    }

    public static function normalizeTopicCode(?string $topicCode): string
    {
        return Str::of((string) $topicCode)
            ->lower()
            ->replace('_', '-')
            ->slug('-')
            ->value();
    }

    public static function normalizeSlug(?string $slug, ?string $topicCode = null): string
    {
        $candidate = trim((string) $slug);

        if ($candidate === '' && filled($topicCode)) {
            $candidate = (string) $topicCode;
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

        return in_array($normalized, TopicProfile::SUPPORTED_LOCALES, true) ? $normalized : 'en';
    }

    /**
     * @return array<int, array{label: string, description: string, ready: bool}>
     */
    public static function seoCompleteness(array $state, ?string $plannedCanonical, bool $isIndexable): array
    {
        return [
            [
                'label' => 'SEO title',
                'description' => 'Search headline for the topic hub.',
                'ready' => filled(trim((string) ($state['seo_title'] ?? ''))),
            ],
            [
                'label' => 'SEO description',
                'description' => 'Search summary for the topic hub, with excerpt fallback if left blank.',
                'ready' => filled(trim((string) ($state['seo_description'] ?? ''))),
            ],
            [
                'label' => 'Canonical route',
                'description' => 'Final frontend topic URL stays locale-aware even before frontend cutover.',
                'ready' => filled($plannedCanonical),
            ],
            [
                'label' => 'Social coverage',
                'description' => 'Open Graph and Twitter overrides for richer sharing cards.',
                'ready' => filled(trim((string) ($state['og_title'] ?? '')))
                    && filled(trim((string) ($state['og_description'] ?? '')))
                    && filled(trim((string) ($state['twitter_title'] ?? '')))
                    && filled(trim((string) ($state['twitter_description'] ?? ''))),
            ],
            [
                'label' => 'Robots',
                'description' => $isIndexable ? 'Defaults to index,follow when left blank.' : 'Defaults to noindex,follow when left blank.',
                'ready' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function entrySummary(array $state, ?TopicProfile $record = null): string
    {
        $groups = self::defaultWorkspaceEntriesState();

        foreach ($groups as $groupKey => $_items) {
            $groups[$groupKey] = is_array($state[$groupKey] ?? null)
                ? $state[$groupKey]
                : ($record instanceof TopicProfile
                    ? self::workspaceEntriesFromRecord($record)[$groupKey]
                    : []);
        }

        return collect($groups)
            ->map(function (array $items, string $groupKey): ?string {
                $count = collect($items)
                    ->filter(static fn ($item): bool => is_array($item) && StatusBadge::isTruthy($item['is_enabled'] ?? true))
                    ->count();

                if ($count === 0) {
                    return null;
                }

                $label = self::entryGroupDefinitions()[$groupKey]['label'] ?? $groupKey;

                return $label.': '.$count;
            })
            ->filter()
            ->implode(' · ');
    }

    public static function entryItemLabel(array $state): ?string
    {
        $entryType = trim((string) ($state['entry_type'] ?? ''));
        $target = trim((string) ($state['title_override'] ?? $state['target_key'] ?? $state['target_url_override'] ?? ''));

        if ($target === '') {
            return Str::of($entryType !== '' ? $entryType : 'Entry')
                ->replace('_', ' ')
                ->headline()
                ->value();
        }

        if ($entryType === '') {
            return $target;
        }

        return Str::of($entryType)->replace('_', ' ')->headline()->value().': '.$target;
    }

    /**
     * @return array<string, string>
     */
    public static function entryTypeOptionsForGroup(string $groupKey): array
    {
        $allowed = self::entryGroupDefinitions()[$groupKey]['allowed_entry_types'] ?? TopicProfileEntry::ENTRY_TYPES;

        return collect($allowed)
            ->mapWithKeys(static fn (string $entryType): array => [
                $entryType => Str::of($entryType)->replace('_', ' ')->headline()->value(),
            ])
            ->all();
    }

    private static function revisionCount(TopicProfile $profile): int
    {
        if ($profile->relationLoaded('revisions')) {
            return $profile->revisions->count();
        }

        return (int) $profile->revisions()->count();
    }

    private static function currentAdminUserId(): ?int
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        if (! is_object($user) || ! method_exists($user, 'getAuthIdentifier')) {
            return null;
        }

        return (int) $user->getAuthIdentifier();
    }

    private static function normalizeRenderVariant(string $variant, string $fallback): string
    {
        $normalized = trim($variant);

        return in_array($normalized, TopicProfileSection::RENDER_VARIANTS, true) ? $normalized : $fallback;
    }

    private static function normalizeNullableText(?string $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private static function normalizeNullableLocale(?string $locale, string $fallback): string
    {
        $normalized = self::normalizeNullableText($locale);

        return self::normalizeLocale($normalized ?? $fallback);
    }

    private static function normalizeTargetKey(string $value, string $entryType): ?string
    {
        $normalized = trim($value);

        if ($normalized === '') {
            return null;
        }

        return match ($entryType) {
            'article', 'custom_link' => Str::of($normalized)->lower()->replace('_', '-')->slug('-')->value(),
            'personality_profile' => filled($normalized) && str_contains($normalized, '-') ? Str::of($normalized)->lower()->replace('_', '-')->slug('-')->value() : Str::upper($normalized),
            'scale' => Str::upper($normalized),
            default => $normalized,
        };
    }

    private static function normalizeRelativePath(?string $value): ?string
    {
        $normalized = trim((string) $value);

        if ($normalized === '' || ! str_starts_with($normalized, '/') || str_starts_with($normalized, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $normalized)) {
            return null;
        }

        return $normalized;
    }

    private static function normalizeSortOrder(mixed $value, int $fallback): int
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return max(0, (int) $value);
    }

    private static function encodeJson(mixed $value): string
    {
        if ($value === null || $value === []) {
            return '';
        }

        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '';
    }

    private static function decodeJsonText(mixed $value, string $field): ?array
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages([
                $field => 'This field must contain valid JSON.',
            ]);
        }

        return is_array($decoded) ? $decoded : null;
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
