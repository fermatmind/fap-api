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
                    'label' => 'Published',
                    'value' => self::formatTimestamp($get('published_at') ?? $record?->published_at),
                ],
                [
                    'label' => 'Scheduled',
                    'value' => self::formatTimestamp($get('scheduled_at') ?? $record?->scheduled_at),
                ],
                [
                    'label' => 'Public URL',
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
                    'label' => 'SEO title',
                    'description' => 'Search result headline.',
                    'ready' => $seoTitle !== '',
                ],
                [
                    'label' => 'SEO description',
                    'description' => 'Search snippet summary.',
                    'ready' => $seoDescription !== '',
                ],
                [
                    'label' => 'Canonical URL',
                    'description' => 'Matches the intended public article URL.',
                    'ready' => $canonicalUrl !== '',
                ],
                [
                    'label' => 'Open Graph',
                    'description' => 'Title, description, and social image coverage.',
                    'ready' => $ogTitle !== '' && $ogDescription !== '' && $ogImageUrl !== '',
                ],
            ],
        ])->render());
    }

    public static function formatTimestamp(mixed $value, string $fallback = 'Not set yet'): string
    {
        $formatted = self::normalizeTimestamp($value);

        return $formatted ?? $fallback;
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
            StatusBadge::booleanLabel($record->is_public, 'Public', 'Private'),
            StatusBadge::booleanLabel($record->is_indexable, 'Indexable', 'Noindex'),
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
            return 'Draft';
        }

        return Str::of($normalized)
            ->replace(['_', '-'], ' ')
            ->headline()
            ->value();
    }
}
