<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Models\ArticleTranslationRevision;
use App\Models\CmsTranslationRevision;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

final class OpsEdit
{
    /**
     * @return array<string, string>
     */
    public static function localeOptions(): array
    {
        return [
            'en' => 'en',
            'zh-CN' => 'zh-CN',
        ];
    }

    /**
     * @param  array<int, string>  $statuses
     * @return array<string, string>
     */
    public static function statusOptions(array $statuses): array
    {
        return collect($statuses)
            ->mapWithKeys(fn (string $status): array => [$status => StatusBadge::label($status)])
            ->all();
    }

    public static function statusVisibility(?Model $record): Htmlable
    {
        if (! $record instanceof Model) {
            return self::card(rows: [], pills: [
                ['label' => __('ops.status.draft'), 'state' => 'draft'],
            ]);
        }

        $attributes = $record->getAttributes();
        $hasPublicFlag = array_key_exists('is_public', $attributes);
        $hasIndexableFlag = array_key_exists('is_indexable', $attributes);

        $isPublic = $hasPublicFlag
            ? (bool) $record->getAttribute('is_public')
            : (string) ($record->getAttribute('status') ?? 'draft') === 'published';

        $pills = [
            ['label' => StatusBadge::label((string) ($record->status ?? 'draft')), 'state' => (string) ($record->status ?? 'draft')],
            ['label' => $isPublic ? __('ops.status.public') : __('ops.status.private'), 'state' => $isPublic ? 'public' : 'inactive'],
        ];

        if ($hasIndexableFlag) {
            $isIndexable = (bool) $record->getAttribute('is_indexable');
            $pills[] = ['label' => $isIndexable ? __('ops.status.indexable') : __('ops.status.noindex'), 'state' => $isIndexable ? 'indexable' : 'noindex'];
        }

        return self::card(
            rows: [
                [__('ops.edit.fields.published_at'), self::timestamp($record->published_at ?? null, __('ops.status.not_published'))],
                [__('ops.edit.fields.updated_at'), self::timestamp($record->updated_at ?? null)],
            ],
            pills: $pills,
        );
    }

    /**
     * @param  array<string, string>  $required
     */
    public static function publishReadiness(?Model $record, array $required = []): Htmlable
    {
        if (! $record instanceof Model) {
            return self::card(
                rows: [[__('ops.edit.fields.readiness'), __('ops.edit.readiness.save_first')]],
                pills: [['label' => __('ops.status.pending'), 'state' => 'pending']],
            );
        }

        $blockers = [];

        foreach ($required as $attribute => $label) {
            $recordValue = $record->getAttribute($attribute);
            $revisionValue = $record->workingRevision?->{$attribute} ?? null;

            if (! filled($recordValue) && ! filled($revisionValue)) {
                $blockers[] = __('ops.edit.readiness.missing_field', ['field' => $label]);
            }
        }

        if (method_exists($record, 'isTranslationStale') && $record->isTranslationStale()) {
            $blockers[] = __('ops.edit.readiness.stale_translation');
        }

        if (property_exists($record, 'working_revision_id') || array_key_exists('working_revision_id', $record->getAttributes())) {
            if (! filled($record->getAttribute('working_revision_id'))) {
                $blockers[] = __('ops.edit.readiness.missing_working_revision');
            }
        }

        if ((string) ($record->getAttribute('status') ?? '') === 'published'
            && (array_key_exists('published_revision_id', $record->getAttributes()) && ! filled($record->getAttribute('published_revision_id')))) {
            $blockers[] = __('ops.edit.readiness.missing_published_revision');
        }

        if ((bool) ($record->getAttribute('is_public') ?? false) && ! (bool) ($record->getAttribute('is_indexable') ?? true)) {
            $blockers[] = __('ops.edit.readiness.public_noindex');
        }

        return self::card(
            rows: $blockers === []
                ? [[__('ops.edit.fields.readiness'), __('ops.edit.readiness.ready_description')]]
                : array_map(static fn (string $blocker): array => [__('ops.edit.fields.blocker'), $blocker], $blockers),
            pills: [[
                'label' => $blockers === [] ? __('ops.status.ready') : __('ops.edit.readiness.blocked'),
                'state' => $blockers === [] ? 'ready' : 'warning',
            ]],
            alerts: $blockers,
        );
    }

    public static function translation(?Model $record): Htmlable
    {
        if (! $record instanceof Model) {
            return self::card(rows: []);
        }

        $sourceId = $record->getAttribute('source_content_id') ?? $record->getAttribute('source_article_id');

        return self::card(
            rows: [
                [__('ops.edit.fields.locale'), self::dash($record->getAttribute('locale'))],
                [__('ops.edit.fields.source_locale'), self::dash($record->getAttribute('source_locale'))],
                [__('ops.edit.fields.translation_group'), self::short($record->getAttribute('translation_group_id'))],
                [__('ops.edit.fields.source_record'), self::dash($sourceId)],
                [__('ops.edit.fields.source_hash'), self::short($record->getAttribute('source_version_hash'))],
                [__('ops.edit.fields.translated_from_hash'), self::short($record->getAttribute('translated_from_version_hash'))],
            ],
            pills: [
                [
                    'label' => StatusBadge::label((string) ($record->getAttribute('translation_status') ?? 'source')),
                    'state' => (string) ($record->getAttribute('translation_status') ?? 'source'),
                ],
                [
                    'label' => method_exists($record, 'isTranslationStale') && $record->isTranslationStale()
                        ? __('ops.status.stale')
                        : __('ops.resources.articles.placeholders.current'),
                    'state' => method_exists($record, 'isTranslationStale') && $record->isTranslationStale() ? 'stale' : 'ready',
                ],
            ],
        );
    }

    public static function revision(?Model $record): Htmlable
    {
        if (! $record instanceof Model) {
            return self::card(rows: []);
        }

        $working = $record->workingRevision ?? null;
        $published = $record->publishedRevision ?? null;

        return self::card(
            rows: [
                [__('ops.edit.fields.working_revision'), self::revisionLabel($working, $record->getAttribute('working_revision_id'))],
                [__('ops.edit.fields.published_revision'), self::revisionLabel($published, $record->getAttribute('published_revision_id'))],
                [__('ops.edit.fields.supersedes_revision'), self::dash($working?->supersedes_revision_id ?? null)],
                [__('ops.edit.fields.revision_number'), self::dash($working?->revision_number ?? null)],
                [__('ops.edit.fields.approved_at'), self::timestamp($working?->approved_at ?? null)],
            ],
            pills: [[
                'label' => StatusBadge::label((string) ($working?->revision_status ?? $record->getAttribute('translation_status') ?? 'draft')),
                'state' => (string) ($working?->revision_status ?? $record->getAttribute('translation_status') ?? 'draft'),
            ]],
        );
    }

    public static function seo(?Model $record, string $descriptionAttribute = 'seo_description'): Htmlable
    {
        if (! $record instanceof Model) {
            return self::card(rows: []);
        }

        $seoTitle = $record->getAttribute('seo_title');
        $seoDescription = $record->getAttribute($descriptionAttribute);
        $canonical = $record->getAttribute('canonical_path') ?? $record->getAttribute('canonical_url');

        return self::card(
            rows: [
                [__('ops.edit.fields.seo_title'), filled($seoTitle) ? __('ops.status.ready') : __('ops.status.missing')],
                [__('ops.edit.fields.seo_description'), filled($seoDescription) ? __('ops.status.ready') : __('ops.status.missing')],
                [__('ops.edit.fields.canonical_path'), filled($canonical) ? (string) $canonical : __('ops.status.missing')],
            ],
            pills: [[
                'label' => filled($seoTitle) && filled($seoDescription) ? __('ops.status.ready') : __('ops.status.missing'),
                'state' => filled($seoTitle) && filled($seoDescription) ? 'ready' : 'draft',
            ]],
        );
    }

    public static function audit(?Model $record): Htmlable
    {
        if (! $record instanceof Model) {
            return self::card(rows: []);
        }

        return self::card(rows: [
            [__('ops.edit.fields.created_at'), self::timestamp($record->created_at ?? null)],
            [__('ops.edit.fields.updated_at'), self::timestamp($record->updated_at ?? null)],
            [__('ops.edit.fields.last_reviewed_at'), self::timestamp($record->last_reviewed_at ?? null)],
            [__('ops.edit.fields.published_at'), self::timestamp($record->published_at ?? null, __('ops.status.not_published'))],
        ]);
    }

    /**
     * @param  list<array{0:string,1:mixed}>  $rows
     * @param  list<array{label:string,state:string}>  $pills
     * @param  list<string>  $alerts
     */
    private static function card(array $rows, array $pills = [], array $alerts = []): Htmlable
    {
        return new HtmlString((string) view('filament.ops.components.ops-metadata-rail-card', [
            'rows' => $rows,
            'pills' => $pills,
            'alerts' => $alerts,
        ])->render());
    }

    private static function revisionLabel(mixed $revision, mixed $fallbackId): string
    {
        if ($revision instanceof ArticleTranslationRevision || $revision instanceof CmsTranslationRevision) {
            return sprintf('#%d · %s', (int) $revision->id, StatusBadge::label((string) $revision->revision_status));
        }

        return self::dash($fallbackId);
    }

    private static function timestamp(mixed $value, ?string $fallback = null): string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->timezone(config('app.timezone'))->format('M j, Y H:i');
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->timezone(config('app.timezone'))->format('M j, Y H:i');
            } catch (\Throwable) {
                return $value;
            }
        }

        return $fallback ?? __('ops.resources.articles.placeholders.not_set_yet');
    }

    private static function short(mixed $value): string
    {
        $string = trim((string) $value);

        if ($string === '') {
            return __('ops.resources.articles.placeholders.not_set_yet');
        }

        return Str::length($string) > 18 ? Str::limit($string, 18, '') : $string;
    }

    private static function dash(mixed $value): string
    {
        $string = trim((string) $value);

        return $string === '' ? __('ops.resources.articles.placeholders.not_set_yet') : $string;
    }
}
