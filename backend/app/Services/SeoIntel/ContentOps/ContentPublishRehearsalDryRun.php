<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\ContentOps;

use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;

final class ContentPublishRehearsalDryRun
{
    public const RUNTIME = 'content_publish_rehearsal';

    /**
     * @param  list<int>  $articleIds
     * @param  list<int>  $acknowledgedClaimWarningArticleIds
     * @return array<string, mixed>
     */
    public function report(array $articleIds = [], array $acknowledgedClaimWarningArticleIds = [], bool $makeIndexable = false): array
    {
        $articleIds = $this->positiveUniqueIntegers($articleIds);
        $acknowledgedClaimWarningArticleIds = $this->positiveUniqueIntegers($acknowledgedClaimWarningArticleIds);
        $candidates = [];
        $blockers = [];
        $warnings = [];

        if ($articleIds === []) {
            $warnings[] = $this->issue('input', 'no_candidates_provided', 'No candidate article ids were provided for rehearsal.');
        }

        foreach ($articleIds as $articleId) {
            $candidate = $this->articleCandidate($articleId, $acknowledgedClaimWarningArticleIds, $makeIndexable);
            $candidates[] = $candidate;

            foreach ((array) ($candidate['blockers'] ?? []) as $blocker) {
                if (is_array($blocker)) {
                    $blockers[] = $blocker + ['article_id' => $articleId];
                }
            }

            foreach ((array) ($candidate['warnings'] ?? []) as $warning) {
                if (is_array($warning)) {
                    $warnings[] = $warning + ['article_id' => $articleId];
                }
            }
        }

        $rehearsalState = $blockers !== []
            ? 'blocked'
            : ($warnings !== [] ? 'needs_review' : 'safe');

        return [
            'runtime' => self::RUNTIME,
            'status' => $rehearsalState === 'blocked' ? 'blocked' : 'success',
            'rehearsal_state' => $rehearsalState,
            'dry_run' => true,
            'no_write' => true,
            'writes_attempted' => false,
            'writes_committed' => false,
            'cms_mutation_attempted' => false,
            'article_publish_attempted' => false,
            'search_channel_enqueue_attempted' => false,
            'search_submission_attempted' => false,
            'sitemap_mutation_attempted' => false,
            'llms_mutation_attempted' => false,
            'observation_queue_write_attempted' => false,
            'collector_write_attempted' => false,
            'scheduler_enabled' => false,
            'production_write_attempted' => false,
            'metabase_exposure_attempted' => false,
            'fap_web_modification_attempted' => false,
            'candidate_count' => count($candidates),
            'candidates' => $candidates,
            'planned_observation_events' => $this->plannedObservationEvents(),
            'claim_lint_state' => $this->rollupState($candidates, 'claim_lint_state'),
            'internal_link_readiness_state' => $this->rollupState($candidates, 'internal_link_readiness_state'),
            'search_channel_eligibility_state' => $rehearsalState === 'safe'
                ? 'dry_run_eligible_after_manual_publish_review'
                : 'dry_run_not_eligible',
            'blockers' => $blockers,
            'warnings' => $warnings,
            'safety_flags' => [
                'dry_run_only' => true,
                'no_cms_mutation' => true,
                'no_article_publish' => true,
                'no_seo_intel_write' => true,
                'no_observation_queue_write' => true,
                'no_search_channel_enqueue' => true,
                'no_search_submission' => true,
                'no_sitemap_mutation' => true,
                'no_llms_mutation' => true,
                'no_scheduler' => true,
                'no_collector_write' => true,
                'no_fap_web_modification' => true,
            ],
        ];
    }

    /**
     * @param  list<int>  $acknowledgedClaimWarningArticleIds
     * @return array<string, mixed>
     */
    private function articleCandidate(int $articleId, array $acknowledgedClaimWarningArticleIds, bool $makeIndexable): array
    {
        $article = Article::query()
            ->withoutGlobalScopes()
            ->with(['workingRevision', 'seoMeta', 'category', 'tags'])
            ->find($articleId);

        if (! $article instanceof Article) {
            return [
                'surface' => 'article',
                'article_id' => $articleId,
                'rehearsal_state' => 'blocked',
                'claim_lint_state' => 'needs_review',
                'internal_link_readiness_state' => 'needs_review',
                'search_channel_eligibility_state' => 'dry_run_not_eligible',
                'blockers' => [
                    $this->issue('article', 'article_not_found', 'Article not found.'),
                ],
                'warnings' => [],
            ];
        }

        $seoMeta = $article->seoMeta instanceof ArticleSeoMeta ? $article->seoMeta : null;
        $revision = $article->workingRevision instanceof ArticleTranslationRevision ? $article->workingRevision : null;
        $import = ArticleEditorialPackageImport::query()
            ->withoutGlobalScopes()
            ->where('article_id', $articleId)
            ->latest('id')
            ->first();
        $editorialPackage = is_array($seoMeta?->schema_json)
            ? (array) data_get($seoMeta->schema_json, 'editorial_package_v1', [])
            : [];
        $claimResult = is_array($import?->claim_result_json) ? $import->claim_result_json : [];
        $claimStatus = (string) data_get($claimResult, 'status', '');
        $claimMatches = is_array(data_get($claimResult, 'matches')) ? (array) data_get($claimResult, 'matches') : [];
        $bodyHash = $revision instanceof ArticleTranslationRevision
            ? hash('sha256', preg_replace("/\r\n?/", "\n", trim((string) $revision->content_md)))
            : '';
        $ctaSlots = data_get($editorialPackage, 'cta_slots', []);
        $faqItems = data_get($editorialPackage, 'answer_surface_v1.faq_items', []);
        $targetTopics = data_get($editorialPackage, 'target_topics', []);
        $targetTests = data_get($editorialPackage, 'target_tests', []);
        $blockers = [];
        $warnings = [];

        if ((string) $article->status === 'published' || $article->published_revision_id !== null) {
            $blockers[] = $this->issue('article.status', 'already_published', 'Already published content is outside publish rehearsal scope.');
        }

        if ((string) $article->lifecycle_state !== '' && in_array((string) $article->lifecycle_state, [
            Article::LIFECYCLE_ARCHIVED,
            Article::LIFECYCLE_SOFT_DELETED,
        ], true)) {
            $blockers[] = $this->issue('article.lifecycle_state', 'article_lifecycle_not_publishable', 'Archived or soft-deleted content cannot pass rehearsal.');
        }

        if (! in_array((string) $article->status, ['draft', 'review_pending'], true)) {
            $blockers[] = $this->issue('article.status', 'invalid_status', 'Rehearsal currently accepts draft or review_pending articles only.');
        }

        if ((bool) $article->is_public) {
            $blockers[] = $this->issue('article.is_public', 'already_public', 'Candidate is already public before rehearsal.');
        }

        if (! $revision instanceof ArticleTranslationRevision) {
            $blockers[] = $this->issue('working_revision', 'missing_working_revision', 'Working revision is required.');
        } elseif ((string) $revision->revision_status !== ArticleTranslationRevision::STATUS_APPROVED) {
            $blockers[] = $this->issue('working_revision.revision_status', 'revision_not_editorially_approved', 'Working revision must be approved before rehearsal can be safe.');
        }

        if (! $import instanceof ArticleEditorialPackageImport) {
            $blockers[] = $this->issue('import', 'missing_import_gate', 'Latest editorial package import gate is required.');
        } else {
            if (! in_array((string) $import->status, [
                ArticleEditorialPackageImport::STATUS_IMPORTED,
                ArticleEditorialPackageImport::STATUS_WARNING,
                ArticleEditorialPackageImport::STATUS_DRY_RUN_PASSED,
            ], true)) {
                $blockers[] = $this->issue('import.status', 'invalid_import_status', 'Import gate status is not rehearsal-ready.');
            }

            if ($bodyHash !== '' && ! hash_equals((string) $import->body_hash, $bodyHash)) {
                $blockers[] = $this->issue('body_hash', 'body_hash_mismatch', 'Working revision body hash does not match the latest import gate.');
            }
        }

        $claimLintState = $this->claimLintState($claimStatus, $claimMatches, in_array($articleId, $acknowledgedClaimWarningArticleIds, true));
        if ($claimLintState === 'blocked') {
            $blockers[] = $this->issue('claim_lint', 'claim_lint_blocked', 'Claim lint is blocked or contains non-boundary warnings.');
        } elseif ($claimLintState === 'needs_review') {
            $warnings[] = $this->issue('claim_lint', 'claim_lint_needs_review', 'Claim lint warning requires human review before publish.');
        }

        if ((string) data_get($import?->media_json, 'status') !== 'complete') {
            $warnings[] = $this->issue('media', 'media_incomplete', 'Media and cover readiness is incomplete.');
        }

        if ((int) ($import?->references_count ?? 0) <= 0) {
            $warnings[] = $this->issue('references', 'references_missing', 'References are missing.');
        }

        if ((string) data_get($import?->graph_json, 'status') !== 'complete') {
            $warnings[] = $this->issue('graph', 'graph_incomplete', 'Content graph metadata is incomplete.');
        }

        if (trim((string) $article->cover_image_alt) === '') {
            $warnings[] = $this->issue('cover_image_alt', 'cover_alt_missing', 'Cover image alt text is missing.');
        }

        if (! $article->category) {
            $warnings[] = $this->issue('category', 'category_missing', 'Article category is missing.');
        }

        if ($article->tags->count() <= 0) {
            $warnings[] = $this->issue('tags', 'tags_missing', 'Article tags are missing.');
        }

        if (! $seoMeta instanceof ArticleSeoMeta) {
            $blockers[] = $this->issue('seo', 'seo_meta_missing', 'SEO metadata is required.');
        } else {
            foreach (['seo_title', 'seo_description', 'canonical_url'] as $field) {
                if (trim((string) $seoMeta->{$field}) === '') {
                    $blockers[] = $this->issue("seo.{$field}", 'seo_field_missing', "SEO field {$field} is required.");
                }
            }
        }

        if (! $makeIndexable && (! (bool) $article->is_indexable || (string) ($seoMeta?->robots ?? '') !== 'index,follow')) {
            $warnings[] = $this->issue('indexability', 'make_indexable_dry_run_required', 'Candidate remains noindex unless --make-indexable is part of the rehearsal request.');
        }

        if (! is_array($ctaSlots) || count($ctaSlots) <= 0) {
            $warnings[] = $this->issue('cta_slots', 'cta_slots_missing', 'CTA slots are missing.');
        }

        if (! is_array($faqItems) || count($faqItems) <= 0) {
            $warnings[] = $this->issue('faq_items', 'faq_items_missing', 'FAQ items are missing.');
        }

        $internalLinkReadinessState = (is_array($targetTopics) && count($targetTopics) > 0)
            || (is_array($targetTests) && count($targetTests) > 0)
                ? 'safe'
                : 'needs_review';

        if ($internalLinkReadinessState !== 'safe') {
            $warnings[] = $this->issue('internal_link_readiness', 'internal_link_targets_missing', 'Target topics or tests are missing from the backend editorial package.');
        }

        $rehearsalState = $blockers !== [] ? 'blocked' : ($warnings !== [] ? 'needs_review' : 'safe');

        return [
            'surface' => 'article',
            'article_id' => $articleId,
            'slug' => (string) $article->slug,
            'locale' => (string) $article->locale,
            'entity_key' => filled($article->translation_group_id) ? 'translation_group_id:'.(string) $article->translation_group_id : 'legacy_unpaired',
            'status' => (string) $article->status,
            'review_state' => (string) ($revision?->revision_status ?? ''),
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'canonical_url_present' => $seoMeta instanceof ArticleSeoMeta && trim((string) $seoMeta->canonical_url) !== '',
            'seo_title_present' => $seoMeta instanceof ArticleSeoMeta && trim((string) $seoMeta->seo_title) !== '',
            'seo_description_present' => $seoMeta instanceof ArticleSeoMeta && trim((string) $seoMeta->seo_description) !== '',
            'robots_state' => (string) ($seoMeta?->robots ?? ''),
            'references_count' => (int) ($import?->references_count ?? 0),
            'cta_count' => is_array($ctaSlots) ? count($ctaSlots) : 0,
            'faq_count' => is_array($faqItems) ? count($faqItems) : 0,
            'media_state' => (string) data_get($import?->media_json, 'status', ''),
            'claim_lint_state' => $claimLintState,
            'internal_link_readiness_state' => $internalLinkReadinessState,
            'search_channel_eligibility_state' => $rehearsalState === 'safe'
                ? 'dry_run_eligible_after_manual_publish_review'
                : 'dry_run_not_eligible',
            'planned_observation_events' => $this->plannedObservationEvents(),
            'rehearsal_state' => $rehearsalState,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    private function rollupState(array $candidates, string $key): string
    {
        $states = array_map(static fn (array $candidate): string => (string) ($candidate[$key] ?? 'needs_review'), $candidates);

        if (in_array('blocked', $states, true)) {
            return 'blocked';
        }

        if ($states === [] || in_array('needs_review', $states, true)) {
            return 'needs_review';
        }

        return 'safe';
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     */
    private function claimLintState(string $claimStatus, array $matches, bool $acknowledged): string
    {
        if ($claimStatus === 'blocked') {
            return 'blocked';
        }

        if ($claimStatus === 'warning') {
            $allBoundaryContext = $matches !== [] && collect($matches)->every(
                static fn (mixed $match): bool => is_array($match) && (bool) ($match['boundary_context'] ?? false)
            );

            return $allBoundaryContext && $acknowledged ? 'safe' : ($allBoundaryContext ? 'needs_review' : 'blocked');
        }

        return in_array($claimStatus, ['passed', 'safe'], true) ? 'safe' : 'needs_review';
    }

    /**
     * @return list<string>
     */
    private function plannedObservationEvents(): array
    {
        return [
            'published',
            'metadata_changed',
            'canonical_changed',
            'robots_changed',
            'locale_link_changed',
            'claim_boundary_changed',
            'issue_detected',
        ];
    }

    /**
     * @param  list<int>  $values
     * @return list<int>
     */
    private function positiveUniqueIntegers(array $values): array
    {
        $integers = array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0, $values),
            static fn (int $value): bool => $value > 0,
        )));
        sort($integers);

        return $integers;
    }

    /**
     * @return array<string, string>
     */
    private function issue(string $field, string $code, string $message): array
    {
        return [
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];
    }
}
