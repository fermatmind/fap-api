<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Filament\Ops\Resources\CareerGuideResource\Support\CareerGuideWorkspace;
use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\CareerGuide;
use App\Models\CareerGuideRevision;
use App\Services\Cms\ArticleMaterialDecisionService;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Native writes only; the exact-package adapter owns journalling and cache closeout. */
final class EqExistingPublicPageWriter
{
    public function __construct(
        private readonly EqExistingPublicPagePackage $package,
        private readonly EqExistingPublicPageState $states,
        private readonly EqPublicRegistryTextPatch $patch,
        private readonly ArticleMaterialDecisionService $material,
    ) {}

    public function publish(PromotionContext $context, array $before): array
    {
        if (DB::transactionLevel() < 1 || $context->lane !== 'W3' || $context->subscope !== 'EQ-EXISTING-PUBLIC-PAGES'
            || $context->expectedRowCount !== 6 || realpath($context->packageDirectory) !== realpath(base_path(EqExistingPublicPagePackage::PACKAGE))) {
            throw new DomainException('eq_existing_writer_context_invalid');
        }
        $rows = $this->package->read(base_path(), $context->packageSha256)['candidates'];
        if ($this->states->hash($this->states->read($context->packageSha256, true)) !== $this->states->hash($before)) {
            throw new DomainException('eq_existing_writer_prestate_drift');
        }
        foreach ($before['articles'] as $article) {
            if ($article['published']['revision_status'] !== ArticleTranslationRevision::STATUS_PUBLISHED) {
                throw new DomainException('eq_existing_writer_rollback_authority_unsupported');
            }
        }
        $changedEntries = [];
        $written = 0;
        foreach ($before['registries'] as $registry) {
            $content = $registry['content'];
            foreach ($rows as $row) {
                if ($row['page_id'] !== 'EQ-01') {
                    continue;
                }
                $after = $this->patch->apply($content, $row);
                if ($this->states->hash($after) !== $this->states->hash($content)) {
                    $changedEntries[$row['identity']['locale']] = true;
                }
                $content = $after;
            }
            if ($this->states->hash($content) !== $this->states->hash($registry['content'])) {
                DB::table($registry['table'])->where('org_id', 0)->where('code', 'EQ_60')->update([
                    'content_i18n_json' => json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
        }
        $written += count($changedEntries);
        // A paired source is updated before its translated public revision.
        usort($rows, static fn (array $a, array $b): int => (($a['identity']['locale'] === 'zh-CN') ? 0 : 1) <=> (($b['identity']['locale'] === 'zh-CN') ? 0 : 1));
        foreach ($rows as $row) {
            $key = $row['page_id'].':'.$row['identity']['locale'];
            if ($row['page_id'] === 'EQ-02') {
                $written += $this->article($row, $before['articles'][$key], $context) ? 1 : 0;
            } elseif ($row['page_id'] === 'SH-02') {
                $written += $this->guide($row, $before['guides'][$key], $context) ? 1 : 0;
            }
        }
        $after = $this->states->read($context->packageSha256, true);
        $this->assertPublished($rows, $after);

        return ['written_count' => $written, 'state' => $after];
    }

    public function restore(PromotionContext $context, array $before, array $expectedPublished): void
    {
        if (DB::transactionLevel() < 1 || $context->lane !== 'W3' || $context->subscope !== 'EQ-EXISTING-PUBLIC-PAGES'
            || $context->expectedRowCount !== 6 || realpath($context->packageDirectory) !== realpath(base_path(EqExistingPublicPagePackage::PACKAGE))) {
            throw new DomainException('eq_existing_writer_context_invalid');
        }
        $current = $this->states->read($context->packageSha256, true);
        // Publication history stays immutable after rollback; only business
        // pointers and the exact previously captured native values are restored.
        if ($this->businessHash($current) === $this->businessHash($before)) {
            return;
        }
        if ($this->states->hash($current) !== $this->states->hash($expectedPublished)) {
            throw new DomainException('eq_existing_writer_rollback_concurrent_state');
        }
        foreach ($before['registries'] as $registry) {
            DB::table($registry['table'])->where('org_id', 0)->where('code', 'EQ_60')->update(['content_i18n_json' => $registry['values']['content_i18n_json']]);
        }
        foreach ($before['articles'] as $key => $saved) {
            $this->restoreValues('articles', $saved['values']);
            if ($saved['seo'] === []) {
                DB::table('article_seo_meta')->where('id', $expectedPublished['articles'][$key]['seo'][0]['id'])
                    ->where('article_id', $saved['values']['id'])->where('org_id', 0)->where('locale', 'en')->delete();
            } else {
                $this->restoreValues('article_seo_meta', $saved['seo'][0]);
            }
            $article = Article::query()->withoutGlobalScopes()->findOrFail($saved['values']['id']);
            $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->findOrFail($saved['published']['id']);
            $this->material->recordPublished($article, $revision, now(), 'rollback');
        }
        foreach ($before['guides'] as $saved) {
            $this->restoreValues('career_guides', $saved['values']);
            $this->restoreValues('career_guide_seo_meta', $saved['seo'][0]);
        }
        if ($this->businessHash($this->states->read($context->packageSha256, true)) !== $this->businessHash($before)) {
            throw new DomainException('eq_existing_writer_rollback_readback');
        }
    }

    private function businessHash(array $state): string
    {
        foreach ($state['guides'] as &$guide) {
            unset($guide['revisions']);
        }
        unset($guide);

        return $this->states->hash($state);
    }

    private function restoreValues(string $table, array $values): void
    {
        $id = $values['id'];
        unset($values['id']);
        DB::table($table)->where('id', $id)->update($values);
    }

    public function assertPublished(array $rows, array $state): void
    {
        foreach ($rows as $row) {
            if ($row['page_id'] === 'EQ-01') {
                foreach ($state['registries'] as $registry) {
                    if ($this->states->hash($this->patch->apply($registry['content'], $row)) !== $this->states->hash($registry['content'])) {
                        throw new DomainException('eq_existing_writer_registry_readback');
                    }
                }

                continue;
            }
            $key = $row['page_id'].':'.$row['identity']['locale'];
            $current = $state[$row['page_id'] === 'EQ-02' ? 'articles' : 'guides'][$key];
            if (! $this->matches($row, $current)) {
                throw new DomainException('eq_existing_writer_native_readback');
            }
        }
    }

    private function matches(array $row, array $current): bool
    {
        $body = $row['page_id'] === 'EQ-02' ? $current['published'] : $current['values'];
        if (count($current['seo']) !== 1) {
            return false;
        }
        foreach ($row['snapshot'] as $field => $expected) {
            if (str_starts_with($field, 'seo_')) {
                $actual = $current['seo'][0][$field] ?? null;
                if ($row['page_id'] === 'EQ-02' && ($body[$field] ?? null) !== $expected) {
                    return false;
                }
            } else {
                $actual = $body[$row['page_id'] === 'SH-02' && $field === 'content_md' ? 'body_md' : $field] ?? null;
            }
            if ($actual !== $expected) {
                return false;
            }
        }

        return true;
    }

    private function article(array $row, array $current, PromotionContext $context): bool
    {
        $article = Article::query()->withoutGlobalScopes()->findOrFail($current['values']['id']);
        $old = $current['published'];
        // Approved source rows may retain their original workflow status.
        // Accept only a self-bound published source revision, never a translation.
        $isSource = $article->isSourceArticle()
            || ($article->translation_status === Article::TRANSLATION_STATUS_APPROVED
                && $article->locale === 'zh-CN' && $article->source_locale === 'zh-CN'
                && $article->source_article_id === null && $article->translated_from_article_id === null);
        $sourceId = (int) $article->id;
        $sourceLocale = $article->locale;
        $translatedSourceHash = null;
        if (! $isSource) {
            $source = $article->sourceArticle();
            if (($article->source_article_id !== null && $article->translated_from_article_id !== null
                    && (int) $article->source_article_id !== (int) $article->translated_from_article_id)
                || ! $source instanceof Article || (int) $source->id === (int) $article->id
                || (int) $source->org_id !== 0 || $source->slug !== $article->slug
                || $source->locale !== 'zh-CN' || $article->source_locale !== $source->locale
                || $source->translation_group_id !== $article->translation_group_id
                || preg_match('/\A[a-f0-9]{64}\z/', (string) $source->source_version_hash) !== 1) {
                throw new DomainException('eq_existing_writer_article_source_identity');
            }
            $sourceId = (int) $source->id;
            $sourceLocale = $source->locale;
            $translatedSourceHash = $source->source_version_hash;
        }
        if ((int) $old['source_article_id'] !== $sourceId || $old['source_locale'] !== $sourceLocale) {
            throw new DomainException('eq_existing_writer_article_source_identity');
        }
        $article->forceFill(['title' => $row['snapshot']['title'], 'excerpt' => $row['snapshot']['excerpt'], 'content_md' => $row['snapshot']['content_md'], 'content_html' => null]);
        $sourceHash = $article->computeSourceVersionHash();
        $revisionSourceHash = $translatedFromHash = $isSource ? $sourceHash : $translatedSourceHash;
        // Body equality alone cannot keep an obsolete source-version binding.
        $versionMatches = $old['source_version_hash'] === $revisionSourceHash
            && $old['translated_from_version_hash'] === $translatedFromHash
            && $article->source_version_hash === $sourceHash
            && ($isSource || $article->translated_from_version_hash === $translatedFromHash);
        if ($this->matches($row, $current) && $versionMatches) {
            return false;
        }
        if (! $versionMatches && $old['authority_package_sha256'] === $context->packageSha256) {
            throw new DomainException('eq_existing_writer_source_version_drift');
        }
        $this->assertSeo($current, 'article_id');
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $sourceId,
            'translation_group_id' => $article->translation_group_id, 'locale' => $article->locale,
            'source_locale' => $sourceLocale, 'translated_from_version_hash' => $translatedFromHash,
            'revision_number' => ((int) ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $article->id)->max('revision_number')) + 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED, 'source_version_hash' => $revisionSourceHash,
            'supersedes_revision_id' => $old['id'], 'authority_asset_key' => 'EQ-02:'.$article->locale.':'.$context->sourceCommit,
            'authority_source_package' => EqExistingPublicPagePackage::PACKAGE,
            'authority_source_hash' => $this->states->hash($row['snapshot']), 'authority_package_sha256' => $context->packageSha256,
            'authority_metadata_json' => $this->provenance($row, $context), ...$row['snapshot'], 'approved_at' => now(), 'published_at' => now(),
        ]);
        $article->forceFill(['source_version_hash' => $sourceHash, 'published_revision_id' => $revision->id]);
        if (! $isSource) {
            $article->forceFill(['translated_from_version_hash' => $translatedFromHash]);
        }
        if ($current['values']['working_revision_id'] === $old['id']) {
            $article->forceFill(['working_revision_id' => $revision->id]);
        }
        $article->saveQuietly();
        $this->updateSeo('article_seo_meta', $current, $row);
        $this->material->recordPublished($article, $revision, now());

        return true;
    }

    private function guide(array $row, array $current, PromotionContext $context): bool
    {
        if ($this->matches($row, $current)) {
            return false;
        }
        $this->assertSeo($current, 'career_guide_id');
        $guide = CareerGuide::query()->withoutGlobalScopes()->findOrFail($current['values']['id']);
        $guide->forceFill(['title' => $row['snapshot']['title'], 'excerpt' => $row['snapshot']['excerpt'], 'body_md' => $row['snapshot']['content_md'], 'body_html' => null])->saveQuietly();
        $this->updateSeo('career_guide_seo_meta', $current, $row);
        $guide->unsetRelation('seoMeta');
        $snapshot = CareerGuideWorkspace::snapshotPayload($guide);
        $snapshot['promotion'] = $this->provenance($row, $context);
        CareerGuideRevision::query()->create([
            'career_guide_id' => $guide->id, 'revision_no' => CareerGuideWorkspace::nextRevisionNo($guide),
            'snapshot_json' => $snapshot, 'note' => 'verified-automated-exact-package:EQ-EXISTING-PUBLIC-PAGES',
            'created_by_admin_user_id' => null, 'created_at' => now(),
        ]);

        return true;
    }

    private function assertSeo(array $current, string $foreignKey): void
    {
        // The existing English article may use native revision SEO without a metadata row.
        // Its exact-package publication can materialize that row; all other missing authorities fail closed.
        if ($foreignKey === 'article_id' && $current['seo'] === []
            && $current['identity'] === ['org_id' => 0, 'slug' => 'eq-test-tool-guide', 'locale' => 'en']) {
            return;
        }
        if (count($current['seo']) !== 1 || (int) $current['seo'][0][$foreignKey] !== (int) $current['values']['id']) {
            throw new DomainException('eq_existing_writer_seo_authority_missing');
        }
    }

    private function updateSeo(string $table, array $current, array $row): void
    {
        if ($table === 'article_seo_meta' && $current['seo'] === []) {
            DB::table($table)->insert([
                'org_id' => 0, 'article_id' => $current['values']['id'], 'locale' => 'en',
                'seo_title' => $row['snapshot']['seo_title'], 'seo_description' => $row['snapshot']['seo_description'],
                'is_indexable' => (bool) $current['values']['is_indexable'],
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return;
        }
        DB::table($table)->where('id', $current['seo'][0]['id'])->update([
            'seo_title' => $row['snapshot']['seo_title'], 'seo_description' => $row['snapshot']['seo_description'],
        ]);
    }

    private function provenance(array $row, PromotionContext $context): array
    {
        return ['approval_kind' => 'verified_automated_exact_package', 'source_commit' => $context->sourceCommit,
            'package_sha256' => $context->packageSha256, 'workflow_run_id' => $context->workflowRunId,
            'workflow_run_attempt' => $context->workflowRunAttempt, 'executor_release_sha256' => $context->executorReleaseSha256,
            'independent_review_input' => $row['independent_review_input'], 'independent_review_output' => $row['independent_review_output'],
            ...($row['page_id'] === 'SH-02' ? ['iq_review_input' => $row['iq_review_input'], 'iq_review_output' => $row['iq_review_output']] : [])];
    }
}
