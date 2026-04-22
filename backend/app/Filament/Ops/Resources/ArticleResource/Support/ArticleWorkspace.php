<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleResource\Support;

use App\Filament\Ops\Support\StatusBadge;
use App\Models\Article;
use Filament\Forms\Get;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

final class ArticleWorkspace
{
    public static function publicUrl(?string $slug): ?string
    {
        $baseUrl = rtrim((string) config('services.seo.articles_url_prefix', ''), '/');
        $resolvedSlug = trim((string) $slug);

        if ($baseUrl === '' || $resolvedSlug === '') {
            return null;
        }

        return $baseUrl.'/'.rawurlencode($resolvedSlug);
    }

    public static function renderEditorialCues(Get $get, ?Article $record = null): Htmlable
    {
        $status = trim((string) ($get('status') ?? $record?->status ?? 'draft'));
        $isPublic = StatusBadge::isTruthy($get('is_public') ?? $record?->is_public ?? false);
        $isIndexable = StatusBadge::isTruthy($get('is_indexable') ?? $record?->is_indexable ?? true);
        $publicUrl = self::publicUrl((string) ($get('slug') ?? $record?->slug ?? ''));

        return new HtmlString((string) view('filament.ops.articles.partials.editorial-cues', [
            'facts' => array_values(array_filter([
                [
                    'label' => __('ops.resources.articles.fields.published'),
                    'value' => self::formatTimestamp($get('published_at') ?? $record?->published_at),
                ],
                [
                    'label' => __('ops.status.scheduled'),
                    'value' => self::formatTimestamp($get('scheduled_at') ?? $record?->scheduled_at),
                ],
                [
                    'label' => __('ops.resources.articles.fields.public_url'),
                    'value' => $publicUrl,
                    'href' => $publicUrl,
                ],
            ], static fn (array $fact): bool => filled($fact['value'] ?? null))),
            'pills' => [
                [
                    'label' => self::statusLabel($status),
                    'state' => $status,
                ],
                [
                    'label' => $isPublic ? __('ops.status.public') : __('ops.status.private'),
                    'state' => $isPublic ? 'public' : 'inactive',
                ],
                [
                    'label' => $isIndexable ? __('ops.status.indexable') : __('ops.status.noindex'),
                    'state' => $isIndexable ? 'indexable' : 'noindex',
                ],
            ],
        ])->render());
    }

    public static function renderSeoSnapshot(Get $get): Htmlable
    {
        $seoTitle = trim((string) ($get('seo_title') ?? ''));
        $seoDescription = trim((string) ($get('seo_description') ?? ''));
        $canonicalUrl = trim((string) ($get('canonical_url') ?? ''));
        $ogTitle = trim((string) ($get('og_title') ?? ''));
        $ogDescription = trim((string) ($get('og_description') ?? ''));
        $ogImageUrl = trim((string) ($get('og_image_url') ?? ''));

        return new HtmlString((string) view('filament.ops.articles.partials.seo-snapshot', [
            'canonicalUrl' => $canonicalUrl !== '' ? $canonicalUrl : null,
            'checks' => [
                [
                    'label' => __('ops.resources.articles.fields.seo_title'),
                    'description' => __('ops.resources.articles.seo_checks.seo_title'),
                    'ready' => $seoTitle !== '',
                ],
                [
                    'label' => __('ops.resources.articles.fields.seo_description'),
                    'description' => __('ops.resources.articles.seo_checks.seo_description'),
                    'ready' => $seoDescription !== '',
                ],
                [
                    'label' => __('ops.resources.articles.fields.canonical_url'),
                    'description' => __('ops.resources.articles.seo_checks.canonical_url'),
                    'ready' => $canonicalUrl !== '',
                ],
                [
                    'label' => __('ops.resources.articles.fields.open_graph'),
                    'description' => __('ops.resources.articles.seo_checks.open_graph'),
                    'ready' => $ogTitle !== '' && $ogDescription !== '' && $ogImageUrl !== '',
                ],
            ],
        ])->render());
    }

    public static function formatTimestamp(mixed $value, ?string $fallback = null): string
    {
        $formatted = self::normalizeTimestamp($value);

        return $formatted ?? ($fallback ?? __('ops.resources.articles.placeholders.not_set_yet'));
    }

    public static function titleMeta(Article $record): string
    {
        return collect([
            filled($record->slug) ? '/'.trim((string) $record->slug, '/') : null,
            filled($record->locale) ? Str::upper((string) $record->locale) : null,
        ])->filter(static fn (?string $value): bool => filled($value))->implode(' · ');
    }

    public static function visibilityMeta(Article $record): string
    {
        return implode(' · ', [
            StatusBadge::booleanLabel($record->is_public, __('ops.status.public'), __('ops.status.private')),
            StatusBadge::booleanLabel($record->is_indexable, __('ops.status.indexable'), __('ops.status.noindex')),
        ]);
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
            return __('ops.status.draft');
        }

        return StatusBadge::label($normalized);
    }
}
