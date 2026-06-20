<?php

declare(strict_types=1);

namespace App\Services\SeoAgent;

use App\Models\Article;
use App\Models\ContentPage;
use Illuminate\Support\Facades\Http;

final class RuntimeSeoQaReadonlyScanner
{
    public const SCHEMA_VERSION = 'seo-agent-runtime-seo-qa-readonly-scanner.v1';

    public const TASK = 'SEO-AGENT-RUNTIME-SEO-QA-READONLY-SCANNER-01';

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
    public function scan(string $source = 'cms-indexable', int $limit = 50): array
    {
        $source = $source === 'cms-indexable' ? $source : 'cms-indexable';
        $limit = max(1, min($limit, 100));
        $candidates = [];

        foreach ($this->cmsIndexableTargets($limit) as $target) {
            $qa = $this->inspectTarget($target);
            $issues = $qa['issues'];

            if ($issues !== []) {
                $candidates[] = $this->candidate($target, $issues, $qa['evidence_refs']);
            }

            if (count($candidates) >= $limit) {
                break;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => self::TASK,
            'status' => 'success',
            'source_family' => 'runtime_seo_qa',
            'run_mode' => 'readonly_discovery',
            'source' => $source,
            'limit' => $limit,
            'targets_checked' => count($this->cmsIndexableTargets($limit)),
            'candidate_count' => count($candidates),
            'candidates' => $candidates,
            'checks' => [
                'http_status',
                'redirect_status',
                'canonical_tag',
                'meta_robots_noindex',
                'x_robots_tag_noindex',
                'json_ld_presence',
            ],
            'forbidden_output_fields_absent' => true,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return list<array{subject_type:string, subject_ref:string, safe_path:string, locale:string}>
     */
    private function cmsIndexableTargets(int $limit): array
    {
        $targets = [];

        $articles = Article::query()
            ->withoutGlobalScopes()
            ->published()
            ->where('is_indexable', true)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($articles as $article) {
            $targets[] = [
                'subject_type' => 'article',
                'subject_ref' => 'article:'.(int) $article->id.':'.(string) $article->locale,
                'safe_path' => $this->articleSafePath($article),
                'locale' => (string) $article->locale,
            ];

            if (count($targets) >= $limit) {
                return $targets;
            }
        }

        $pages = ContentPage::query()
            ->withoutGlobalScopes()
            ->publishedPublic()
            ->where('is_indexable', true)
            ->orderBy('id')
            ->limit($limit - count($targets))
            ->get();

        foreach ($pages as $page) {
            $targets[] = [
                'subject_type' => 'content_page',
                'subject_ref' => 'content_page:'.(int) $page->id.':'.(string) $page->locale,
                'safe_path' => $this->contentPageSafePath($page),
                'locale' => (string) $page->locale,
            ];

            if (count($targets) >= $limit) {
                break;
            }
        }

        return $targets;
    }

    /**
     * @param  array{subject_type:string, subject_ref:string, safe_path:string, locale:string}  $target
     * @return array{issues:list<string>, evidence_refs:list<array<string, mixed>>}
     */
    private function inspectTarget(array $target): array
    {
        $issues = [];
        $evidence = [];
        $response = Http::withOptions(['allow_redirects' => false])
            ->timeout(10)
            ->get($this->publicUrlForSafePath($target['safe_path']));

        $status = $response->status();
        $body = (string) $response->body();
        $headers = $response->headers();

        if ($status !== 200) {
            $issues[] = $status >= 300 && $status < 400 ? 'redirect_present' : 'http_status_not_200';
            $evidence[] = [
                'code' => $issues[array_key_last($issues)],
                'status_code' => $status,
            ];
        }

        $robots = $this->metaRobots($body);
        if ($robots !== null && str_contains(strtolower($robots), 'noindex')) {
            $issues[] = 'noindex_present';
            $evidence[] = [
                'code' => 'noindex_present',
                'field_status' => 'present',
            ];
        }

        $xRobots = implode(',', (array) ($headers['X-Robots-Tag'] ?? $headers['x-robots-tag'] ?? []));
        if ($xRobots !== '' && str_contains(strtolower($xRobots), 'noindex')) {
            $issues[] = 'x_robots_noindex';
            $evidence[] = [
                'code' => 'x_robots_noindex',
                'field_status' => 'present',
            ];
        }

        $canonicalPath = $this->canonicalPath($body);
        if ($canonicalPath === null) {
            $issues[] = 'missing_canonical';
            $evidence[] = [
                'code' => 'missing_canonical',
                'field_status' => 'missing',
            ];
        } elseif ($canonicalPath !== $target['safe_path']) {
            $issues[] = 'canonical_mismatch';
            $evidence[] = [
                'code' => 'canonical_mismatch',
                'expected_safe_path_hash' => hash('sha256', $target['safe_path']),
                'observed_safe_path_hash' => hash('sha256', $canonicalPath),
            ];
        }

        if (! $this->hasJsonLd($body)) {
            $issues[] = 'missing_json_ld';
            $evidence[] = [
                'code' => 'missing_json_ld',
                'field_status' => 'missing',
            ];
        }

        return [
            'issues' => array_values(array_unique($issues)),
            'evidence_refs' => $evidence,
        ];
    }

    /**
     * @param  array{subject_type:string, subject_ref:string, safe_path:string, locale:string}  $target
     * @param  list<string>  $issues
     * @param  list<array<string, mixed>>  $evidenceRefs
     * @return array<string, mixed>
     */
    private function candidate(array $target, array $issues, array $evidenceRefs): array
    {
        $sourcePayload = implode('|', [$target['subject_type'], $target['subject_ref'], $target['safe_path'], implode(',', $issues)]);

        return [
            'source_family' => 'runtime_seo_qa',
            'source_id' => hash('sha256', $sourcePayload),
            'subject_type' => $target['subject_type'],
            'subject_ref' => $target['subject_ref'],
            'safe_path' => $target['safe_path'],
            'locale' => $target['locale'],
            'severity' => $this->severity($issues),
            'gap_types' => $issues,
            'evidence_refs' => $evidenceRefs,
            'recommended_next_step' => 'codex_review_required_before_runtime_seo_fix',
            'allowed_action' => 'readonly_review',
            'blocked_actions' => self::BLOCKED_ACTIONS,
        ];
    }

    /**
     * @param  list<string>  $issues
     */
    private function severity(array $issues): string
    {
        if (array_intersect($issues, ['http_status_not_200', 'noindex_present', 'x_robots_noindex', 'missing_canonical', 'canonical_mismatch']) !== []) {
            return 'p1';
        }

        if (in_array('redirect_present', $issues, true)) {
            return 'p2';
        }

        return 'p3';
    }

    private function publicUrlForSafePath(string $safePath): string
    {
        $host = rtrim((string) config('seo_intel.public_canonical_host', 'https://fermatmind.com'), '/');

        return $host.'/'.ltrim($safePath, '/');
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

    private function canonicalPath(string $body): ?string
    {
        if (preg_match('/<link\b[^>]*rel=["\']canonical["\'][^>]*href=["\']([^"\']+)["\'][^>]*>/i', $body, $matches) !== 1
            && preg_match('/<link\b[^>]*href=["\']([^"\']+)["\'][^>]*rel=["\']canonical["\'][^>]*>/i', $body, $matches) !== 1) {
            return null;
        }

        $path = parse_url((string) $matches[1], PHP_URL_PATH);
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return preg_replace('#/+#', '/', '/'.ltrim($path, '/')) ?: null;
    }

    private function metaRobots(string $body): ?string
    {
        if (preg_match('/<meta\b[^>]*name=["\']robots["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i', $body, $matches) !== 1
            && preg_match('/<meta\b[^>]*content=["\']([^"\']+)["\'][^>]*name=["\']robots["\'][^>]*>/i', $body, $matches) !== 1) {
            return null;
        }

        return (string) $matches[1];
    }

    private function hasJsonLd(string $body): bool
    {
        return preg_match('/<script\b[^>]*type=["\']application\/ld\+json["\'][^>]*>/i', $body) === 1;
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
            'google_search_console_api_call' => false,
            'google_indexing_api_call' => false,
            'pr_train_metadata_change' => false,
        ];
    }
}
