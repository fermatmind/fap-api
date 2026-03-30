<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\DataPageResource\Support;

use App\Models\DataPage;
use App\Models\DataPageRevision;
use App\Models\DataPageSeoMeta;

final class DataPageWorkspace
{
    /**
     * @return array<string, mixed>
     */
    public static function defaultFormState(): array
    {
        return [
            'org_id' => 0,
            'schema_version' => 'v1',
            'data_code' => '',
            'slug' => '',
            'locale' => 'en',
            'title' => '',
            'subtitle' => '',
            'excerpt' => '',
            'hero_kicker' => '',
            'body_md' => '',
            'body_html' => '',
            'sample_size_label' => '',
            'time_window_label' => '',
            'methodology_md' => '',
            'limitations_md' => '',
            'summary_statement_md' => '',
            'cover_image_url' => '',
            'status' => DataPage::STATUS_DRAFT,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => null,
            'scheduled_at' => null,
            'sort_order' => 0,
            'workspace_seo' => self::defaultWorkspaceSeoState(),
        ];
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

    public static function normalizeDataCode(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    public static function normalizeSlug(?string $value, ?string $fallbackCode = null): string
    {
        $source = trim((string) ($value ?: $fallbackCode));
        $source = strtolower($source);
        $source = preg_replace('/[^a-z0-9]+/', '-', $source) ?: '';

        return trim($source, '-');
    }

    public static function normalizeLocale(?string $value): string
    {
        return trim((string) $value) === 'zh-CN' ? 'zh-CN' : 'en';
    }

    /**
     * @return array<string, mixed>
     */
    public static function workspaceSeoFromRecord(?DataPage $page): array
    {
        $state = self::defaultWorkspaceSeoState();

        if (! $page instanceof DataPage) {
            return $state;
        }

        $page->loadMissing('seoMeta');
        $seoMeta = $page->seoMeta;

        if (! $seoMeta instanceof DataPageSeoMeta) {
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
    public static function syncWorkspaceSeo(DataPage $page, array $state): void
    {
        DataPageSeoMeta::query()->updateOrCreate(
            ['data_page_id' => (int) $page->id],
            [
                'seo_title' => self::nullableText($state['seo_title'] ?? null),
                'seo_description' => self::nullableText($state['seo_description'] ?? null),
                'canonical_url' => self::nullableText($state['canonical_url'] ?? null),
                'og_title' => self::nullableText($state['og_title'] ?? null),
                'og_description' => self::nullableText($state['og_description'] ?? null),
                'og_image_url' => self::nullableText($state['og_image_url'] ?? null),
                'twitter_title' => self::nullableText($state['twitter_title'] ?? null),
                'twitter_description' => self::nullableText($state['twitter_description'] ?? null),
                'twitter_image_url' => self::nullableText($state['twitter_image_url'] ?? null),
                'robots' => self::nullableText($state['robots'] ?? null),
                'jsonld_overrides_json' => self::decodeJson($state['jsonld_overrides_json_text'] ?? null),
            ]
        );

        $page->unsetRelation('seoMeta');
    }

    public static function createRevision(DataPage $page, ?string $note = null): void
    {
        $nextRevision = ((int) DataPageRevision::query()
            ->where('data_page_id', (int) $page->id)
            ->max('revision_no')) + 1;

        DataPageRevision::query()->create([
            'data_page_id' => (int) $page->id,
            'revision_no' => $nextRevision,
            'snapshot_json' => [
                'page' => $page->fresh()->toArray(),
                'seo_meta' => $page->seoMeta?->toArray(),
            ],
            'note' => self::nullableText($note),
            'created_by_admin_user_id' => self::currentAdminId(),
            'created_at' => now(),
        ]);
    }

    private static function nullableText(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    private static function decodeJson(mixed $value): ?array
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function encodeJson(mixed $value): string
    {
        return is_array($value)
            ? (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '')
            : '';
    }

    private static function currentAdminId(): ?int
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        if (! is_object($user) || ! method_exists($user, 'getAuthIdentifier')) {
            return null;
        }

        return (int) $user->getAuthIdentifier();
    }
}
