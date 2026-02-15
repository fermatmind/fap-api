<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\Scale\ScaleRegistry;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScalesLookupController extends Controller
{
    public function __construct(
        private ScaleRegistry $registry,
        private OrgContext $orgContext,
    ) {}

    /**
     * GET /api/v0.3/scales/lookup?slug=xxx
     */
    public function lookup(Request $request): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $slug = (string) $request->query('slug', '');
        $slug = trim(strtolower($slug));
        if ($slug === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'SLUG_REQUIRED',
                'message' => 'slug is required.',
            ], 400);
        }
        if (! preg_match('/^[a-z0-9-]{0,127}$/', $slug)) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'scale not found.',
            ], 404);
        }

        $row = $this->registry->lookupBySlug($slug, $orgId);
        if (! $row) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'scale not found.',
            ], 404);
        }

        $locale = $this->resolveRequestedLocale($request, (string) ($row['default_locale'] ?? 'en'));
        $seo = $this->resolveSeoByLocale($row, $locale);
        $isIndexable = $this->resolveIsIndexable($row);

        return response()->json([
            'ok' => true,
            'scale_code' => $row['code'] ?? '',
            'primary_slug' => $row['primary_slug'] ?? '',
            'slug' => $row['primary_slug'] ?? '',
            'pack_id' => $row['default_pack_id'] ?? null,
            'dir_version' => $row['default_dir_version'] ?? null,
            'region' => $row['default_region'] ?? null,
            'locale' => $locale,
            'driver_type' => $row['driver_type'] ?? '',
            'view_policy' => $row['view_policy_json'] ?? null,
            'capabilities' => $row['capabilities_json'] ?? null,
            'commercial' => $row['commercial_json'] ?? null,
            'seo_title' => $seo['title'],
            'seo_description' => $seo['description'],
            'og_image_url' => $seo['og_image_url'],
            'is_indexable' => $isIndexable,
            'content_i18n_json' => $row['content_i18n_json'] ?? null,
            'report_summary_i18n_json' => $row['report_summary_i18n_json'] ?? null,
            'seo_schema_json' => $row['seo_schema_json'] ?? null,
            'seo_schema' => $row['seo_schema_json'] ?? null,
        ]);
    }

    /**
     * GET /api/v0.3/scales/sitemap-source?locale=en|zh
     */
    public function sitemapSource(Request $request): JsonResponse
    {
        return app(ScalesSitemapSourceController::class)->index($request);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{title:?string,description:?string,og_image_url:?string}
     */
    private function resolveSeoByLocale(array $row, string $locale): array
    {
        $seoI18n = $this->toArray($row['seo_i18n_json'] ?? null);
        $lang = $this->localeToLanguage($locale);
        $defaultLocale = (string) ($row['default_locale'] ?? 'en');
        $defaultLang = $this->localeToLanguage($defaultLocale);

        $byLang = $this->toArray($seoI18n[$lang] ?? null);
        if ($byLang === [] && $defaultLang !== '') {
            $byLang = $this->toArray($seoI18n[$defaultLang] ?? null);
        }
        if ($byLang === []) {
            $byLang = $this->toArray($seoI18n['en'] ?? null);
        }

        $legacy = $this->toArray($row['seo_schema_json'] ?? null);
        $legacyOg = $this->toArray($legacy['og'] ?? null);

        $title = $this->trimOrNull($byLang['title'] ?? null)
            ?? $this->trimOrNull($legacy['title'] ?? null)
            ?? $this->trimOrNull($legacy['name'] ?? null);
        $description = $this->trimOrNull($byLang['description'] ?? null) ?? $this->trimOrNull($legacy['description'] ?? null);
        $ogImage = $this->trimOrNull($byLang['og_image_url'] ?? null)
            ?? $this->trimOrNull($legacyOg['image'] ?? null)
            ?? $this->trimOrNull($legacy['og_image_url'] ?? null);

        return [
            'title' => $title,
            'description' => $description,
            'og_image_url' => $ogImage,
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolveIsIndexable(array $row): bool
    {
        if (array_key_exists('is_indexable', $row)) {
            return (bool) $row['is_indexable'];
        }

        $policy = $this->toArray($row['view_policy_json'] ?? null);
        if (array_key_exists('indexable', $policy)) {
            return (bool) $policy['indexable'];
        }

        $robots = strtolower(trim((string) ($policy['robots'] ?? '')));
        if ($robots !== '' && str_contains($robots, 'noindex')) {
            return false;
        }

        return true;
    }

    private function resolveRequestedLocale(Request $request, string $defaultLocale): string
    {
        $raw = trim((string) (
            $request->query('locale')
            ?? $request->header('X-FAP-Locale')
            ?? $request->attributes->get('locale')
            ?? ''
        ));

        if ($raw === '') {
            $raw = trim($defaultLocale);
        }
        if ($raw === '') {
            $raw = 'en';
        }

        $normalized = str_replace('_', '-', $raw);
        $parts = array_values(array_filter(explode('-', $normalized), static fn ($p) => $p !== ''));
        if ($parts === []) {
            return 'en';
        }

        $lang = strtolower((string) ($parts[0] ?? 'en'));
        $region = trim((string) ($parts[1] ?? ''));

        if ($lang === 'zh') {
            return 'zh-CN';
        }
        if ($lang === 'en') {
            return $region !== '' ? 'en-' . strtoupper($region) : 'en';
        }

        return $lang;
    }

    private function localeToLanguage(string $locale): string
    {
        $locale = strtolower(trim($locale));
        if ($locale === '') {
            return 'en';
        }

        $parts = explode('-', $locale);
        return strtolower((string) ($parts[0] ?? 'en'));
    }

    /**
     * @return array<string,mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function trimOrNull(mixed $value): ?string
    {
        $trimmed = trim((string) $value);
        return $trimmed !== '' ? $trimmed : null;
    }
}
