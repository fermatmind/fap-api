<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\CareerGuide;
use App\Models\CareerGuideRevision;
use App\Services\ContentPromotion\EqExistingPublicPageState;
use App\Services\Scale\ScaleRegistryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EqExistingPublicPageStateTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = '427f656fc3d6ac40eac4819c749e4962a54df0aca1ce7549ccd2eb12e25896ad';

    public function test_reads_six_native_targets_and_preserves_the_separate_english_working_revision(): void
    {
        $this->seedNativeTargets();
        $reader = app(EqExistingPublicPageState::class);
        $state = $reader->read(self::SHA);
        self::assertCount(2, $state['registries']);
        self::assertCount(2, $state['articles']);
        self::assertCount(2, $state['guides']);
        $english = $state['articles']['EQ-02:en'];
        self::assertNotSame($english['published']['id'], $english['working']['id']);
        self::assertSame('Operator working r2', $english['working']['content_md']);
        self::assertSame($state, $reader->read(self::SHA));
        self::assertSame($state, DB::transaction(fn () => $reader->read(self::SHA, true)));
    }

    public function test_state_hash_changes_for_operator_working_draft_and_guide_relationship_changes(): void
    {
        $this->seedNativeTargets();
        $reader = app(EqExistingPublicPageState::class);
        $before = $reader->hash($reader->read(self::SHA));
        ArticleTranslationRevision::query()->where('revision_number', 2)->update(['content_md' => 'Operator changed r2']);
        $draftChanged = $reader->hash($reader->read(self::SHA));
        self::assertNotSame($before, $draftChanged);
        $guide = CareerGuide::query()->where('locale', 'en')->firstOrFail();
        $article = Article::query()->where('locale', 'en')->firstOrFail();
        $guide->relatedArticles()->attach($article->id, ['sort_order' => 10]);
        self::assertNotSame($draftChanged, $reader->hash($reader->read(self::SHA)));
    }

    public function test_held_public_target_fails_before_any_publication(): void
    {
        $this->seedNativeTargets();
        CareerGuide::query()->where('locale', 'en')->update(['is_public' => false]);
        $this->expectExceptionMessage('eq_existing_state_target_not_public');
        app(EqExistingPublicPageState::class)->read(self::SHA);
    }

    public function test_cross_locale_revision_pointer_is_rejected(): void
    {
        $this->seedNativeTargets();
        $english = Article::query()->where('locale', 'en')->firstOrFail();
        $chinese = Article::query()->where('locale', 'zh-CN')->firstOrFail();
        $english->forceFill(['published_revision_id' => $chinese->published_revision_id])->saveQuietly();
        $this->expectExceptionMessage('eq_existing_state_article_revision_identity');
        app(EqExistingPublicPageState::class)->read(self::SHA);
    }

    public function test_registry_identity_or_manual_hold_is_not_ignored(): void
    {
        $this->seedNativeTargets();
        DB::table('scales_registry_v2')->where('code', 'EQ_60')->update(['is_active' => false]);
        $this->expectExceptionMessage('eq_existing_state_registry_identity_or_hold');
        app(EqExistingPublicPageState::class)->read(self::SHA);
    }

    private function seedNativeTargets(): void
    {
        config(['content_packs.public_scale_cache_store' => 'array']);
        app(ScaleRegistryWriter::class)->upsertScale([
            'org_id' => 0, 'code' => 'EQ_60', 'primary_slug' => 'eq-test-emotional-intelligence-assessment',
            'slugs_json' => ['eq-test-emotional-intelligence-assessment'], 'driver_type' => 'eq_60',
            'default_locale' => 'en', 'is_public' => true, 'is_active' => true, 'is_indexable' => true,
            'content_i18n_json' => ['en' => ['why_choose' => ['intro' => 'Original']], 'zh' => ['why_choose' => ['intro' => '原文']]],
        ]);
        foreach (['zh-CN', 'en'] as $locale) {
            $article = Article::query()->create(['org_id' => 0, 'slug' => 'eq-test-tool-guide', 'locale' => $locale,
                'title' => 'Published title', 'content_md' => 'Published public body', 'status' => 'published', 'is_public' => true]);
            $revision = ArticleTranslationRevision::query()->create([
                'org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $article->id,
                'translation_group_id' => $article->translation_group_id, 'locale' => $locale, 'source_locale' => $locale,
                'revision_number' => 1, 'revision_status' => 'published', 'title' => 'Published title', 'content_md' => 'Published public body',
            ]);
            $working = $revision;
            if ($locale === 'en') {
                $working = $revision->replicate();
                $working->forceFill(['revision_number' => 2, 'revision_status' => 'machine_draft', 'content_md' => 'Operator working r2'])->save();
            }
            $article->forceFill(['published_revision_id' => $revision->id, 'working_revision_id' => $working->id])->saveQuietly();
            $guide = CareerGuide::query()->create(['org_id' => 0, 'guide_code' => 'eq-work-'.$locale, 'slug' => 'iq-eq-balance-at-work',
                'locale' => $locale, 'title' => 'Work guide', 'body_md' => 'Original guide body', 'status' => 'published', 'is_public' => true]);
            CareerGuideRevision::query()->create(['career_guide_id' => $guide->id, 'revision_no' => 1, 'snapshot_json' => ['guide' => ['body_md' => 'Original guide body']]]);
        }
    }
}
