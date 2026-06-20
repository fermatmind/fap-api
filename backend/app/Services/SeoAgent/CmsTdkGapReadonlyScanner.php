<?php

declare(strict_types=1);

namespace App\Services\SeoAgent;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ContentPage;

final class CmsTdkGapReadonlyScanner
{
    public const SCHEMA_VERSION = 'seo-agent-cms-tdk-gap-readonly-scanner.v1';

    public const TASK = 'SEO-AGENT-CMS-TDK-GAP-READONLY-SCANNER-01';

    private const BLOCKED_ACTIONS = [
        'cms_write',
        'cms_publish',
        'search_channel_enqueue',
        'search_channel_submit',
        'indexing_request',
        'scheduler_activation',
        'queue_worker_activation',
    ];

    /**
     * @return array<string, mixed>
     */
    public function scan(string $surface = 'all', int $limit = 100): array
    {
        $surface = in_array($surface, ['articles', 'content-pages', 'all'], true) ? $surface : 'all';
        $limit = max(1, min($limit, 250));
        $candidates = [];

        if ($surface === 'articles' || $surface === 'all') {
            foreach ($this->articleCandidates($limit) as $candidate) {
                $candidates[] = $candidate;
                if (count($candidates) >= $limit) {
                    break;
                }
            }
        }

        if (($surface === 'content-pages' || $surface === 'all') && count($candidates) < $limit) {
            foreach ($this->contentPageCandidates($limit - count($candidates)) as $candidate) {
                $candidates[] = $candidate;
                if (count($candidates) >= $limit) {
                    break;
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => self::TASK,
            'status' => 'success',
            'source_family' => 'cms_tdk_gap',
            'run_mode' => 'readonly_discovery',
            'surface' => $surface,
            'limit' => $limit,
            'candidate_count' => count($candidates),
            'candidates' => $candidates,
            'candidate_shape' => [
                'required_fields' => [
                    'source_family',
                    'source_id',
                    'subject_type',
                    'subject_ref',
                    'safe_path',
                    'severity',
                    'evidence_refs',
                    'recommended_next_step',
                    'allowed_action',
                    'blocked_actions',
                ],
            ],
            'forbidden_output_fields_absent' => true,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function articleCandidates(int $limit): array
    {
        $rows = Article::query()
            ->withoutGlobalScopes()
            ->with(['seoMeta' => static fn ($query) => $query->withoutGlobalScopes()])
            ->published()
            ->orderBy('id')
            ->limit($limit * 3)
            ->get();

        $candidates = [];
        foreach ($rows as $article) {
            $seoMeta = $article->seoMeta instanceof ArticleSeoMeta ? $article->seoMeta : null;
            $gaps = [];

            if (! $seoMeta instanceof ArticleSeoMeta) {
                $gaps[] = 'missing_indexability_metadata';
                $gaps[] = 'missing_title';
                $gaps[] = 'missing_meta_description';
                $gaps[] = 'missing_canonical';
            } else {
                if (trim((string) $seoMeta->seo_title) === '') {
                    $gaps[] = 'missing_title';
                }
                if (trim((string) $seoMeta->seo_description) === '') {
                    $gaps[] = 'missing_meta_description';
                }
                if (trim((string) $seoMeta->canonical_url) === '') {
                    $gaps[] = 'missing_canonical';
                }
                if ($seoMeta->getAttribute('is_indexable') === null) {
                    $gaps[] = 'missing_indexability_metadata';
                }
            }

            $gaps = array_values(array_unique($gaps));
            if ($gaps === []) {
                continue;
            }

            $candidates[] = $this->candidate(
                subjectType: 'article',
                subjectRef: 'article:'.(int) $article->id.':'.(string) $article->locale,
                safePath: $this->articleSafePath($article),
                locale: (string) $article->locale,
                isIndexable: (bool) $article->is_indexable,
                gapTypes: $gaps,
            );

            if (count($candidates) >= $limit) {
                break;
            }
        }

        return $candidates;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contentPageCandidates(int $limit): array
    {
        $rows = ContentPage::query()
            ->withoutGlobalScopes()
            ->publishedPublic()
            ->orderBy('id')
            ->limit($limit * 3)
            ->get();

        $candidates = [];
        foreach ($rows as $page) {
            $gaps = [];

            if (trim((string) $page->seo_title) === '') {
                $gaps[] = 'missing_title';
            }
            if (trim((string) $page->seo_description) === '' && trim((string) $page->meta_description) === '') {
                $gaps[] = 'missing_meta_description';
            }
            if (trim((string) $page->canonical_path) === '') {
                $gaps[] = 'missing_canonical';
            }
            if ($page->getAttribute('is_indexable') === null) {
                $gaps[] = 'missing_indexability_metadata';
            }

            $gaps = array_values(array_unique($gaps));
            if ($gaps === []) {
                continue;
            }

            $candidates[] = $this->candidate(
                subjectType: 'content_page',
                subjectRef: 'content_page:'.(int) $page->id.':'.(string) $page->locale,
                safePath: $this->contentPageSafePath($page),
                locale: (string) $page->locale,
                isIndexable: (bool) $page->is_indexable,
                gapTypes: $gaps,
            );

            if (count($candidates) >= $limit) {
                break;
            }
        }

        return $candidates;
    }

    /**
     * @param  list<string>  $gapTypes
     * @return array<string, mixed>
     */
    private function candidate(string $subjectType, string $subjectRef, string $safePath, string $locale, bool $isIndexable, array $gapTypes): array
    {
        $sourcePayload = implode('|', [$subjectType, $subjectRef, $safePath, implode(',', $gapTypes)]);

        return [
            'source_family' => 'cms_tdk_gap',
            'source_id' => hash('sha256', $sourcePayload),
            'subject_type' => $subjectType,
            'subject_ref' => $subjectRef,
            'safe_path' => $safePath,
            'locale' => $locale,
            'severity' => $this->severity($gapTypes, $isIndexable),
            'gap_types' => $gapTypes,
            'evidence_refs' => array_map(
                static fn (string $gap): array => [
                    'code' => $gap,
                    'field_status' => 'missing',
                ],
                $gapTypes
            ),
            'recommended_next_step' => 'codex_review_required_before_cms_draft',
            'allowed_action' => 'readonly_review',
            'blocked_actions' => self::BLOCKED_ACTIONS,
        ];
    }

    /**
     * @param  list<string>  $gapTypes
     */
    private function severity(array $gapTypes, bool $isIndexable): string
    {
        if ($isIndexable && (in_array('missing_title', $gapTypes, true) || in_array('missing_canonical', $gapTypes, true))) {
            return 'p1';
        }

        if (in_array('missing_meta_description', $gapTypes, true) || in_array('missing_indexability_metadata', $gapTypes, true)) {
            return 'p2';
        }

        return 'p3';
    }

    private function articleSafePath(Article $article): string
    {
        $slug = $this->safeSlug((string) $article->slug);
        if ($slug === '') {
            $slug = 'article-'.substr(hash('sha256', (string) $article->id), 0, 12);
        }

        return str_starts_with(strtolower((string) $article->locale), 'zh')
            ? '/zh/articles/'.$slug
            : '/articles/'.$slug;
    }

    private function contentPageSafePath(ContentPage $page): string
    {
        $path = trim((string) $page->path);
        if ($path === '') {
            $path = '/'.$this->safeSlug((string) $page->slug);
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            $parsedPath = parse_url($path, PHP_URL_PATH);
            $path = is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : '/content-page-'.substr(hash('sha256', (string) $page->id), 0, 12);
        }

        $path = '/'.ltrim(strtok($path, '?#') ?: $path, '/');

        return preg_replace('#/+#', '/', $path) ?: '/';
    }

    private function safeSlug(string $slug): string
    {
        $slug = trim($slug, "/ \t\n\r\0\x0B");
        $slug = preg_replace('/[^A-Za-z0-9._~%-]+/', '-', $slug) ?: '';

        return trim($slug, '-');
    }

    /**
     * @return array<string, bool>
     */
    private function negativeGuarantees(): array
    {
        return [
            'database_write' => false,
            'cms_write' => false,
            'cms_publish' => false,
            'search_channel_enqueue' => false,
            'search_channel_submit' => false,
            'indexing_request' => false,
            'sitemap_submission' => false,
            'scheduler_activation' => false,
            'queue_worker_started' => false,
            'production_env_change' => false,
            'source_code_mutation' => false,
            'pr_train_metadata_change' => false,
        ];
    }
}
