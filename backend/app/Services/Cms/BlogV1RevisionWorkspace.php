<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Console\Commands\UpdateArticlePublicReaderMetadata;
use App\Events\PublicAuthorityChanged;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleTranslationRevision;
use App\Models\LandingSurface;
use App\Services\Audit\AuditLogger;
use App\Support\ArticleSourceTargetSnapshot;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Fixed blog cohort only; human review and publication remain separate.
 * @review-surface article
 * @review-surface article_translation_revision
 */
final class BlogV1RevisionWorkspace
{
    public const SOURCE_SHA256 = '2363384898db3112efcbbbb25b0786b0febf794c16c7b7e7063d94dd8749c8e2';

    public const IDS = [3, 10, 31, 40, 204, 221, 240, 241];

    private const CATEGORY_CHANGES = [31 => 11, 240 => 11, 241 => 1];

    private const FIELDS = ['title', 'excerpt', 'content_md', 'seo_title', 'seo_description'];

    private const KEY = 'blog_v1_candidate';

    public function __construct(
        private readonly ArticleTranslationRevisionWorkspace $workspace,
        private readonly ArticlePublishService $publisher,
        private readonly ArticleBodyHeadingGuard $headings,
        private readonly ArticleEditorialCompletenessGate $completeness,
        private readonly CmsEditorialReviewAttestationService $reviews,
        private readonly ArticleTranslationWorkflowService $workflow,
        private readonly AuditLogger $audit,
    ) {}

    public function loadSource(string $file, string $sha): array
    {
        if ($sha !== self::SOURCE_SHA256 || ! is_file($file) || is_link($file)
            || ! hash_equals($sha, (string) hash_file('sha256', $file))) {
            throw new RuntimeException('blog_source_package_drift');
        }
        $package = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        if (($package['schema'] ?? null) !== 'blog_v1_b_final_eight_reader_edge_review.v1') {
            throw new RuntimeException('blog_source_schema_invalid');
        }

        return $package;
    }

    /** Complete native state, including histories, SEO, taxonomy, edges and source lineage. */
    public function snapshot(bool $lock = false): array
    {
        $query = Article::withoutGlobalScopes()->withTrashed()->whereIn('id', self::IDS)->orderBy('id');
        $articles = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($articles->pluck('id')->all() !== self::IDS) {
            throw new RuntimeException('blog_target_inventory_missing');
        }
        $state = [];
        foreach ($articles as $article) {
            $row = UpdateArticlePublicReaderMetadata::state($article, $lock);
            $row['tags_sha256'] = ArticleSourceTargetSnapshot::tagsHash($article, $lock);
            $edges = DB::table('article_test_edges')->where('article_id', $article->id)->orderBy('id');
            $row['test_edges'] = ($lock ? $edges->lockForUpdate() : $edges)->get()->map(fn ($edge) => (array) $edge)->all();
            $identities = ['article:'.$article->getMorphClass().':'.$article->id];
            foreach ($row['history'] as $revision) {
                $identities[] = 'article_translation_revision:'.(new ArticleTranslationRevision)->getMorphClass().':'.$revision['revision_id'];
            }
            $evidence = DB::table('review_attestation_target_evidences')->whereIn('target_identity', $identities)->orderBy('id');
            $row['review_evidence'] = ($lock ? $evidence->lockForUpdate() : $evidence)->get()->map(fn ($item) => (array) $item)->all();
            $attestations = DB::table('review_attestations')->whereIn('id', array_column($row['review_evidence'], 'review_attestation_id'))->orderBy('id');
            $row['review_attestations'] = ($lock ? $attestations->lockForUpdate() : $attestations)->get()->map(fn ($item) => (array) $item)->all();
            $editorial = DB::table('editorial_reviews')->where('content_type', 'article')->where('content_id', $article->id)->orderBy('id');
            $row['editorial_reviews'] = ($lock ? $editorial->lockForUpdate() : $editorial)->get()->map(fn ($item) => (array) $item)->all();
            $sourceId = (int) ($article->source_article_id ?: $article->translated_from_article_id ?: 0);
            if ($sourceId > 0 && $sourceId !== $article->id) {
                $sourceQuery = Article::withoutGlobalScopes()->withTrashed()->whereKey($sourceId);
                $source = ($lock ? $sourceQuery->lockForUpdate() : $sourceQuery)->first();
                if (! $source instanceof Article) {
                    throw new RuntimeException('blog_source_lineage_missing');
                }
                $row['source'] = ['article' => $source->getAttributes(),
                    'history' => ArticleSourceTargetSnapshot::sourceRevisions($source, [], $lock)];
                $sourceReviews = DB::table('editorial_reviews')->where('content_type', 'article')->where('content_id', $sourceId)->orderBy('id');
                $row['source']['editorial_reviews'] = ($lock ? $sourceReviews->lockForUpdate() : $sourceReviews)->get()->map(fn ($item) => (array) $item)->all();
                $sourceSeo = DB::table('article_seo_meta')->where('article_id', $sourceId)->orderBy('id');
                $row['source']['seo'] = ($lock ? $sourceSeo->lockForUpdate() : $sourceSeo)->get()->map(fn ($item) => (array) $item)->all();
            }
            $state[(string) $article->id] = $row;
        }
        $categories = ArticleCategory::withoutGlobalScopes()->where('org_id', 0)->orderBy('id');
        $state['categories'] = ($lock ? $categories->lockForUpdate() : $categories)->get()->map(fn ($row) => $row->getAttributes())->all();

        return $state;
    }

    public function plan(array $package, bool $promotion = false): array
    {
        $state = $this->snapshot();
        $rows = $this->validateCandidates($package, false);
        $blockers = [];
        if ($promotion) {
            foreach ($rows as $row) {
                if ($row['verify_only']) {
                    continue;
                }
                $article = $this->article($row['article_id']);
                if ($article->working_revision_id === $article->published_revision_id) {
                    $blockers[] = ['article_id' => $article->id, 'code' => 'blog_isolated_working_revision_required'];

                    continue;
                }
                try {
                    $this->assertReview($article, $article->workingRevision);
                } catch (RuntimeException $error) {
                    $blockers[] = ['article_id' => $article->id, 'code' => $error->getMessage()];
                }
            }
        }

        return ['ok' => $blockers === [], 'readonly' => true, 'source_sha256' => self::SOURCE_SHA256,
            'state_sha256' => Hash::hash($state), 'target_count' => 8, 'new_revision_count' => 7,
            'verify_only_ids' => [10], 'targets' => $rows, 'publication_count' => 0,
            'review_attestation_created' => false, 'promotion_blockers' => $blockers];
    }

    /** Called only after command package-byte, operator, release and confirmation gates. */
    public function stage(array $package, string $expectedState, int $actor): array
    {
        return DB::transaction(function () use ($package, $expectedState, $actor): array {
            $before = $this->snapshot(true);
            $this->assertState($expectedState, $before);
            $this->validateCandidates($package, false);
            $newIds = [];
            foreach ($this->targets($package) as $target) {
                $article = $this->article($target['article_id']);
                if ($article->id === 10 || $article->working_revision_id !== $article->published_revision_id) {
                    continue;
                }
                $published = $article->publishedRevision;
                $candidate = $published->replicate();
                $candidate->forceFill(array_replace($target['after_fields'], [
                    'revision_number' => ArticleTranslationRevision::withoutGlobalScopes()->where('article_id', $article->id)->max('revision_number') + 1,
                    'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
                    'supersedes_revision_id' => $published->id,
                    'created_by' => $actor, 'reviewed_by' => null, 'reviewed_at' => null,
                    'approved_at' => null, 'published_at' => null,
                    'authority_asset_key' => null, 'authority_source_package' => 'blog-v1-b4',
                    'authority_source_hash' => self::SOURCE_SHA256, 'authority_package_sha256' => self::SOURCE_SHA256,
                    'authority_metadata_json' => [self::KEY => $this->candidateMetadata($article, $target)],
                ]));
                // The existing public/source/translation lineage is immutable during staging.
                // The candidate's own hash is recalculated only for canonical source articles.
                if ($article->isSourceArticle()) {
                    $candidate->source_version_hash = $this->workspace->hashForRevision($article, $target['after_fields']);
                }
                $this->headings->assertNoBodyH1((string) $candidate->content_md);
                $candidate->save();
                $article->forceFill(['working_revision_id' => $candidate->id])->saveQuietly();
                $newIds[(string) $article->id] = $candidate->id;
            }
            $after = $this->snapshot(true);
            foreach (self::IDS as $id) {
                $old = $before[(string) $id];
                $new = $after[(string) $id];
                if (isset($newIds[(string) $id])) {
                    $old['article']['working_revision_id'] = $newIds[(string) $id];
                    unset($old['article']['updated_at'], $new['article']['updated_at']);
                    $new['history'] = array_values(array_filter($new['history'], fn ($row) => $row['revision_id'] !== $newIds[(string) $id]));
                }
                if (Hash::hash($old) !== Hash::hash($new)) {
                    throw new RuntimeException('blog_stage_public_state_changed');
                }
            }
            $this->validateCandidates($package, false);
            $this->log('blog_v1_working_revisions_staged', $expectedState, $actor, ['new_revision_ids' => $newIds]);

            return ['ok' => true, 'readonly' => false, 'new_revision_ids' => $newIds,
                'state_sha256' => Hash::hash($after), 'publication_count' => 0, 'review_attestation_created' => false];
        });
    }

    /** Existing controlled-promotion command is the sole console entry for this transition. */
    public function promote(array $package, string $expectedState, int $actor): array
    {
        return DB::transaction(function () use ($package, $expectedState, $actor): array {
            $before = $this->snapshot(true);
            $this->assertState($expectedState, $before);
            $this->validateCandidates($package, true);
            $published = [];
            foreach ($this->targets($package) as $target) {
                $article = $this->article($target['article_id']);
                if ($article->id === 10) {
                    continue;
                }
                $this->publisher->promoteExistingWorkingRevision(
                    $article->id, $article->working_revision_id, $article->published_revision_id,
                    source: 'blog_v1_existing_article_promotion', dispatchFollowUp: false,
                    transactionGuard: function (Article $locked, ArticleTranslationRevision $revision) use ($target): void {
                        $this->assertReview($locked, $revision);
                        $locked->forceFill(['category_id' => $this->categoryFor($locked, $target)])->saveQuietly();
                    },
                );
                $fresh = $article->fresh(['publishedRevision', 'seoMeta']);
                if ($fresh->published_revision_id !== $article->working_revision_id
                    || $fresh->category_id !== $this->categoryFor($article, $target)
                    || $fresh->publishedRevision->content_md !== $target['after_fields']['content_md']) {
                    throw new RuntimeException('blog_promotion_readback_failed');
                }
                $old = $before[(string) $article->id];
                foreach (['id', 'org_id', 'slug', 'locale', 'translation_group_id', 'source_locale', 'source_article_id',
                    'translated_from_article_id', 'translated_from_version_hash', 'published_at', 'is_indexable', 'sitemap_eligible', 'llms_eligible'] as $field) {
                    if ($fresh->getAttributes()[$field] !== $old['article'][$field]) {
                        throw new RuntimeException('blog_promotion_protected_article_drift');
                    }
                }
                $seo = $fresh->seoMeta->getAttributes();
                foreach (['canonical_url', 'robots', 'is_indexable', 'schema_json'] as $field) {
                    if ($seo[$field] !== $old['seo'][$field]) {
                        throw new RuntimeException('blog_promotion_seo_hold_drift');
                    }
                }
                $published[(string) $article->id] = $fresh->published_revision_id;
            }
            $this->log('blog_v1_existing_revisions_promoted', $expectedState, $actor, ['published_revision_ids' => $published]);
            if (Hash::hash($before['10']) !== Hash::hash($this->snapshot(true)['10'])) {
                throw new RuntimeException('blog_verify_only_public_state_changed');
            }

            return ['ok' => true, 'readonly' => false, 'published_revision_ids' => $published,
                'publication_count' => 7, 'verify_only_ids' => [10], 'review_attestation_created' => false];
        });
    }

    public function surface(array $package, string $phase, bool $execute, string $expectedState, int $actor, string $packageSha = ''): array
    {
        if (($package['schema'] ?? null) !== 'blog_v1_landing_surfaces.v1'
            || ! is_array($package['surfaces'] ?? null) || count($package['surfaces']) !== 2) {
            throw new RuntimeException('blog_surface_package_invalid');
        }

        return DB::transaction(function () use ($package, $phase, $execute, $expectedState, $actor, $packageSha): array {
            $q = LandingSurface::withoutGlobalScopes()->where('org_id', 0)->where('surface_key', 'articles_index')->orderBy('locale')->orderBy('id');
            $rows = ($execute ? $q->lockForUpdate() : $q)->get();
            if ($rows->count() > 2 || $rows->pluck('locale')->unique()->count() !== $rows->count()) {
                throw new RuntimeException('blog_surface_identity_collision');
            }
            $state = ['surfaces' => $rows->map(function ($row) use ($execute): array {
                $blocks = $row->blocks();

                return ['surface' => $row->getAttributes(), 'blocks' => ($execute ? $blocks->lockForUpdate() : $blocks)->get()->map(fn ($block) => $block->getAttributes())->all()];
            })->all(), 'article_authority' => $this->snapshot($execute)];
            $beforeSha = Hash::hash($state);
            if ($execute) {
                $this->assertState($expectedState, $state);
            }
            $locales = [];
            foreach ($package['surfaces'] as $candidate) {
                $locale = $candidate['locale'] ?? null;
                $config = $candidate['blog_v1'] ?? null;
                $v = Validator::make(is_array($config) ? $config : [], [
                    'schema_version' => ['required', 'integer', Rule::in([1])],
                    'categories' => ['present', 'array', 'max:32'],
                    'categories.*.slug' => ['required', 'string', 'max:127', 'distinct', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
                    'categories.*.line_key' => ['required', Rule::in(ArticleBlogService::LINE_KEYS)],
                    'categories.*.name' => ['required', 'string', 'max:255'],
                    'categories.*.description' => ['required', 'string', 'max:4000'],
                    'featured_article_ids' => ['present', 'array', 'max:12'],
                    'featured_article_ids.*' => ['required', 'integer', 'distinct', Rule::in(self::IDS)],
                ]);
                if (! in_array($locale, ['en', 'zh-CN'], true) || in_array($locale, $locales, true)
                    || array_diff(array_keys($candidate), ['locale', 'title', 'description', 'blog_v1']) !== []
                    || ! is_string($candidate['title'] ?? null) || trim($candidate['title']) === '' || mb_strlen($candidate['title']) > 255
                    || ! is_string($candidate['description'] ?? null) || trim($candidate['description']) === '' || mb_strlen($candidate['description']) > 4000
                    || $v->fails() || ! array_is_list($config['categories']) || ! array_is_list($config['featured_article_ids'])) {
                    throw new RuntimeException('blog_surface_candidate_invalid');
                }
                $locales[] = $locale;
                foreach ($config['categories'] as $category) {
                    if (! ArticleCategory::withoutGlobalScopes()->where('org_id', 0)->where('slug', $category['slug'])->where('is_active', true)->exists()) {
                        throw new RuntimeException('blog_surface_category_invalid');
                    }
                }
                foreach ($config['featured_article_ids'] as $id) {
                    if (! Article::withoutGlobalScopes()->where('org_id', 0)->where('locale', $locale)->whereKey($id)->publiclyReadable()
                        ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
                        ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))->exists()) {
                        throw new RuntimeException('blog_surface_featured_locale_invalid');
                    }
                }
                $surface = $rows->firstWhere('locale', $locale);
                if ($surface && ($surface->status !== 'draft' || $surface->is_public || $surface->is_indexable || $surface->blocks()->exists()
                    || array_diff(array_keys($surface->payload_json ?? []), ['blog_v1']) !== [])) {
                    throw new RuntimeException('blog_surface_existing_authority_collision');
                }
                if ($phase === 'surface-publish' && (! $surface || $surface->title !== $candidate['title']
                    || $surface->description !== $candidate['description'] || Hash::hash($surface->payload_json['blog_v1'] ?? null) !== Hash::hash($config))) {
                    throw new RuntimeException('blog_surface_preview_candidate_drift');
                }
                if (! $execute) {
                    continue;
                }
                $surface ??= new LandingSurface(['org_id' => 0, 'surface_key' => 'articles_index', 'locale' => $locale]);
                $publish = $phase === 'surface-publish';
                $surface->forceFill(['title' => $candidate['title'], 'description' => $candidate['description'], 'schema_version' => 'v1',
                    'payload_json' => ['blog_v1' => $config], 'status' => $publish ? 'published' : 'draft',
                    'is_public' => $publish, 'is_indexable' => false, 'published_at' => $publish ? now() : null, 'scheduled_at' => null])->save();
                $fresh = $surface->fresh();
                if ($fresh->is_public !== $publish || $fresh->status !== ($publish ? 'published' : 'draft')
                    || Hash::hash($fresh->payload_json['blog_v1'] ?? null) !== Hash::hash($config)) {
                    throw new RuntimeException('blog_surface_readback_failed');
                }
                if ($publish) {
                    event(new PublicAuthorityChanged('landing_surface', (string) $surface->id, $locale, Hash::hash($surface->getAttributes()), 'publish'));
                }
            }
            if ($execute) {
                $this->log('blog_v1_'.$phase, $beforeSha, $actor, ['surface_locales' => $locales, 'surface_package_sha256' => $packageSha]);
            }

            return ['ok' => true, 'readonly' => ! $execute, 'state_sha256' => $beforeSha, 'surface_count' => 2,
                'publication_count' => $execute && $phase === 'surface-publish' ? 2 : 0, 'review_attestation_created' => false];
        });
    }

    private function validateCandidates(array $package, bool $promotion): array
    {
        $rows = [];
        foreach ($this->targets($package) as $target) {
            $article = $this->article($target['article_id']);
            $old = $article->publishedRevision;
            if ($article->org_id !== 0 || ! Article::withoutGlobalScopes()->whereKey($article->id)->publiclyReadable()->exists()
                || $article->published_at?->isFuture() || $article->scheduled_at?->isFuture()
                || $article->slug !== $target['slug'] || $article->locale !== $target['locale']
                || $article->published_revision_id !== $target['base_published_revision_id']
                || $article->category_id !== ($target['classification_change']['before'] ?? null)
                || ! $old instanceof ArticleTranslationRevision || ! $article->seoMeta
                || $article->seoMeta->canonical_url !== $target['canonical_url']) {
                throw new RuntimeException('blog_target_identity_drift');
            }
            foreach (self::FIELDS as $field) {
                if ($target['before_fields'][$field] !== $old->$field) {
                    throw new RuntimeException('blog_published_field_drift');
                }
                if (! in_array($field, ['excerpt', 'content_md'], true) && $target['after_fields'][$field] !== $target['before_fields'][$field]) {
                    throw new RuntimeException('blog_protected_candidate_field_changed');
                }
            }
            $category = $this->categoryFor($article, $target);
            if ($category !== null && ! ArticleCategory::withoutGlobalScopes()->where('org_id', 0)->whereKey($category)->where('is_active', true)->exists()) {
                throw new RuntimeException('blog_candidate_category_invalid');
            }
            $candidate = $article->workingRevision;
            if ($article->id === 10) {
                if ($article->working_revision_id !== $article->published_revision_id || $target['after_fields'] !== $target['before_fields']) {
                    throw new RuntimeException('blog_verify_only_changed');
                }
            } elseif ($article->working_revision_id !== $article->published_revision_id) {
                if (! $candidate instanceof ArticleTranslationRevision || $candidate->org_id !== 0 || $candidate->article_id !== $article->id
                    || $candidate->locale !== $article->locale || $candidate->translation_group_id !== $article->translation_group_id
                    || Hash::hash($candidate->authority_metadata_json[self::KEY] ?? null) !== Hash::hash($this->candidateMetadata($article, $target))) {
                    throw new RuntimeException('blog_working_revision_collision');
                }
                foreach (self::FIELDS as $field) {
                    if ($target['after_fields'][$field] !== $candidate->$field) {
                        throw new RuntimeException('blog_working_candidate_drift');
                    }
                }
                if ($promotion) {
                    $this->assertReview($article, $candidate);
                }
            } elseif ($promotion) {
                throw new RuntimeException('blog_isolated_working_revision_required');
            }
            $this->headings->assertNoBodyH1($target['after_fields']['content_md']);
            if (preg_match('~/(?:results?|orders?|payments?|pay|share|history|private)(?:/|[?#\s)"\x27]|$)|/(?:en|zh)/tests/[^/\s]+/take(?:/|[?#\s)"\x27]|$)|[?&](?:token|access_token|result_access_token|result_id|order_id|payment_id|report_id|session_id)=~i', implode("\n", $target['after_fields'])) === 1) {
                throw new RuntimeException('blog_private_reader_reference_forbidden');
            }
            $rows[] = ['article_id' => $article->id, 'locale' => $article->locale,
                'working_revision_id' => $article->working_revision_id, 'published_revision_id' => $article->published_revision_id,
                'candidate_category_id' => $category, 'verify_only' => $article->id === 10,
                'preview_path' => '/ops/article-preview/'.$article->id];
        }

        return $rows;
    }

    private function targets(array $package): array
    {
        $targets = $package['articles'] ?? null;
        if (! is_array($targets) || ! array_is_list($targets) || count($targets) !== 8) {
            throw new RuntimeException('blog_fixed_cohort_required');
        }
        usort($targets, fn ($a, $b) => ($a['article_id'] ?? 0) <=> ($b['article_id'] ?? 0));
        if (array_column($targets, 'article_id') !== self::IDS) {
            throw new RuntimeException('blog_fixed_cohort_required');
        }
        foreach ($targets as $target) {
            foreach (['before_fields', 'after_fields'] as $key) {
                if (! is_array($target[$key] ?? null) || array_diff(array_keys($target[$key]), self::FIELDS) !== [] || count($target[$key]) !== count(self::FIELDS)) {
                    throw new RuntimeException('blog_candidate_fields_invalid');
                }
                foreach ($target[$key] as $value) {
                    if (! is_string($value)) {
                        throw new RuntimeException('blog_candidate_fields_invalid');
                    }
                }
            }
        }

        return $targets;
    }

    private function article(int $id): Article
    {
        return Article::withoutGlobalScopes()->with(['workingRevision', 'publishedRevision', 'seoMeta'])->findOrFail($id);
    }

    private function categoryFor(Article $article, array $target): ?int
    {
        return self::CATEGORY_CHANGES[$article->id] ?? ($target['classification_change']['before'] ?? null);
    }

    private function candidateMetadata(Article $article, array $target): array
    {
        return ['source_sha256' => self::SOURCE_SHA256, 'article_id' => $article->id,
            'base_published_revision_id' => $target['base_published_revision_id'],
            'candidate_fields_sha256' => Hash::hash($target['after_fields']),
            'category_id' => $this->categoryFor($article, $target), 'human_review_required' => true];
    }

    private function assertReview(Article $article, ArticleTranslationRevision $candidate): void
    {
        if ($candidate->revision_status !== 'approved' || ! $candidate->reviewed_by || ! $candidate->reviewed_at || ! $candidate->approved_at
            || ! $this->reviews->isConfiguredSoloOwner((int) $candidate->reviewed_by)
            || ! $this->reviews->hasApprovedEvidence('article', $article)
            || ! $this->reviews->hasApprovedEvidence('article_translation_revision', $candidate)
            || (! $article->isSourceArticle() && ! $this->workflow->preflight($article)['ok'])
            || ! $this->completeness->inspect($article->locale, $candidate->content_md, $candidate->only(self::FIELDS))['ok']) {
            throw new RuntimeException('blog_actual_revision_review_or_preflight_missing');
        }
    }

    private function assertState(string $expected, array $state): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $expected) !== 1 || ! hash_equals($expected, Hash::hash($state))) {
            throw new RuntimeException('blog_complete_state_drift');
        }
    }

    private function log(string $action, string $before, int $actor, array $extra): void
    {
        $this->audit->log(Request::create('/console/articles/blog-v1', 'POST'), $action, 'blog_v1', 'fixed_eight',
            ['source_sha256' => self::SOURCE_SHA256, 'before_state_sha256' => $before,
                'authorized_operator_id' => $actor, 'editorial_attestation_created' => false, ...$extra],
            'Explicit fixed-cohort native blog operation');
    }
}
