<?php

namespace App\Services\SEO;

use App\Models\Article;
use App\Models\CareerJob;
use App\Models\PersonalityProfile;
use App\Models\TopicProfile;
use App\Services\Cms\ArticleSeoService;
use App\Services\Cms\CareerJobSeoService;
use App\Services\Cms\PersonalityProfileSeoService;
use App\Services\Cms\TopicProfileSeoService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SitemapGenerator
{
    private string $urlPrefix;

    public function __construct(
        private readonly ArticleSeoService $articleSeoService,
        private readonly CareerJobSeoService $careerJobSeoService,
        private readonly PersonalityProfileSeoService $personalityProfileSeoService,
        private readonly TopicProfileSeoService $topicProfileSeoService,
    ) {
        $configuredPrefix = trim((string) config('services.seo.tests_url_prefix', ''));
        if ($configuredPrefix === '') {
            $configuredPrefix = rtrim((string) config('app.url', 'http://localhost'), '/').'/tests/';
        }

        $this->urlPrefix = rtrim($configuredPrefix, '/').'/';
    }

    public function generate(): array
    {
        $locale = (string) config('app.locale', 'en');

        $urls = array_merge(
            $this->getScaleUrls($locale),
            $this->getArticleUrls(),
            $this->getCareerJobUrls(),
            $this->getPersonalityUrls(),
            $this->getTopicUrls()
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

    private function getArticleUrls(): array
    {
        $rows = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('status', 'published')
            ->where('is_public', true)
            ->where('is_indexable', true)
            ->whereIn('locale', ArticleSeoService::SUPPORTED_LOCALES)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->select(['slug', 'locale', 'updated_at', 'published_at'])
            ->orderBy('locale')
            ->orderBy('slug')
            ->get();

        $urls = [];
        $listLastModified = [];

        foreach ($rows as $row) {
            $slug = trim((string) $row->slug);
            $locale = trim((string) $row->locale);

            if ($slug === '' || $locale === '') {
                continue;
            }

            $lastmod = $row->updated_at
                ?? $row->published_at
                ?? now();

            $segment = $this->articleSeoService->mapBackendLocaleToFrontendSegment($locale);
            $url = $this->articleSeoService->buildCanonicalUrl($slug, $locale);

            if ($url === null) {
                continue;
            }

            $urls[] = [
                'loc' => $url,
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'articles:'.$segment.':'.$slug,
                'updated_at' => $lastmod->toDateTimeString(),
            ];

            if (! isset($listLastModified[$locale]) || $lastmod->gt($listLastModified[$locale])) {
                $listLastModified[$locale] = $lastmod;
            }
        }

        foreach ($listLastModified as $locale => $lastmod) {
            $url = $this->articleSeoService->buildListUrl((string) $locale);
            if ($url === null) {
                continue;
            }

            $urls[] = [
                'loc' => $url,
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'articles-list:'.$this->articleSeoService->mapBackendLocaleToFrontendSegment((string) $locale),
                'updated_at' => $lastmod->toDateTimeString(),
            ];
        }

        return $urls;
    }

    private function getPersonalityUrls(): array
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        if ($baseUrl === '') {
            return [];
        }

        $rows = PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->where('status', 'published')
            ->where('is_public', true)
            ->where('is_indexable', true)
            ->whereIn('locale', PersonalityProfile::SUPPORTED_LOCALES)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->select(['slug', 'locale', 'updated_at', 'published_at'])
            ->orderBy('locale')
            ->orderBy('slug')
            ->get();

        $urls = [];
        $listLastModified = [];

        foreach ($rows as $row) {
            $slug = trim((string) $row->slug);
            $locale = trim((string) $row->locale);

            if ($slug === '' || $locale === '') {
                continue;
            }

            $segment = $this->personalityProfileSeoService->mapBackendLocaleToFrontendSegment($locale);
            $lastmod = $row->updated_at
                ?? $row->published_at
                ?? now();

            $urls[] = [
                'loc' => $baseUrl.'/'.$segment.'/personality/'.rawurlencode($slug),
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'personality:'.$segment.':'.$slug,
                'updated_at' => $lastmod->toDateTimeString(),
            ];

            if (! isset($listLastModified[$segment]) || $lastmod->gt($listLastModified[$segment])) {
                $listLastModified[$segment] = $lastmod;
            }
        }

        foreach ($listLastModified as $segment => $lastmod) {
            $urls[] = [
                'loc' => $baseUrl.'/'.$segment.'/personality',
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'personality-list:'.$segment,
                'updated_at' => $lastmod->toDateTimeString(),
            ];
        }

        return $urls;
    }

    private function getCareerJobUrls(): array
    {
        return array_merge(
            $this->getCareerJobListUrls(),
            $this->getCareerJobDetailUrls()
        );
    }

    private function getCareerJobListUrls(): array
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        if ($baseUrl === '') {
            return [];
        }

        $rows = CareerJob::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('status', CareerJob::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->where('is_indexable', true)
            ->whereIn('locale', CareerJob::SUPPORTED_LOCALES)
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->select(['locale', 'updated_at', 'published_at'])
            ->orderBy('locale')
            ->get();

        $listLastModified = [];

        foreach ($rows as $row) {
            $locale = trim((string) $row->locale);
            if ($locale === '') {
                continue;
            }

            $segment = $this->careerJobSeoService->mapBackendLocaleToFrontendSegment($locale);
            $lastmod = $row->updated_at
                ?? $row->published_at
                ?? now();

            if (! isset($listLastModified[$segment]) || $lastmod->gt($listLastModified[$segment])) {
                $listLastModified[$segment] = $lastmod;
            }
        }

        $urls = [];
        foreach ($listLastModified as $segment => $lastmod) {
            $urls[] = [
                'loc' => $baseUrl.'/'.$segment.'/career/jobs',
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'career-jobs-list:'.$segment,
                'updated_at' => $lastmod->toDateTimeString(),
            ];
        }

        return $urls;
    }

    private function getCareerJobDetailUrls(): array
    {
        $rows = CareerJob::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('status', CareerJob::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->where('is_indexable', true)
            ->whereIn('locale', CareerJob::SUPPORTED_LOCALES)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->select(['slug', 'locale', 'updated_at', 'published_at'])
            ->orderBy('locale')
            ->orderBy('slug')
            ->get();

        $urls = [];

        foreach ($rows as $row) {
            $slug = trim((string) $row->slug);
            $locale = trim((string) $row->locale);

            if ($slug === '' || $locale === '') {
                continue;
            }

            $lastmod = $row->updated_at
                ?? $row->published_at
                ?? now();

            $urls[] = [
                'loc' => $this->careerJobSeoService->buildCanonicalUrl($row, $locale),
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'career-jobs:'.$this->careerJobSeoService->mapBackendLocaleToFrontendSegment($locale).':'.$slug,
                'updated_at' => $lastmod->toDateTimeString(),
            ];
        }

        return array_values(array_filter($urls, static fn (array $row): bool => ! empty($row['loc'])));
    }

    private function getTopicUrls(): array
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        if ($baseUrl === '') {
            return [];
        }

        $rows = TopicProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('status', TopicProfile::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->where('is_indexable', true)
            ->whereIn('locale', TopicProfile::SUPPORTED_LOCALES)
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->select(['slug', 'locale', 'updated_at', 'published_at'])
            ->orderBy('locale')
            ->orderBy('slug')
            ->get();

        $urls = [];
        $listLastModified = [];

        foreach ($rows as $row) {
            $slug = trim((string) $row->slug);
            $locale = trim((string) $row->locale);

            if ($slug === '' || $locale === '') {
                continue;
            }

            $segment = $this->topicProfileSeoService->mapBackendLocaleToFrontendSegment($locale);
            $lastmod = $row->updated_at
                ?? $row->published_at
                ?? now();

            $urls[] = [
                'loc' => $baseUrl.'/'.$segment.'/topics/'.rawurlencode($slug),
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'topics:'.$segment.':'.$slug,
                'updated_at' => $lastmod->toDateTimeString(),
            ];

            if (! isset($listLastModified[$segment]) || $lastmod->gt($listLastModified[$segment])) {
                $listLastModified[$segment] = $lastmod;
            }
        }

        foreach ($listLastModified as $segment => $lastmod) {
            $urls[] = [
                'loc' => $baseUrl.'/'.$segment.'/topics',
                'lastmod' => $lastmod->toAtomString(),
                'slug' => 'topics-list:'.$segment,
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
