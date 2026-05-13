<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Models\ContentPage;
use App\Models\InterpretationGuide;
use App\Models\SupportArticle;

final class ContentReleasePathPlanner
{
    /**
     * @return list<string>
     */
    public function paths(string $type, object $record): array
    {
        return $this->dedupe(match ($type) {
            'article' => $record instanceof Article ? $this->articlePaths($record) : [],
            'guide' => $record instanceof CareerGuide ? $this->careerGuidePaths($record) : [],
            'job' => $record instanceof CareerJob ? $this->careerJobPaths($record) : [],
            'support_article' => $record instanceof SupportArticle ? $this->supportArticlePaths($record) : [],
            'interpretation_guide' => $record instanceof InterpretationGuide ? $this->interpretationGuidePaths($record) : [],
            'content_page' => $record instanceof ContentPage ? $this->contentPagePaths($record) : [],
            default => [],
        });
    }

    /**
     * @return list<string>
     */
    private function articlePaths(Article $article): array
    {
        $slug = $this->slug((string) $article->slug);
        $locale = $this->localeSegment((string) $article->locale);
        $metadata = $this->editorialPackageMetadata($article);

        $paths = [
            $this->homePath($locale),
            "/{$locale}/articles",
            $slug !== '' ? "/{$locale}/articles/{$slug}" : null,
            '/llms.txt',
            '/llms-full.txt',
        ];

        foreach ($this->strings(data_get($metadata, 'target_topics', [])) as $topic) {
            $topicSlug = $this->slugFromPathOrValue($topic, 'topics');
            if ($topicSlug !== '') {
                $paths[] = "/{$locale}/topics/{$topicSlug}";
            }
        }

        foreach ($this->strings(data_get($metadata, 'graph_edges.from_article_to_topic', [])) as $topic) {
            $topicSlug = $this->slugFromPathOrValue($topic, 'topics');
            if ($topicSlug !== '') {
                $paths[] = "/{$locale}/topics/{$topicSlug}";
            }
        }

        foreach ($this->strings(data_get($metadata, 'target_tests', [])) as $test) {
            $testSlug = $this->slugFromPathOrValue($test, 'tests');
            if ($testSlug !== '') {
                $paths[] = "/{$locale}/tests/{$testSlug}";
            }
        }

        foreach ($this->strings(data_get($metadata, 'graph_edges.from_article_to_test', [])) as $test) {
            $testSlug = $this->slugFromPathOrValue($test, 'tests');
            if ($testSlug !== '') {
                $paths[] = "/{$locale}/tests/{$testSlug}";
            }
        }

        foreach ($this->strings(data_get($metadata, 'target_personality_pages', [])) as $personality) {
            $personalityPath = $this->personalityPath($personality, $locale);
            if ($personalityPath !== '') {
                $paths[] = $personalityPath;
            }
        }

        foreach ($this->strings(data_get($metadata, 'graph_edges.from_article_to_personality', [])) as $personality) {
            $personalityPath = $this->personalityPath($personality, $locale);
            if ($personalityPath !== '') {
                $paths[] = $personalityPath;
            }
        }

        foreach ($this->strings(data_get($metadata, 'target_career_pages', [])) as $careerPage) {
            $careerGuidePath = $this->careerGuidePath($careerPage, $locale);
            if ($careerGuidePath !== '') {
                $paths[] = $careerGuidePath;
            }
        }

        foreach ($this->strings(data_get($metadata, 'graph_edges.from_article_to_career', [])) as $careerPage) {
            $careerGuidePath = $this->careerGuidePath($careerPage, $locale);
            if ($careerGuidePath !== '') {
                $paths[] = $careerGuidePath;
            }
        }

        foreach ($this->strings(data_get($metadata, 'recommended_reverse_links.career_guide', [])) as $careerPage) {
            $careerGuidePath = $this->careerGuidePath($careerPage, $locale);
            if ($careerGuidePath !== '') {
                $paths[] = $careerGuidePath;
            }
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function careerGuidePaths(CareerGuide $guide): array
    {
        $locale = $this->localeSegment((string) $guide->locale);
        $slug = $this->slug((string) $guide->slug);

        return [
            $this->homePath($locale),
            $slug !== '' ? "/{$locale}/career/guides/{$slug}" : null,
            '/llms.txt',
            '/llms-full.txt',
        ];
    }

    /**
     * @return list<string>
     */
    private function careerJobPaths(CareerJob $job): array
    {
        $locale = $this->localeSegment((string) $job->locale);
        $slug = $this->slug((string) $job->slug);

        return [
            $this->homePath($locale),
            $slug !== '' ? "/{$locale}/career/jobs/{$slug}" : null,
            '/llms.txt',
            '/llms-full.txt',
        ];
    }

    /**
     * @return list<string>
     */
    private function supportArticlePaths(SupportArticle $article): array
    {
        return array_values(array_filter([
            $this->safePath((string) ($article->canonical_path ?: '/support/articles/'.$article->slug)),
            '/support',
        ]));
    }

    /**
     * @return list<string>
     */
    private function interpretationGuidePaths(InterpretationGuide $guide): array
    {
        return array_values(array_filter([
            $this->safePath((string) ($guide->canonical_path ?: '/support/guides/'.$guide->slug)),
            '/support',
        ]));
    }

    /**
     * @return list<string>
     */
    private function contentPagePaths(ContentPage $page): array
    {
        return array_values(array_filter([
            $this->safePath((string) ($page->canonical_path ?: $page->path ?: '/'.$page->slug)),
            (string) data_get($page, 'kind') === ContentPage::KIND_HELP ? '/support' : null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function editorialPackageMetadata(Article $article): array
    {
        $article->loadMissing('seoMeta');
        $seoMeta = $article->seoMeta;
        if (! $seoMeta instanceof ArticleSeoMeta || ! is_array($seoMeta->schema_json)) {
            return [];
        }

        $metadata = data_get($seoMeta->schema_json, 'editorial_package_v1', []);

        return is_array($metadata) ? $metadata : [];
    }

    private function localeSegment(string $locale): string
    {
        return str_starts_with(strtolower(trim($locale)), 'zh') ? 'zh' : 'en';
    }

    private function homePath(string $locale): string
    {
        return $locale === 'zh' ? '/zh' : '/en';
    }

    private function personalityPath(string $value, string $locale): string
    {
        $slug = $this->slugFromPathOrValue($value, 'personality');
        if ($slug === '') {
            return "/{$locale}/personality";
        }

        return "/{$locale}/personality/{$slug}";
    }

    private function careerGuidePath(string $value, string $locale): string
    {
        $slug = $this->slugFromPathOrValue($value, 'guides');
        if ($slug === '') {
            return '';
        }

        return "/{$locale}/career/guides/{$slug}";
    }

    private function slugFromPathOrValue(string $value, string $segment): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $parts = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));
            $segmentIndex = array_search($segment, $parts, true);
            if ($segmentIndex !== false && isset($parts[(int) $segmentIndex + 1])) {
                return $this->slug((string) $parts[(int) $segmentIndex + 1]);
            }
        }

        return $this->slug($value);
    }

    private function safePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }

    private function slug(string $value): string
    {
        $slug = trim($value);
        $slug = trim($slug, '/');

        return preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) === 1 ? $slug : '';
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $normalized = trim($item);
            } elseif (is_array($item)) {
                $normalized = trim((string) ($item['slug'] ?? $item['path'] ?? $item['href'] ?? ''));
            } else {
                $normalized = '';
            }

            if ($normalized !== '') {
                $items[] = $normalized;
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @param  list<string|null>  $paths
     * @return list<string>
     */
    private function dedupe(array $paths): array
    {
        $normalized = [];
        foreach ($paths as $path) {
            if (! is_string($path)) {
                continue;
            }

            $path = trim($path);
            if ($path === '' || ! str_starts_with($path, '/')) {
                continue;
            }

            $normalized[] = $path;
        }

        return array_values(array_unique($normalized));
    }
}
