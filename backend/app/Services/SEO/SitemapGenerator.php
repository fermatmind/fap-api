<?php

namespace App\Services\SEO;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SitemapGenerator
{
    private string $urlPrefix;

    private string $topicsUrlPrefix;

    public function __construct()
    {
        $configuredPrefix = trim((string) config('services.seo.tests_url_prefix', ''));
        if ($configuredPrefix === '') {
            $configuredPrefix = rtrim((string) config('app.url', 'http://localhost'), '/').'/tests/';
        }

        $this->urlPrefix = rtrim($configuredPrefix, '/').'/';

        $configuredTopicsPrefix = trim((string) config('services.seo.topics_url_prefix', ''));
        if ($configuredTopicsPrefix === '') {
            $configuredTopicsPrefix = rtrim((string) config('app.url', 'http://localhost'), '/').'/topics';
        }

        $this->topicsUrlPrefix = rtrim($configuredTopicsPrefix, '/');
    }

    public function generate(): array
    {
        $locale = (string) config('app.locale', 'en');

        $urls = array_merge(
            $this->getScaleUrls($locale),
            $this->getArticleUrls($locale),
            $this->getTopicUrls($locale)
        );

        $urls = collect($urls)
            ->unique('loc')
            ->values()
            ->all();

        usort($urls, static function (array $a, array $b): int {
            return strcmp((string) ($a['loc'] ?? ''), (string) ($b['loc'] ?? ''));
        });

        $slugList = [];
        $maxUpdatedAt = null;

        foreach ($urls as $row) {
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug !== '') {
                $slugList[] = $slug;
            }

            $updatedValue = (string) ($row['updated_at'] ?? '');
            if ($updatedValue === '') {
                $updatedValue = (string) ($row['lastmod'] ?? '');
            }

            $updatedAt = $this->parseUpdatedAt($updatedValue);
            if ($updatedAt && ($maxUpdatedAt === null || $updatedAt->gt($maxUpdatedAt))) {
                $maxUpdatedAt = $updatedAt;
            }
        }

        $xml = $this->buildXml($urls);

        return [
            'xml' => $xml,
            'slug_list' => $slugList,
            'slug_count' => count($slugList),
            'max_updated_at' => $maxUpdatedAt ? $maxUpdatedAt->toDateTimeString() : '',
        ];
    }

    private function getScaleUrls(string $locale): array
    {
        $rows = DB::table('scales_registry')
            ->select('primary_slug', 'slugs_json', 'view_policy_json', 'updated_at')
            ->where('is_active', 1)
            ->where('is_public', 1)
            ->where('org_id', 0)
            ->get();

        $slugDates = [];

        foreach ($rows as $row) {
            if (! $this->isIndexablePublic($row->view_policy_json ?? null)) {
                continue;
            }

            $updatedAt = $this->parseUpdatedAt($row->updated_at ?? null);

            $slugs = $this->collectSlugs($row->primary_slug ?? null, $row->slugs_json ?? null);
            foreach ($slugs as $slug) {
                $slug = trim((string) $slug);
                if ($slug === '') {
                    continue;
                }

                if (! array_key_exists($slug, $slugDates)) {
                    $slugDates[$slug] = $updatedAt;

                    continue;
                }

                if ($updatedAt && (! $slugDates[$slug] || $updatedAt->gt($slugDates[$slug]))) {
                    $slugDates[$slug] = $updatedAt;
                }
            }
        }

        $slugList = array_keys($slugDates);
        sort($slugList, SORT_STRING);

        $urls = [];
        foreach ($slugList as $slug) {
            $urls[] = [
                'loc' => $this->urlPrefix.rawurlencode($slug),
                'lastmod' => $this->formatLastmod($slugDates[$slug] ?? null),
                'slug' => $slug,
                'updated_at' => ($slugDates[$slug] ?? null)?->toDateTimeString(),
            ];
        }

        return $urls;
    }

    private function getArticleUrls(string $locale): array
    {
        $rows = \App\Models\Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('status', 'published')
            ->where('is_public', true)
            ->where('is_indexable', true)
            ->select(['slug', 'updated_at', 'published_at'])
            ->orderByDesc('updated_at')
            ->get();

        $prefix = rtrim((string) config('services.seo.articles_url_prefix'), '/');

        $urls = [];

        foreach ($rows as $row) {
            $url = $prefix.'/'.rawurlencode($row->slug);

            $lastmod = $row->updated_at
                ?? $row->published_at
                ?? now();

            $urls[] = [
                'loc' => $url,
                'lastmod' => $lastmod->toAtomString(),
                'slug' => (string) $row->slug,
                'updated_at' => $lastmod->toDateTimeString(),
            ];
        }

        return $urls;
    }

    private function getTopicUrls(string $locale): array
    {
        $rows = \App\Models\Topic::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->select(['slug', 'updated_at', 'created_at'])
            ->orderByDesc('updated_at')
            ->get();

        $urls = [];

        foreach ($rows as $row) {
            $slug = trim((string) $row->slug);
            if ($slug === '') {
                continue;
            }

            $lastmod = $row->updated_at
                ?? $row->created_at
                ?? now();

            $urls[] = [
                'loc' => $this->topicsUrlPrefix.'/'.rawurlencode($slug),
                'lastmod' => $lastmod->toAtomString(),
                'slug' => $slug,
                'updated_at' => $lastmod->toDateTimeString(),
            ];
        }

        return $urls;
    }

    private function collectSlugs($primarySlug, $slugsJson): array
    {
        $slugs = [];

        if ($primarySlug !== null) {
            $slugs[] = (string) $primarySlug;
        }

        foreach ($this->decodeSlugsJson($slugsJson) as $slug) {
            $slugs[] = $slug;
        }

        return $slugs;
    }

    private function decodeSlugsJson($slugsJson): array
    {
        if (is_array($slugsJson)) {
            return $slugsJson;
        }

        if (! is_string($slugsJson) || trim($slugsJson) === '') {
            return [];
        }

        $decoded = json_decode($slugsJson, true);
        if (! is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function parseUpdatedAt($updatedAt): ?Carbon
    {
        if ($updatedAt === null || $updatedAt === '') {
            return null;
        }

        try {
            return Carbon::parse($updatedAt);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function isIndexablePublic(mixed $viewPolicyJson): bool
    {
        $policy = [];
        if (is_array($viewPolicyJson)) {
            $policy = $viewPolicyJson;
        } elseif (is_string($viewPolicyJson) && trim($viewPolicyJson) !== '') {
            $decoded = json_decode($viewPolicyJson, true);
            if (is_array($decoded)) {
                $policy = $decoded;
            }
        }

        $isPublic = $policy['public'] ?? $policy['is_public'] ?? $policy['visibility'] ?? null;
        if (is_string($isPublic)) {
            $normalizedVisibility = strtolower(trim($isPublic));
            if (in_array($normalizedVisibility, ['private', 'internal', 'hidden'], true)) {
                return false;
            }
        } elseif (is_bool($isPublic) && $isPublic === false) {
            return false;
        }

        $indexable = $policy['indexable'] ?? null;
        if (is_bool($indexable) && $indexable === false) {
            return false;
        }

        $robots = $policy['robots'] ?? null;
        if (is_string($robots) && str_contains(strtolower($robots), 'noindex')) {
            return false;
        }

        return true;
    }

    private function buildXml(array $urls): string
    {
        $lines = [];
        $lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($urls as $row) {
            $loc = trim((string) ($row['loc'] ?? ''));
            if ($loc === '') {
                continue;
            }

            $lastmod = trim((string) ($row['lastmod'] ?? ''));
            if ($lastmod === '') {
                $lastmod = '1970-01-01';
            }

            $lines[] = '  <url>';
            $lines[] = '    <loc>'.htmlspecialchars($loc, ENT_XML1).'</loc>';
            $lines[] = '    <lastmod>'.htmlspecialchars($lastmod, ENT_XML1).'</lastmod>';
            $lines[] = '    <changefreq>weekly</changefreq>';
            $lines[] = '    <priority>0.7</priority>';
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines)."\n";
    }

    private function formatLastmod(?Carbon $updatedAt): string
    {
        if (! $updatedAt) {
            return '1970-01-01';
        }

        return $updatedAt->toDateString();
    }
}
