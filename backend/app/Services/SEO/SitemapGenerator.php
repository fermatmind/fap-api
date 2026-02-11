<?php

namespace App\Services\SEO;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SitemapGenerator
{
    public function generate(): array
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        $rows = DB::table('scales_registry')
            ->select('primary_slug', 'slugs_json', 'updated_at')
            ->where('is_active', 1)
            ->where('is_public', 1)
            ->where('org_id', 0)
            ->get();

        $slugDates = [];
        $maxUpdatedAt = null;

        foreach ($rows as $row) {
            $updatedAt = $this->parseUpdatedAt($row->updated_at ?? null);
            if ($updatedAt && ($maxUpdatedAt === null || $updatedAt->gt($maxUpdatedAt))) {
                $maxUpdatedAt = $updatedAt;
            }

            $slugs = $this->collectSlugs($row->primary_slug ?? null, $row->slugs_json ?? null);
            foreach ($slugs as $slug) {
                $slug = trim((string) $slug);
                if ($slug === '') {
                    continue;
                }

                if (!array_key_exists($slug, $slugDates)) {
                    $slugDates[$slug] = $updatedAt;
                    continue;
                }

                if ($updatedAt && (!$slugDates[$slug] || $updatedAt->gt($slugDates[$slug]))) {
                    $slugDates[$slug] = $updatedAt;
                }
            }
        }

        $slugList = array_keys($slugDates);
        sort($slugList, SORT_STRING);

        $xml = $this->buildXml($slugList, $slugDates, $baseUrl);

        return [
            'xml' => $xml,
            'slug_list' => $slugList,
            'slug_count' => count($slugList),
            'max_updated_at' => $maxUpdatedAt ? $maxUpdatedAt->toDateTimeString() : '',
        ];
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

        if (!is_string($slugsJson) || trim($slugsJson) === '') {
            return [];
        }

        $decoded = json_decode($slugsJson, true);
        if (!is_array($decoded)) {
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

    private function buildXml(array $slugList, array $slugDates, string $baseUrl): string
    {
        $lines = [];
        $lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($slugList as $slug) {
            $slug = trim((string) $slug);
            if ($slug === '') {
                continue;
            }

            $loc = $baseUrl . '/tests/' . rawurlencode($slug);
            $lastmod = $this->formatLastmod($slugDates[$slug] ?? null);

            $lines[] = '  <url>';
            $lines[] = '    <loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>';
            $lines[] = '    <lastmod>' . $lastmod . '</lastmod>';
            $lines[] = '    <changefreq>weekly</changefreq>';
            $lines[] = '    <priority>0.7</priority>';
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines) . "\n";
    }

    private function formatLastmod(?Carbon $updatedAt): string
    {
        if (!$updatedAt) {
            return '1970-01-01';
        }

        return $updatedAt->toDateString();
    }
}
