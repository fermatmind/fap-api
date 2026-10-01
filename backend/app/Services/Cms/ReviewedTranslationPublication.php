<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Console\Commands\ArticleForkPrivateTranslationLinks as Fingerprint;
use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** @review-surface article_translation_revision */
final readonly class ReviewedTranslationPublication
{
    public function __construct(private ArticleTranslationWorkflowService $workflow, private CmsEditorialReviewAttestationService $reviews, private AuditLogger $audit) {}

    public function run(array $p, bool $execute, bool $previewApproved, string $confirmation, bool $indexable): array
    {
        $keys = array_keys($p);
        $expected = ['schema', 'source_id', 'target_id', 'source_published_revision_id', 'working_revision_id', 'source_snapshot_hash', 'target_snapshot_hash', 'body_sha256'];
        sort($keys);
        sort($expected);
        if ($keys !== $expected || $p['schema'] !== 'reviewed_article_translation_publication_v1') {
            throw new RuntimeException('translation_package_invalid');
        }
        foreach (['source_id', 'target_id', 'source_published_revision_id', 'working_revision_id'] as $k) {
            if (! is_int($p[$k]) || $p[$k] < 1) {
                throw new RuntimeException('translation_identity_invalid');
            }
        }
        foreach (['source_snapshot_hash', 'target_snapshot_hash', 'body_sha256'] as $k) {
            if (! is_string($p[$k]) || ! preg_match('/^[a-f0-9]{64}$/', $p[$k])) {
                throw new RuntimeException('translation_hash_invalid');
            }
        }
        $phrase = 'I explicitly approve Codex to publish article id '.$p['target_id'].' after preflight passes.';
        if ($execute && (! $previewApproved || ! hash_equals($phrase, $confirmation))) {
            throw new RuntimeException('translation_preview_or_confirmation_missing');
        }
        if (! $execute) {
            $this->snapshot($p, false);

            return ['ok' => true, 'dry_run' => true, 'expected_confirmation' => $phrase, 'published_article_ids' => []];
        }
        if (! $this->reviews->isConfiguredSoloOwner((int) auth((string) config('admin.guard', 'admin'))->id())) {
            throw new RuntimeException('translation_operator_unavailable');
        }

        return DB::transaction(function () use ($p, $phrase, $indexable): array {
            [$s, $t] = $this->snapshot($p, true);
            $sourceHash = Fingerprint::sourceHash($s, true);
            $history = $this->history($t, $p['working_revision_id']);
            $restore = ['article' => $t->only(['status', 'is_public', 'is_indexable', 'published_at', 'published_revision_id', 'translation_status']),
                'revision' => $t->workingRevision->only(['revision_status', 'published_at']), 'seo' => $t->seoMeta->only(['robots', 'is_indexable'])];
            if ($indexable) {
                $t->forceFill(['is_indexable' => true])->saveQuietly();
                $t->seoMeta->forceFill(['robots' => 'index,follow', 'is_indexable' => true])->saveQuietly();
            }
            $revision = $this->workflow->publishTranslation($t, 'controlled_codex_translation_publish');
            $t = $t->fresh(['publishedRevision', 'seoMeta']);
            if ($t->published_revision_id !== $p['working_revision_id'] || ! $t->is_public
                || $revision->revision_status !== 'published' || hash('sha256', $revision->content_md) !== $p['body_sha256']
                || Fingerprint::sourceHash($s->fresh(), true) !== $sourceHash || $this->history($t, $p['working_revision_id']) !== $history) {
                throw new RuntimeException('translation_publication_readback_failed');
            }
            $this->audit->log(Request::create('/ops/articles/publish-controlled', 'POST'), 'codex_controlled_translation_publish', 'article', (string) $t->id,
                ['confirmation_sha256' => hash('sha256', $phrase), 'locks' => $p, 'restore_fields' => $restore,
                    'after_snapshot_hash' => Fingerprint::targetHash($s->fresh(), true), 'make_indexable' => $indexable],
                reason: 'reviewed_translation_publication', result: 'success');

            return ['ok' => true, 'dry_run' => false, 'published_article_ids' => [$t->id], 'source_and_prior_versions_preserved' => true];
        });
    }

    private function history(Article $target, int $except): string
    {
        return DB::table('article_translation_revisions')->where('article_id', $target->id)->where('id', '!=', $except)->orderBy('id')->get()->toJson();
    }

    private function snapshot(array $p, bool $lock): array
    {
        $q = Article::withoutGlobalScopes()->where('org_id', 0)->whereIn('id', [$p['source_id'], $p['target_id']])->orderBy('id');
        $rows = ($lock ? $q->lockForUpdate() : $q)->get()->keyBy('id');
        $s = $rows->get($p['source_id']);
        $t = $rows->get($p['target_id']);
        if (! $s instanceof Article || ! $t instanceof Article || $s->locale !== 'zh-CN' || $t->locale !== 'en' || ! $s->isSourceArticle()
            || $s->published_revision_id !== $p['source_published_revision_id'] || $s->working_revision_id !== $s->published_revision_id
            || $t->working_revision_id !== $p['working_revision_id'] || $t->published_revision_id !== null || $t->status !== 'draft' || $t->is_public
            || $t->source_article_id !== $s->id || $t->translation_group_id !== $s->translation_group_id
            || Fingerprint::sourceHash($s, $lock) !== $p['source_snapshot_hash'] || Fingerprint::targetHash($s, $lock) !== $p['target_snapshot_hash']) {
            throw new RuntimeException('translation_snapshot_drift');
        }
        $t->load(['workingRevision', 'seoMeta', 'category', 'tags']);
        $s->load('publishedRevision');
        if (! $t->workingRevision instanceof ArticleTranslationRevision || $t->workingRevision->revision_status !== 'approved'
            || hash('sha256', $t->workingRevision->content_md) !== $p['body_sha256']
            || $t->title !== $t->workingRevision->title || $t->excerpt !== $t->workingRevision->excerpt
            || $t->content_md !== $t->workingRevision->content_md
            || ! $this->reviews->hasApprovedEvidence('article_translation_revision', $t->workingRevision)
            || ! $s->publishedRevision instanceof ArticleTranslationRevision
            || ! $this->reviews->hasApprovedEvidence('article_translation_revision', $s->publishedRevision) || ! $this->workflow->preflight($t)['ok']) {
            throw new RuntimeException('translation_review_or_lineage_invalid');
        }
        if (! $t->category || $t->tags->isEmpty() || ! filled($t->cover_image_url) || ! filled($t->cover_image_alt) || ! $t->seoMeta
            || $t->seoMeta->canonical_url !== 'https://fermatmind.com/en/articles/'.$t->slug
            || ! filled($t->seoMeta->seo_title) || ! filled($t->seoMeta->seo_description) || ! filled($t->seoMeta->og_image_url)) {
            throw new RuntimeException('translation_public_metadata_incomplete');
        }

        return [$s, $t];
    }
}
