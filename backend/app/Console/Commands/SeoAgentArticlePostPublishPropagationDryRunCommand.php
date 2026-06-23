<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\SeoIntel\UrlTruthHandoffArtifact;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class SeoAgentArticlePostPublishPropagationDryRunCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-article-post-publish-propagation-dry-run.v1';

    private const PUBLISH_SCHEMA_VERSION = 'seo-agent-article-cms-publish-canary.v1';

    private const SEO_TITLE_MAX_LENGTH = 60;

    private const ARTICLE_PAGE_TYPE = 'article';

    private const FORBIDDEN_STRINGS = [
        'raw_url',
        'raw_query',
        'credential_path',
        'service_account_json',
        'client_email',
        'private_key',
        'Bearer ',
        'Cookie:',
        'Set-Cookie:',
        'content_md',
        'content_html',
        'cms_draft_body',
    ];

    protected $signature = 'seo-agent:article-post-publish-propagation-dry-run
        {--publish-evidence= : Path to seo-agent-article-cms-publish-canary.v1 JSON evidence}
        {--target= : Exact article subject ref, e.g. article:41:en}
        {--revision-id= : Exact SEO Agent ArticleRevision id that was published}
        {--limit=1 : Bounded target count; must equal 1}
        {--artifact-dir= : Directory for sanitized propagation dry-run evidence}
        {--json : Emit JSON summary}';

    protected $description = 'Read-only SEO Agent article post-publish propagation dry-run; plans URL Truth/search readiness without writes or submissions.';

    public function handle(UrlTruthHandoffArtifact $urlTruthArtifact): int
    {
        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $input = $this->loadInput();
        if (($input['issue'] ?? null) !== null) {
            $summary = $this->failureSummary((string) $input['issue'], (array) ($input['extra'] ?? []));
            $summary['artifact'] = $this->writeArtifact($artifactDir, $summary);

            return $this->finish($summary);
        }

        $target = trim((string) $this->option('target'));
        $revisionId = filter_var($this->option('revision-id'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $publishEvidence = (array) $input['publish_evidence'];
        $publishEvidenceSha = (string) $input['publish_evidence_sha256'];

        $issues = [];
        if ($limit !== 1) {
            $issues[] = 'limit_must_equal_one';
        }
        if ($target === '' || str_contains($target, "\0") || ! is_int($revisionId) || $revisionId <= 0) {
            $issues[] = 'target_or_revision_invalid';
        }
        $evidenceIssue = $this->validatePublishEvidence($publishEvidence, $target, is_int($revisionId) ? $revisionId : 0);
        if ($evidenceIssue !== null) {
            $issues[] = $evidenceIssue;
        }

        $articleId = $this->articleIdFromTarget($target);
        $article = $articleId > 0
            ? Article::query()
                ->withoutGlobalScopes()
                ->with([
                    'publishedRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                    'seoMeta' => static fn ($query) => $query->withoutGlobalScopes(),
                ])
                ->find($articleId)
            : null;
        $draftRevision = is_int($revisionId) && $revisionId > 0
            ? ArticleRevision::query()->withoutGlobalScopes()->find($revisionId)
            : null;

        if (! $article instanceof Article) {
            $issues[] = 'article_not_found';
        }
        if (! $draftRevision instanceof ArticleRevision) {
            $issues[] = 'source_article_revision_not_found';
        } elseif ($article instanceof Article && (int) $draftRevision->article_id !== (int) $article->id) {
            $issues[] = 'source_article_revision_article_mismatch';
        }

        $runtime = $article instanceof Article
            ? $this->runtimeReadiness($article, $target, is_int($revisionId) ? $revisionId : 0)
            : null;
        foreach ((array) ($runtime['blocking_issues'] ?? []) as $issue) {
            $issues[] = (string) $issue;
        }

        if ($issues !== []) {
            $summary = $this->failureSummary('post_publish_propagation_not_ready', [
                'issues' => array_values(array_unique($issues)),
                'target' => $target,
                'revision_id' => $revisionId,
                'publish_evidence_sha256' => $publishEvidenceSha,
                'runtime' => $runtime,
            ]);
            $summary['artifact'] = $this->writeArtifact($artifactDir, $summary);

            return $this->finish($summary);
        }

        /** @var Article $article */
        $canonicalPath = $this->canonicalPathForArticle($article);
        $canonicalUrl = $this->canonicalUrl($canonicalPath);
        $urlTruthSupported = $urlTruthArtifact->supportsPageEntityType(self::ARTICLE_PAGE_TYPE);
        $searchBridgeSupported = false;
        $adapterGaps = [];
        if (! $urlTruthSupported) {
            $adapterGaps[] = 'url_truth_article_page_type_not_supported';
        }
        if (! $searchBridgeSupported) {
            $adapterGaps[] = 'post_publish_search_bridge_article_evidence_not_supported';
        }

        $summary = [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => $adapterGaps === [] ? 'planned' : 'ready_with_adapter_gap',
            'dry_run' => true,
            'execute' => false,
            'target' => $target,
            'revision_id' => $revisionId,
            'publish_evidence_sha256' => $publishEvidenceSha,
            'canonical' => [
                'safe_path' => $canonicalPath,
                'canonical_url_hash' => hash('sha256', $canonicalUrl),
                'canonical_url_host' => parse_url($canonicalUrl, PHP_URL_HOST),
            ],
            'runtime' => $runtime,
            'sitemap_llms' => [
                'already_sitemap_eligible' => (bool) $article->sitemap_eligible,
                'already_llms_eligible' => (bool) $article->llms_eligible,
                'discoverability_release_required' => ! ((bool) $article->sitemap_eligible && (bool) $article->llms_eligible),
                'discoverability_write_attempted' => false,
                'sitemap_llms_mutation_attempted' => false,
            ],
            'url_truth_readiness' => [
                'page_type' => self::ARTICLE_PAGE_TYPE,
                'subject_ref' => $target,
                'entity_ref' => 'article:'.(int) $article->id.':'.(string) $article->locale,
                'source_authority' => 'backend_cms_article',
                'public' => (bool) $article->is_public,
                'indexable' => (bool) $article->is_indexable,
                'url_truth_page_type_supported' => $urlTruthSupported,
                'url_truth_adapter_required' => ! $urlTruthSupported,
                'url_truth_write_attempted' => false,
            ],
            'search_channel_readiness' => [
                'channels' => ['indexnow'],
                'google_sitemap_planning_only' => true,
                'article_publish_evidence_bridge_supported' => $searchBridgeSupported,
                'search_channel_adapter_required' => ! $searchBridgeSupported,
                'queue_enqueue_attempted' => false,
                'live_submit_attempted' => false,
                'external_api_calls_attempted' => false,
            ],
            'adapter_gaps' => $adapterGaps,
            'next_gates' => [
                'url_truth_write_requires_separate_approval' => true,
                'search_channel_enqueue_requires_separate_approval' => true,
                'indexnow_live_submit_requires_separate_approval' => true,
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
        $summary['artifact'] = $this->writeArtifact($artifactDir, $summary);

        return $this->finish($summary);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadInput(): array
    {
        $path = $this->readablePath((string) $this->option('publish-evidence'));
        if ($path === null) {
            return ['issue' => 'publish_evidence_unreadable'];
        }

        $raw = (string) file_get_contents($path);
        $forbidden = $this->forbiddenStringsPresent($raw);
        if ($forbidden !== []) {
            return [
                'issue' => 'forbidden_input_field_present',
                'extra' => ['forbidden_matches' => $forbidden],
            ];
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return ['issue' => 'publish_evidence_json_invalid'];
        }

        return [
            'publish_evidence' => $payload,
            'publish_evidence_sha256' => hash_file('sha256', $path) ?: '',
        ];
    }

    private function validatePublishEvidence(array $evidence, string $target, int $revisionId): ?string
    {
        if (($evidence['schema_version'] ?? null) !== self::PUBLISH_SCHEMA_VERSION) {
            return 'publish_evidence_schema_invalid';
        }
        if (($evidence['status'] ?? null) !== 'success' || (bool) ($evidence['execute'] ?? false) !== true) {
            return 'publish_evidence_not_success_execute';
        }
        if ((int) ($evidence['published_count'] ?? 0) !== 1 || (bool) ($evidence['writes_committed'] ?? false) !== true) {
            return 'publish_evidence_missing_one_committed_publish';
        }
        if (($evidence['target'] ?? null) !== $target || (int) ($evidence['revision_id'] ?? 0) !== $revisionId) {
            return 'publish_evidence_target_revision_mismatch';
        }
        if ((bool) data_get($evidence, 'boundaries.url_truth_write') !== false
            || (bool) data_get($evidence, 'boundaries.indexnow_submit') !== false
            || (bool) data_get($evidence, 'boundaries.search_channel_enqueue') !== false
            || (bool) data_get($evidence, 'boundaries.search_channel_submit') !== false
            || (bool) data_get($evidence, 'boundaries.indexing_request') !== false) {
            return 'publish_evidence_boundary_invalid';
        }

        $publishedRefFound = false;
        foreach ((array) ($evidence['affected_refs'] ?? []) as $ref) {
            if (! is_array($ref) || ($ref['status'] ?? null) !== 'published') {
                continue;
            }
            $publishedRefFound = true;
            if (($ref['target_model'] ?? null) !== 'article'
                || ($ref['subject_ref'] ?? null) !== $target
                || (int) ($ref['revision_id'] ?? 0) !== $revisionId
                || (int) ($ref['article_translation_revision_id'] ?? 0) <= 0) {
                return 'publish_evidence_affected_ref_invalid';
            }
        }

        return $publishedRefFound ? null : 'publish_evidence_published_ref_missing';
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeReadiness(Article $article, string $target, int $revisionId): array
    {
        $publishedRevision = $article->publishedRevision;
        $seoMeta = $article->seoMeta;
        $canonicalPath = $this->canonicalPathForArticle($article);
        $blocking = [];
        $seoTitle = $this->firstNonEmpty(
            $publishedRevision instanceof ArticleTranslationRevision ? $publishedRevision->seo_title : null,
            $seoMeta instanceof ArticleSeoMeta ? $seoMeta->seo_title : null,
        );
        $seoTitleLength = mb_strlen($seoTitle);

        if ((string) $article->status !== 'published') {
            $blocking[] = 'article_not_published';
        }
        if (! (bool) $article->is_public) {
            $blocking[] = 'article_not_public';
        }
        if (! (bool) $article->is_indexable) {
            $blocking[] = 'article_not_indexable';
        }
        if (! $publishedRevision instanceof ArticleTranslationRevision) {
            $blocking[] = 'published_revision_missing';
        } elseif ((int) $publishedRevision->article_id !== (int) $article->id
            || (string) $publishedRevision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED) {
            $blocking[] = 'published_revision_invalid';
        }
        if (! $seoMeta instanceof ArticleSeoMeta) {
            $blocking[] = 'seo_meta_missing';
        } else {
            $actualCanonicalPath = $this->pathFromCanonical((string) $seoMeta->canonical_url);
            if ($actualCanonicalPath !== '' && $actualCanonicalPath !== $canonicalPath) {
                $blocking[] = 'canonical_path_mismatch';
            }
            if (! (bool) $seoMeta->is_indexable || (string) $seoMeta->robots !== 'index,follow') {
                $blocking[] = 'seo_meta_not_index_follow';
            }
        }
        if ($seoTitle === '' || $seoTitleLength > self::SEO_TITLE_MAX_LENGTH) {
            $blocking[] = 'seo_title_length_invalid';
        }

        return [
            'article_id' => (int) $article->id,
            'target' => $target,
            'source_article_revision_id' => $revisionId,
            'status' => (string) $article->status,
            'locale' => (string) $article->locale,
            'safe_path' => $canonicalPath,
            'public' => (bool) $article->is_public,
            'indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'published_revision_id' => (int) $article->published_revision_id,
            'working_revision_id' => (int) $article->working_revision_id,
            'published_revision_status' => $publishedRevision instanceof ArticleTranslationRevision
                ? (string) $publishedRevision->revision_status
                : null,
            'published_revision_article_id' => $publishedRevision instanceof ArticleTranslationRevision
                ? (int) $publishedRevision->article_id
                : null,
            'seo_title_length' => $seoTitleLength,
            'seo_title_max_length' => self::SEO_TITLE_MAX_LENGTH,
            'seo_description_length' => mb_strlen($this->firstNonEmpty(
                $publishedRevision instanceof ArticleTranslationRevision ? $publishedRevision->seo_description : null,
                $seoMeta instanceof ArticleSeoMeta ? $seoMeta->seo_description : null,
            )),
            'blocking_issues' => array_values(array_unique($blocking)),
        ];
    }

    private function articleIdFromTarget(string $target): int
    {
        $parts = explode(':', $target);
        if (count($parts) < 3 || $parts[0] !== 'article') {
            return 0;
        }

        return ctype_digit($parts[1]) ? (int) $parts[1] : 0;
    }

    private function canonicalPathForArticle(Article $article): string
    {
        $segment = str_starts_with(strtolower(trim((string) $article->locale)), 'zh') ? 'zh' : 'en';

        return '/'.$segment.'/articles/'.rawurlencode((string) $article->slug);
    }

    private function canonicalUrl(string $canonicalPath): string
    {
        $base = rtrim((string) config('app.url', 'https://fermatmind.com'), '/');
        if ($base === '') {
            $base = 'https://fermatmind.com';
        }

        return $base.$canonicalPath;
    }

    private function pathFromCanonical(string $canonical): string
    {
        $canonical = trim($canonical);
        if ($canonical === '') {
            return '';
        }
        if (str_starts_with($canonical, '/')) {
            return $canonical;
        }

        $path = parse_url($canonical, PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    private function firstNonEmpty(mixed ...$values): string
    {
        foreach ($values as $value) {
            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function readablePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        $path = str_starts_with($path, '/') ? $path : base_path($path);

        return is_file($path) && is_readable($path) ? $path : null;
    }

    private function artifactDir(): ?string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            $dir = storage_path('app/seo-agent/article-post-publish-propagation-dry-run');
        }
        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);
        File::ensureDirectoryExists($dir, 0775, true);

        return is_dir($dir) && is_writable($dir) ? $dir : null;
    }

    /**
     * @return list<string>
     */
    private function forbiddenStringsPresent(string $raw): array
    {
        $matches = [];
        foreach (self::FORBIDDEN_STRINGS as $needle) {
            if (str_contains($raw, $needle)) {
                $matches[] = $needle;
            }
        }

        return array_values(array_unique($matches));
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
            'url_truth_write' => false,
            'sitemap_llms_mutation' => false,
            'search_channel_enqueue' => false,
            'search_channel_submit' => false,
            'indexnow_submit' => false,
            'indexing_request' => false,
            'scheduler_activation' => false,
            'queue_worker_start' => false,
            'external_model_api_call' => false,
            'external_search_api_call' => false,
            'live_gsc_api_call' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array{path: string, size_bytes: int, sha256: string}
     */
    private function writeArtifact(string $artifactDir, array $summary): array
    {
        $path = rtrim($artifactDir, '/').'/seo-agent-article-post-publish-propagation-dry-run-'.Carbon::now('UTC')->format('Ymd\THis\Z').'.json';
        File::put($path, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return [
            'path' => $path,
            'size_bytes' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function failureSummary(string $issue, array $extra = []): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => false,
            'status' => 'blocked',
            'dry_run' => true,
            'execute' => false,
            'issue' => $issue,
            'issues' => array_values(array_unique((array) ($extra['issues'] ?? [$issue]))),
            'writes_attempted' => false,
            'writes_committed' => false,
            'negative_guarantees' => $this->negativeGuarantees(),
        ] + $extra;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function finish(array $summary): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach (['status', 'dry_run', 'target', 'revision_id'] as $key) {
                if (array_key_exists($key, $summary)) {
                    $this->line($key.'='.(string) $summary[$key]);
                }
            }
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
