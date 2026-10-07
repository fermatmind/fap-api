<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Console\Commands\UpdateArticlePublicReaderMetadata;
use App\Events\PublicAuthorityChanged;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Models\LandingSurface;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\EditorialPackage\EditorialPackageDraftImporter;
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

    public const SURFACE_ORIGINAL_PACKAGE = '0678e1d8e0e7929e853547a9a6a77a754100543d02bc7a52f9b360af041ebcfb';

    public const SURFACE_DESCRIPTION_PACKAGE = '7d28b163da11d9a924ceae9ebc254bfcf50472e54a32ee076a3d37b2aca7a543';

    private const SURFACE_DESCRIPTION_DIFF = [
        'en' => ['FermatMind Blog starts with real questions about personality and interests, learning and career choices, communication and growth, and what research can support. As you read, look for the evidence, its limits and a next step relevant to your question.',
            'Clear explanations, practical examples, and evidence-informed guidance on personality, careers, communication, and growth—to help you understand assessment results, compare options, and decide what to try next.'],
        'zh-CN' => ['费马博客从真实问题出发，讨论人格与兴趣、学习和职业选择、沟通与成长，以及研究能支持什么。读文章时，留意依据、适用边界和与你当前问题有关的下一步。',
            '围绕人格、职业、沟通与成长，用清晰的解释、具体案例和有依据的建议，帮助你读懂测评结果、比较选择，并找到适合自己的下一步。'],
    ];

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
        private readonly EditorialPackageDraftImporter $claimPolicy,
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
            $imports = ArticleEditorialPackageImport::withoutGlobalScopes()->where('article_id', $article->id)->orderBy('id');
            $row['imports'] = ($lock ? $imports->lockForUpdate() : $imports)->get()->map(fn ($item) => $item->getAttributes())->all();
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

    public function plan(array $package, bool $promotion = false, string $claimAcknowledgement = ''): array
    {
        $state = $this->snapshot();
        $rows = $this->validateCandidates($package, false);
        $blockers = [];
        $claims = [];
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
                    $claims[] = $this->claimRecord($article, $article->workingRevision);
                } catch (RuntimeException $error) {
                    $blockers[] = ['article_id' => $article->id, 'code' => $error->getMessage()];
                }
            }
        }
        $requiredAck = $this->claimAcknowledgement($claims);
        if ($requiredAck !== null && ! hash_equals($requiredAck, $claimAcknowledgement)) {
            $blockers[] = ['article_id' => null, 'code' => 'blog_claim_warning_ack_required'];
        }

        return ['ok' => $blockers === [], 'readonly' => true, 'source_sha256' => self::SOURCE_SHA256,
            'state_sha256' => Hash::hash($state), 'target_count' => 8, 'new_revision_count' => 7,
            'verify_only_ids' => [10], 'targets' => $rows, 'publication_count' => 0,
            'review_attestation_created' => false, 'promotion_blockers' => $blockers,
            'claim_records' => $claims, 'required_claim_acknowledgement' => $requiredAck];
    }

    /** Called only after command package-byte, operator, release and confirmation gates. */
    public function stage(array $package, string $expectedState, int $actor): array
    {
        return DB::transaction(function () use ($package, $expectedState, $actor): array {
            $before = $this->snapshot(true);
            $this->assertState($expectedState, $before);
            $this->validateCandidates($package, false);
            $newIds = [];
            $newImportIds = [];
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
                $import = ArticleEditorialPackageImport::withoutGlobalScopes()->create([
                    'org_id' => 0, 'article_id' => $article->id, 'slug' => $article->slug, 'locale' => $article->locale,
                    'title' => $candidate->title, 'content_track' => 'blog-v1-b4', 'status' => 'imported',
                    'intended_status' => 'working_revision_human_review', 'imported_by' => $actor,
                    'body_hash' => hash('sha256', $candidate->content_md),
                    'validation_summary_json' => $this->claimBinding($article, $candidate),
                    'claim_result_json' => $this->claimPolicy->inspectCandidateClaims($candidate->only(self::FIELDS)),
                ]);
                $newImportIds[(string) $article->id] = $import->id;
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
                    $new['imports'] = array_values(array_filter($new['imports'], fn ($row) => $row['id'] !== $newImportIds[(string) $id]));
                }
                if (Hash::hash($old) !== Hash::hash($new)) {
                    throw new RuntimeException('blog_stage_public_state_changed');
                }
            }
            $this->validateCandidates($package, false);
            $this->log('blog_v1_working_revisions_staged', $expectedState, $actor, ['new_revision_ids' => $newIds]);

            return ['ok' => true, 'readonly' => false, 'new_revision_ids' => $newIds, 'new_claim_import_ids' => $newImportIds,
                'state_sha256' => Hash::hash($after), 'publication_count' => 0, 'review_attestation_created' => false];
        });
    }

    /** Existing controlled-promotion command is the sole console entry for this transition. */
    public function promote(array $package, string $expectedState, int $actor, string $claimAcknowledgement = ''): array
    {
        return DB::transaction(function () use ($package, $expectedState, $actor, $claimAcknowledgement): array {
            $before = $this->snapshot(true);
            $this->assertState($expectedState, $before);
            $this->validateCandidates($package, true);
            $claims = [];
            foreach (array_diff(self::IDS, [10]) as $id) {
                $article = $this->article($id);
                $claims[$id] = $this->claimRecord($article, $article->workingRevision);
            }
            $requiredAck = $this->claimAcknowledgement(array_values($claims));
            if ($requiredAck !== null && ! hash_equals($requiredAck, $claimAcknowledgement)) {
                throw new RuntimeException('blog_claim_warning_ack_required');
            }
            $published = [];
            foreach ($this->targets($package) as $target) {
                $article = $this->article($target['article_id']);
                if ($article->id === 10) {
                    continue;
                }
                $this->publisher->promoteExistingWorkingRevision(
                    $article->id, $article->working_revision_id, $article->published_revision_id,
                    source: 'blog_v1_existing_article_promotion', dispatchFollowUp: false,
                    transactionGuard: function (Article $locked, ArticleTranslationRevision $revision) use ($target, $claims): void {
                        $this->assertReview($locked, $revision);
                        if (Hash::hash($claims[$locked->id]) !== Hash::hash($this->claimRecord($locked, $revision))) {
                            throw new RuntimeException('blog_claim_result_drift');
                        }
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
            $this->log('blog_v1_existing_revisions_promoted', $expectedState, $actor, ['published_revision_ids' => $published,
                'claim_records_sha256' => Hash::hash(array_values($claims)), 'claim_acknowledgement' => $requiredAck]);
            if (Hash::hash($before['10']) !== Hash::hash($this->snapshot(true)['10'])) {
                throw new RuntimeException('blog_verify_only_public_state_changed');
            }

            return ['ok' => true, 'readonly' => false, 'published_revision_ids' => $published,
                'publication_count' => 7, 'verify_only_ids' => [10], 'review_attestation_created' => false];
        });
    }

    public function surface(array $package, string $phase, bool $execute, string $expectedState, int $actor, string $packageSha = ''): array
    {

        if ($phase === 'surface-revise-description') {
            return $this->reviseSurfaceDescription($package, $execute, $expectedState, $actor, $packageSha);
        }
        if (($package['schema'] ?? null) !== 'blog_v1_landing_surfaces.v1'
            || ! is_array($package['surfaces'] ?? null) || count($package['surfaces']) !== 2
            || ! in_array($phase, ['surface-plan', 'surface-stage', 'surface-publish'], true)
            || ($execute && $phase === 'surface-plan')
            || preg_match('/\A[a-f0-9]{64}\z/', $packageSha) !== 1) {
            throw new RuntimeException('blog_surface_package_invalid');
        }

        return DB::transaction(function () use ($package, $phase, $execute, $expectedState, $actor, $packageSha): array {
            $q = LandingSurface::withoutGlobalScopes()->where('org_id', 0)->where('surface_key', 'articles_index')
                ->whereIn('locale', ['en', 'zh-CN'])->orderBy('locale')->orderBy('id');
            $rows = ($execute ? $q->lockForUpdate() : $q)->get();
            if ($rows->count() > 2 || $rows->pluck('locale')->unique()->count() !== $rows->count()) {
                throw new RuntimeException('blog_surface_identity_collision');
            }
            $auditQuery = AuditLog::withoutGlobalScopes()->where('org_id', 0)->where('target_type', 'blog_v1')
                ->where('target_id', 'fixed_eight')->whereIn('action', ['blog_v1_surface-stage', 'blog_v1_surface-revise-description'])->orderBy('id');
            $proofs = ($execute ? $auditQuery->lockForUpdate() : $auditQuery)->get();
            $state = ['surfaces' => $rows->map(function ($row) use ($execute): array {
                $blocks = $row->blocks();

                return ['surface' => $row->getAttributes(), 'blocks' => ($execute ? $blocks->lockForUpdate() : $blocks)->get()->map(fn ($block) => $block->getAttributes())->all()];
            })->all(), 'surface_provenance' => $proofs->map(fn ($proof) => $proof->getAttributes())->all(),
                'article_authority' => $this->snapshot($execute)];
            $beforeSha = Hash::hash($state);
            if ($execute) {
                $this->assertState($expectedState, $state);
            }
            $locales = [];
            $records = [];
            $newIds = [];
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
                if ($surface) {
                    $owner = $this->assertSurfaceProvenance($surface, $candidate, $packageSha, $proofs->all(), $execute ? $actor : null);
                }
                if (! $execute) {
                    $records[] = $surface ? $this->surfaceRecord($surface, $candidate, $packageSha, $owner) : ['id' => null, 'org_id' => 0, 'surface_key' => 'articles_index', 'locale' => $locale];

                    continue;
                }
                if (! $this->reviews->isConfiguredSoloOwner($actor)) {
                    throw new RuntimeException('blog_surface_configured_owner_required');
                }
                if (! $surface && $rows->isNotEmpty()) {
                    throw new RuntimeException('blog_surface_existing_authority_collision');
                }
                if ($surface && $phase === 'surface-stage') {
                    // Repeat staging proves ownership and state, then performs zero row writes.
                    $records[] = $this->surfaceRecord($surface, $candidate, $packageSha, $actor);

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
                $records[] = $this->surfaceRecord($fresh, $candidate, $packageSha, $actor);
                if (! $publish) {
                    $newIds[] = $fresh->id;
                }
            }
            if ($execute) {
                $this->log('blog_v1_'.$phase, $beforeSha, $actor, ['surface_locales' => $locales,
                    'surface_package_sha256' => $packageSha, 'surface_records' => $records, 'new_surface_ids' => $newIds]);
                if ($phase === 'surface-stage') {
                    $written = $auditQuery->get()->last();
                    if (! $written || ! $this->surfaceRecordsMatch($written, $records)
                        || ($written->meta_json['surface_package_sha256'] ?? null) !== $packageSha) {
                        throw new RuntimeException('blog_surface_provenance_readback_failed');
                    }
                }
            }

            return ['ok' => true, 'readonly' => ! $execute, 'state_sha256' => $beforeSha, 'surface_count' => 2,
                'surface_records' => $records, 'new_surface_ids' => $newIds, 'surface_package_sha256' => $packageSha,
                'publication_count' => $execute && $phase === 'surface-publish' ? 2 : 0, 'review_attestation_created' => false];
        });
    }

    /** Exact private copy revision; preserves the original stage audit and every other CMS field. */
    private function reviseSurfaceDescription(array $package, bool $execute, string $expectedState, int $actor, string $packageSha): array
    {
        if ($packageSha !== self::SURFACE_DESCRIPTION_PACKAGE || ($package['schema'] ?? null) !== 'blog_v1_landing_surfaces.v1'
            || ! is_array($package['surfaces'] ?? null) || ! array_is_list($package['surfaces']) || count($package['surfaces']) !== 2
            || array_keys($package) !== ['schema', 'surfaces']) {
            throw new RuntimeException('blog_surface_description_package_drift');
        }

        return DB::transaction(function () use ($package, $execute, $expectedState, $actor, $packageSha): array {
            $q = LandingSurface::withoutGlobalScopes()->where('org_id', 0)->where('surface_key', 'articles_index')->orderBy('locale');
            $rows = ($execute ? $q->lockForUpdate() : $q)->get();
            $a = AuditLog::withoutGlobalScopes()->where('org_id', 0)->where('target_type', 'blog_v1')
                ->where('target_id', 'fixed_eight')->whereIn('action', ['blog_v1_surface-stage', 'blog_v1_surface-revise-description'])->orderBy('id');
            $proofs = ($execute ? $a->lockForUpdate() : $a)->get();
            $parent = $proofs->firstWhere('id', 1473);
            if ($rows->pluck('id')->all() !== [20, 21] || $rows->pluck('locale')->all() !== ['en', 'zh-CN']
                || ! $parent || $parent->action !== 'blog_v1_surface-stage' || $parent->result !== 'success'
                || ($parent->meta_json['surface_package_sha256'] ?? null) !== self::SURFACE_ORIGINAL_PACKAGE
                || $proofs->contains('action', 'blog_v1_surface-revise-description')) {
                throw new RuntimeException('blog_surface_description_parent_drift');
            }
            $state = ['surfaces' => $rows->map(function ($row) use ($execute): array {
                $blocks = $row->blocks();

                return ['surface' => $row->getAttributes(), 'blocks' => ($execute ? $blocks->lockForUpdate() : $blocks)->get()->map(fn ($block) => $block->getAttributes())->all()];
            })->all(), 'surface_provenance' => $proofs->map(fn ($proof) => $proof->getAttributes())->all(), 'article_authority' => $this->snapshot($execute)];
            if ($execute) {
                $this->assertState($expectedState, $state);
            }
            $previousRows = $rows->map(fn ($row) => $row->getAttributes())->all();
            $previousRecords = [];
            foreach ($rows as $index => $row) {
                $candidate = $package['surfaces'][$index];
                $original = ['locale' => $row->locale, 'title' => $row->title, 'description' => $row->description, 'blog_v1' => $row->payload_json['blog_v1'] ?? null];
                if (! Hash::sameValue($candidate, [...$original, 'description' => self::SURFACE_DESCRIPTION_DIFF[$row->locale][1]])
                    || $row->description !== self::SURFACE_DESCRIPTION_DIFF[$row->locale][0]
                    || $row->status !== 'draft' || $row->is_public || $row->is_indexable || $row->blocks()->exists()
                    || $row->published_at !== null || $row->scheduled_at !== null || $row->schema_version !== 'v1') {
                    throw new RuntimeException('blog_surface_description_only_required');
                }
                $owner = $this->assertSurfaceProvenance($row, $original, self::SURFACE_ORIGINAL_PACKAGE, [$parent], $execute ? $actor : null);
                if ($owner !== 1) {
                    throw new RuntimeException('blog_surface_description_owner_drift');
                }
                $previousRecords[] = $this->surfaceRecord($row, $original, self::SURFACE_ORIGINAL_PACKAGE, $owner);
            }
            $records = [];
            if ($execute) {
                foreach ($rows as $index => $row) {
                    $row->forceFill(['description' => $package['surfaces'][$index]['description']])->save();
                    $records[] = $this->surfaceRecord($row->fresh(), $package['surfaces'][$index], $packageSha, $actor);
                }
                $this->log('blog_v1_surface-revise-description', Hash::hash($state), $actor, [
                    'surface_locales' => ['en', 'zh-CN'], 'surface_package_sha256' => $packageSha,
                    'surface_records' => $records, 'new_surface_ids' => [],
                    'parent_audit_id' => 1473, 'parent_audit_meta_sha256' => Hash::hash($parent->meta_json),
                    'previous_surface_package_sha256' => self::SURFACE_ORIGINAL_PACKAGE,
                    'previous_surface_records' => $previousRecords, 'previous_surface_rows' => $previousRows,
                    'revised_surface_rows' => $rows->map(fn ($row) => $row->fresh()->getAttributes())->all(),
                ]);
                $written = $a->get()->last();
                if (! $written || ! $this->surfaceRecordsMatch($written, $records)
                    || ! $this->descriptionRevisionProofValid($written, [$parent])
                    || Hash::hash($this->snapshot()) !== Hash::hash($state['article_authority'])) {
                    throw new RuntimeException('blog_surface_description_readback_failed');
                }
            }

            return ['ok' => true, 'readonly' => ! $execute, 'state_sha256' => Hash::hash($state),
                'surface_count' => 2, 'surface_records' => $records, 'surface_package_sha256' => $packageSha,
                'parent_audit_id' => 1473, 'previous_surface_package_sha256' => self::SURFACE_ORIGINAL_PACKAGE,
                'description_revision_count' => $execute ? 2 : 0, 'publication_count' => 0, 'review_attestation_created' => false];
        });
    }

    /** Verify the appended proof against the immutable parent and exact description-only row delta. */
    private function descriptionRevisionProofValid(AuditLog $proof, array $proofs): bool
    {
        $m = $proof->meta_json;
        $parent = collect($proofs)->firstWhere('id', 1473);
        $records = $this->surfaceAuditRecords($proof);
        if (! $parent || $parent->action !== 'blog_v1_surface-stage' || $parent->result !== 'success'
            || $proof->result !== 'success' || $proof->action !== 'blog_v1_surface-revise-description'
            || ($m['parent_audit_id'] ?? null) !== 1473 || ($m['authorized_operator_id'] ?? null) !== 1
            || ($m['source_sha256'] ?? null) !== self::SOURCE_SHA256
            || ($m['surface_package_sha256'] ?? null) !== self::SURFACE_DESCRIPTION_PACKAGE
            || ($m['previous_surface_package_sha256'] ?? null) !== self::SURFACE_ORIGINAL_PACKAGE
            || ($parent->meta_json['surface_package_sha256'] ?? null) !== self::SURFACE_ORIGINAL_PACKAGE
            || ($parent->meta_json['source_sha256'] ?? null) !== self::SOURCE_SHA256
            || ($parent->meta_json['authorized_operator_id'] ?? null) !== 1
            || ($m['parent_audit_meta_sha256'] ?? null) !== Hash::hash($parent->meta_json)
            || ! Hash::sameValue($m['previous_surface_records'] ?? null, $this->surfaceAuditRecords($parent))
            || ! is_array($records) || array_column($records, 'id') !== [20, 21]) {
            return false;
        }
        foreach (['previous_surface_rows', 'revised_surface_rows'] as $key) {
            if (! is_array($m[$key] ?? null) || ! array_is_list($m[$key]) || count($m[$key]) !== 2) {
                return false;
            }
        }
        foreach (['en', 'zh-CN'] as $index => $locale) {
            $old = $m['previous_surface_rows'][$index];
            $new = $m['revised_surface_rows'][$index];
            if (! is_array($old) || ! is_array($new) || ($old['id'] ?? null) !== 20 + $index || ($new['id'] ?? null) !== 20 + $index
                || ($old['org_id'] ?? null) !== 0 || ($old['surface_key'] ?? null) !== 'articles_index' || ($old['locale'] ?? null) !== $locale
                || ($old['description'] ?? null) !== self::SURFACE_DESCRIPTION_DIFF[$locale][0]
                || ($new['description'] ?? null) !== self::SURFACE_DESCRIPTION_DIFF[$locale][1]
                || ($m['previous_surface_records'][$index]['surface_state_sha256'] ?? null) !== Hash::hash($old)
                || ($records[$index]['surface_state_sha256'] ?? null) !== Hash::hash($new)) {
                return false;
            }
            unset($old['description'], $old['updated_at'], $new['description'], $new['updated_at']);
            if (! Hash::sameValue($old, $new)) {
                return false;
            }
        }

        return true;
    }

    public function surfacePreviewBinding(LandingSurface $surface): array
    {
        $candidate = ['locale' => $surface->locale, 'title' => $surface->title,
            'description' => $surface->description, 'blog_v1' => $surface->payload_json['blog_v1'] ?? null];
        $proofs = AuditLog::withoutGlobalScopes()->where('org_id', 0)->where('target_type', 'blog_v1')->where('target_id', 'fixed_eight')
            ->whereIn('action', ['blog_v1_surface-stage', 'blog_v1_surface-revise-description'])->orderBy('id')->get()->all();
        foreach (array_reverse($proofs) as $proof) {
            $package = $proof->meta_json['surface_package_sha256'] ?? '';
            if (! is_string($package) || preg_match('/\A[a-f0-9]{64}\z/', $package) !== 1) {
                continue;
            }
            try {
                $owner = $this->assertSurfaceProvenance($surface, $candidate, $package, $proofs, null);

                return ['source_sha256' => self::SOURCE_SHA256, 'package_sha256' => $package,
                    'candidate_sha256' => Hash::hash($candidate), 'surface_state_sha256' => Hash::hash($surface->getAttributes()),
                    'owner_admin_user_id' => $owner];
            } catch (RuntimeException) {
                continue;
            }
        }
        throw new RuntimeException('blog_surface_task_provenance_required');
    }

    private function surfaceRecord(LandingSurface $surface, array $candidate, string $packageSha, ?int $owner = null): array
    {
        return ['id' => $surface->id, 'org_id' => $surface->org_id, 'surface_key' => $surface->surface_key,
            'locale' => $surface->locale, 'owner_admin_user_id' => $owner,
            'source_sha256' => self::SOURCE_SHA256, 'surface_package_sha256' => $packageSha,
            'candidate_sha256' => Hash::hash($candidate), 'surface_state_sha256' => Hash::hash($surface->getAttributes())];
    }

    private function surfaceRecordsMatch(AuditLog $audit, array $expected): bool
    {
        $records = $this->surfaceAuditRecords($audit);

        return $records !== null && Hash::sameValue($records, $expected);
    }

    /** The array cast alone loses the distinction between a JSON list and a numeric-key object. */
    private function surfaceAuditRecords(AuditLog $audit): ?array
    {
        $raw = $audit->getRawOriginal('meta_json');
        if (! is_string($raw)) {
            return null;
        }
        $json = json_decode($raw);
        $records = $json instanceof \stdClass ? ($json->surface_records ?? null) : null;
        if (! is_array($records) || count($records) !== 2
            || ! array_is_list($records) || count(array_filter($records, static fn ($record): bool => $record instanceof \stdClass)) !== 2) {
            return null;
        }

        $castRecords = $audit->meta_json['surface_records'] ?? null;
        if (! is_array($castRecords) || ($audit->meta_json['surface_locales'] ?? null) !== array_column($castRecords, 'locale')) {
            return null;
        }

        return $castRecords;
    }

    private function assertSurfaceProvenance(LandingSurface $surface, array $candidate, string $packageSha, array $proofs, ?int $actor): int
    {
        foreach (array_reverse($proofs) as $proof) {
            if ($packageSha === self::SURFACE_DESCRIPTION_PACKAGE && $proof->action !== 'blog_v1_surface-revise-description') {
                continue;
            }
            $meta = $proof->meta_json;
            $owner = $meta['authorized_operator_id'] ?? null;
            if (! is_int($owner) || $proof->result !== 'success' || ($meta['source_sha256'] ?? null) !== self::SOURCE_SHA256
                || ($meta['surface_package_sha256'] ?? null) !== $packageSha
                || ! $this->reviews->isConfiguredSoloOwner($owner) || ($actor !== null && $actor !== $owner)) {
                continue;
            }
            if ($proof->action === 'blog_v1_surface-revise-description' && ! $this->descriptionRevisionProofValid($proof, $proofs)) {
                continue;
            }
            $records = $this->surfaceAuditRecords($proof);
            if (! is_array($records) || count($records) !== 2
                || collect($records)->pluck('locale')->sort()->values()->all() !== ['en', 'zh-CN']) {
                continue;
            }
            $record = collect($records)->firstWhere('id', $surface->id);
            if (is_array($record) && Hash::sameValue($record, $this->surfaceRecord($surface, $candidate, $packageSha, $owner))) {
                return $owner;
            }
        }
        throw new RuntimeException('blog_surface_task_provenance_required');
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

    private function claimBinding(Article $article, ArticleTranslationRevision $candidate): array
    {
        return ['source_sha256' => self::SOURCE_SHA256, 'org_id' => $article->org_id,
            'article_id' => $article->id, 'slug' => $article->slug, 'locale' => $article->locale,
            'working_revision_id' => $candidate->id, 'created_by' => $candidate->created_by,
            'candidate_fields_sha256' => Hash::hash($candidate->only(self::FIELDS)),
            'body_sha256' => hash('sha256', $candidate->content_md)];
    }

    private function claimRecord(Article $article, ArticleTranslationRevision $candidate): array
    {
        $binding = $this->claimBinding($article, $candidate);
        $records = ArticleEditorialPackageImport::withoutGlobalScopes()->where('org_id', 0)
            ->where('article_id', $article->id)->where('content_track', 'blog-v1-b4')
            ->lockForUpdate()->get()->filter(fn ($record) => (int) ($record->validation_summary_json['working_revision_id'] ?? 0) === $candidate->id);
        if ($records->count() !== 1) {
            throw new RuntimeException('blog_fresh_candidate_claim_required');
        }
        $record = $records->first();
        if ($record->status !== 'imported' || $record->slug !== $article->slug || $record->locale !== $article->locale
            || (int) $record->imported_by !== (int) $candidate->created_by
            || $record->body_hash !== $binding['body_sha256']
            || Hash::hash($record->validation_summary_json) !== Hash::hash($binding)) {
            throw new RuntimeException('blog_candidate_claim_binding_drift');
        }
        $result = $record->claim_result_json;
        if (($result['status'] ?? null) === 'blocked') {
            throw new RuntimeException('blog_claim_blocked');
        }
        if (($result['status'] ?? null) === 'warning'
            && (! is_array($result['matches'] ?? null) || $result['matches'] === []
                || ! collect($result['matches'])->every(fn ($match) => is_array($match) && ($match['boundary_context'] ?? null) === true))) {
            throw new RuntimeException('blog_claim_warning_not_boundary_context');
        }
        if (! in_array($result['status'] ?? null, ['passed', 'warning'], true)
            || Hash::hash($result) !== Hash::hash($this->claimPolicy->inspectCandidateClaims($candidate->only(self::FIELDS)))) {
            throw new RuntimeException('blog_claim_result_drift');
        }

        return [...$binding, 'claim_import_id' => $record->id, 'claim_status' => $result['status'],
            'claim_result_sha256' => Hash::hash($result)];
    }

    private function claimAcknowledgement(array $claims): ?string
    {
        usort($claims, fn ($a, $b) => $a['article_id'] <=> $b['article_id']);

        return collect($claims)->contains(fn ($claim) => $claim['claim_status'] === 'warning')
            ? 'blog-v1-claims:'.Hash::hash($claims) : null;
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
