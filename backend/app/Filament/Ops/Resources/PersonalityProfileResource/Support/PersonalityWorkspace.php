<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\PersonalityProfileResource\Support;

use App\Filament\Ops\Support\StatusBadge;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileSeoMeta;
use App\Services\Cms\PersonalityProfileSeoService;
use Filament\Forms\Get;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PersonalityWorkspace
{
    /**
     * @return array<string, array{label: string, title: string, description: string, render_variant: string, sort_order: int, enabled: bool}>
     */
    public static function sectionDefinitions(): array
    {
        return [
            'core_snapshot' => [
                'label' => 'Core Snapshot',
                'title' => 'Core snapshot',
                'description' => 'Foundational summary of the personality type and how it tends to orient to the world.',
                'render_variant' => 'rich_text',
                'sort_order' => 10,
                'enabled' => true,
            ],
            'strengths' => [
                'label' => 'Strengths',
                'title' => 'Strengths',
                'description' => 'Clear strengths editors want to highlight on the public profile.',
                'render_variant' => 'bullets',
                'sort_order' => 20,
                'enabled' => true,
            ],
            'growth_edges' => [
                'label' => 'Growth Edges',
                'title' => 'Growth edges',
                'description' => 'Blind spots, trade-offs, and development cues written in a constructive tone.',
                'render_variant' => 'bullets',
                'sort_order' => 30,
                'enabled' => true,
            ],
            'work_style' => [
                'label' => 'Work Style',
                'title' => 'Work style',
                'description' => 'How this profile tends to work, plan, execute, and collaborate.',
                'render_variant' => 'rich_text',
                'sort_order' => 40,
                'enabled' => true,
            ],
            'relationships' => [
                'label' => 'Relationships',
                'title' => 'Relationships',
                'description' => 'How this personality profile tends to show up in close partnerships and teamwork.',
                'render_variant' => 'rich_text',
                'sort_order' => 50,
                'enabled' => true,
            ],
            'communication' => [
                'label' => 'Communication',
                'title' => 'Communication',
                'description' => 'What communication style works best and what commonly creates friction.',
                'render_variant' => 'rich_text',
                'sort_order' => 60,
                'enabled' => true,
            ],
            'stress_and_recovery' => [
                'label' => 'Stress & Recovery',
                'title' => 'Stress and recovery',
                'description' => 'What overload tends to look like and what helps the profile reset.',
                'render_variant' => 'cards',
                'sort_order' => 70,
                'enabled' => true,
            ],
            'career_fit' => [
                'label' => 'Career Fit',
                'title' => 'Career fit',
                'description' => 'Career and environment patterns that usually fit this profile best.',
                'render_variant' => 'cards',
                'sort_order' => 80,
                'enabled' => true,
            ],
            'faq' => [
                'label' => 'FAQ',
                'title' => 'Frequently asked questions',
                'description' => 'Optional FAQ block for recurring editor-curated questions.',
                'render_variant' => 'faq',
                'sort_order' => 90,
                'enabled' => false,
            ],
            'related_content' => [
                'label' => 'Related Content',
                'title' => 'Related content',
                'description' => 'Optional structured links or references that complement the profile.',
                'render_variant' => 'links',
                'sort_order' => 100,
                'enabled' => false,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function renderVariantOptions(): array
    {
        return collect(PersonalityProfileSection::RENDER_VARIANTS)
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
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => '',
            'slug' => '',
            'locale' => 'en',
            'title' => '',
            'subtitle' => '',
            'excerpt' => '',
            'hero_kicker' => '',
            'hero_quote' => '',
            'hero_image_url' => '',
            'status' => 'draft',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => null,
            'scheduled_at' => null,
            'workspace_sections' => self::defaultWorkspaceSectionsState(),
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
    public static function workspaceSectionsFromRecord(?PersonalityProfile $profile): array
    {
        $state = self::defaultWorkspaceSectionsState();

        if (! $profile instanceof PersonalityProfile) {
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
     * @return array<string, mixed>
     */
    public static function workspaceSeoFromRecord(?PersonalityProfile $profile): array
    {
        $state = self::defaultWorkspaceSeoState();

        if (! $profile instanceof PersonalityProfile) {
            return $state;
        }

        $profile->loadMissing('seoMeta');
        $seoMeta = $profile->seoMeta;

        if (! $seoMeta instanceof PersonalityProfileSeoMeta) {
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
    public static function syncWorkspaceSections(PersonalityProfile $profile, array $state): void
    {
        $definitions = self::sectionDefinitions();
        $knownSectionKeys = array_keys($definitions);

        PersonalityProfileSection::query()
            ->where('profile_id', (int) $profile->id)
            ->whereNotIn('section_key', $knownSectionKeys)
            ->delete();

        foreach ($definitions as $sectionKey => $definition) {
            $sectionState = array_merge(
                self::defaultWorkspaceSectionsState()[$sectionKey],
                is_array($state[$sectionKey] ?? null) ? $state[$sectionKey] : [],
            );

            PersonalityProfileSection::query()->updateOrCreate(
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
    public static function syncWorkspaceSeo(PersonalityProfile $profile, array $state): void
    {
        PersonalityProfileSeoMeta::query()->updateOrCreate(
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

    public static function nextRevisionNo(PersonalityProfile $profile): int
    {
        return (int) PersonalityProfileRevision::query()
            ->where('profile_id', (int) $profile->id)
            ->max('revision_no') + 1;
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshotPayload(PersonalityProfile $profile): array
    {
        $profile->loadMissing('sections', 'seoMeta');

        return [
            'profile' => [
                'id' => (int) $profile->id,
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
                'published_at' => $profile->published_at?->toIso8601String(),
                'scheduled_at' => $profile->scheduled_at?->toIso8601String(),
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
                : null,
        ];
    }

    public static function createRevision(PersonalityProfile $profile, string $note): void
    {
        PersonalityProfileRevision::query()->create([
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

        $segment = app(PersonalityProfileSeoService::class)->mapBackendLocaleToFrontendSegment($locale);

        return $baseUrl.'/'.trim($segment, '/').'/personality/'.rawurlencode(Str::lower($resolvedSlug));
    }

    public static function renderEditorialCues(Get $get, ?PersonalityProfile $record = null): Htmlable
    {
        $status = trim((string) ($get('status') ?? $record?->status ?? 'draft'));
        $isPublic = StatusBadge::isTruthy($get('is_public') ?? $record?->is_public ?? false);
        $isIndexable = StatusBadge::isTruthy($get('is_indexable') ?? $record?->is_indexable ?? true);
        $plannedUrl = self::plannedPublicUrl(
            (string) ($get('slug') ?? $record?->slug ?? ''),
            (string) ($get('locale') ?? $record?->locale ?? 'en'),
        );

        return new HtmlString((string) view('filament.ops.personality.partials.editorial-cues', [
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
                    'label' => 'Revisions',
                    'value' => $record instanceof PersonalityProfile ? (string) self::revisionCount($record) : null,
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

    public static function renderSeoSnapshot(Get $get, ?PersonalityProfile $record = null): Htmlable
    {
        $plannedCanonical = self::plannedPublicUrl(
            (string) ($get('slug') ?? $record?->slug ?? ''),
            (string) ($get('locale') ?? $record?->locale ?? 'en'),
        );

        return new HtmlString((string) view('filament.ops.personality.partials.seo-snapshot', [
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

    public static function titleMeta(PersonalityProfile $record): string
    {
        return collect([
            filled($record->type_code) ? Str::upper((string) $record->type_code) : null,
            filled($record->slug) ? '/'.trim((string) $record->slug, '/') : null,
            filled($record->locale) ? Str::upper((string) $record->locale) : null,
        ])->filter(static fn (?string $value): bool => filled($value))->implode(' · ');
    }

    public static function visibilityMeta(PersonalityProfile $record): string
    {
        return implode(' · ', [
            StatusBadge::booleanLabel($record->is_public, 'Public', 'Private'),
            StatusBadge::booleanLabel($record->is_indexable, 'Indexable', 'Noindex'),
        ]);
    }

    public static function normalizeTypeCode(?string $typeCode): string
    {
        $normalized = Str::upper(trim((string) $typeCode));

        return in_array($normalized, PersonalityProfile::TYPE_CODES, true) ? $normalized : $normalized;
    }

    public static function normalizeSlug(?string $slug, ?string $typeCode = null): string
    {
        $candidate = trim((string) $slug);

        if ($candidate === '' && filled($typeCode)) {
            $candidate = (string) $typeCode;
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

        return in_array($normalized, PersonalityProfile::SUPPORTED_LOCALES, true) ? $normalized : 'en';
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<int, array{label: string, description: string, ready: bool}>
     */
    public static function seoCompleteness(array $state, ?string $plannedCanonical, bool $isIndexable): array
    {
        return [
            [
                'label' => 'SEO title',
                'description' => 'Search headline for the profile detail page.',
                'ready' => filled(trim((string) ($state['seo_title'] ?? ''))),
            ],
            [
                'label' => 'SEO description',
                'description' => 'Search snippet summary, with excerpt fallback if left blank.',
                'ready' => filled(trim((string) ($state['seo_description'] ?? ''))),
            ],
            [
                'label' => 'Canonical route',
                'description' => 'Final frontend URL stays locale-aware even before frontend cutover.',
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

    private static function revisionCount(PersonalityProfile $profile): int
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

        return in_array($normalized, PersonalityProfileSection::RENDER_VARIANTS, true) ? $normalized : $fallback;
    }

    private static function normalizeNullableText(string $value): ?string
    {
        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
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
        } catch (\JsonException $exception) {
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
