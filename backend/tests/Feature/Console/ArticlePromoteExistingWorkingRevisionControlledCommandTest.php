<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\ArticlePromoteExistingWorkingRevisionControlled;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticlePublishService;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class ArticlePromoteExistingWorkingRevisionControlledCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSLATION_GROUP_ID = 'article-4';

    private const SLUG = 'eq-test-tool-guide';

    private const CANONICAL = '/zh/articles/eq-test-tool-guide';

    #[DataProvider('bodyOnlySeoStates')]
    public function test_locked_body_only_editor_publication_preserves_absent_and_existing_seo(bool $hasSeo, ?string $canonical): void
    {
        $this->loginPublisher();
        config(['ops.content_release_observability.cache_invalidation_urls' => ['https://cache.example.test/revalidate'],
            'ops.content_release_observability.cache_invalidation_secret' => 'fixture-only-secret']);
        $receipt = self::frontendArticleSuccessReceipt();
        $receipt['revalidated_paths'] = ['/en', '/en/articles', '/en/articles/eq-test-tool-guide', '/llms.txt', '/llms-full.txt', '/zh/articles', '/zh/articles/eq-test-tool-guide'];
        $receipt['invalidated_tags'] = ['article-detail:en:eq-test-tool-guide', 'article-seo:en:eq-test-tool-guide'];
        Http::fake(['*' => Http::response($receipt)]);

        $article = $this->bodyOnlyArticle($hasSeo, $canonical);
        $source = $this->makeEnglishTranslation($article);
        if ($hasSeo) {
            \App\Models\EditorialReview::withoutGlobalScopes()->create([
                'org_id' => 0, 'content_type' => 'article', 'content_id' => $source->id,
                'workflow_state' => 'approved', 'last_transition_at' => now()->addSecond(),
            ]);
        }
        $sourceBefore = $source->getAttributes();
        $sourceRevisionBefore = $source->publishedRevision->getAttributes();
        $article = $article->fresh(['workingRevision', 'publishedRevision', 'seoMeta']);
        $actorId = (int) auth('admin')->id();
        config(['review_governance.mode' => 'solo_owner', 'review_governance.solo_owner_admin_user_id' => $actorId]);
        $article->workingRevision->forceFill([
            'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'created_by' => $actorId, 'reviewed_by' => null, 'reviewed_at' => null, 'approved_at' => null,
        ])->save();
        $article = $article->fresh(['workingRevision', 'publishedRevision', 'seoMeta']);
        $this->assertSame([], \App\Filament\Ops\Support\EditorialReviewChecklist::missing('article', $article));
        \App\Filament\Ops\Support\EditorialReviewAudit::assignOwner($actorId, 'article', $article);
        \App\Filament\Ops\Support\EditorialReviewAudit::assignReviewer($actorId, 'article', $article);
        app(OrgContext::class)->set(10, $actorId, 'admin');
        \Filament\Facades\Filament::setCurrentPanel(app(\Filament\PanelRegistry::class)->get('ops'));
        $review = \Livewire\Livewire::test(\App\Filament\Ops\Pages\EditorialReviewPage::class);
        $row = collect($review->get('reviewItems'))->firstWhere('id', (int) $article->id);
        $this->assertTrue($row['can_submit']);
        $review->call('submitItem', 'article', (int) $article->id)
            ->call('approveItem', 'article', (int) $article->id);

        $article = $article->fresh(['workingRevision', 'publishedRevision', 'seoMeta']);
        $this->assertSame(ArticleTranslationRevision::STATUS_APPROVED, $article->workingRevision->revision_status);
        $this->assertSame($actorId, (int) $article->workingRevision->reviewed_by);
        $this->assertTrue(app(\App\Services\Cms\CmsEditorialReviewAttestationService::class)
            ->hasApprovedEvidence('article_translation_revision', $article->workingRevision));
        $old = (int) $article->published_revision_id;
        $seo = $article->seoMeta?->getAttributes();
        $workspace = app(\App\Filament\Ops\Support\ArticleControlledPublicationWorkspace::class);
        $prepared = $workspace->prepare($article);
        $this->assertTrue(json_decode($prepared['preflight'], true)['ok'], $prepared['preflight']);
        $result = $workspace->publish($article, ['target_hash' => $prepared['target_hash'], 'preview_approved' => true]);
        $this->assertTrue($result['ok'], json_encode($result));
        $article = $article->fresh(['seoMeta']);
        $this->assertNotSame($old, (int) $article->published_revision_id);
        $this->assertSame($seo, $article->seoMeta?->getAttributes());
        $this->assertSame($hasSeo, $article->is_indexable);
        $this->assertSame($hasSeo, $article->sitemap_eligible);
        $this->assertSame($hasSeo, $article->llms_eligible);
        $this->assertNull($article->cover_image_url);
        $this->assertSame($sourceBefore, $source->fresh()->getAttributes());
        $this->assertSame($sourceRevisionBefore, $source->publishedRevision->fresh()->getAttributes());

    }

    #[DataProvider('bodyOnlyFailures')]
    public function test_body_only_exceptions_require_snapshot_exact_import_and_unchanged_metadata(string $failure): void
    {

        $article = $this->bodyOnlyArticle(false);
        if ($failure === 'import') {
            $import = ArticleEditorialPackageImport::query()->withoutGlobalScopes()->where('article_id', $article->id)->latest('id')->firstOrFail();
            $summary = $import->validation_summary_json;
            $summary['published_revision_id'] = 999999;
            $import->forceFill(['validation_summary_json' => $summary])->save();
        } elseif (in_array($failure, ['title', 'seo_title'], true)) {
            $article->workingRevision->forceFill([$failure => 'Different metadata'])->save();
        }
        if ($failure !== 'snapshot') {
            $this->assertContains('seo title', \App\Filament\Ops\Support\EditorialReviewChecklist::missing('article', $article->fresh()));
        }
        $options = ['--dry-run' => true, '--json' => true];
        if ($failure !== 'snapshot') {
            $options['--expected-article-state-sha256'] = \App\Support\ArticleSourceTargetSnapshot::publicationHash($article->fresh());
        }
        $this->assertSame(1, Artisan::call('articles:promote-existing-working-revision', $this->commandOptions($article, $options)));
        $this->assertErrorCode($this->jsonOutput(), 'cover_image_or_alt_missing');

    }

    public static function bodyOnlySeoStates(): array
    {
        return [[false, null], [true, null], [true, ''], [true, self::CANONICAL]];
    }

    public static function bodyOnlyFailures(): array
    {
        return [['snapshot'], ['import'], ['title'], ['seo_title']];
    }

    #[DataProvider('legacySourceReviewFailures')]
    public function test_legacy_source_workflow_compatibility_preserves_rejections_provenance_and_normal_translation_rules(string $failure): void
    {
        $this->loginPublisher();
        config(['review_governance.mode' => 'solo_owner', 'review_governance.solo_owner_admin_user_id' => (int) auth('admin')->id()]);
        $article = $this->bodyOnlyArticle(false);
        $source = $this->makeEnglishTranslation($article);
        $article = $article->fresh(['workingRevision', 'publishedRevision', 'seoMeta']);
        $article->workingRevision->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'reviewed_by' => null, 'reviewed_at' => null, 'approved_at' => null])->save();
        if (in_array($failure, ['rejected', 'changes_requested', 'in_review', 'ready'], true)) {
            \App\Models\EditorialReview::withoutGlobalScopes()->create(['org_id' => 0, 'content_type' => 'article',
                'content_id' => $source->id, 'workflow_state' => $failure, 'last_transition_at' => now()->addSecond()]);
        } elseif ($failure === 'source_hash') {
            $source->workingRevision->forceFill(['source_version_hash' => str_repeat('b', 64)])->save();
        } elseif ($failure === 'provenance') {
            $article->workingRevision->forceFill(['translated_from_version_hash' => str_repeat('b', 64)])->save();
        } elseif ($failure === 'metadata') {
            $article->workingRevision->forceFill(['title' => 'Unselected title change'])->save();
        } elseif ($failure === 'unapproved_source') {
            $source->publishedRevision->forceFill(['approved_at' => null])->save();
        } elseif ($failure === 'references') {
            $source->forceFill(['content_md' => 'Reference: https://example.test/source'])->save();
        }
        $sourceBefore = $source->fresh()->getAttributes();
        $oldPublished = (int) $article->published_revision_id;
        $service = app(\App\Services\Cms\ArticleTranslationWorkflowService::class);
        try {
            if ($failure === 'ordinary_translation') {
                $service->approveTranslation($article->fresh());
            } else {
                $service->approveEditorialWorkingRevision($article->fresh(), (int) auth('admin')->id());
            }
            $this->fail('The original approval blockers must remain effective.');
        } catch (\App\Services\Cms\ArticleTranslationWorkflowException) {
            $this->assertSame(ArticleTranslationRevision::STATUS_HUMAN_REVIEW, $article->fresh()->workingRevision->revision_status);
            $this->assertSame($oldPublished, (int) $article->fresh()->published_revision_id);
            $this->assertSame($sourceBefore, $source->fresh()->getAttributes());
        }
    }

    public static function legacySourceReviewFailures(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'rejected', 'changes_requested', 'in_review', 'ready', 'source_hash', 'provenance',
            'metadata', 'unapproved_source', 'references', 'ordinary_translation',
        ]);
    }

    private function makeEnglishTranslation(Article $target): Article
    {
        $source = Article::unguarded(fn (): Article => Article::withoutGlobalScopes()->create([
            'org_id' => 0, 'slug' => 'existing-source-'.$target->id, 'locale' => 'zh-CN',
            'source_locale' => 'zh-CN', 'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'translation_group_id' => self::TRANSLATION_GROUP_ID, 'title' => '既有公开源文',
            'excerpt' => '既有摘要', 'content_md' => '既有已发布正文。',
            'status' => 'published', 'is_public' => true, 'published_at' => now()->subDay(),
        ]));
        $hash = app(\App\Services\Cms\ArticleTranslationRevisionWorkspace::class)->hashForRevision($source, $source->getAttributes());
        $revision = ArticleTranslationRevision::withoutGlobalScopes()->create([
            'org_id' => 0, 'article_id' => $source->id, 'source_article_id' => $source->id,
            'translation_group_id' => self::TRANSLATION_GROUP_ID, 'locale' => 'zh-CN', 'source_locale' => 'zh-CN',
            'revision_number' => 1, 'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => $source->title, 'excerpt' => $source->excerpt, 'content_md' => $source->content_md,
            'source_version_hash' => $hash, 'published_at' => now()->subDay(),
            'reviewed_by' => 7, 'reviewed_at' => now()->subDay(), 'approved_at' => now()->subDay(),
        ]);
        $source->forceFill(['working_revision_id' => $revision->id, 'published_revision_id' => $revision->id, 'source_version_hash' => $hash])->save();
        $target->forceFill(['locale' => 'en', 'source_article_id' => $source->id, 'translated_from_article_id' => $source->id,
            'source_locale' => 'zh-CN', 'translation_status' => Article::TRANSLATION_STATUS_PUBLISHED])->save();
        foreach ([$target->workingRevision, $target->publishedRevision] as $row) {
            $row->forceFill(['locale' => 'en', 'source_article_id' => $source->id, 'source_locale' => 'zh-CN',
                'source_version_hash' => $hash, 'translated_from_version_hash' => $hash])->save();
        }
        $target->workingRevision->forceFill(['content_md' => "## Teaching guide\n\n".str_repeat('Preserve units, unknown information and interpretation limits. ', 30)])->save();
        if ($target->seoMeta) {
            $target->seoMeta->forceFill(['locale' => 'en', 'og_image_url' => null,
                'canonical_url' => $target->seoMeta->canonical_url === null ? null : str_replace('/zh/', '/en/', $target->seoMeta->canonical_url)])->save();
        }
        $import = ArticleEditorialPackageImport::withoutGlobalScopes()->where('article_id', $target->id)->latest('id')->firstOrFail();
        $exactness = $import->exactness_json;
        $exactness['body_hash'] = $this->bodyHash($target->workingRevision->content_md);
        $exactness['canonical_url'] = '/en/articles/'.self::SLUG;
        $import->forceFill(['locale' => 'en', 'body_hash' => $exactness['body_hash'], 'exactness_json' => $exactness])->save();

        return $source->fresh(['publishedRevision']);
    }

    private function bodyOnlyArticle(bool $hasSeo, ?string $canonical = null): Article
    {
        $article = $this->createExistingArticleWithWorkingRevision();
        $published = $article->publishedRevision;
        $fields = [];
        foreach (['title', 'excerpt', 'seo_title', 'seo_description'] as $field) {
            $fields[$field] = $published->getRawOriginal($field);
        }
        $article->workingRevision->forceFill($fields)->save();
        $article->forceFill(['cover_image_url' => null, 'cover_image_alt' => null,
            'is_indexable' => $hasSeo, 'sitemap_eligible' => $hasSeo, 'llms_eligible' => $hasSeo])->save();
        $article->tags()->detach();
        if ($hasSeo) {
            $article->seoMeta->forceFill(['canonical_url' => $canonical])->save();
        } else {
            $article->seoMeta->delete();
            $published->forceFill(['excerpt' => null, 'seo_title' => null, 'seo_description' => null])->save();
            $article->workingRevision->forceFill(['excerpt' => null, 'seo_title' => null, 'seo_description' => null])->save();
        }
        $import = ArticleEditorialPackageImport::query()->withoutGlobalScopes()->where('article_id', $article->id)->latest('id')->firstOrFail();
        $summary = $import->validation_summary_json;
        $summary['article_id'] = (int) $article->id;
        $summary['working_revision_id'] = (int) $article->working_revision_id;
        $summary['published_revision_id'] = (int) $published->id;
        $exactness = $import->exactness_json;
        $exactness['translation_group_id'] = (string) $article->translation_group_id;
        $import->forceFill(['validation_summary_json' => $summary, 'exactness_json' => $exactness])->save();

        return $article->fresh(['workingRevision', 'publishedRevision', 'seoMeta']);
    }

    public function test_editor_promotes_existing_revision_and_distinguishes_committed_cache_failure(): void
    {
        $this->loginPublisher();
        config(['ops.content_release_observability.cache_invalidation_urls' => ['https://cache.example.test/revalidate'],
            'ops.content_release_observability.cache_invalidation_secret' => 'fixture-only-secret']);
        Http::fake(['*' => Http::response(['ok' => false], 503)]);
        $article = $this->createExistingArticleWithWorkingRevision();
        $target = (int) $article->working_revision_id;
        $workspace = app(\App\Filament\Ops\Support\ArticleControlledPublicationWorkspace::class);
        $prepared = $workspace->prepare($article);
        $this->assertTrue(json_decode($prepared['preflight'], true)['ok']);
        $data = ['target_hash' => $prepared['target_hash'], 'preview_approved' => true];
        $result = $workspace->publish($article, $data);
        $this->assertFalse($result['ok']);
        $this->assertSame('committed', $result['publication']);
        $this->assertSame('failed', $result['cache_closeout']);
        $this->assertSame($target, (int) $article->fresh()->published_revision_id);
        Http::assertSentCount(1);
        try {
            $workspace->publish($article, $data);
            $this->fail('A committed promotion cannot replay its old snapshot.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertStringContainsString('article_publication_state_changed', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_existing_native_transaction_rejects_a_stale_editor_snapshot(): void
    {
        $article = $this->createExistingArticleWithWorkingRevision();
        $published = (int) $article->published_revision_id;
        $hash = \App\Support\ArticleSourceTargetSnapshot::publicationHash($article);
        $article->seoMeta->forceFill(['og_title' => 'Concurrent metadata edit'])->saveQuietly();
        $this->assertSame(1, Artisan::call('articles:promote-existing-working-revision', $this->commandOptions($article, [
            '--expected-article-state-sha256' => $hash,
            '--execute' => true, '--confirm' => $this->expectedConfirmation((int) $article->id, (int) $article->working_revision_id),
            '--preview-approved' => true, '--schema-hold' => true, '--hreflang-hold' => true,
            '--search-hold' => true, '--no-revalidation' => true, '--no-sitemap' => true, '--no-llms' => true, '--json' => true,
        ])));
        $payload = $this->jsonOutput();
        $this->assertSame('not_committed', $payload['publication']);
        $this->assertStringContainsString('article_publication_state_changed', json_encode($payload));
        $this->assertSame($published, (int) $article->fresh()->published_revision_id);
    }

    public function test_failed_editor_promotion_restores_then_new_owner_can_publish_without_losing_history(): void
    {
        $this->loginPublisher();
        config(['ops.content_release_observability.cache_invalidation_urls' => ['https://cache.example.test/revalidate'],
            'ops.content_release_observability.cache_invalidation_secret' => 'fixture-only-secret']);
        Http::fake(['*' => Http::response(self::frontendArticleSuccessReceipt())]);
        $article = $this->createExistingArticleWithWorkingRevision();
        $old = $article->publishedRevision->getRawOriginal();
        $candidate = $article->workingRevision->getRawOriginal();
        $workspace = app(\App\Filament\Ops\Support\ArticleControlledPublicationWorkspace::class);
        $prepared = $workspace->prepare($article);
        $dispatcher = ArticleTranslationRevision::getEventDispatcher();
        ArticleTranslationRevision::setEventDispatcher(clone $dispatcher);
        try {
            ArticleTranslationRevision::updating(function ($revision) use ($candidate): void {
                if ((int) $revision->id === (int) $candidate['id'] && $revision->revision_status === 'published') {
                    throw new RuntimeException('Synthetic transaction failure after pointer write.');
                }
            });
            try {
                $workspace->publish($article, ['target_hash' => $prepared['target_hash'], 'preview_approved' => true]);
                $this->fail('Failed transaction must be rejected.');
            } catch (\Illuminate\Validation\ValidationException) {
                $this->assertSame((int) $old['id'], (int) $article->fresh()->published_revision_id);
                $this->assertSame($candidate, $article->fresh()->workingRevision->getRawOriginal());
                $this->assertSame($old, $article->fresh()->publishedRevision->getRawOriginal());
                Http::assertNothingSent();
            }
        } finally {
            ArticleTranslationRevision::setEventDispatcher($dispatcher);
        }
        $this->loginPublisher();
        $next = $workspace->prepare($article);
        $result = $workspace->publish($article, ['target_hash' => $next['target_hash'], 'preview_approved' => true]);
        $this->assertTrue($result['ok']);
        $this->assertSame('success', $result['cache_closeout']);
        $this->assertSame((int) $candidate['id'], (int) $article->fresh()->published_revision_id);
        $this->assertSame(\Illuminate\Support\Arr::except([...$old, 'revision_status' => 'stale'], ['updated_at']), \Illuminate\Support\Arr::except(ArticleTranslationRevision::withoutGlobalScopes()->findOrFail($old['id'])->getRawOriginal(), ['updated_at']));
        $this->assertDatabaseCount('article_translation_revisions', 2);
        Http::assertSentCount(1);
    }

    private function loginPublisher(): void
    {
        config(['admin.totp.enabled' => true]);
        $role = \App\Models\Role::create(['name' => 'publisher-'.uniqid()]);
        $role->permissions()->attach(\App\Models\Permission::firstOrCreate(['name' => 'admin.owner'], ['description' => 'Owner']));
        $admin = \App\Models\AdminUser::create(['name' => 'Publisher', 'email' => uniqid().'@example.test', 'password' => 'test-only-password', 'is_active' => true]);
        $admin->roles()->attach($role);
        $this->actingAs($admin, 'admin');
        session(['ops_admin_totp_verified_user_id' => (int) $admin->id]);
    }

    #[DataProvider('taxonomyContexts')]
    public function test_preflight_uses_the_selected_articles_taxonomy_org_instead_of_ops_context(string $case): void
    {
        $article = $this->createExistingArticleWithWorkingRevision();
        if ($case === 'foreign_rows') {
            $category = ArticleCategory::withoutGlobalScopes()->create(['org_id' => 91, 'slug' => 'foreign-category', 'name' => 'Foreign category']);
            $tag = ArticleTag::withoutGlobalScopes()->create(['org_id' => 91, 'slug' => 'foreign-tag', 'name' => 'Foreign tag']);
            $article->forceFill(['category_id' => $category->id])->saveQuietly();
            $article->tags()->detach();
            $article->tags()->attach($tag->id, ['org_id' => 91]);
        } elseif (in_array($case, ['mixed_foreign_pivot', 'mixed_foreign_tag'], true)) {
            $tag = ArticleTag::withoutGlobalScopes()->create(['org_id' => $case === 'mixed_foreign_tag' ? 91 : 0, 'slug' => 'extra-tag', 'name' => 'Extra tag']);
            $article->tags()->attach($tag->id, ['org_id' => $case === 'mixed_foreign_pivot' ? 91 : 0]);
        } elseif ($case === 'mixed_dangling') {
            DB::table('article_tag_map')->insert(['org_id' => 0, 'article_id' => $article->id, 'tag_id' => 999999, 'created_at' => now()]);
        } elseif ($case === 'foreign_pivot') {
            DB::table('article_tag_map')->where('article_id', $article->id)->update(['org_id' => 91]);
        } elseif ($case === 'missing') {
            $article->forceFill(['category_id' => null])->saveQuietly();
            $article->tags()->detach();
        }
        $before = $article->fresh()->getRawOriginal();
        $links = DB::table('article_tag_map')->where('article_id', $article->id)->orderBy('tag_id')->get()->toArray();
        app(OrgContext::class)->set(91, null, 'admin');
        Artisan::call('articles:promote-existing-working-revision', $this->commandOptions($article, ['--dry-run' => true, '--json' => true]));
        $plan = $this->jsonOutput();
        $codes = array_column($plan['errors'], 'code');
        if ($case === 'valid') {
            $this->assertTrue($plan['ok'], json_encode($plan['errors']));
            $this->assertNotContains('category_missing', $codes);
            $this->assertNotContains('tags_missing', $codes);
        } elseif (str_starts_with($case, 'mixed_')) {
            $this->assertContains('tag_binding_invalid', $codes);
        } else {
            $this->assertContains('tags_missing', $codes);
            if ($case !== 'foreign_pivot') {
                $this->assertContains('category_missing', $codes);
            }
        }
        $this->assertSame($before, $article->fresh()->getRawOriginal());
        $this->assertEquals($links, DB::table('article_tag_map')->where('article_id', $article->id)->orderBy('tag_id')->get()->toArray());
    }

    public function test_controlled_execution_accepts_global_taxonomy_under_another_ops_tenant(): void
    {
        Http::fake();
        $article = $this->createExistingArticleWithWorkingRevision();
        $revisionId = (int) $article->working_revision_id;
        $oldPublished = $article->publishedRevision->getRawOriginal();
        app(OrgContext::class)->set(91, null, 'admin');
        $this->assertSame(0, Artisan::call('articles:promote-existing-working-revision', $this->commandOptions($article, [
            '--execute' => true, '--confirm' => $this->expectedConfirmation((int) $article->id, $revisionId),
            '--preview-approved' => true, '--schema-hold' => true, '--hreflang-hold' => true,
            '--search-hold' => true, '--no-revalidation' => true, '--no-sitemap' => true, '--no-llms' => true,
            '--json' => true,
        ])), Artisan::output());
        $article->refresh();
        $this->assertSame($revisionId, (int) $article->published_revision_id);
        $this->assertTrue($article->is_public);
        $this->assertSame(\Illuminate\Support\Arr::except([...$oldPublished, 'revision_status' => ArticleTranslationRevision::STATUS_STALE], ['updated_at']), \Illuminate\Support\Arr::except(ArticleTranslationRevision::withoutGlobalScopes()->findOrFail($oldPublished['id'])->getRawOriginal(), ['updated_at']));
        Http::assertNothingSent();
    }

    public static function taxonomyContexts(): array
    {
        return [['valid'], ['foreign_rows'], ['foreign_pivot'], ['missing'], ['mixed_foreign_pivot'], ['mixed_foreign_tag'], ['mixed_dangling']];
    }

    public function test_native_login_mfa_preview_and_review_bind_the_exact_isolated_revision(): void
    {
        // Exercise real login/MFA without coupling this article test to a Redis
        // daemon. The distributed limiter's key semantics have their own suite.
        \Illuminate\Support\Facades\Redis::shouldReceive('del')->times(4)->andReturn(1);
        config(['admin.panel_enabled' => true, 'admin.totp.enabled' => true]);
        \Filament\Facades\Filament::setCurrentPanel(app(\Filament\PanelRegistry::class)->get('ops'));
        $password = 'Isolated-test-password-42!';
        $actor = \App\Models\AdminUser::query()->create([
            'name' => 'isolated-test-owner', 'email' => 'isolated-owner@example.test',
            'password' => bcrypt($password), 'is_active' => 1,
        ]);
        $permission = \App\Models\Permission::query()->firstOrCreate(
            ['name' => 'admin.owner'], ['guard_name' => 'admin']
        );
        $role = \App\Models\Role::query()->create(['name' => 'isolated-test-owner', 'guard_name' => 'admin']);
        $role->permissions()->attach($permission);
        $actor->roles()->attach($role);
        $org = \App\Models\Organization::query()->create([
            'name' => 'Isolated acceptance', 'owner_user_id' => 0, 'status' => 'active',
            'domain' => 'isolated.example.test', 'timezone' => 'UTC', 'locale' => 'zh-CN',
        ]);
        config(['review_governance.solo_owner_admin_user_id' => (int) $actor->id]);
        $totp = app(\App\Services\Auth\AdminTotpService::class);
        $secret = $totp->generateSecret();
        $totp->enableForUser($actor, $secret, []);
        $article = $this->createExistingArticleWithWorkingRevision(['working_revision_status' => 'human_review']);
        $revision = $article->workingRevision;
        $revision->forceFill(['reviewed_by' => null, 'reviewed_at' => null, 'approved_at' => null])->save();
        $article->refresh();
        $preview = \App\Filament\Ops\Resources\ArticleResource\Support\ArticleWorkspace::previewUrl($article, (int) $revision->id);

        $this->get($preview)->assertUnauthorized();
        \Livewire\Livewire::test(\App\Filament\Ops\Pages\OpsLogin::class)
            ->fillForm(['email' => $actor->email, 'password' => $password])
            ->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($actor, 'admin');
        $this->get($preview)->assertRedirect(route('filament.ops.pages.two-factor-challenge'));
        $code = (new ReflectionMethod($totp, 'totpAt'))->invoke($totp, $secret, (int) floor(time() / 30));
        \Livewire\Livewire::test(\App\Filament\Ops\Pages\TwoFactorChallenge::class)
            ->set('code', $code)->call('verify')->assertRedirect('/ops/select-org');
        $this->assertSame((int) $actor->id, session('ops_admin_totp_verified_user_id'));
        \Livewire\Livewire::test(\App\Filament\Ops\Pages\SelectOrgPage::class)
            ->call('selectOrg', (int) $org->id)->assertRedirect('/ops');
        $this->get($preview)->assertOk()->assertSee($revision->title)
            ->assertHeader('X-Robots-Tag', 'noindex, noarchive, nosnippet');

        $approved = app(\App\Services\Cms\ArticleTranslationWorkflowService::class)
            ->approveEditorialWorkingRevision($article, (int) $actor->id);
        $this->assertSame('approved', $approved->revision_status);
        $this->assertSame((int) $actor->id, (int) $approved->reviewed_by);
        $this->assertNotNull($approved->approved_at);
        $reviews = app(\App\Services\Cms\CmsEditorialReviewAttestationService::class);
        $this->assertTrue($reviews->hasApprovedEvidence('article', $article->fresh()));
        $this->assertTrue($reviews->hasApprovedEvidence('article_translation_revision', $approved));
        $this->get($preview)->assertConflict();
        $this->assertNotSame((int) $approved->id, (int) $article->fresh()->published_revision_id);
    }

    private function revalidationOptions(Article $article, array $extra = []): array
    {
        return $this->commandOptions($article, array_merge([
            '--execute' => true,
            '--confirm' => $this->expectedConfirmation((int) $article->id, (int) $article->working_revision_id),
            '--preview-approved' => true, '--schema-hold' => true, '--hreflang-hold' => true,
            '--search-hold' => true, '--no-sitemap' => true, '--no-llms' => true,
            '--revalidate-after-commit' => true, '--json' => true,
        ], $extra));
    }

    public function test_single_target_revalidation_runs_once_after_commit_and_repeat_does_not_publish_again(): void
    {
        config(['ops.content_release_observability.cache_invalidation_urls' => ['https://cache.example.test/revalidate'],
            'ops.content_release_observability.cache_invalidation_secret' => 'fixture-only-secret']);
        $article = $this->createExistingArticleWithWorkingRevision();
        $target = (int) $article->working_revision_id;
        $baseline = \Illuminate\Support\Facades\DB::transactionLevel();
        Http::fake(function ($request) use ($article, $target, $baseline) {
            $this->assertSame($baseline, \Illuminate\Support\Facades\DB::transactionLevel());
            $this->assertSame($target, (int) $article->fresh()->published_revision_id);
            $this->assertContains(self::CANONICAL, $request['cache_signal']['paths']);

            return Http::response(self::frontendArticleSuccessReceipt());
        });
        $options = $this->revalidationOptions($article);
        $this->assertSame(0, Artisan::call('articles:promote-existing-working-revision', $options));
        $payload = $this->jsonOutput();
        $this->assertSame('committed', $payload['publication']);
        $this->assertSame('success', $payload['cache_closeout']);
        Http::assertSentCount(1);
        $count = ArticleTranslationRevision::withoutGlobalScopes()->count();
        $this->assertSame(1, Artisan::call('articles:promote-existing-working-revision', $options));
        $this->assertSame($count, ArticleTranslationRevision::withoutGlobalScopes()->count());
        Http::assertSentCount(1);
        $this->getJson('/api/v0.5/articles/'.self::SLUG.'?org_id=0&locale=zh-CN')
            ->assertOk()->assertJsonPath('article.title', $article->fresh()->title);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cacheCloseoutFailures')]
    public function test_cache_failure_is_reported_as_committed_without_repromoting(int $status, mixed $body): void
    {
        config(['ops.content_release_observability.cache_invalidation_urls' => ['https://cache.example.test/revalidate'],
            'ops.content_release_observability.cache_invalidation_secret' => 'fixture-only-secret']);
        Http::fake(['*' => Http::response($body, $status)]);
        $article = $this->createExistingArticleWithWorkingRevision();
        $target = (int) $article->working_revision_id;
        $this->assertSame(1, Artisan::call('articles:promote-existing-working-revision', $this->revalidationOptions($article)));
        $payload = $this->jsonOutput();
        $this->assertFalse($payload['ok']);
        $this->assertSame('committed', $payload['publication']);
        $this->assertSame('failed', $payload['cache_closeout']);
        $this->assertSame($target, (int) $article->fresh()->published_revision_id);
        Http::assertSentCount(1);
    }

    public function test_native_post_commit_failure_keeps_the_publication_committed_and_prevents_repeat(): void
    {
        config(['ops.content_release_observability.cache_invalidation_urls' => ['https://cache.example.test/revalidate'],
            'ops.content_release_observability.cache_invalidation_secret' => 'fixture-only-secret']);
        Http::fake();
        $article = $this->createExistingArticleWithWorkingRevision();
        $target = (int) $article->working_revision_id;
        $options = $this->revalidationOptions($article);
        \Illuminate\Support\Facades\File::partialMock()
            ->shouldReceive('ensureDirectoryExists')->once()
            ->andThrow(new RuntimeException('Public projection mutation lock unavailable.'));
        $this->assertSame(1, Artisan::call('articles:promote-existing-working-revision', $options));
        $payload = $this->jsonOutput();
        $this->assertFalse($payload['ok']);
        $this->assertSame('committed', $payload['publication']);
        $this->assertSame((int) $article->id, $payload['promoted_article_id']);
        $this->assertSame('failed', $payload['cache_closeout']);
        $this->assertSame($target, (int) $article->fresh()->published_revision_id);
        $this->assertErrorCode($payload, 'committed_promotion_closeout_failed');
        $count = ArticleTranslationRevision::withoutGlobalScopes()->count();
        $this->assertSame(1, Artisan::call('articles:promote-existing-working-revision', $options));
        $this->assertSame($count, ArticleTranslationRevision::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    // Captures the existing frontend article consumer's native response shape,
    // including its implicit cross-locale paths (observed on staging).
    private static function frontendArticleSuccessReceipt(): array
    {
        return ['ok' => true, 'revalidated_paths' => [
            '/zh', '/zh/articles', '/zh/articles/eq-test-tool-guide', '/llms.txt', '/llms-full.txt',
            '/en/articles', '/en/articles/eq-test-tool-guide',
        ], 'rejected_paths' => [], 'invalidated_tags' => [
            'article-detail:zh-CN:eq-test-tool-guide', 'article-seo:zh-CN:eq-test-tool-guide',
        ]];
    }

    public function test_new_mode_transaction_failure_rolls_back_and_sends_no_http(): void
    {
        config(['ops.content_release_observability.cache_invalidation_urls' => ['https://cache.example.test/revalidate'],
            'ops.content_release_observability.cache_invalidation_secret' => 'fixture-only-secret']);
        Http::fake();
        $article = $this->createExistingArticleWithWorkingRevision();
        $published = (int) $article->published_revision_id;
        $target = (int) $article->working_revision_id;
        $baseline = \Illuminate\Support\Facades\DB::transactionLevel();
        $entered = false;
        $dispatcher = ArticleTranslationRevision::getEventDispatcher();
        ArticleTranslationRevision::setEventDispatcher(clone $dispatcher);
        try {
            ArticleTranslationRevision::updating(function (ArticleTranslationRevision $revision) use ($article, $target, $baseline, &$entered): void {
                if ((int) $revision->id === $target && $revision->revision_status === ArticleTranslationRevision::STATUS_PUBLISHED) {
                    $this->assertGreaterThan($baseline, \Illuminate\Support\Facades\DB::transactionLevel());
                    // The article pointer was written before this revision event.
                    $this->assertSame($target, (int) $article->fresh()->published_revision_id);
                    $entered = true;
                    throw new RuntimeException('Synthetic transaction failure.');
                }
            });
            $this->assertSame(1, Artisan::call('articles:promote-existing-working-revision', $this->revalidationOptions($article)));
            $this->assertTrue($entered);
            $payload = $this->jsonOutput();
            $this->assertFalse($payload['ok']);
            $this->assertSame('not_committed', $payload['publication']);
            $this->assertNull($payload['promoted_article_id']);
            $this->assertSame($published, (int) $article->fresh()->published_revision_id);
            $this->assertSame(ArticleTranslationRevision::STATUS_APPROVED, $article->fresh()->workingRevision->revision_status);
            Http::assertNothingSent();
        } finally {
            ArticleTranslationRevision::setEventDispatcher($dispatcher);
        }
    }

    public static function cacheCloseoutFailures(): array
    {
        $missing = self::frontendArticleSuccessReceipt();
        array_pop($missing['revalidated_paths']);
        $extra = self::frontendArticleSuccessReceipt();
        $extra['revalidated_paths'][] = '/en/tests/unrequested';

        return [[503, ['ok' => false]], [200, ['ok' => false]], [200, 'invalid acknowledgement'],
            [200, ['ok' => true]], [200, $missing], [200, $extra]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('configuredCacheSecrets')]
    public function test_new_mode_uses_the_existing_sender_secret_precedence(string $hmac, string $legacy, string $effective): void
    {
        config(['ops.content_release_observability.cache_invalidation_urls' => ['https://cache.example.test/revalidate'],
            'ops.content_release_observability.hmac_revalidation_secret' => $hmac,
            'ops.content_release_observability.cache_invalidation_secret' => $legacy]);
        Http::fake(function ($request) use ($effective) {
            $timestamp = $request->header('X-FM-Content-Release-Timestamp')[0];
            $nonce = $request->header('X-FM-Content-Release-Nonce')[0];
            $this->assertSame('sha256='.hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$request->body(), $effective),
                $request->header('X-FM-Content-Release-Signature')[0]);

            return Http::response(self::frontendArticleSuccessReceipt());
        });
        $article = $this->createExistingArticleWithWorkingRevision();
        $this->assertSame(0, Artisan::call('articles:promote-existing-working-revision', $this->revalidationOptions($article)));
        $this->assertSame('success', $this->jsonOutput()['cache_closeout']);
        Http::assertSentCount(1);
    }

    public static function configuredCacheSecrets(): array
    {
        return [['hmac-fixture', '', 'hmac-fixture'], ['', 'legacy-fixture', 'legacy-fixture'],
            ['hmac-fixture', 'legacy-fixture', 'hmac-fixture']];
    }

    public function test_missing_configuration_and_conflicting_modes_fail_before_promotion(): void
    {
        Http::fake();
        $article = $this->createExistingArticleWithWorkingRevision();
        $published = (int) $article->published_revision_id;
        foreach ([[], ['--no-revalidation' => true]] as $extra) {
            $this->assertSame(1, Artisan::call('articles:promote-existing-working-revision', $this->revalidationOptions($article, $extra)));
            $this->assertSame('not_committed', $this->jsonOutput()['publication']);
            $this->assertSame($published, (int) $article->fresh()->published_revision_id);
        }
        Http::assertNothingSent();
    }

    public function test_dry_run_accepts_approved_existing_article_working_revision_without_writes(): void
    {
        $article = $this->createExistingArticleWithWorkingRevision();
        $publishedRevisionId = (int) $article->published_revision_id;
        $workingRevisionId = (int) $article->working_revision_id;

        $exitCode = Artisan::call('articles:promote-existing-working-revision', $this->commandOptions($article, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame('would_promote_existing_working_revision', $payload['action']);
        $this->assertSame($this->expectedConfirmation((int) $article->id, $workingRevisionId), $payload['expected_confirmation']);
        $this->assertSame($workingRevisionId, (int) data_get($payload, 'plan.working_revision_id'));
        $this->assertSame($publishedRevisionId, (int) data_get($payload, 'plan.published_revision_id'));
        $this->assertSame('approved', (string) data_get($payload, 'plan.working_revision_status'));
        $this->assertContains('references_operator_review_hold', array_map(
            static fn (array $warning): string => (string) ($warning['code'] ?? ''),
            data_get($payload, 'plan.warnings', [])
        ));

        $article->refresh();
        $this->assertSame('Published EQ article', (string) $article->title);
        $this->assertSame($publishedRevisionId, (int) $article->published_revision_id);
        $this->assertSame($workingRevisionId, (int) $article->working_revision_id);
        $this->assertSame(ArticleTranslationRevision::STATUS_APPROVED, (string) $article->workingRevision?->revision_status);
    }

    public function test_dry_run_rejects_short_body_and_forbidden_draft_markers(): void
    {
        $article = $this->createExistingArticleWithWorkingRevision([
            'working_body' => "## Draft\n\nBig Five Authority V2 draft candidate pending manual review.\n\n短模板。",
        ]);

        $exitCode = Artisan::call('articles:promote-existing-working-revision', $this->commandOptions($article, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertErrorCode($payload, 'forbidden_draft_marker');
        $this->assertErrorCode($payload, 'body_han_characters_below_minimum');
        $this->assertFalse((bool) data_get($payload, 'plan.editorial_completeness.ok'));
        $this->assertSame(2000, data_get($payload, 'plan.editorial_completeness.minimum_han_characters'));
    }

    public function test_execute_revalidates_editorial_completeness_inside_promotion_transaction(): void
    {
        $article = $this->createExistingArticleWithWorkingRevision();
        $publishedRevisionId = (int) $article->published_revision_id;
        $workingRevisionId = (int) $article->working_revision_id;
        $command = app(ArticlePromoteExistingWorkingRevisionControlled::class);
        $assertCompleteness = new ReflectionMethod($command, 'assertEditorialCompleteness');

        $this->assertNull($assertCompleteness->invoke($command, $article, $article->workingRevision));

        ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereKey($workingRevisionId)
            ->update([
                'content_md' => 'Big Five Authority V2 draft candidate pending manual review.',
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forbidden_draft_marker');

        try {
            app(ArticlePublishService::class)->promoteExistingWorkingRevision(
                (int) $article->id,
                $workingRevisionId,
                $publishedRevisionId,
                dispatchFollowUp: false,
                transactionGuard: fn (Article $lockedArticle, ArticleTranslationRevision $lockedRevision) => $assertCompleteness->invoke(
                    $command,
                    $lockedArticle,
                    $lockedRevision,
                ),
            );
        } finally {
            $article->refresh();
            $this->assertSame($publishedRevisionId, (int) $article->published_revision_id);
            $this->assertSame($workingRevisionId, (int) $article->working_revision_id);
            $this->assertSame(ArticleTranslationRevision::STATUS_APPROVED, (string) $article->workingRevision?->revision_status);
        }
    }

    public function test_independent_promotion_rejects_tag_drift_and_rolls_back_transaction(): void
    {
        $article = $this->createExistingArticleWithWorkingRevision();
        $tagsHash = \App\Support\ArticleSourceTargetSnapshot::tagsHash($article);
        $revision = $article->workingRevision;
        $revision->forceFill(['authority_metadata_json' => [
            ...($revision->authority_metadata_json ?? []),
            'draft_origin' => 'published_english_editorial_adaptation',
            'target_tags_sha256' => $tagsHash,
        ]])->saveQuietly();
        $before = $article->getAttributes();
        try {
            app(ArticlePublishService::class)->promoteExistingWorkingRevision(
                (int) $article->id, (int) $revision->id, (int) $article->published_revision_id,
                dispatchFollowUp: false,
                transactionGuard: function (Article $lockedArticle): void {
                    \Illuminate\Support\Facades\DB::table('article_tag_map')->where('article_id', $lockedArticle->id)->delete();
                },
            );
            $this->fail('tag drift must reject promotion');
        } catch (RuntimeException $exception) {
            $this->assertSame('independent_candidate_tags_drift', $exception->getMessage());
        }
        $this->assertSame($before, $article->fresh()->getAttributes());
        $this->assertSame($tagsHash, \App\Support\ArticleSourceTargetSnapshot::tagsHash($article));
        $this->assertSame('approved', $revision->fresh()->revision_status);
        $revision->forceFill(['authority_metadata_json' => [...($revision->authority_metadata_json ?? []), 'target_tags_sha256' => str_repeat('a', 64)]])->saveQuietly();
        $this->assertSame(1, Artisan::call('articles:promote-existing-working-revision', $this->commandOptions($article, ['--dry-run' => true, '--json' => true])));
        $this->assertErrorCode($this->jsonOutput(), 'independent_candidate_tags_drift');
    }

    public function test_execute_promotes_existing_article_working_revision_and_preserves_route_state(): void
    {
        config()->set('ops.content_release_observability.cache_invalidation_urls', ['https://cache.example.test/revalidate']);
        config()->set('ops.content_release_observability.broadcast_webhook', 'https://events.example.test/releases');
        Http::fake();

        $article = $this->createExistingArticleWithWorkingRevision();
        $firstPublishedAt = $article->published_at->toISOString();
        $previousPublishedRevisionId = (int) $article->published_revision_id;
        $workingRevisionId = (int) $article->working_revision_id;

        $exitCode = Artisan::call('articles:promote-existing-working-revision', $this->commandOptions($article, [
            '--execute' => true,
            '--confirm' => $this->expectedConfirmation((int) $article->id, $workingRevisionId),
            '--preview-approved' => true,
            '--schema-hold' => true,
            '--hreflang-hold' => true,
            '--search-hold' => true,
            '--no-revalidation' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame((int) $article->id, (int) $payload['promoted_article_id']);

        $article = Article::query()
            ->withoutGlobalScopes()
            ->with(['workingRevision', 'publishedRevision', 'seoMeta'])
            ->findOrFail((int) $article->id);

        $this->assertSame($firstPublishedAt, $article->published_at->toISOString());
        $this->assertNotSame($firstPublishedAt, $article->publishedRevision->published_at->toISOString());
        $jsonLd = app(\App\Services\Cms\ArticleSeoService::class)->generateJsonLd($article, $article->publishedRevision);
        $this->assertSame($article->published_at->toAtomString(), $jsonLd['datePublished']);
        $this->assertSame($article->publishedRevision->updated_at->toAtomString(), $jsonLd['dateModified']);
        $this->getJson('/api/v0.5/articles/'.self::SLUG.'?org_id=0&locale=zh-CN')
            ->assertOk()->assertJsonPath('article.published_at', $firstPublishedAt);
        $this->getJson('/api/v0.5/articles?org_id=0&locale=zh-CN')
            ->assertOk()->assertJsonPath('items.0.published_at', $firstPublishedAt);
        $this->assertSame(self::SLUG, (string) $article->slug);
        $this->assertSame(self::TRANSLATION_GROUP_ID, (string) $article->translation_group_id);
        $this->assertSame('published', (string) $article->status);
        $this->assertTrue((bool) $article->is_public);
        $this->assertTrue((bool) $article->is_indexable);
        $this->assertTrue((bool) $article->sitemap_eligible);
        $this->assertTrue((bool) $article->llms_eligible);
        $this->assertSame($workingRevisionId, (int) $article->published_revision_id);
        $this->assertSame($workingRevisionId, (int) $article->working_revision_id);
        $this->assertSame('EQ测试怎么用：从分数到情绪调节的完整指南', (string) $article->title);
        $this->assertSame('更新后的 EQ 正文摘要', (string) $article->excerpt);
        $this->assertStringContainsString('## EQ 分数应该怎么理解', (string) $article->content_md);
        $this->assertNull($article->content_html);

        $this->assertSame(ArticleTranslationRevision::STATUS_PUBLISHED, (string) $article->workingRevision?->revision_status);
        $this->assertSame($workingRevisionId, (int) $article->publishedRevision?->id);
        $this->assertNotSame($previousPublishedRevisionId, (int) $article->published_revision_id);
        $this->assertSame(
            ArticleTranslationRevision::STATUS_STALE,
            (string) ArticleTranslationRevision::query()->withoutGlobalScopes()->findOrFail($previousPublishedRevisionId)->revision_status,
        );
        $this->assertSame('EQ测试怎么用：从分数到情绪调节的完整指南 | FermatMind', (string) $article->seoMeta?->seo_title);
        $this->assertSame('更新后的 EQ SEO 描述。', (string) $article->seoMeta?->seo_description);
        $this->assertSame('https://fermatmind.com'.self::CANONICAL, (string) $article->seoMeta?->canonical_url);
        $this->assertSame('https://ops.fermatmind.com/storage/media-library/variants/articleeqscoreemotional-intelligencepillarcoverv1/hero_1600x900.jpg', (string) $article->seoMeta?->og_image_url);
        $this->assertSame('index,follow', (string) $article->seoMeta?->robots);
        Http::assertNothingSent();
    }

    public function test_dry_run_rejects_human_review_revision(): void
    {
        $article = $this->createExistingArticleWithWorkingRevision([
            'working_revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'approved_at' => null,
        ]);

        $exitCode = Artisan::call('articles:promote-existing-working-revision', $this->commandOptions($article, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertErrorCode($payload, 'revision_not_editorially_approved');
    }

    public function test_execute_requires_confirmation_preview_and_downstream_hold_flags(): void
    {
        $article = $this->createExistingArticleWithWorkingRevision();

        $exitCode = Artisan::call('articles:promote-existing-working-revision', $this->commandOptions($article, [
            '--execute' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'confirmation_mismatch');
        $this->assertErrorCode($payload, 'preview_approval_required');
        $this->assertErrorCode($payload, 'required_hold_flag_missing');
    }

    public function test_dry_run_rejects_published_revision_lock_mismatch(): void
    {
        $article = $this->createExistingArticleWithWorkingRevision();

        $exitCode = Artisan::call('articles:promote-existing-working-revision', $this->commandOptions($article, [
            '--current-published-revision-id' => ((int) $article->published_revision_id) + 999,
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertErrorCode($payload, 'published_revision_lock_mismatch');
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function commandOptions(Article $article, array $overrides = []): array
    {
        return array_replace([
            '--article-id' => (int) $article->id,
            '--working-revision-id' => (int) $article->working_revision_id,
            '--current-published-revision-id' => (int) $article->published_revision_id,
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--expected-slug' => self::SLUG,
            '--expected-canonical' => self::CANONICAL,
        ], $overrides);
    }

    private function expectedConfirmation(int $articleId, int $workingRevisionId): string
    {
        return "I explicitly approve Codex to promote article id {$articleId} working revision {$workingRevisionId} after preflight passes.";
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, Artisan::output());

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertErrorCode(array $payload, string $code): void
    {
        $this->assertContains($code, array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createExistingArticleWithWorkingRevision(array $overrides = []): Article
    {
        $category = ArticleCategory::query()->withoutGlobalScopes()->firstOrCreate(
            ['org_id' => 0, 'slug' => 'emotional-intelligence'],
            ['name' => '情绪智能', 'is_active' => true]
        );
        $tag = ArticleTag::query()->withoutGlobalScopes()->firstOrCreate(
            ['org_id' => 0, 'slug' => 'eq'],
            ['name' => 'EQ', 'is_active' => true]
        );
        $publishedBody = "## Existing EQ article\n\n旧正文。";
        $workingBody = (string) ($overrides['working_body'] ?? (
            "## EQ 分数应该怎么理解\n\nEQ 分数是情绪线索，不是能力判决。\n\n"
            .str_repeat('理解情绪线索时，需要结合场景、持续时间和个人目标，再用一次小实验记录变化。', 100)
            ."\n\n## 下一步\n\n[开始 EQ 测试](/zh/tests/eq-test-emotional-intelligence-assessment)"
        ));
        $workingStatus = (string) ($overrides['working_revision_status'] ?? ArticleTranslationRevision::STATUS_APPROVED);

        $article = Article::unguarded(fn (): Article => Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'category_id' => (int) $category->id,
            'slug' => self::SLUG,
            'locale' => 'zh-CN',
            'translation_group_id' => self::TRANSLATION_GROUP_ID,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => 'Published EQ article',
            'excerpt' => 'Published EQ excerpt',
            'content_md' => $publishedBody,
            'content_html' => '<p>old html</p>',
            'cover_image_url' => 'https://ops.fermatmind.com/storage/media-library/variants/articleeqscoreemotional-intelligencepillarcoverv1/hero_1600x900.jpg',
            'cover_image_alt' => 'EQ测试与情绪理解流程示意图',
            'cover_image_width' => 1600,
            'cover_image_height' => 900,
            'status' => 'published',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'published_at' => now()->subDay(),
        ]));
        $article->tags()->attach((int) $tag->id, ['org_id' => 0]);

        $publishedRevision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => self::TRANSLATION_GROUP_ID,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => 'Published EQ article',
            'excerpt' => 'Published EQ excerpt',
            'content_md' => $publishedBody,
            'seo_title' => 'Published EQ SEO',
            'seo_description' => 'Published EQ SEO description',
            'published_at' => now()->subDay(),
        ]);

        $workingRevision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => self::TRANSLATION_GROUP_ID,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 2,
            'revision_status' => $workingStatus,
            'title' => 'EQ测试怎么用：从分数到情绪调节的完整指南',
            'excerpt' => '更新后的 EQ 正文摘要',
            'content_md' => $workingBody,
            'seo_title' => 'EQ测试怎么用：从分数到情绪调节的完整指南 | FermatMind',
            'seo_description' => '更新后的 EQ SEO 描述。',
            'reviewed_by' => $overrides['reviewed_by'] ?? 7,
            'reviewed_at' => array_key_exists('reviewed_at', $overrides) ? $overrides['reviewed_at'] : now()->subMinutes(10),
            'approved_at' => array_key_exists('approved_at', $overrides) ? $overrides['approved_at'] : now()->subMinutes(5),
            'supersedes_revision_id' => (int) $publishedRevision->id,
        ]);

        $article->forceFill([
            'working_revision_id' => (int) $workingRevision->id,
            'published_revision_id' => (int) $publishedRevision->id,
        ])->save();

        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'zh-CN',
            'seo_title' => 'Published EQ SEO',
            'seo_description' => 'Published EQ SEO description',
            'canonical_url' => 'https://fermatmind.com'.self::CANONICAL,
            'og_title' => 'Published EQ OG',
            'og_description' => 'Published EQ OG description',
            'og_image_url' => 'https://ops.fermatmind.com/storage/media-library/variants/articleeqscoreemotional-intelligencepillarcoverv1/hero_1600x900.jpg',
            'robots' => 'index,follow',
            'schema_json' => ['status' => 'existing_public_schema'],
            'is_indexable' => true,
        ]);

        ArticleEditorialPackageImport::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'slug' => self::SLUG,
            'locale' => 'zh-CN',
            'title' => 'EQ测试怎么用：从分数到情绪调节的完整指南',
            'content_track' => 'seo_content_package_existing_article_update',
            'status' => ArticleEditorialPackageImport::STATUS_IMPORTED,
            'intended_status' => 'working_revision_human_review',
            'validation_summary_json' => [
                'source' => 'articles:update-existing-seo-content-package',
                'operation' => 'update_existing_article_working_revision',
                'schema_hreflang_search_hold' => true,
            ],
            'claim_result_json' => ['status' => 'not_reviewed', 'matches' => []],
            'exactness_json' => [
                'status' => 'passed',
                'article_id' => (int) $article->id,
                'slug' => self::SLUG,
                'canonical_url' => self::CANONICAL,
                'body_hash' => $this->bodyHash($workingBody),
            ],
            'references_json' => ['status' => 'operator_review_required'],
            'media_json' => ['status' => 'unchanged_hold'],
            'graph_json' => ['status' => 'unchanged_hold'],
            'answer_surface_json' => ['status' => 'visible_only'],
            'body_hash' => $this->bodyHash($workingBody),
            'heading_sequence_json' => ['2:EQ 分数应该怎么理解', '2:下一步'],
            'references_count' => 0,
        ]);

        return $article->fresh(['workingRevision', 'publishedRevision', 'seoMeta', 'category', 'tags']) ?? $article;
    }

    private function bodyHash(string $body): string
    {
        return hash('sha256', preg_replace("/\r\n?/", "\n", trim($body)));
    }
}
