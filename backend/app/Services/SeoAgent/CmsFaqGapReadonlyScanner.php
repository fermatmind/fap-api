<?php

declare(strict_types=1);

namespace App\Services\SeoAgent;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ContentPage;

final class CmsFaqGapReadonlyScanner
{
    public const SCHEMA_VERSION = 'seo-agent-cms-faq-gap-readonly-scanner.v1';

    public const TASK = 'SEO-AGENT-CMS-FAQ-GAP-READONLY-SCANNER-01';

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
            'source_family' => 'cms_faq_gap',
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
                    'gap_types',
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
            ->limit($limit * 4)
            ->get();

        $candidates = [];
        foreach ($rows as $article) {
            $seoMeta = $article->seoMeta instanceof ArticleSeoMeta ? $article->seoMeta : null;
            $schema = $seoMeta instanceof ArticleSeoMeta && is_array($seoMeta->schema_json) ? $seoMeta->schema_json : [];
            $answerSurface = data_get($schema, 'editorial_package_v1.answer_surface_v1');
            $hasAnswerSurfaceSignal = is_array($answerSurface);
            $hasFaqSchemaSignal = $this->containsFaqPage($schema);

            if (! $hasAnswerSurfaceSignal && ! $hasFaqSchemaSignal) {
                continue;
            }

            $faqItems = is_array(data_get($schema, 'editorial_package_v1.answer_surface_v1.faq_items'))
                ? (array) data_get($schema, 'editorial_package_v1.answer_surface_v1.faq_items')
                : [];
            $hasVisibleFaq = $this->hasValidFaqItems($faqItems);
            if ($hasVisibleFaq) {
                continue;
            }

            $gaps = ['missing_faq_items'];
            if ($hasFaqSchemaSignal) {
                $gaps[] = 'faq_schema_enabled_without_visible_faq';
            }

            $candidates[] = $this->candidate(
                subjectType: 'article',
                subjectRef: 'article:'.(int) $article->id.':'.(string) $article->locale,
                safePath: $this->articleSafePath($article),
                locale: (string) $article->locale,
                gapTypes: array_values(array_unique($gaps)),
                evidenceRefs: [
                    [
                        'code' => 'article_faq_signal_present',
                        'field_status' => 'present',
                    ],
                    [
                        'code' => 'visible_faq_items',
                        'field_status' => 'missing_or_incomplete',
                    ],
                ],
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
            ->limit($limit * 4)
            ->get();

        $candidates = [];
        foreach ($rows as $page) {
            if (! $this->contentPageHasFaqSignal($page)) {
                continue;
            }

            $hasVisibleFaq = $this->hasValidFaqItems(is_array($page->faq_items) ? $page->faq_items : []);
            if ($hasVisibleFaq) {
                continue;
            }

            $gaps = ['missing_faq_items'];
            if ((bool) $page->schema_enabled) {
                $gaps[] = 'faq_schema_enabled_without_visible_faq';
            }

            $candidates[] = $this->candidate(
                subjectType: 'content_page',
                subjectRef: 'content_page:'.(int) $page->id.':'.(string) $page->locale,
                safePath: $this->contentPageSafePath($page),
                locale: (string) $page->locale,
                gapTypes: array_values(array_unique($gaps)),
                evidenceRefs: [
                    [
                        'code' => 'content_page_faq_signal_present',
                        'field_status' => 'present',
                    ],
                    [
                        'code' => 'faq_items',
                        'field_status' => 'missing_or_incomplete',
                    ],
                ],
            );

            if (count($candidates) >= $limit) {
                break;
            }
        }

        return $candidates;
    }

    /**
     * @param  list<string>  $gapTypes
     * @param  list<array<string, mixed>>  $evidenceRefs
     * @return array<string, mixed>
     */
    private function candidate(string $subjectType, string $subjectRef, string $safePath, string $locale, array $gapTypes, array $evidenceRefs): array
    {
        $sourcePayload = implode('|', [$subjectType, $subjectRef, $safePath, implode(',', $gapTypes)]);

        return [
            'source_family' => 'cms_faq_gap',
            'source_id' => hash('sha256', $sourcePayload),
            'subject_type' => $subjectType,
            'subject_ref' => $subjectRef,
            'safe_path' => $safePath,
            'locale' => $locale,
            'severity' => $this->severity($gapTypes),
            'gap_types' => $gapTypes,
            'evidence_refs' => $evidenceRefs,
            'recommended_next_step' => 'codex_review_required_before_cms_draft',
            'allowed_action' => 'readonly_review',
            'blocked_actions' => self::BLOCKED_ACTIONS,
        ];
    }

    /**
     * @param  list<string>  $gapTypes
     */
    private function severity(array $gapTypes): string
    {
        if (in_array('faq_schema_enabled_without_visible_faq', $gapTypes, true)) {
            return 'p1';
        }

        if (in_array('missing_faq_items', $gapTypes, true)) {
            return 'p2';
        }

        return 'p3';
    }

    private function contentPageHasFaqSignal(ContentPage $page): bool
    {
        if ((bool) $page->faq_schema_eligible || (bool) $page->schema_enabled) {
            return true;
        }

        $identity = strtolower(implode(' ', [
            (string) $page->kind,
            (string) $page->page_type,
            (string) $page->slug,
            (string) $page->path,
        ]));

        return preg_match('/\b(faq|help|support)\b/u', $identity) === 1;
    }

    /**
     * @param  mixed  $items
     */
    private function hasValidFaqItems(mixed $items): bool
    {
        if (! is_array($items) || $items === []) {
            return false;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                return false;
            }

            $question = trim((string) ($item['question'] ?? $item['q'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? $item['a'] ?? ''));
            if ($question === '' || $answer === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  mixed  $value
     */
    private function containsFaqPage(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        $type = $value['@type'] ?? null;
        if ($type === 'FAQPage' || (is_array($type) && in_array('FAQPage', $type, true))) {
            return true;
        }

        foreach ($value as $child) {
            if ($this->containsFaqPage($child)) {
                return true;
            }
        }

        return false;
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
            'faq_schema_enable' => false,
            'search_channel_enqueue' => false,
            'search_channel_submit' => false,
            'indexing_request' => false,
            'sitemap_submission' => false,
            'scheduler_activation' => false,
            'queue_worker_started' => false,
            'production_env_change' => false,
            'pr_train_metadata_change' => false,
        ];
    }
}
