<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\ArticlePublishService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SeoAgentArticleCmsPublishCanaryCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-article-cms-publish-canary.v1';

    private const PACKAGE_SCHEMA_VERSION = 'seo-agent-cms-draft-package-dry-run.v1';

    private const WRITE_SCHEMA_VERSION = 'seo-agent-controlled-cms-draft-write.v1';

    private const GATE_SCHEMA_VERSION = 'seo-agent-gsc-draft-publish-gate-readiness.v1';

    private const WRITER_TASK = 'SEO-AGENT-CONTROLLED-CMS-DRAFT-WRITER-01';

    private const TASK = 'SEO-AGENT-ARTICLE-CMS-PUBLISH-CANARY-01';

    private const FORBIDDEN_STRINGS = [
        'raw_url',
        'raw_query',
        'credential_path',
        'service_account_json',
        'client_email',
        'private_key',
        'Bearer ',
        'token',
        'cookie',
        'session',
        'content_md',
        'content_html',
        'cms_draft_body',
    ];

    protected $signature = 'seo-agent:article-cms-publish-canary
        {--package= : Path to a seo-agent-cms-draft-package-dry-run.v1 JSON artifact}
        {--write-evidence= : Path to a seo-agent-controlled-cms-draft-write.v1 JSON artifact}
        {--publish-gate-evidence= : Path to a seo-agent-gsc-draft-publish-gate-readiness.v1 JSON artifact}
        {--target= : Exact article subject ref, e.g. article:41:en}
        {--revision-id= : Exact SEO Agent ArticleRevision id to publish}
        {--limit=1 : Maximum article drafts to publish; must equal 1}
        {--artifact-dir= : Directory for sanitized publish evidence}
        {--confirm-write-evidence-sha256= : Required write evidence sha256 for execute mode}
        {--confirm-publish= : Exact publish gate approval phrase for execute mode}
        {--execute : Actually promote one bounded article draft}
        {--json : Emit JSON summary}';

    protected $description = 'Publish one SEO Agent article draft canary through ArticleTranslationRevision promotion; no search, indexing, sitemap, scheduler, or queue action.';

    public function handle(ArticlePublishService $publisher, AuditLogger $auditLogger): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit !== 1) {
            return $this->finish($this->failureSummary('limit_must_equal_one'));
        }

        $target = trim((string) $this->option('target'));
        $revisionId = filter_var($this->option('revision-id'), FILTER_VALIDATE_INT);
        if ($target === '' || str_contains($target, "\0") || ! is_int($revisionId) || $revisionId <= 0) {
            return $this->finish($this->failureSummary('target_or_revision_invalid'));
        }

        $packagePath = $this->readablePath((string) $this->option('package'));
        $writePath = $this->readablePath((string) $this->option('write-evidence'));
        $gatePath = $this->readablePath((string) $this->option('publish-gate-evidence'));
        if ($packagePath === null || $writePath === null || $gatePath === null) {
            return $this->finish($this->failureSummary('input_artifact_unreadable'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $loaded = $this->loadInputs($packagePath, $writePath, $gatePath);
        if (($loaded['issue'] ?? null) !== null) {
            return $this->finish($this->failureSummary((string) $loaded['issue'], (array) ($loaded['extra'] ?? [])));
        }

        $package = (array) $loaded['package'];
        $writeEvidence = (array) $loaded['write_evidence'];
        $gateEvidence = (array) $loaded['publish_gate_evidence'];
        $packageSha = (string) $loaded['package_sha256'];
        $writeSha = (string) $loaded['write_evidence_sha256'];
        $gateSha = (string) $loaded['publish_gate_evidence_sha256'];

        $plan = $this->plan($package, $writeEvidence, $gateEvidence, $target, $revisionId, $packageSha, $writeSha, $gateSha);
        $execute = (bool) $this->option('execute');
        $requiredPhrase = (string) ($plan['required_confirmation_phrase'] ?? '');
        $issues = (array) ($plan['issues'] ?? []);

        if ($execute && (string) $this->option('confirm-write-evidence-sha256') !== $writeSha) {
            $issues[] = 'write_evidence_sha256_confirmation_mismatch';
        }
        if ($execute && ! hash_equals($requiredPhrase, (string) $this->option('confirm-publish'))) {
            $issues[] = 'confirm_publish_phrase_mismatch';
        }

        if ($issues !== []) {
            $summary = $this->failureSummary('publish_plan_not_publishable', [
                'issues' => array_values(array_unique($issues)),
                'target' => $target,
                'revision_id' => $revisionId,
                'package_sha256' => $packageSha,
                'write_evidence_sha256' => $writeSha,
                'required_confirmation_phrase' => $requiredPhrase,
            ]);
            $summary['artifact'] = $this->writeArtifact($artifactDir, $summary);

            return $this->finish($summary);
        }

        if (! $execute) {
            $summary = [
                'schema_version' => self::SCHEMA_VERSION,
                'ok' => true,
                'status' => 'planned',
                'dry_run' => true,
                'execute' => false,
                'would_publish' => true,
                'planned_count' => 1,
                'target' => $target,
                'revision_id' => $revisionId,
                'package_sha256' => $packageSha,
                'write_evidence_sha256' => $writeSha,
                'publish_gate_evidence_sha256' => $gateSha,
                'required_confirmation_phrase' => $requiredPhrase,
                'plan' => $this->sanitizedPlan($plan),
                'writes_attempted' => false,
                'writes_committed' => false,
                'boundaries' => $this->boundaries(false),
            ];
            $summary['artifact'] = $this->writeArtifact($artifactDir, $summary);

            return $this->finish($summary);
        }

        try {
            $result = $this->publish($plan, $packageSha, $writeSha, $gateSha, $publisher, $auditLogger);
        } catch (RuntimeException|\InvalidArgumentException $exception) {
            $summary = $this->failureSummary('publish_execution_failed', [
                'issues' => ['publish_execution_failed'],
                'error' => $exception->getMessage(),
                'target' => $target,
                'revision_id' => $revisionId,
                'package_sha256' => $packageSha,
                'write_evidence_sha256' => $writeSha,
            ]);
            $summary['artifact'] = $this->writeArtifact($artifactDir, $summary);

            return $this->finish($summary);
        }

        $summary = [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => 'success',
            'dry_run' => false,
            'execute' => true,
            'approval_mode' => 'exact_human_confirmation',
            'target' => $target,
            'revision_id' => $revisionId,
            'package_sha256' => $packageSha,
            'write_evidence_sha256' => $writeSha,
            'publish_gate_evidence_sha256' => $gateSha,
            'writes_attempted' => true,
            'writes_committed' => true,
            'published_count' => 1,
            'rows_created' => (int) ($result['rows_created'] ?? 0),
            'affected_refs' => [
                [
                    'status' => 'published',
                    'target_model' => 'article',
                    'subject_ref' => $target,
                    'revision_id' => $revisionId,
                    'article_translation_revision_id' => (int) ($result['article_translation_revision_id'] ?? 0),
                ],
            ],
            'rollback_evidence' => (array) ($result['rollback_evidence'] ?? []),
            'boundaries' => $this->boundaries(true),
        ];
        $summary['artifact'] = $this->writeArtifact($artifactDir, $summary);

        return $this->finish($summary);
    }

    private function readablePath(string $rawPath): ?string
    {
        $path = trim($rawPath);
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
            $dir = storage_path('app/seo-agent/article-cms-publish-canary');
        }
        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadInputs(string $packagePath, string $writePath, string $gatePath): array
    {
        $raw = [
            'package' => (string) file_get_contents($packagePath),
            'write_evidence' => (string) file_get_contents($writePath),
            'publish_gate_evidence' => (string) file_get_contents($gatePath),
        ];
        $forbidden = [];
        foreach ($raw as $contents) {
            $forbidden = array_merge($forbidden, $this->forbiddenStringsPresent($contents));
        }
        $forbidden = array_values(array_unique($forbidden));
        if ($forbidden !== []) {
            return ['issue' => 'forbidden_input_field_present', 'extra' => ['forbidden_matches' => $forbidden]];
        }

        $package = json_decode($raw['package'], true);
        $writeEvidence = json_decode($raw['write_evidence'], true);
        $gateEvidence = json_decode($raw['publish_gate_evidence'], true);
        if (! is_array($package) || ! is_array($writeEvidence) || ! is_array($gateEvidence)) {
            return ['issue' => 'input_artifact_json_invalid'];
        }
        if (($package['schema_version'] ?? null) !== self::PACKAGE_SCHEMA_VERSION) {
            return ['issue' => 'package_schema_invalid'];
        }
        if (($writeEvidence['schema_version'] ?? null) !== self::WRITE_SCHEMA_VERSION) {
            return ['issue' => 'write_evidence_schema_invalid'];
        }
        if (($gateEvidence['schema_version'] ?? null) !== self::GATE_SCHEMA_VERSION) {
            return ['issue' => 'publish_gate_evidence_schema_invalid'];
        }
        if ((bool) ($package['dry_run'] ?? false) !== true
            || (bool) ($package['cms_write_allowed'] ?? true) !== false
            || (bool) ($package['execution_permission'] ?? true) !== false) {
            return ['issue' => 'package_execution_boundary_invalid'];
        }
        if (($writeEvidence['status'] ?? null) !== 'success' || (bool) ($writeEvidence['execute'] ?? false) !== true) {
            return ['issue' => 'write_evidence_not_success_execute'];
        }

        return [
            'package' => $package,
            'write_evidence' => $writeEvidence,
            'publish_gate_evidence' => $gateEvidence,
            'package_sha256' => hash_file('sha256', $packagePath) ?: '',
            'write_evidence_sha256' => hash_file('sha256', $writePath) ?: '',
            'publish_gate_evidence_sha256' => hash_file('sha256', $gatePath) ?: '',
        ];
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  array<string, mixed>  $writeEvidence
     * @param  array<string, mixed>  $gateEvidence
     * @return array<string, mixed>
     */
    private function plan(
        array $package,
        array $writeEvidence,
        array $gateEvidence,
        string $target,
        int $revisionId,
        string $packageSha,
        string $writeSha,
        string $gateSha
    ): array {
        $issues = [];
        if ((string) ($writeEvidence['package_sha256'] ?? '') !== $packageSha) {
            $issues[] = 'write_evidence_package_sha256_mismatch';
        }

        $proposal = $this->proposalForTarget($package, $target);
        if ($proposal === null) {
            $issues[] = 'target_not_found_in_package';
        } elseif ((string) ($proposal['target_model'] ?? $proposal['subject_type'] ?? '') !== 'article') {
            $issues[] = 'target_model_not_article';
        }

        $writeRef = $this->writeRefForTarget($writeEvidence, $target);
        if ($writeRef === null) {
            $issues[] = 'target_not_found_in_write_evidence';
        } elseif ((int) ($writeRef['revision_id'] ?? 0) !== $revisionId) {
            $issues[] = 'write_evidence_revision_id_mismatch';
        }

        $gateVerdict = $this->gateVerdictForTarget($gateEvidence, $target);
        if ($gateVerdict === null) {
            $issues[] = 'target_not_found_in_publish_gate_evidence';
        } else {
            if ((int) ($gateVerdict['revision_id'] ?? 0) !== $revisionId) {
                $issues[] = 'publish_gate_revision_id_mismatch';
            }
            if (($gateVerdict['gate_status'] ?? null) !== 'publish_ready') {
                $issues[] = 'publish_gate_not_ready';
            }
        }

        $articleId = $this->idFromSubjectRef($target, 'article');
        $article = $articleId > 0
            ? Article::query()->withoutGlobalScopes()->with(['seoMeta'])->find($articleId)
            : null;
        $draftRevision = ArticleRevision::query()->withoutGlobalScopes()->whereKey($revisionId)->first();
        if (! $article instanceof Article) {
            $issues[] = 'article_not_found';
        }
        if (! $draftRevision instanceof ArticleRevision) {
            $issues[] = 'draft_revision_not_found';
        }
        if ($article instanceof Article && $draftRevision instanceof ArticleRevision) {
            if ((int) $draftRevision->article_id !== (int) $article->id) {
                $issues[] = 'draft_revision_article_mismatch';
            }
            if ((string) data_get($draftRevision->payload_json, 'seo_agent.task') !== self::WRITER_TASK) {
                $issues[] = 'draft_revision_writer_task_mismatch';
            }
            if ((string) data_get($draftRevision->payload_json, 'seo_agent.package_sha256') !== $packageSha) {
                $issues[] = 'draft_revision_package_sha256_mismatch';
            }
            if ((string) data_get($draftRevision->payload_json, 'seo_agent.subject_ref') !== $target) {
                $issues[] = 'draft_revision_subject_ref_mismatch';
            }
        }
        if ($article instanceof Article) {
            if ((string) $article->status !== 'published' || ! (bool) $article->is_public) {
                $issues[] = 'article_not_published_public';
            }
            if (in_array((string) $article->lifecycle_state, [Article::LIFECYCLE_ARCHIVED, Article::LIFECYCLE_SOFT_DELETED], true)) {
                $issues[] = 'article_lifecycle_not_publishable';
            }
            if (method_exists($article, 'trashed') && $article->trashed()) {
                $issues[] = 'article_soft_deleted';
            }
            if ((int) ($article->published_revision_id ?? 0) <= 0) {
                $issues[] = 'current_published_revision_lock_missing';
            }
        }

        $requiredPhrase = (string) data_get($gateVerdict, 'publish_approval_phrase', '');
        if ($requiredPhrase === '') {
            $requiredPhrase = 'I explicitly approve production CMS publish canary for '.$target.' revision '.$revisionId.' using write evidence sha256 '.$writeSha.'; no URL Truth, no sitemap, no IndexNow, no search, no indexing, no scheduler.';
        }

        return [
            'issues' => array_values(array_unique($issues)),
            'target' => $target,
            'article_id' => $article instanceof Article ? (int) $article->id : $articleId,
            'revision_id' => $revisionId,
            'current_published_revision_id' => $article instanceof Article && $article->published_revision_id ? (int) $article->published_revision_id : null,
            'current_working_revision_id' => $article instanceof Article && $article->working_revision_id ? (int) $article->working_revision_id : null,
            'package_sha256' => $packageSha,
            'write_evidence_sha256' => $writeSha,
            'publish_gate_evidence_sha256' => $gateSha,
            'required_confirmation_phrase' => $requiredPhrase,
            'proposal' => $proposal,
            'write_ref' => $writeRef,
            'gate_verdict' => $gateVerdict,
            'article' => $article,
            'draft_revision' => $draftRevision,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function publish(
        array $plan,
        string $packageSha,
        string $writeSha,
        string $gateSha,
        ArticlePublishService $publisher,
        AuditLogger $auditLogger
    ): array {
        $article = $plan['article'] ?? null;
        $draftRevision = $plan['draft_revision'] ?? null;
        if (! $article instanceof Article || ! $draftRevision instanceof ArticleRevision) {
            throw new RuntimeException('publish plan missing article or draft revision.');
        }
        $previousState = $this->articleState($article);
        $createdRevisionId = null;

        $publishedArticle = DB::transaction(function () use ($article, $draftRevision, $packageSha, $writeSha, $gateSha, &$createdRevisionId, $publisher): Article {
            $lockedArticle = Article::query()
                ->withoutGlobalScopes()
                ->whereKey((int) $article->id)
                ->lockForUpdate()
                ->first();
            if (! $lockedArticle instanceof Article) {
                throw new RuntimeException('article disappeared before publish.');
            }
            if ((int) ($lockedArticle->published_revision_id ?? 0) !== (int) ($article->published_revision_id ?? 0)) {
                throw new RuntimeException('current published revision lock no longer matches.');
            }

            $translationRevision = $this->createApprovedTranslationRevision($lockedArticle, $draftRevision, $packageSha, $writeSha, $gateSha);
            $createdRevisionId = (int) $translationRevision->id;

            $lockedArticle->forceFill([
                'working_revision_id' => (int) $translationRevision->id,
            ])->save();

            return $publisher->promoteExistingWorkingRevision(
                (int) $lockedArticle->id,
                (int) $translationRevision->id,
                (int) $article->published_revision_id,
                'seo_agent_article_cms_publish_canary'
            );
        });
        $translationRevision = ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereKey($createdRevisionId)
            ->first();
        if ($translationRevision instanceof ArticleTranslationRevision) {
            $this->ensureSeoMeta($publishedArticle, $translationRevision);
        }

        $auditLogger->log(
            Request::create('/ops/seo-agent/article-cms-publish-canary', 'POST'),
            'seo_agent_article_cms_publish_canary',
            'article',
            (string) $publishedArticle->id,
            [
                'task' => self::TASK,
                'article_id' => (int) $publishedArticle->id,
                'source_article_revision_id' => (int) $draftRevision->id,
                'article_translation_revision_id' => $createdRevisionId,
                'package_sha256' => $packageSha,
                'write_evidence_sha256' => $writeSha,
                'publish_gate_evidence_sha256' => $gateSha,
                'search_submit_allowed' => false,
                'indexing_request_allowed' => false,
                'sitemap_submission_allowed' => false,
            ],
            reason: 'seo_agent_article_cms_publish_canary',
            result: 'success',
        );

        return [
            'rows_created' => 1,
            'article_translation_revision_id' => $createdRevisionId,
            'rollback_evidence' => [
                'available' => true,
                'previous_article_state' => $previousState,
                'new_published_revision_id' => $createdRevisionId,
            ],
        ];
    }

    private function createApprovedTranslationRevision(
        Article $article,
        ArticleRevision $draftRevision,
        string $packageSha,
        string $writeSha,
        string $gateSha
    ): ArticleTranslationRevision {
        $proposal = is_array($draftRevision->payload_json)
            ? (array) data_get($draftRevision->payload_json, 'proposal', [])
            : [];
        $revisionNumber = ((int) ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->where('article_id', (int) $article->id)
            ->max('revision_number')) + 1;
        $now = Carbon::now('UTC');

        return ArticleTranslationRevision::query()->create([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'source_article_id' => $article->source_article_id ? (int) $article->source_article_id : (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => (string) $article->locale,
            'source_locale' => (string) ($article->source_locale ?: $article->locale),
            'revision_number' => $revisionNumber,
            'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
            'source_version_hash' => $article->source_version_hash,
            'translated_from_version_hash' => $article->translated_from_version_hash,
            'supersedes_revision_id' => $this->sameArticleTranslationRevisionId($article, $article->working_revision_id ? (int) $article->working_revision_id : null),
            'title' => (string) $draftRevision->title,
            'excerpt' => $draftRevision->excerpt,
            'content_md' => (string) $draftRevision->content_md,
            'seo_title' => $proposal['proposed_seo_title'] ?? null,
            'seo_description' => $proposal['proposed_seo_description'] ?? null,
            'created_by' => null,
            'reviewed_by' => null,
            'reviewed_at' => $now,
            'approved_at' => $now,
        ]);
    }

    private function sameArticleTranslationRevisionId(Article $article, ?int $revisionId): ?int
    {
        if ($revisionId === null || $revisionId <= 0) {
            return null;
        }

        return ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereKey($revisionId)
            ->where('article_id', (int) $article->id)
            ->exists()
                ? $revisionId
                : null;
    }

    private function ensureSeoMeta(Article $article, ArticleTranslationRevision $revision): void
    {
        if (! filled($revision->seo_title) && ! filled($revision->seo_description)) {
            return;
        }

        $updates = [
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'locale' => (string) $article->locale,
        ];
        if (filled($revision->seo_title)) {
            $updates['seo_title'] = (string) $revision->seo_title;
            $updates['og_title'] = (string) $revision->seo_title;
        }
        if (filled($revision->seo_description)) {
            $updates['seo_description'] = (string) $revision->seo_description;
            $updates['og_description'] = (string) $revision->seo_description;
        }

        ArticleSeoMeta::query()
            ->withoutGlobalScopes()
            ->updateOrCreate(['article_id' => (int) $article->id], $updates);
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>|null
     */
    private function proposalForTarget(array $package, string $target): ?array
    {
        foreach ((array) ($package['proposal_items'] ?? []) as $proposal) {
            if (is_array($proposal) && (string) ($proposal['subject_ref'] ?? '') === $target) {
                return $proposal;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $writeEvidence
     * @return array<string, mixed>|null
     */
    private function writeRefForTarget(array $writeEvidence, string $target): ?array
    {
        foreach ((array) ($writeEvidence['affected_refs'] ?? []) as $ref) {
            if (is_array($ref)
                && (string) ($ref['target_model'] ?? '') === 'article'
                && (string) ($ref['subject_ref'] ?? '') === $target
                && (string) ($ref['status'] ?? '') !== 'skipped_existing') {
                return $ref;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $gateEvidence
     * @return array<string, mixed>|null
     */
    private function gateVerdictForTarget(array $gateEvidence, string $target): ?array
    {
        foreach ((array) ($gateEvidence['draft_verdicts'] ?? []) as $verdict) {
            if (is_array($verdict) && (string) ($verdict['subject_ref'] ?? '') === $target) {
                return $verdict;
            }
        }

        return null;
    }

    private function idFromSubjectRef(string $subjectRef, string $expectedType): int
    {
        $parts = explode(':', $subjectRef);
        if (($parts[0] ?? '') !== $expectedType || ! isset($parts[1]) || ! ctype_digit($parts[1])) {
            return 0;
        }

        return (int) $parts[1];
    }

    /**
     * @return array<string, mixed>
     */
    private function articleState(Article $article): array
    {
        return [
            'article_id' => (int) $article->id,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'working_revision_id' => $article->working_revision_id ? (int) $article->working_revision_id : null,
            'published_revision_id' => $article->published_revision_id ? (int) $article->published_revision_id : null,
            'seo_title_sha256' => $article->seoMeta instanceof ArticleSeoMeta ? hash('sha256', (string) $article->seoMeta->seo_title) : null,
            'seo_description_sha256' => $article->seoMeta instanceof ArticleSeoMeta ? hash('sha256', (string) $article->seoMeta->seo_description) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function sanitizedPlan(array $plan): array
    {
        return [
            'target' => (string) ($plan['target'] ?? ''),
            'article_id' => (int) ($plan['article_id'] ?? 0),
            'revision_id' => (int) ($plan['revision_id'] ?? 0),
            'current_published_revision_id' => $plan['current_published_revision_id'] ?? null,
            'current_working_revision_id' => $plan['current_working_revision_id'] ?? null,
            'package_sha256' => (string) ($plan['package_sha256'] ?? ''),
            'write_evidence_sha256' => (string) ($plan['write_evidence_sha256'] ?? ''),
            'publish_gate_evidence_sha256' => (string) ($plan['publish_gate_evidence_sha256'] ?? ''),
            'creates_article_translation_revision' => true,
            'promotes_existing_article_working_revision' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{path:string,size_bytes:int,sha256:string}
     */
    private function writeArtifact(string $artifactDir, array $payload): array
    {
        $path = rtrim($artifactDir, '/').'/seo-agent-article-cms-publish-canary-'.Carbon::now('UTC')->format('Ymd\THis\Z').'.json';
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        file_put_contents($path, $encoded);

        return [
            'path' => $path,
            'size_bytes' => filesize($path) ?: strlen($encoded),
            'sha256' => hash_file('sha256', $path) ?: '',
        ];
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

        return $matches;
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
            'issues' => [$issue],
            ...$extra,
            'writes_attempted' => false,
            'writes_committed' => false,
            'boundaries' => $this->boundaries(false),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function finish(array $summary): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('status='.(string) ($summary['status'] ?? 'unknown'));
            foreach ((array) ($summary['issues'] ?? []) as $issue) {
                $this->line('issue='.(string) $issue);
            }
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, bool>
     */
    private function boundaries(bool $published): array
    {
        return [
            'cms_publish' => $published,
            'article_translation_revision_created' => $published,
            'article_published_revision_mutation' => $published,
            'url_truth_write' => false,
            'sitemap_submission' => false,
            'indexnow_submit' => false,
            'search_channel_enqueue' => false,
            'search_channel_submit' => false,
            'indexing_request' => false,
            'scheduler_activation' => false,
            'queue_worker_start' => false,
            'external_model_api_call' => false,
            'live_gsc_api_call' => false,
        ];
    }
}
