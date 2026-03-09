<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\CareerJobResource\Support;

use App\Filament\Ops\Support\StatusBadge;
use App\Models\CareerJob;
use App\Models\CareerJobRevision;
use App\Models\CareerJobSection;
use App\Models\CareerJobSeoMeta;
use App\Services\Cms\CareerJobSeoService;
use Filament\Forms\Get;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CareerJobWorkspace
{
    /**
     * @return array<string, array{label: string, title: string, description: string, render_variant: string, sort_order: int, enabled: bool}>
     */
    public static function sectionDefinitions(): array
    {
        return [
            'day_to_day' => [
                'label' => 'Day to Day',
                'title' => 'A typical day',
                'description' => 'Optional close-up on the rhythm, cadence, and recurring work patterns in this role.',
                'render_variant' => 'rich_text',
                'sort_order' => 10,
                'enabled' => false,
            ],
            'skills_explained' => [
                'label' => 'Skills Explained',
                'title' => 'How the skills show up',
                'description' => 'Optional explanation of how abstract skill labels appear in real work.',
                'render_variant' => 'cards',
                'sort_order' => 20,
                'enabled' => false,
            ],
            'growth_story' => [
                'label' => 'Growth Story',
                'title' => 'Growth story',
                'description' => 'Optional narrative that makes the career progression feel more concrete and human.',
                'render_variant' => 'rich_text',
                'sort_order' => 30,
                'enabled' => false,
            ],
            'work_environment' => [
                'label' => 'Work Environment',
                'title' => 'Work environment',
                'description' => 'Optional guidance about teams, collaboration patterns, pace, and operating context.',
                'render_variant' => 'callout',
                'sort_order' => 40,
                'enabled' => false,
            ],
            'faq' => [
                'label' => 'FAQ',
                'title' => 'Frequently asked questions',
                'description' => 'Optional FAQ block for recurring editorial questions about this career path.',
                'render_variant' => 'faq',
                'sort_order' => 50,
                'enabled' => false,
            ],
            'related_reading_intro' => [
                'label' => 'Related Reading Intro',
                'title' => 'Related reading',
                'description' => 'Optional lead-in copy for future supporting guides or resource links.',
                'render_variant' => 'links',
                'sort_order' => 60,
                'enabled' => false,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function renderVariantOptions(): array
    {
        return collect(CareerJobSection::RENDER_VARIANTS)
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
            'job_code' => '',
            'slug' => '',
            'locale' => 'en',
            'title' => '',
            'subtitle' => '',
            'excerpt' => '',
            'hero_kicker' => '',
            'hero_quote' => '',
            'cover_image_url' => '',
            'industry_slug' => '',
            'industry_label' => '',
            'body_md' => '',
            'status' => CareerJob::STATUS_DRAFT,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => null,
            'scheduled_at' => null,
            'sort_order' => 0,
            'salary_json' => [
                'currency' => '',
                'region' => '',
                'low' => null,
                'median' => null,
                'high' => null,
                'notes' => '',
            ],
            'outlook_json' => [
                'summary' => '',
                'horizon_years' => null,
                'notes' => '',
            ],
            'skills_json' => [
                'core' => [],
                'supporting' => [],
            ],
            'work_contents_json' => [
                'items' => [],
            ],
            'growth_path_json' => [
                'entry' => '',
                'mid' => '',
                'senior' => '',
                'notes' => '',
            ],
            'fit_personality_codes_json' => [],
            'mbti_primary_codes_json' => [],
            'mbti_secondary_codes_json' => [],
            'riasec_profile_json' => [
                'R' => null,
                'I' => null,
                'A' => null,
                'S' => null,
                'E' => null,
                'C' => null,
            ],
            'big5_targets_json' => [
                'openness' => '',
                'conscientiousness' => '',
                'extraversion' => '',
                'agreeableness' => '',
                'neuroticism' => '',
            ],
            'iq_eq_notes_json' => [
                'iq' => '',
                'eq' => '',
            ],
            'market_demand_json' => [
                'signal' => '',
                'notes' => '',
            ],
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
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function workspaceSectionsFromRecord(?CareerJob $job): array
    {
        $state = self::defaultWorkspaceSectionsState();

        if (! $job instanceof CareerJob) {
            return $state;
        }

        $job->loadMissing('sections');

        foreach ($job->sections as $section) {
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
    public static function workspaceSeoFromRecord(?CareerJob $job): array
    {
        $state = self::defaultWorkspaceSeoState();

        if (! $job instanceof CareerJob) {
            return $state;
        }

        $job->loadMissing('seoMeta');
        $seoMeta = $job->seoMeta;

        if (! $seoMeta instanceof CareerJobSeoMeta) {
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
     * @param  array<string, mixed>  $state
     */
    public static function syncWorkspaceSections(CareerJob $job, array $state): void
    {
        $definitions = self::sectionDefinitions();
        $knownSectionKeys = array_keys($definitions);

        CareerJobSection::query()
            ->where('job_id', (int) $job->id)
            ->whereNotIn('section_key', $knownSectionKeys)
            ->delete();

        foreach ($definitions as $sectionKey => $definition) {
            $sectionState = array_merge(
                self::defaultWorkspaceSectionsState()[$sectionKey],
                is_array($state[$sectionKey] ?? null) ? $state[$sectionKey] : [],
            );

            CareerJobSection::query()->updateOrCreate(
                [
                    'job_id' => (int) $job->id,
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

        $job->unsetRelation('sections');
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function syncWorkspaceSeo(CareerJob $job, array $state): void
    {
        CareerJobSeoMeta::query()->updateOrCreate(
            [
                'job_id' => (int) $job->id,
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

        $job->unsetRelation('seoMeta');
    }

    public static function nextRevisionNo(CareerJob $job): int
    {
        return (int) CareerJobRevision::query()
            ->where('job_id', (int) $job->id)
            ->max('revision_no') + 1;
    }

    /**
     * @param  array<string, mixed>  $formData
     * @return array<string, mixed>
     */
    public static function snapshotPayload(CareerJob $job, array $formData = []): array
    {
        $job->loadMissing('sections', 'seoMeta');

        return [
            'job' => [
                'id' => (int) $job->id,
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
                'published_at' => $job->published_at?->toIso8601String(),
                'scheduled_at' => $job->scheduled_at?->toIso8601String(),
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
                ]
                : null,
            'workspace' => [
                'form_data' => $formData,
            ],
        ];
    }

    public static function createRevision(CareerJob $job, string $note, ?object $adminUser = null): void
    {
        CareerJobRevision::query()->create([
            'job_id' => (int) $job->id,
            'revision_no' => self::nextRevisionNo($job),
            'snapshot_json' => self::snapshotPayload($job),
            'note' => $note,
            'created_by_admin_user_id' => self::resolveAdminUserId($adminUser),
            'created_at' => now(),
        ]);
    }

    public static function plannedPublicUrl(CareerJob|string $jobOrSlug, string $locale): ?string
    {
        $slug = $jobOrSlug instanceof CareerJob
            ? (string) $jobOrSlug->slug
            : (string) $jobOrSlug;

        $resolvedSlug = trim($slug);
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');

        if ($resolvedSlug === '' || $baseUrl === '') {
            return null;
        }

        $segment = app(CareerJobSeoService::class)->mapBackendLocaleToFrontendSegment($locale);

        return $baseUrl.'/'.trim($segment, '/').'/career/jobs/'.rawurlencode(Str::lower($resolvedSlug));
    }

    /**
     * @return array<int, array{label: string, description: string, ready: bool}>
     */
    public static function seoCompleteness(CareerJob $job): array
    {
        $seoMeta = self::resolveSeoMeta($job);

        return self::buildSeoCompleteness([
            'seo_title' => $seoMeta?->seo_title,
            'seo_description' => $seoMeta?->seo_description,
            'og_title' => $seoMeta?->og_title,
            'og_description' => $seoMeta?->og_description,
            'twitter_title' => $seoMeta?->twitter_title,
            'twitter_description' => $seoMeta?->twitter_description,
        ], self::plannedPublicUrl($job, (string) $job->locale), (bool) $job->is_indexable);
    }

    public static function renderEditorialCues(Get $get, ?CareerJob $record = null): Htmlable
    {
        $status = trim((string) ($get('status') ?? $record?->status ?? CareerJob::STATUS_DRAFT));
        $isPublic = StatusBadge::isTruthy($get('is_public') ?? $record?->is_public ?? false);
        $isIndexable = StatusBadge::isTruthy($get('is_indexable') ?? $record?->is_indexable ?? true);
        $plannedUrl = self::plannedPublicUrl(
            (string) ($get('slug') ?? $record?->slug ?? ''),
            (string) ($get('locale') ?? $record?->locale ?? 'en'),
        );

        return new HtmlString((string) view('filament.ops.career-jobs.partials.editorial-cues', [
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
                    'value' => $record instanceof CareerJob ? (string) self::revisionCount($record) : null,
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

    public static function renderSeoSnapshot(Get $get, ?CareerJob $record = null): Htmlable
    {
        $plannedCanonical = self::plannedPublicUrl(
            (string) ($get('slug') ?? $record?->slug ?? ''),
            (string) ($get('locale') ?? $record?->locale ?? 'en'),
        );

        return new HtmlString((string) view('filament.ops.career-jobs.partials.seo-snapshot', [
            'checks' => self::buildSeoCompleteness([
                'seo_title' => $get('workspace_seo.seo_title') ?? $record?->seoMeta?->seo_title,
                'seo_description' => $get('workspace_seo.seo_description') ?? $record?->seoMeta?->seo_description,
                'og_title' => $get('workspace_seo.og_title') ?? $record?->seoMeta?->og_title,
                'og_description' => $get('workspace_seo.og_description') ?? $record?->seoMeta?->og_description,
                'twitter_title' => $get('workspace_seo.twitter_title') ?? $record?->seoMeta?->twitter_title,
                'twitter_description' => $get('workspace_seo.twitter_description') ?? $record?->seoMeta?->twitter_description,
            ], $plannedCanonical, StatusBadge::isTruthy($get('is_indexable') ?? $record?->is_indexable ?? true)),
            'plannedCanonical' => $plannedCanonical,
        ])->render());
    }

    public static function formatTimestamp(mixed $value, string $fallback = 'Not set yet'): string
    {
        $formatted = self::normalizeTimestamp($value);

        return $formatted ?? $fallback;
    }

    public static function titleMeta(CareerJob $job): string
    {
        return collect([
            filled($job->job_code) ? Str::lower((string) $job->job_code) : null,
            filled($job->slug) ? '/'.trim((string) $job->slug, '/') : null,
            filled($job->locale) ? Str::upper((string) $job->locale) : null,
        ])->filter(static fn (?string $value): bool => filled($value))->implode(' · ');
    }

    public static function visibilityMeta(CareerJob $job): string
    {
        return implode(' · ', [
            StatusBadge::booleanLabel($job->is_public, 'Public', 'Private'),
            StatusBadge::booleanLabel($job->is_indexable, 'Indexable', 'Noindex'),
        ]);
    }

    public static function normalizeJobCode(?string $jobCode, ?string $slug = null): string
    {
        $candidate = trim((string) $jobCode);

        if ($candidate === '' && filled($slug)) {
            $candidate = (string) $slug;
        }

        return Str::of($candidate)
            ->lower()
            ->replace('_', '-')
            ->slug('-')
            ->value();
    }

    public static function normalizeSlug(?string $slug, ?string $jobCode = null): string
    {
        $candidate = trim((string) $slug);

        if ($candidate === '' && filled($jobCode)) {
            $candidate = (string) $jobCode;
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

        return in_array($normalized, CareerJob::SUPPORTED_LOCALES, true) ? $normalized : 'en';
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<int, array{label: string, description: string, ready: bool}>
     */
    private static function buildSeoCompleteness(array $state, ?string $plannedCanonical, bool $isIndexable): array
    {
        return [
            [
                'label' => 'SEO title',
                'description' => 'Search headline for the career job page.',
                'ready' => filled(trim((string) ($state['seo_title'] ?? ''))),
            ],
            [
                'label' => 'SEO description',
                'description' => 'Search description fallback is excerpt, then subtitle.',
                'ready' => filled(trim((string) ($state['seo_description'] ?? ''))),
            ],
            [
                'label' => 'Canonical route',
                'description' => 'Final frontend route stays locale-aware even before frontend cutover.',
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

    private static function resolveSeoMeta(CareerJob $job): ?CareerJobSeoMeta
    {
        if ($job->relationLoaded('seoMeta') && $job->seoMeta instanceof CareerJobSeoMeta) {
            return $job->seoMeta;
        }

        return CareerJobSeoMeta::query()
            ->where('job_id', (int) $job->id)
            ->first();
    }

    private static function revisionCount(CareerJob $job): int
    {
        return CareerJobRevision::query()
            ->where('job_id', (int) $job->id)
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

    private static function normalizeRenderVariant(string $value, string $fallback): string
    {
        $normalized = trim($value);

        return in_array($normalized, CareerJobSection::RENDER_VARIANTS, true) ? $normalized : $fallback;
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

    private static function encodeJson(mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        try {
            $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable) {
            return '';
        }

        return is_string($encoded) ? $encoded : '';
    }

    private static function decodeJsonText(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        try {
            $decoded = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $field => 'Structured payload must be valid JSON.',
            ]);
        }

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                $field => 'Structured payload must decode to a JSON object or array.',
            ]);
        }

        return $decoded;
    }
}
