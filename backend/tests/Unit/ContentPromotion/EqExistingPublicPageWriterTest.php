<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\CareerGuide;
use App\Models\CareerGuideRevision;
use App\Models\CareerGuideSeoMeta;
use App\Services\ContentPromotion\EqExistingPublicPagePackage;
use App\Services\ContentPromotion\EqExistingPublicPageState;
use App\Services\ContentPromotion\EqExistingPublicPageWriter;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\Scale\ScaleRegistryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EqExistingPublicPageWriterTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = '427f656fc3d6ac40eac4819c749e4962a54df0aca1ce7549ccd2eb12e25896ad';

    public function test_atomic_native_publication_keeps_working_draft_relations_flags_and_human_review_empty(): void
    {
        $this->seedNativeTargets();
        $states = app(EqExistingPublicPageState::class);
        $before = $states->read(self::SHA);
        $writer = app(EqExistingPublicPageWriter::class);
        $result = DB::transaction(fn () => $writer->publish($this->context(), $before));
        self::assertSame(6, $result['written_count']);
        $after = $result['state'];
        self::assertSame($before['articles']['EQ-02:en']['working'], $after['articles']['EQ-02:en']['working']);
        self::assertSame($before['scale_slugs'], $after['scale_slugs']);
        foreach ($before['registries'] as $index => $registry) {
            $oldValues = $registry['values'];
            $newValues = $after['registries'][$index]['values'];
            unset($oldValues['content_i18n_json'], $newValues['content_i18n_json']);
            self::assertSame($oldValues, $newValues);
            foreach (['en', 'zh'] as $locale) {
                foreach ($registry['content'][$locale]['faq'] as $faqIndex => $faq) {
                    self::assertSame($faq['q'], $after['registries'][$index]['content'][$locale]['faq'][$faqIndex]['q']);
                    self::assertSame($faq['id'], $after['registries'][$index]['content'][$locale]['faq'][$faqIndex]['id']);
                }
            }
        }
        foreach ($before['guides'] as $key => $guide) {
            self::assertSame($guide['maps'], $after['guides'][$key]['maps']);
            self::assertSame($guide['values']['is_indexable'], $after['guides'][$key]['values']['is_indexable']);
            self::assertSame($guide['revisions'][0], $after['guides'][$key]['revisions'][0]);
        }
        foreach ($after['articles'] as $article) {
            self::assertNull($article['published']['reviewed_by']);
            self::assertNull($article['published']['reviewed_at']);
            self::assertSame('verified_automated_exact_package', json_decode($article['published']['authority_metadata_json'], true)['approval_kind']);
        }
        $replay = DB::transaction(fn () => $writer->publish($this->context(), $after));
        self::assertSame(0, $replay['written_count']);
        self::assertSame($after, $replay['state']);
    }

    public function test_failure_at_the_last_guide_restores_every_prior_native_write_in_the_transaction(): void
    {
        $this->seedNativeTargets();
        $guide = CareerGuide::query()->where('locale', 'en')->firstOrFail();
        CareerGuideSeoMeta::query()->where('career_guide_id', $guide->id)->delete();
        $states = app(EqExistingPublicPageState::class);
        $before = $states->read(self::SHA);
        try {
            DB::transaction(fn () => app(EqExistingPublicPageWriter::class)->publish($this->context(), $before));
            self::fail('Missing final SEO authority must fail atomically');
        } catch (\DomainException $error) {
            self::assertSame('eq_existing_writer_seo_authority_missing', $error->getMessage());
        }
        self::assertSame($before, $states->read(self::SHA));
    }

    public function test_missing_english_article_seo_is_materialized_without_changing_qualification_or_user_draft_and_restored(): void
    {
        $this->seedNativeTargets();
        $article = Article::query()->where('locale', 'en')->firstOrFail();
        $article->forceFill(['is_indexable' => false])->saveQuietly();
        ArticleSeoMeta::query()->where('article_id', $article->id)->delete();
        $states = app(EqExistingPublicPageState::class);
        $before = $states->read(self::SHA);
        $writer = app(EqExistingPublicPageWriter::class);
        $after = DB::transaction(fn () => $writer->publish($this->context(), $before))['state'];
        self::assertCount(1, $after['articles']['EQ-02:en']['seo']);
        self::assertFalse((bool) $after['articles']['EQ-02:en']['seo'][0]['is_indexable']);
        self::assertSame($before['articles']['EQ-02:en']['working'], $after['articles']['EQ-02:en']['working']);
        self::assertSame(0, DB::transaction(fn () => $writer->publish($this->context(), $after))['written_count']);
        DB::transaction(fn () => $writer->restore($this->context(), $before, $after));
        self::assertSame($before['articles'], $states->read(self::SHA)['articles']);
    }

    public function test_new_english_article_seo_is_rolled_back_when_a_later_native_write_fails(): void
    {
        $this->seedNativeTargets();
        $article = Article::query()->where('locale', 'en')->firstOrFail();
        ArticleSeoMeta::query()->where('article_id', $article->id)->delete();
        $guide = CareerGuide::query()->where('locale', 'en')->firstOrFail();
        CareerGuideSeoMeta::query()->where('career_guide_id', $guide->id)->delete();
        $states = app(EqExistingPublicPageState::class);
        $before = $states->read(self::SHA);
        try {
            DB::transaction(fn () => app(EqExistingPublicPageWriter::class)->publish($this->context(), $before));
            self::fail('Later missing guide SEO must reject every prior write.');
        } catch (\DomainException $error) {
            self::assertSame('eq_existing_writer_seo_authority_missing', $error->getMessage());
        }
        self::assertSame($before, $states->read(self::SHA));
    }

    public function test_operator_draft_drift_is_rejected_before_publication(): void
    {
        $this->seedNativeTargets();
        $states = app(EqExistingPublicPageState::class);
        $before = $states->read(self::SHA);
        ArticleTranslationRevision::query()->where('revision_number', 2)->update(['content_md' => 'New operator bytes']);
        $changed = $states->read(self::SHA);
        try {
            DB::transaction(fn () => app(EqExistingPublicPageWriter::class)->publish($this->context(), $before));
            self::fail('Operator changes must invalidate prestate');
        } catch (\DomainException $error) {
            self::assertSame('eq_existing_writer_prestate_drift', $error->getMessage());
        }
        self::assertSame($changed, $states->read(self::SHA));
    }

    public function test_post_commit_restore_preserves_user_working_bytes_and_immutable_history(): void
    {
        $this->seedNativeTargets();
        $states = app(EqExistingPublicPageState::class);
        $before = $states->read(self::SHA);
        $writer = app(EqExistingPublicPageWriter::class);
        $published = DB::transaction(fn () => $writer->publish($this->context(), $before))['state'];
        DB::transaction(fn () => $writer->restore($this->context(), $before, $published));
        $restored = $states->read(self::SHA);
        self::assertSame($before['registries'], $restored['registries']);
        self::assertSame($before['articles'], $restored['articles']);
        foreach ($before['guides'] as $key => $guide) {
            self::assertSame($guide['values'], $restored['guides'][$key]['values']);
            self::assertSame($guide['seo'], $restored['guides'][$key]['seo']);
            self::assertSame($guide['maps'], $restored['guides'][$key]['maps']);
            self::assertCount(2, $restored['guides'][$key]['revisions']);
            self::assertSame($guide['revisions'][0], $restored['guides'][$key]['revisions'][0]);
        }
        DB::transaction(fn () => $writer->restore($this->context(), $before, $published));
        self::assertSame($restored, $states->read(self::SHA));
    }

    public function test_corrective_commit_after_restoration_preserves_history_and_working_drafts(): void
    {
        $this->seedNativeTargets();
        $states = app(EqExistingPublicPageState::class);
        $writer = app(EqExistingPublicPageWriter::class);
        $before = $states->read(self::SHA);
        $context = $this->context();
        $failed = DB::transaction(fn () => $writer->publish($context, $before))['state'];
        $history = ArticleTranslationRevision::query()->orderBy('id')->get()->map->getAttributes()->all();
        DB::transaction(fn () => $writer->restore($context, $before, $failed));
        $restored = $states->read(self::SHA);
        try {
            DB::transaction(fn () => $writer->publish($context, $restored));
            self::fail('A restored execution cannot reclaim its immutable revision.');
        } catch (\Illuminate\Database\UniqueConstraintViolationException $error) {
            self::assertStringContainsString('authority', $error->getMessage());
        }
        self::assertSame($restored, $states->read(self::SHA));
        $next = new PromotionContext($context->packageDirectory, $context->packageSha256, $context->lane, $context->subscope,
            str_repeat('f', 40), $context->executorReleaseSha256, $context->releasePolicySha256, '12346', 1,
            $context->workflowSignature, $context->expectedRowCount, $context->idempotencyKey);
        $published = DB::transaction(fn () => $writer->publish($next, $restored));
        self::assertSame(6, $published['written_count']);
        $after = $published['state'];
        self::assertSame($before['articles']['EQ-02:en']['working'], $after['articles']['EQ-02:en']['working']);
        foreach ($history as $revision) {
            self::assertSame($revision, ArticleTranslationRevision::query()->findOrFail($revision['id'])->getAttributes());
        }
        foreach ($after['articles'] as $key => $article) {
            self::assertNotSame($failed['articles'][$key]['published']['id'], $article['published']['id']);
            self::assertSame($next->sourceCommit, json_decode($article['published']['authority_metadata_json'], true)['source_commit']);
            self::assertSame($before['articles'][$key]['values']['is_indexable'], $article['values']['is_indexable']);
        }
        $correctiveHistory = ArticleTranslationRevision::query()->orderBy('id')->get()->map->getAttributes()->all();
        $correctiveGuideHistory = CareerGuideRevision::query()->orderBy('id')->get()->map->getAttributes()->all();
        self::assertSame(count($history) + 2, count($correctiveHistory));
        self::assertSame(0, DB::transaction(fn () => $writer->publish($next, $after))['written_count']);
        self::assertSame($correctiveHistory, ArticleTranslationRevision::query()->orderBy('id')->get()->map->getAttributes()->all());
        DB::transaction(fn () => $writer->restore($next, $restored, $after));
        self::assertSame($correctiveHistory, ArticleTranslationRevision::query()->orderBy('id')->get()->map->getAttributes()->all());
        self::assertSame($correctiveGuideHistory, CareerGuideRevision::query()->orderBy('id')->get()->map->getAttributes()->all());
        self::assertSame($before['articles'], $states->read(self::SHA)['articles']);
    }

    public function test_restore_does_not_overwrite_a_later_operator_change(): void
    {
        $this->seedNativeTargets();
        $states = app(EqExistingPublicPageState::class);
        $before = $states->read(self::SHA);
        $writer = app(EqExistingPublicPageWriter::class);
        $published = DB::transaction(fn () => $writer->publish($this->context(), $before))['state'];
        ArticleTranslationRevision::query()->where('content_md', 'Operator working r2')->update(['content_md' => 'Later operator change']);
        $changed = $states->read(self::SHA);
        try {
            DB::transaction(fn () => $writer->restore($this->context(), $before, $published));
            self::fail('Rollback must preserve later operator changes');
        } catch (\DomainException $error) {
            self::assertSame('eq_existing_writer_rollback_concurrent_state', $error->getMessage());
        }
        self::assertSame($changed, $states->read(self::SHA));
    }

    public function test_non_source_status_without_source_binding_still_fails_atomically(): void
    {
        $this->seedNativeTargets();
        Article::query()->where('locale', 'zh-CN')->update(['translation_status' => 'published']);
        $states = app(EqExistingPublicPageState::class);
        $before = $states->read(self::SHA);
        try {
            DB::transaction(fn () => app(EqExistingPublicPageWriter::class)->publish($this->context(), $before));
            self::fail('An unbound translation must not become a source');
        } catch (\DomainException $error) {
            self::assertSame('eq_existing_writer_article_source_identity', $error->getMessage());
        }
        self::assertSame($before, $states->read(self::SHA));
    }

    public static function bindingConflicts(): array
    {
        return [['revision_id', false], ['revision_locale', false], ['article_pointers', false],
            ['article_locale', false], ['revision_id', true], ['revision_locale', true]];
    }

    #[DataProvider('bindingConflicts')]
    public function test_conflicting_source_bindings_fail_atomically_including_no_op(string $conflict, bool $alreadyPublished): void
    {
        $this->seedNativeTargets();
        $chinese = Article::query()->where('locale', 'zh-CN')->firstOrFail();
        $english = Article::query()->where('locale', 'en')->firstOrFail();
        $chinese->forceFill(['translation_status' => 'approved'])->saveQuietly();
        $english->forceFill(['translation_status' => 'published', 'source_locale' => 'zh-CN',
            'source_article_id' => $chinese->id, 'translated_from_article_id' => $chinese->id,
            'translation_group_id' => $chinese->translation_group_id])->saveQuietly();
        ArticleTranslationRevision::query()->where('article_id', $english->id)->update([
            'source_article_id' => $chinese->id, 'source_locale' => 'zh-CN', 'translation_group_id' => $chinese->translation_group_id,
        ]);
        $states = app(EqExistingPublicPageState::class);
        $writer = app(EqExistingPublicPageWriter::class);
        if ($alreadyPublished) {
            DB::transaction(fn () => $writer->publish($this->context(), $states->read(self::SHA)));
            $english->refresh();
        }
        match ($conflict) {
            'revision_id' => ArticleTranslationRevision::query()->whereKey($english->published_revision_id)->update(['source_article_id' => $english->id]),
            'revision_locale' => ArticleTranslationRevision::query()->whereKey($english->published_revision_id)->update(['source_locale' => 'en']),
            'article_pointers' => $english->forceFill(['translated_from_article_id' => $english->id])->saveQuietly(),
            'article_locale' => $english->forceFill(['source_locale' => 'en'])->saveQuietly(),
        };
        $before = $states->read(self::SHA);
        try {
            DB::transaction(fn () => $writer->publish($this->context(), $before));
            self::fail('Conflicting provenance must not be published or replayed');
        } catch (\DomainException $error) {
            self::assertSame('eq_existing_writer_article_source_identity', $error->getMessage());
        }
        self::assertSame($before, $states->read(self::SHA));
    }

    public static function selfReferences(): array
    {
        return [['source', false], ['approved', false], ['source', true], ['approved', true]];
    }

    #[DataProvider('selfReferences')]
    public function test_self_referencing_sources_fail_atomically(string $status, bool $alreadyPublished): void
    {
        $this->seedNativeTargets();
        $states = app(EqExistingPublicPageState::class);
        $writer = app(EqExistingPublicPageWriter::class);
        if ($alreadyPublished) {
            DB::transaction(fn () => $writer->publish($this->context(), $states->read(self::SHA)));
        }
        $chinese = Article::query()->where('locale', 'zh-CN')->firstOrFail();
        $chinese->forceFill(['translation_status' => $status, 'source_article_id' => $chinese->id,
            'translated_from_article_id' => $chinese->id])->saveQuietly();
        $before = $states->read(self::SHA);
        try {
            DB::transaction(fn () => $writer->publish($this->context(), $before));
            self::fail('A source cannot enter the translation branch through self pointers');
        } catch (\DomainException $error) {
            self::assertSame('eq_existing_writer_article_source_identity', $error->getMessage());
        }
        self::assertSame($before, $states->read(self::SHA));
    }

    public static function staleVersions(): array
    {
        return [['chinese_body'], ['revision_hash'], ['article_hash']];
    }

    #[DataProvider('staleVersions')]
    public function test_matching_english_body_refreshes_source_version_and_preserves_working_draft(string $stale): void
    {
        $this->seedNativeTargets();
        $chinese = Article::query()->where('locale', 'zh-CN')->firstOrFail();
        $english = Article::query()->where('locale', 'en')->firstOrFail();
        $chinese->forceFill(['translation_status' => 'approved'])->saveQuietly();
        $english->forceFill(['translation_status' => 'published', 'source_locale' => 'zh-CN',
            'source_article_id' => $chinese->id, 'translated_from_article_id' => $chinese->id,
            'translation_group_id' => $chinese->translation_group_id])->saveQuietly();
        ArticleTranslationRevision::query()->where('article_id', $english->id)->update([
            'source_article_id' => $chinese->id, 'source_locale' => 'zh-CN', 'translation_group_id' => $chinese->translation_group_id,
        ]);
        $states = app(EqExistingPublicPageState::class);
        $writer = app(EqExistingPublicPageWriter::class);
        DB::transaction(fn () => $writer->publish($this->context(), $states->read(self::SHA)));
        $chinese->refresh();
        $english->refresh();
        // These matching bodies belong to prior, non-package publication.
        // Current exact-package revisions remain immutable and unique.
        ArticleTranslationRevision::query()->whereIn('id', [$chinese->published_revision_id, $english->published_revision_id])->update([
            'authority_package_sha256' => null, 'authority_asset_key' => null, 'authority_source_package' => null,
            'authority_source_hash' => null, 'authority_metadata_json' => null,
        ]);
        if ($stale === 'chinese_body') {
            $chinese->forceFill(['content_md' => 'Earlier Chinese source body']);
            $oldHash = $chinese->computeSourceVersionHash();
            $chinese->forceFill(['source_version_hash' => $oldHash])->saveQuietly();
            ArticleTranslationRevision::query()->whereKey($chinese->published_revision_id)->update([
                'content_md' => 'Earlier Chinese source body', 'source_version_hash' => $oldHash, 'translated_from_version_hash' => $oldHash,
            ]);
            ArticleTranslationRevision::query()->whereKey($english->published_revision_id)->update([
                'source_version_hash' => $oldHash, 'translated_from_version_hash' => $oldHash,
            ]);
            $english->forceFill(['translated_from_version_hash' => $oldHash])->saveQuietly();
        } elseif ($stale === 'revision_hash') {
            ArticleTranslationRevision::query()->whereKey($english->published_revision_id)->update([
                'source_version_hash' => str_repeat('f', 64), 'translated_from_version_hash' => str_repeat('f', 64),
            ]);
        } else {
            $english->forceFill(['translated_from_version_hash' => str_repeat('f', 64)])->saveQuietly();
        }
        $before = $states->read(self::SHA);
        $result = DB::transaction(fn () => $writer->publish($this->context(), $before));
        $after = $result['state'];
        self::assertSame($stale === 'chinese_body' ? 2 : 1, $result['written_count']);
        self::assertNotSame($before['articles']['EQ-02:en']['published']['id'], $after['articles']['EQ-02:en']['published']['id']);
        self::assertSame($after['articles']['EQ-02:zh-CN']['values']['source_version_hash'], $after['articles']['EQ-02:en']['published']['source_version_hash']);
        self::assertSame($after['articles']['EQ-02:en']['published']['source_version_hash'], $after['articles']['EQ-02:en']['published']['translated_from_version_hash']);
        self::assertSame($after['articles']['EQ-02:en']['published']['source_version_hash'], $after['articles']['EQ-02:en']['values']['translated_from_version_hash']);
        self::assertSame($before['articles']['EQ-02:en']['working'], $after['articles']['EQ-02:en']['working']);
        self::assertSame(0, DB::transaction(fn () => $writer->publish($this->context(), $after))['written_count']);
    }

    public function test_drift_in_a_current_exact_package_version_fails_without_rewriting_immutable_history(): void
    {
        $this->seedNativeTargets();
        $states = app(EqExistingPublicPageState::class);
        $writer = app(EqExistingPublicPageWriter::class);
        DB::transaction(fn () => $writer->publish($this->context(), $states->read(self::SHA)));
        $english = Article::query()->where('locale', 'en')->firstOrFail();
        ArticleTranslationRevision::query()->whereKey($english->published_revision_id)->update(['source_version_hash' => str_repeat('f', 64)]);
        $before = $states->read(self::SHA);
        try {
            DB::transaction(fn () => $writer->publish($this->context(), $before));
            self::fail('The same exact package cannot replace drifted immutable provenance');
        } catch (\DomainException $error) {
            self::assertSame('eq_existing_writer_source_version_drift', $error->getMessage());
        }
        self::assertSame($before, $states->read(self::SHA));
    }

    public static function sourceStatuses(): array
    {
        return [['source'], ['approved']];
    }

    #[DataProvider('sourceStatuses')]
    public function test_paired_english_public_revision_binds_updated_source_without_rewriting_its_working_draft(string $sourceStatus): void
    {
        $this->seedNativeTargets();
        $chinese = Article::query()->where('locale', 'zh-CN')->firstOrFail();
        $chinese->forceFill(['translation_status' => $sourceStatus])->saveQuietly();
        $english = Article::query()->where('locale', 'en')->firstOrFail();
        $english->forceFill(['translation_status' => 'published', 'source_locale' => 'zh-CN',
            'source_article_id' => $chinese->id, 'translated_from_article_id' => $chinese->id,
            'translation_group_id' => $chinese->translation_group_id, 'translated_from_version_hash' => $chinese->source_version_hash])->saveQuietly();
        ArticleTranslationRevision::query()->where('article_id', $english->id)->update([
            'source_article_id' => $chinese->id, 'source_locale' => 'zh-CN', 'translation_group_id' => $chinese->translation_group_id,
            'source_version_hash' => $chinese->source_version_hash, 'translated_from_version_hash' => $chinese->source_version_hash,
        ]);
        $states = app(EqExistingPublicPageState::class);
        $before = $states->read(self::SHA);
        $after = DB::transaction(fn () => app(EqExistingPublicPageWriter::class)->publish($this->context(), $before))['state'];
        self::assertSame($sourceStatus, $after['articles']['EQ-02:zh-CN']['values']['translation_status']);
        self::assertSame($after['articles']['EQ-02:zh-CN']['values']['source_version_hash'], $after['articles']['EQ-02:en']['published']['source_version_hash']);
        self::assertSame($after['articles']['EQ-02:zh-CN']['values']['source_version_hash'], $after['articles']['EQ-02:en']['values']['translated_from_version_hash']);
        self::assertSame($before['articles']['EQ-02:en']['working'], $after['articles']['EQ-02:en']['working']);
    }

    private function context(): PromotionContext
    {
        return new PromotionContext(base_path(EqExistingPublicPagePackage::PACKAGE), self::SHA, 'W3', 'EQ-EXISTING-PUBLIC-PAGES',
            str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12345', 1, str_repeat('d', 64), 6, str_repeat('e', 64));
    }

    public static function seedNativeTargets(): void
    {
        config(['content_packs.public_scale_cache_store' => 'array']);
        $localeContent = ['why_choose' => ['intro' => 'Original public intro', 'items' => array_fill(0, 4, ['body' => 'Original public body', 'link' => ['href' => '#original', 'label' => 'Original link']])],
            'faq' => array_fill(0, 11, ['a' => 'Original answer', 'q' => 'Original question', 'id' => 'original',
                'references' => [['href' => 'https://example.org', 'label' => 'Original reference']], 'related_links' => [['href' => '#choose-version', 'label' => 'Original link']]])];
        app(ScaleRegistryWriter::class)->upsertScale([
            'org_id' => 0, 'code' => 'EQ_60', 'primary_slug' => 'eq-test-emotional-intelligence-assessment',
            'slugs_json' => ['eq-test-emotional-intelligence-assessment'], 'driver_type' => 'eq_60',
            'default_locale' => 'en', 'is_public' => true, 'is_active' => true, 'is_indexable' => true,
            'content_i18n_json' => ['en' => $localeContent, 'zh' => $localeContent],
        ]);
        foreach (['zh-CN', 'en'] as $locale) {
            $article = Article::query()->create(['org_id' => 0, 'slug' => 'eq-test-tool-guide', 'locale' => $locale,
                'title' => 'Published title', 'excerpt' => 'Published excerpt', 'content_md' => 'Published public body', 'status' => 'published', 'is_public' => true]);
            $revision = ArticleTranslationRevision::query()->create([
                'org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $article->id,
                'translation_group_id' => $article->translation_group_id, 'locale' => $locale, 'source_locale' => $locale,
                'revision_number' => 1, 'revision_status' => 'published', 'title' => 'Published title', 'excerpt' => 'Published excerpt', 'content_md' => 'Published public body',
            ]);
            $working = $revision;
            if ($locale === 'en') {
                $working = $revision->replicate();
                $working->forceFill(['revision_number' => 2, 'revision_status' => 'machine_draft', 'content_md' => 'Operator working r2'])->save();
            }
            $article->forceFill(['published_revision_id' => $revision->id, 'working_revision_id' => $working->id])->saveQuietly();
            ArticleSeoMeta::query()->create(['article_id' => $article->id, 'org_id' => 0, 'locale' => $locale, 'seo_title' => 'Original title', 'seo_description' => 'Original description']);
            $guide = CareerGuide::query()->create(['org_id' => 0, 'guide_code' => 'eq-work-'.$locale, 'slug' => 'iq-eq-balance-at-work',
                'locale' => $locale, 'title' => 'Work guide', 'excerpt' => 'Guide excerpt', 'body_md' => 'Original guide body', 'status' => 'published', 'is_public' => true]);
            CareerGuideSeoMeta::query()->create(['career_guide_id' => $guide->id, 'seo_title' => 'Original title', 'seo_description' => 'Original description']);
            $guide->relatedArticles()->attach($article->id, ['sort_order' => 10]);
            CareerGuideRevision::query()->create(['career_guide_id' => $guide->id, 'revision_no' => 1,
                'snapshot_json' => ['guide' => ['body_md' => 'Original guide body']], 'note' => 'Original fixture history']);
        }
    }
}
