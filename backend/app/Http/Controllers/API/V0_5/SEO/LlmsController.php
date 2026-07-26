<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\SEO;

use App\Http\Controllers\Controller;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Http\Response;

class LlmsController extends Controller
{
    private const LLMS_TXT_CACHE_KEY = 'seo:llms-txt:v1:body';
    private const LLMS_FULL_TXT_CACHE_KEY = 'seo:llms-full-txt:v1:body';
    private const CACHE_TTL_SECONDS = 600;

    public function llmsTxt(SitemapGenerator $generator): Response
    {
        return $this->cachedTextResponse(
            self::LLMS_TXT_CACHE_KEY,
            fn () => $this->buildLlmsTxt($generator)
        );
    }

    public function llmsFullTxt(SitemapGenerator $generator): Response
    {
        return $this->cachedTextResponse(
            self::LLMS_FULL_TXT_CACHE_KEY,
            fn () => $this->buildLlmsFullTxt($generator)
        );
    }

    private function cachedTextResponse(string $cacheKey, callable $builder): Response
    {
        $body = cache()->get($cacheKey);
        if (! is_string($body)) {
            $body = $builder();
            cache()->put($cacheKey, $body, self::CACHE_TTL_SECONDS);
        }

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=300, s-maxage=600',
        ]);
    }

    private function buildLlmsTxt(SitemapGenerator $generator): string
    {
        $urls = $generator->generateUrls();
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $siteName = 'FermatMind';

        $primary = $this->primaryPaths();
        $personality = $this->personalityPaths($urls, $baseUrl);
        $topics = $this->topicPaths($urls, $baseUrl);
        $career = $this->careerJobPaths($urls, $baseUrl);
        $help = $this->helpPagePaths($urls, $baseUrl);

        $lines = [
            "# {$siteName} llms.txt",
            "Site: {$baseUrl}",
            "Languages: en, zh",
            '',
            'Primary Entries:',
            ...array_map(fn (string $url): string => "- {$url}", $primary),
            '',
            'Indexable Personality:',
            ...array_map(fn (string $url): string => "- {$url}", $personality),
            '',
            'Indexable Topics:',
            ...array_map(fn (string $url): string => "- {$url}", $topics),
            '',
            'Indexable Career:',
            ...array_map(fn (string $url): string => "- {$url}", $career),
            '',
            'Indexable Help:',
            ...array_map(fn (string $url): string => "- {$url}", $help),
            '',
        ];

        return implode("\n", $lines);
    }

    private function buildLlmsFullTxt(SitemapGenerator $generator): string
    {
        $urls = $generator->generateUrls();
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $siteName = 'FermatMind';

        $primary = $this->primaryPaths();
        $personality = $this->personalityPaths($urls, $baseUrl);
        $topics = $this->topicPaths($urls, $baseUrl);
        $career = $this->careerJobPaths($urls, $baseUrl);
        $help = $this->helpPagePaths($urls, $baseUrl);

        $count = count($primary) + count($personality) + count($topics) + count($career) + count($help);

        $lines = [
            "# {$siteName} llms-full.txt",
            "Site: {$baseUrl}",
            "Languages: en, zh",
            "Total indexable entries: {$count}",
            '',
            '## Primary',
            ...array_map(fn (string $url): string => "- {$url}", $primary),
            '',
            '## Personality (Big Five, MBTI, Enneagram)',
            ...array_map(fn (string $url): string => "- {$url}", $personality),
            '',
            '## Topics',
            ...array_map(fn (string $url): string => "- {$url}", $topics),
            '',
            '## Career Jobs',
            ...array_map(fn (string $url): string => "- {$url}", $career),
            '',
            '## Help & Content Pages',
            ...array_map(fn (string $url): string => "- {$url}", $help),
            '',
        ];

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function primaryPaths(): array
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');

        return [
            "{$baseUrl}/",
            "{$baseUrl}/en",
            "{$baseUrl}/zh",
            "{$baseUrl}/en/personality",
            "{$baseUrl}/zh/personality",
            "{$baseUrl}/en/topics",
            "{$baseUrl}/zh/topics",
            "{$baseUrl}/en/career",
            "{$baseUrl}/zh/career",
            "{$baseUrl}/en/career/guides",
            "{$baseUrl}/zh/career/guides",
        ];
    }

    /**
     * @param  list<array{loc: string, slug?: string}>  $urls
     * @return list<string>
     */
    private function personalityPaths(array $urls, string $baseUrl): array
    {
        return $this->filterAndSort($urls, static function (string $path): bool {
            if (preg_match('#^/(?:en|zh)/personality/[a-z]{4}$#', $path) === 1) {
                return false;
            }

            return str_starts_with($path, '/en/personality/')
                || str_starts_with($path, '/zh/personality/')
                || $path === '/en/personality'
                || $path === '/zh/personality';
        });
    }

    /**
     * @param  list<array{loc: string, slug?: string}>  $urls
     * @return list<string>
     */
    private function topicPaths(array $urls, string $baseUrl): array
    {
        return $this->filterAndSort($urls, static function (string $path): bool {
            return str_starts_with($path, '/en/topics/')
                || str_starts_with($path, '/zh/topics/');
        });
    }

    /**
     * @param  list<array{loc: string, slug?: string}>  $urls
     * @return list<string>
     */
    private function careerJobPaths(array $urls, string $baseUrl): array
    {
        return $this->filterAndSort($urls, static function (string $path): bool {
            return str_starts_with($path, '/en/career/jobs/')
                || str_starts_with($path, '/zh/career/jobs/');
        });
    }

    /**
     * @param  list<array{loc: string, slug?: string}>  $urls
     * @return list<string>
     */
    private function helpPagePaths(array $urls, string $baseUrl): array
    {
        return $this->filterAndSort($urls, static function (string $path): bool {
            $isHelp = str_starts_with($path, '/en/help/') || str_starts_with($path, '/zh/help/');
            $isStatic = in_array($path, [
                '/en/method-boundaries', '/zh/method-boundaries',
                '/en/reliability-validity', '/zh/reliability-validity',
                '/en/privacy', '/zh/privacy',
            ], true);

            return $isHelp || $isStatic;
        });
    }

    /**
     * @param  list<array{loc: string, slug?: string}>  $urls
     * @param  callable(string):bool  $keep
     * @return list<string>
     */
    private function filterAndSort(array $urls, callable $keep): array
    {
        $baseUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '');
        $prefixLength = strlen($baseUrl);

        $paths = [];
        foreach ($urls as $entry) {
            $loc = (string) ($entry['loc'] ?? '');
            if ($loc === '' || ! str_starts_with($loc, $baseUrl)) {
                continue;
            }
            $path = substr($loc, $prefixLength);
            if ($path === '') {
                $path = '/';
            }
            if ($keep($path)) {
                $paths[$path] = $loc;
            }
        }

        ksort($paths, SORT_STRING);

        return array_values($paths);
    }
}
