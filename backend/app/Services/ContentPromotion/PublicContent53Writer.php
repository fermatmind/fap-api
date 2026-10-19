<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Filament\Ops\Resources\CareerGuideResource\Support\CareerGuideWorkspace;
use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\CareerGuide;
use App\Models\CareerGuideRevision;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Models\LandingSurface;
use App\Models\TopicProfileRevision;
use App\Services\Cms\ArticleMaterialDecisionService;
use App\Services\Cms\ContentPagePublishGate;
use App\Services\Cms\ContentPageTranslationAdapter;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Copy-only native writes; never edits existing revisions or operator drafts. */
final class PublicContent53Writer
{
    public function __construct(
        private readonly PublicContent53Package $package,
        private readonly PublicContent53State $states,
        private readonly ArticleMaterialDecisionService $material,
        private readonly ContentPageTranslationAdapter $pages,
        private readonly ContentPagePublishGate $pageGate,
    ) {}

    public function publish(PromotionContext $context, array $before): array
    {
        $this->assertContext($context);
        if ($this->states->hash($this->states->read($context->packageSha256, true)) !== $this->states->hash($before)) {
            throw new DomainException('public_content_53_native_prestate_drift');
        }
        $rows = $this->package->read(base_path(), $context->packageSha256)['candidates'];
        $this->states->assertLockedBaseline($rows, $before);
        usort($rows, static fn (array $a, array $b): int => ($a['identity']['locale'] === 'en') <=> ($b['identity']['locale'] === 'en'));
        $written = 0;
        foreach ($rows as $row) {
            $saved = $before[$row['page_id']];
            if ($this->states->hash($this->states->projection($row)) === $this->states->hash($row['snapshot'])) {
                continue;
            }
            match ($row['kind']) {
                'guide' => $this->guide($row, $saved, $context),
                'article' => $this->article($row, $saved, $context),
                'page' => $this->page($row, $saved, $context),
                'surface' => $this->surface($row, $saved),
                'profile' => $this->topic($row, $saved, $context),
            };
            $written++;
        }

        $after = $this->states->read($context->packageSha256, true);
        foreach ($before as $id => $saved) {
            if ($saved['table'] !== 'career_guides') {
                continue;
            }
            $protected = $after[$id];
            foreach (['body_md', 'body_html', 'updated_at'] as $field) {
                $protected['values'][$field] = $saved['values'][$field];
            }
            $protected['revisions'] = $saved['revisions'];
            if ($this->states->hash($protected) !== $this->states->hash($saved)) {
                throw new DomainException('public_content_53_guide_protected_state_changed');
            }
        }

        return ['written_count' => $written, 'state' => $after];
    }

    public function restore(PromotionContext $context, array $before, array $after): void
    {
        $this->assertContext($context);
        $current = $this->states->read($context->packageSha256, true);
        if ($this->businessHash($current) === $this->businessHash($before)) {
            return;
        }
        if ($this->states->hash($current) !== $this->states->hash($after)) {
            throw new DomainException('public_content_53_rollback_concurrent_state');
        }
        foreach ($before as $saved) {
            $this->restoreValues($saved['table'], $saved['values']);
            foreach ($saved['relations'] as $table => $records) {
                foreach ($records as $record) {
                    $this->restoreValues($table, $record);
                }
            }
            if ($saved['table'] === 'articles') {
                $article = Article::query()->withoutGlobalScopes()->findOrFail($saved['values']['id']);
                $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->findOrFail($saved['published']['id']);
                $this->material->recordPublished($article, $revision, now(), 'rollback');
            }
        }
        if ($this->businessHash($this->states->read($context->packageSha256, true)) !== $this->businessHash($before)) {
            throw new DomainException('public_content_53_rollback_readback');
        }
    }

    private function businessHash(array $state): string
    {
        foreach ($state as &$saved) {
            if ($saved['table'] === 'career_guides') {
                unset($saved['revisions']);
            }
        }
        unset($saved);

        return $this->states->hash($state);
    }

    private function guide(array $row, array $saved, PromotionContext $context): void
    {
        $guide = CareerGuide::query()->withoutGlobalScopes()->findOrFail($saved['values']['id']);
        $guide->forceFill(['body_md' => $row['snapshot']['body_md'], 'body_html' => null])->saveQuietly();
        $snapshot = CareerGuideWorkspace::snapshotPayload($guide);
        $snapshot['promotion'] = ['source_commit' => $context->sourceCommit, 'workflow_run_id' => $context->workflowRunId,
            'package_sha256' => $context->packageSha256, 'approval_kind' => 'verified_automated_exact_package'];
        CareerGuideRevision::query()->create([
            'career_guide_id' => $guide->id, 'revision_no' => CareerGuideWorkspace::nextRevisionNo($guide),
            'snapshot_json' => $snapshot, 'note' => 'verified-automated-exact-package:'.$context->packageSha256,
            'created_by_admin_user_id' => null, 'created_at' => now(),
        ]);
    }

    private function article(array $row, array $saved, PromotionContext $context): void
    {
        $article = Article::query()->withoutGlobalScopes()->findOrFail($saved['values']['id']);
        $old = $saved['published'];
        $seoCopy = $row['snapshot']['seo_text'] ?? null;
        $revisionCopy = $seoCopy !== null
            ? array_intersect_key($seoCopy, array_flip(['seo_title', 'seo_description'])) + array_intersect_key($row['snapshot'], ['title' => true])
            : $row['snapshot'] + ['content_html' => null];
        if ($seoCopy === null) {
            $article->forceFill($revisionCopy);
        } elseif (isset($row['snapshot']['title'])) {
            $article->forceFill(['title' => $row['snapshot']['title']]);
        }
        $hash = $article->computeSourceVersionHash();
        $source = Article::query()->withoutGlobalScopes()->findOrFail($old['source_article_id']);
        if ($source->org_id !== $article->org_id || $source->translation_group_id !== $article->translation_group_id || $source->locale !== $old['source_locale']) {
            throw new DomainException('public_content_53_article_source_identity');
        }
        $sourceHash = $source->id === $article->id ? $hash : $source->source_version_hash;
        $payload = $old;
        foreach (['id', 'created_at', 'updated_at', 'archived_at'] as $key) {
            unset($payload[$key]);
        }
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create(array_replace($payload, $revisionCopy, [
            'revision_number' => (int) ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $article->id)->max('revision_number') + 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => $sourceHash, 'translated_from_version_hash' => $sourceHash,
            'supersedes_revision_id' => $old['id'], 'authority_asset_key' => $row['page_id'].':'.$context->sourceCommit,
            'authority_source_package' => PublicContent53Package::PACKAGE, 'authority_source_hash' => $this->states->hash($row['snapshot']),
            'authority_package_sha256' => $context->packageSha256,
            'authority_metadata_json' => ['source_commit' => $context->sourceCommit, 'workflow_run_id' => $context->workflowRunId, 'approval_kind' => 'verified_automated_exact_package'],
            'created_by' => null, 'reviewed_by' => null, 'reviewed_at' => null,
            'approved_at' => now(), 'published_at' => now(),
        ]));
        $article->forceFill(['source_version_hash' => $hash, 'published_revision_id' => $revision->id]);
        if ($source->id !== $article->id) {
            $article->translated_from_version_hash = $sourceHash;
        }
        if ($saved['values']['working_revision_id'] === $old['id']) {
            $article->working_revision_id = $revision->id;
        }
        $article->saveQuietly();
        if ($seoCopy !== null) {
            if (count($saved['relations']['article_seo_meta']) !== 1) {
                throw new DomainException('public_content_53_copy_seo_missing');
            }
            DB::table('article_seo_meta')->where('id', $saved['relations']['article_seo_meta'][0]['id'])->update($seoCopy);
        }
        $this->material->recordPublished($article, $revision, now());
    }

    private function page(array $row, array $saved, PromotionContext $context): void
    {
        $page = ContentPage::query()->withoutGlobalScopes()->findOrFail($saved['values']['id']);
        $page->forceFill($row['snapshot'] + ['content_html' => '']);
        $page->headings_json = preg_match_all('/^## (.+)$/m', $page->content_md, $matches) ? $matches[1] : [];
        $this->pageGate->assertPasses($page);
        $hash = $page->freshSourceVersionHash();
        $translatedHash = $page->translated_from_version_hash;
        if ($page->source_content_id) {
            $source = ContentPage::query()->withoutGlobalScopes()->findOrFail($page->source_content_id);
            if ($source->org_id !== $page->org_id || $source->translation_group_id !== $page->translation_group_id || $source->locale !== $page->source_locale) {
                throw new DomainException('public_content_53_page_source_identity');
            }
            $translatedHash = $source->source_version_hash;
        }
        $revision = CmsTranslationRevision::query()->create([
            'org_id' => 0, 'content_type' => 'content_page', 'content_id' => $page->id,
            'source_content_id' => $page->source_content_id, 'translation_group_id' => $page->translation_group_id,
            'locale' => $page->locale, 'source_locale' => $page->source_locale,
            'revision_number' => (int) CmsTranslationRevision::query()->where('content_type', 'content_page')->where('content_id', $page->id)->max('revision_number') + 1,
            'revision_status' => CmsTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => $hash, 'translated_from_version_hash' => $translatedHash,
            'payload_json' => $this->pages->snapshotPayload($page), 'supersedes_revision_id' => $page->published_revision_id,
            'authority_asset_key' => $row['page_id'].':'.$context->sourceCommit, 'authority_source_package' => PublicContent53Package::PACKAGE,
            'authority_source_hash' => $this->states->hash($row['snapshot']), 'authority_package_sha256' => $context->packageSha256,
            'approved_at' => now(), 'published_at' => now(),
        ]);
        if ($page->working_revision_id === $page->published_revision_id) {
            $page->working_revision_id = $revision->id;
        }
        $page->published_revision_id = $revision->id;
        $page->source_version_hash = $hash;
        $page->translated_from_version_hash = $translatedHash;
        $page->saveQuietly();
    }

    private function surface(array $row, array $saved): void
    {
        $copy = $row['snapshot'];
        unset($copy['block_copy']);
        LandingSurface::query()->withoutGlobalScopes()->findOrFail($saved['values']['id'])->forceFill($copy)->saveQuietly();
        foreach ($row['snapshot']['block_copy'] ?? [] as $key => $payload) {
            DB::table('page_blocks')->where('landing_surface_id', $saved['values']['id'])->where('block_key', $key)
                ->update(['payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        }
    }

    private function topic(array $row, array $saved, PromotionContext $context): void
    {
        $candidate = $row['snapshot'];
        $id = $saved['values']['id'];
        DB::table('topic_profiles')->where('id', $id)->update(array_intersect_key($candidate, array_flip(['title', 'subtitle', 'excerpt'])));
        foreach ($candidate['sections'] as $section) {
            $matches = array_values(array_filter($saved['relations']['topic_profile_sections'], static fn (array $s): bool => $s['section_key'] === $section['section_key']));
            if (count($matches) !== 1) {
                throw new DomainException('public_content_53_topic_section_identity');
            }
            DB::table('topic_profile_sections')->where('id', $matches[0]['id'])->update(['title' => $section['title'], 'body_md' => $section['body_md']]);
        }
        foreach ($candidate['entry_groups'] as $entries) {
            foreach ($entries as $entry) {
                $matches = array_values(array_filter($saved['relations']['topic_profile_entries'], static fn (array $e): bool => $e['entry_type'] === $entry['entry_type'] && $e['group_key'] === $entry['group_key'] && $e['target_key'] === $entry['target_key']));
                if (count($matches) !== 1) {
                    throw new DomainException('public_content_53_topic_entry_identity');
                }
                // Preserve derived source excerpts unless this package actually edits one.
                $beforeEntries = $row['before']['entry_groups'][$entry['group_key']];
                $previous = array_values(array_filter($beforeEntries, static fn (array $e): bool => $e['target_key'] === $entry['target_key'] && $e['entry_type'] === $entry['entry_type']));
                if ($previous[0]['excerpt'] !== $entry['excerpt']) {
                    DB::table('topic_profile_entries')->where('id', $matches[0]['id'])->update(['excerpt_override' => $entry['excerpt']]);
                }
            }
        }
        if (count($saved['relations']['topic_profile_seo_meta']) !== 1) {
            throw new DomainException('public_content_53_topic_seo_identity');
        }
        DB::table('topic_profile_seo_meta')->where('id', $saved['relations']['topic_profile_seo_meta'][0]['id'])->update(['seo_title' => $candidate['seo_text']['title'], 'seo_description' => $candidate['seo_text']['description']]);
        TopicProfileRevision::query()->create([
            'profile_id' => $id, 'revision_no' => (int) TopicProfileRevision::query()->where('profile_id', $id)->max('revision_no') + 1,
            'authority_asset_key' => $row['page_id'].':'.$context->sourceCommit, 'source_package' => PublicContent53Package::PACKAGE,
            'source_hash' => $this->states->hash($candidate), 'authority_package_sha256' => $context->packageSha256,
            'snapshot_json' => $candidate, 'workflow_state' => 'approved_exact_package', 'created_at' => now(),
        ]);
    }

    private function assertContext(PromotionContext $context): void
    {
        if (DB::transactionLevel() < 1 || $context->lane !== 'W3' || $context->subscope !== 'PUBLIC-CONTENT-53'
            || $context->expectedRowCount !== count($this->package->read(base_path(), $context->packageSha256)['candidates']) || realpath($context->packageDirectory) !== realpath(base_path(PublicContent53Package::PACKAGE))) {
            throw new DomainException('public_content_53_writer_context_invalid');
        }
    }

    private function restoreValues(string $table, array $values): void
    {
        $id = $values['id'];
        unset($values['id']);
        DB::table($table)->where('id', $id)->update($values);
    }
}
