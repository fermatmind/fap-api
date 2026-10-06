<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\BlogV1RevisionWorkspaceCommand as Command;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Models\LandingSurface;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\ArticleTranslationWorkflowService;
use App\Services\Cms\BlogV1RevisionWorkspace as Workspace;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

final class BlogV1RevisionWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_readonly_plan_and_repeat_stage_preserve_public_payload_history_and_eligibility(): void
    {
        $p = $this->fixtures();
        $service = app(Workspace::class);
        $before = $service->snapshot();
        $plan = $service->plan($p);
        $this->assertSame($before, $service->snapshot());
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
        $result = $service->stage($p, $plan['state_sha256'], $this->actor()->id);
        $this->assertCount(7, $result['new_revision_ids']);
        $this->assertArrayNotHasKey(10, $result['new_revision_ids']);
        foreach (Workspace::IDS as $id) {
            $article = Article::withoutGlobalScopes()->findOrFail($id);
            $this->assertSame($before[(string) $id]['article']['content_md'], $article->content_md);
            $this->assertSame($before[(string) $id]['article']['published_revision_id'], $article->published_revision_id);
            $this->assertSame($before[(string) $id]['article']['category_id'], $article->category_id);
            $this->assertSame($before[(string) $id]['article']['source_version_hash'], $article->source_version_hash);
            if ($id !== 10) {
                $this->assertSame('human_review', $article->workingRevision->revision_status);
                $this->assertNull($article->workingRevision->reviewed_by);
                $this->assertNull($article->workingRevision->approved_at);
                $this->assertNull($article->workingRevision->published_at);
            }
        }
        $state = $service->snapshot();
        $again = $service->stage($p, Hash::hash($state), $this->actor()->id);
        $this->assertSame([], $again['new_revision_ids']);
        $this->assertSame($state, $service->snapshot());
        $this->assertSame(15, ArticleTranslationRevision::withoutGlobalScopes()->count());
    }

    public function test_complete_state_lock_refuses_seo_drift_and_unowned_working_revision(): void
    {
        $p = $this->fixtures();
        $service = app(Workspace::class);
        $state = Hash::hash($service->snapshot());
        ArticleSeoMeta::withoutGlobalScopes()->where('article_id', 204)->update(['robots' => 'noindex,follow']);
        $this->refused(fn () => $service->stage($p, $state, 1), 'blog_complete_state_drift');
        $article = Article::withoutGlobalScopes()->findOrFail(31);
        $revision = $article->publishedRevision->replicate();
        $revision->forceFill(['revision_number' => 2, 'revision_status' => 'human_review'])->save();
        $article->forceFill(['working_revision_id' => $revision->id])->saveQuietly();
        $this->refused(fn () => $service->stage($p, Hash::hash($service->snapshot()), 1), 'blog_working_revision_collision');
        $this->assertSame(9, ArticleTranslationRevision::withoutGlobalScopes()->count());
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_staging_audit_failure_rolls_back_every_candidate_and_pointer(): void
    {
        $p = $this->fixtures();
        $before = app(Workspace::class)->snapshot();
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit_failed'));
        $this->refused(fn () => app(Workspace::class)->stage($p, Hash::hash($before), 1), 'audit_failed');
        $this->assertSame($before, app(Workspace::class)->snapshot());
        $this->assertSame(8, ArticleTranslationRevision::withoutGlobalScopes()->count());
    }

    public function test_fixed_scope_and_verify_only_protected_fields_are_not_expandable(): void
    {
        $p = $this->fixtures();
        $p['articles'][0]['article_id'] = 15;
        $this->refused(fn () => app(Workspace::class)->plan($p), 'blog_fixed_cohort_required');
        $p = $this->package();
        $p['articles'][1]['after_fields']['content_md'] = 'Changed verify-only';
        $this->refused(fn () => app(Workspace::class)->plan($p), 'blog_verify_only_changed');
        $p = $this->package();
        $p['articles'][0]['after_fields']['title'] = 'Replacement title';
        $this->refused(fn () => app(Workspace::class)->plan($p), 'blog_protected_candidate_field_changed');
    }

    public function test_old_review_is_not_inherited_and_exact_new_owner_review_is_required(): void
    {
        $p = $this->fixtures();
        $actor = $this->actor();
        $service = app(Workspace::class);
        $service->stage($p, Hash::hash($service->snapshot()), $actor->id);
        $blocked = $service->plan($p, true);
        $this->assertFalse($blocked['ok']);
        $this->assertCount(7, $blocked['promotion_blockers']);
        foreach (array_diff(Workspace::IDS, [10]) as $id) {
            app(ArticleTranslationWorkflowService::class)->approveEditorialWorkingRevision(Article::withoutGlobalScopes()->findOrFail($id), $actor->id);
        }
        $plan = $service->plan($p, true);
        $before = $service->snapshot();
        $result = $service->promote($p, $plan['state_sha256'], $actor->id);
        $this->assertSame(7, $result['publication_count']);
        $this->assertSame($before['10'], $service->snapshot()['10']);
        foreach (Workspace::IDS as $id) {
            $a = Article::withoutGlobalScopes()->findOrFail($id);
            $this->assertSame($before[(string) $id]['article']['published_at'], $a->getAttributes()['published_at']);
            $this->assertSame($before[(string) $id]['article']['is_indexable'], $a->getAttributes()['is_indexable']);
            $this->assertSame($before[(string) $id]['seo']['schema_json'], $a->seoMeta->getAttributes()['schema_json']);
            $this->assertSame($before[(string) $id]['seo']['robots'], $a->seoMeta->robots);
        }
        $this->assertSame(11, Article::withoutGlobalScopes()->findOrFail(31)->category_id);
        $this->assertSame(1, Article::withoutGlobalScopes()->findOrFail(241)->category_id);
        $this->assertSame(11, Article::withoutGlobalScopes()->findOrFail(240)->category_id);
        $this->assertNull(Article::withoutGlobalScopes()->findOrFail(221)->category_id);
    }

    public function test_post_approval_payload_change_fails_closed(): void
    {
        $p = $this->fixtures();
        $actor = $this->actor();
        $service = app(Workspace::class);
        $service->stage($p, Hash::hash($service->snapshot()), $actor->id);
        $article = Article::withoutGlobalScopes()->findOrFail(3);
        app(ArticleTranslationWorkflowService::class)->approveEditorialWorkingRevision($article, $actor->id);
        $article->workingRevision->update(['content_md' => 'Concurrent review payload mutation']);
        $this->refused(fn () => $service->plan($p, true), 'blog_working_candidate_drift');
        $this->assertSame($article->published_revision_id, $article->fresh()->published_revision_id);
    }

    public function test_promotion_audit_failure_rolls_back_public_pointers_category_and_dates(): void
    {
        $p = $this->fixtures();
        $actor = $this->actor();
        $service = app(Workspace::class);
        $service->stage($p, Hash::hash($service->snapshot()), $actor->id);
        foreach (array_diff(Workspace::IDS, [10]) as $id) {
            app(ArticleTranslationWorkflowService::class)->approveEditorialWorkingRevision(Article::withoutGlobalScopes()->findOrFail($id), $actor->id);
        }
        $before = $service->snapshot();
        $auditCalls = 0;
        $this->mock(AuditLogger::class)->shouldReceive('log')->times(8)->andReturnUsing(function () use (&$auditCalls): void {
            if (++$auditCalls === 8) {
                throw new RuntimeException('audit_failed');
            }
        });
        $key = \App\Http\Controllers\API\V0_5\SEO\SitemapSourceController::CACHE_KEY_FRESH;
        \Illuminate\Support\Facades\Cache::put($key, ['unchanged' => 'public-cache'], 300);
        // Resolve after binding the failing audit logger; the native publisher also uses it.
        $this->refused(fn () => app(Workspace::class)->promote($p, Hash::hash($before), $actor->id), 'audit_failed');
        $this->assertSame($before, app(Workspace::class)->snapshot());
        $this->assertSame(['unchanged' => 'public-cache'], \Illuminate\Support\Facades\Cache::get($key));
    }

    public function test_private_reader_urls_are_not_accepted_as_candidate_content(): void
    {
        $p = $this->fixtures();
        $p['articles'][0]['after_fields']['content_md'] .= '\n[Private](/en/tests/mbti/take?token=private)';
        $this->refused(fn () => app(Workspace::class)->plan($p), 'blog_private_reader_reference_forbidden');
        $this->assertSame(8, ArticleTranslationRevision::withoutGlobalScopes()->count());
    }

    public function test_existing_english_translation_preserves_external_source_and_refuses_stale_lineage(): void
    {
        $this->fixtures();
        $actor = $this->actor();
        $source = new Article;
        $source->forceFill(['id' => 161, 'org_id' => 0, 'locale' => 'zh-CN', 'slug' => 'synthetic-source-161',
            'title' => '中文合成来源', 'excerpt' => '中文摘要', 'content_md' => '## 中文来源\n既有公开来源。',
            'translation_group_id' => 'fixture-221', 'translation_status' => 'source', 'source_locale' => 'zh-CN',
            'status' => 'published', 'is_public' => true, 'published_at' => now()->subWeek()])->save();
        $revision = ArticleTranslationRevision::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => 161, 'source_article_id' => 161,
            'locale' => 'zh-CN', 'source_locale' => 'zh-CN', 'translation_group_id' => $source->translation_group_id,
            'revision_number' => 1, 'revision_status' => 'published', 'title' => $source->title, 'excerpt' => $source->excerpt,
            'content_md' => $source->content_md, 'source_version_hash' => $source->source_version_hash, 'published_at' => now()->subWeek()]);
        $source->forceFill(['working_revision_id' => $revision->id, 'published_revision_id' => $revision->id])->saveQuietly();
        \App\Models\EditorialReview::withoutGlobalScopes()->create(['org_id' => 0, 'content_type' => 'article', 'content_id' => 161,
            'workflow_state' => 'approved', 'owner_admin_user_id' => $actor->id, 'reviewer_admin_user_id' => $actor->id,
            'reviewed_at' => now(), 'last_transition_at' => now()]);
        $target = Article::withoutGlobalScopes()->findOrFail(221);
        $target->forceFill(['translation_status' => 'published', 'source_locale' => 'zh-CN', 'source_article_id' => 161,
            'translated_from_article_id' => 161, 'translated_from_version_hash' => $source->source_version_hash])->saveQuietly();
        $target->publishedRevision->forceFill(['source_article_id' => 161, 'source_locale' => 'zh-CN',
            'source_version_hash' => $source->source_version_hash, 'translated_from_version_hash' => $source->source_version_hash])->save();
        $p = $this->package();
        $service = app(Workspace::class);
        $before = $service->snapshot();
        $service->stage($p, Hash::hash($before), $actor->id);
        $this->assertSame($before['221']['source'], $service->snapshot()['221']['source']);
        foreach (array_diff(Workspace::IDS, [10]) as $id) {
            app(ArticleTranslationWorkflowService::class)->approveEditorialWorkingRevision(Article::withoutGlobalScopes()->findOrFail($id), $actor->id);
        }
        $this->assertTrue($service->plan($p, true)['ok']);
        $revision->update(['source_version_hash' => str_repeat('a', 64)]);
        $blocked = $service->plan($p, true);
        $this->assertFalse($blocked['ok']);
        $this->assertSame(221, $blocked['promotion_blockers'][0]['article_id']);
        $this->assertSame($before['221']['article']['published_revision_id'], $target->fresh()->published_revision_id);
    }

    public function test_surface_audit_failure_rolls_back_both_locale_drafts(): void
    {
        $this->fixtures();
        $this->actor();
        $p = $this->surfaces();
        $service = app(Workspace::class);
        $plan = $service->surface($p, 'surface-plan', false, '', 0, str_repeat('b', 64));
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit_failed'));
        $this->refused(fn () => app(Workspace::class)->surface($p, 'surface-stage', true, $plan['state_sha256'], 1, str_repeat('b', 64)), 'audit_failed');
        $this->assertSame(0, LandingSurface::withoutGlobalScopes()->where('surface_key', 'articles_index')->count());
    }

    public function test_surface_two_locales_are_private_until_separate_preview_and_publish(): void
    {
        $this->fixtures();
        $p = $this->surfaces();
        $actor = $this->actor();
        $service = app(Workspace::class);
        $plan = $service->surface($p, 'surface-plan', false, '', 0, str_repeat('b', 64));
        $this->assertSame(0, LandingSurface::withoutGlobalScopes()->where('surface_key', 'articles_index')->count());
        $service->surface($p, 'surface-stage', true, $plan['state_sha256'], $actor->id, str_repeat('b', 64));
        $this->assertSame(0, LandingSurface::withoutGlobalScopes()->where('surface_key', 'articles_index')->publishedPublic()->count());
        $plan = $service->surface($p, 'surface-plan', false, '', 0, str_repeat('b', 64));
        $changed = $p;
        $changed['surfaces'][0]['title'] = 'Changed after preview';
        $this->refused(fn () => $service->surface($changed, 'surface-publish', true, $plan['state_sha256'], $actor->id, str_repeat('b', 64)), 'blog_surface_preview_candidate_drift');
        $service->surface($p, 'surface-publish', true, $plan['state_sha256'], $actor->id, str_repeat('b', 64));
        $this->assertSame(2, LandingSurface::withoutGlobalScopes()->where('surface_key', 'articles_index')->publishedPublic()->count());
    }

    public function test_surface_wrong_locale_features_and_existing_authority_are_refused(): void
    {
        $this->fixtures();
        $p = $this->surfaces();
        $p['surfaces'][0]['blog_v1']['featured_article_ids'] = [40];
        $this->refused(fn () => app(Workspace::class)->surface($p, 'surface-plan', false, '', 0, str_repeat('b', 64)), 'blog_surface_featured_locale_invalid');
        $p = $this->surfaces();
        LandingSurface::withoutGlobalScopes()->create(['org_id' => 0, 'surface_key' => 'articles_index', 'locale' => 'en',
            'title' => 'Other owner surface', 'status' => 'published', 'is_public' => true, 'payload_json' => ['other' => true]]);
        $this->refused(fn () => app(Workspace::class)->surface($p, 'surface-plan', false, '', 0, str_repeat('b', 64)), 'blog_surface_existing_authority_collision');
    }

    public function test_native_command_rejects_package_bytes_operator_and_release_without_writes(): void
    {
        $this->assertSame(1, Artisan::call('articles:blog-v1-workspace', ['--file' => '/missing', '--sha256' => Workspace::SOURCE_SHA256]));
        $this->assertStringContainsString('blog_source_package_drift', Artisan::output());
        $this->assertSame(1, Artisan::call('articles:promote-existing-working-revision', ['--blog-v1-file' => '/missing', '--blog-v1-sha256' => Workspace::SOURCE_SHA256]));
        $this->assertStringContainsString('blog_source_package_drift', Artisan::output());
        $actor = $this->actor();
        $actor->update(['is_active' => false]);
        $this->refused(fn () => Command::executionActor('exact', 'exact', $actor->id, ''), 'blog_configured_operator_required');
        $environment = app()['env'];
        app()['env'] = 'production';
        try {
            $this->refused(fn () => Command::executionActor('exact', 'exact', $actor->id, ''), 'blog_production_release_drift');
        } finally {
            app()['env'] = $environment;
        }
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_unbounded_claim_cannot_be_overridden_by_the_old_fixed_acknowledgement(): void
    {
        $p = $this->fixtures();
        $p['articles'][0]['after_fields']['content_md'] .= "\n\n精准匹配职业。";
        $actor = $this->stageAndApprove($p);
        $service = app(Workspace::class);
        $before = $service->snapshot();
        $plan = $service->plan($p, true, 'blog-v1-eight');
        $this->assertContains('blog_claim_blocked', array_column($plan['promotion_blockers'], 'code'));
        $this->refused(fn () => $service->promote($p, Hash::hash($before), $actor->id, 'blog-v1-eight'), 'blog_claim_blocked');
        $this->assertSame($before, $service->snapshot());
    }

    public function test_boundary_claim_requires_exact_current_candidate_acknowledgement(): void
    {
        $p = $this->fixtures();
        $p['articles'][0]['after_fields']['content_md'] .= "\n\n不能预测职业成功。";
        $actor = $this->stageAndApprove($p);
        $service = app(Workspace::class);
        $plan = $service->plan($p, true, 'blog-v1-eight');
        $this->assertFalse($plan['ok']);
        $this->assertStringStartsWith('blog-v1-claims:', $plan['required_claim_acknowledgement']);
        $this->assertCount(7, $plan['claim_records']);
        $this->assertTrue($service->plan($p, true, $plan['required_claim_acknowledgement'])['ok']);
        $this->refused(fn () => $service->promote($p, $plan['state_sha256'], $actor->id, 'blog-v1-eight'), 'blog_claim_warning_ack_required');
        $this->assertSame(7, $service->promote($p, $plan['state_sha256'], $actor->id, $plan['required_claim_acknowledgement'])['publication_count']);
    }

    public function test_claim_records_must_bind_the_new_revision_and_nonboundary_warning_is_rejected(): void
    {
        $p = $this->fixtures();
        $actor = $this->stageAndApprove($p);
        $service = app(Workspace::class);
        $plan = $service->plan($p, true);
        $record = ArticleEditorialPackageImport::withoutGlobalScopes()->where('article_id', 3)->firstOrFail();
        $record->update(['claim_result_json' => ['status' => 'warning', 'matches' => [['boundary_context' => false]]]]);
        $this->refused(fn () => $service->promote($p, $plan['state_sha256'], $actor->id, 'blog-v1-eight'), 'blog_complete_state_drift');
        $before = $service->snapshot();
        $this->refused(fn () => $service->promote($p, Hash::hash($before), $actor->id, 'blog-v1-eight'), 'blog_claim_warning_not_boundary_context');
        $this->assertSame($before, $service->snapshot());
        $binding = $record->validation_summary_json;
        $binding['working_revision_id'] = Article::withoutGlobalScopes()->findOrFail(3)->published_revision_id;
        $record->update(['validation_summary_json' => $binding, 'claim_result_json' => ['status' => 'passed', 'matches' => []]]);
        $this->assertContains('blog_fresh_candidate_claim_required', array_column($service->plan($p, true)['promotion_blockers'], 'code'));
    }

    public function test_unknown_private_surfaces_are_not_owned_even_if_the_payload_matches(): void
    {
        $this->fixtures();
        $actor = $this->actor();
        $p = $this->surfaces();
        foreach ($p['surfaces'] as $candidate) {
            LandingSurface::withoutGlobalScopes()->create(['org_id' => 0, 'surface_key' => 'articles_index', 'locale' => $candidate['locale'],
                'title' => $candidate['title'], 'description' => $candidate['description'], 'status' => 'draft',
                'is_public' => false, 'is_indexable' => false, 'payload_json' => ['blog_v1' => $candidate['blog_v1']]]);
        }
        $before = LandingSurface::withoutGlobalScopes()->get()->toArray();
        $this->refused(fn () => app(Workspace::class)->surface($p, 'surface-plan', false, '', 0, str_repeat('b', 64)), 'blog_surface_task_provenance_required');
        $this->assertSame($before, LandingSurface::withoutGlobalScopes()->get()->toArray());
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_owned_surface_repeat_is_zero_increment_and_proof_package_id_and_state_drift_are_refused(): void
    {
        $this->fixtures();
        $actor = $this->actor();
        $p = $this->surfaces();
        $service = app(Workspace::class);
        $sha = str_repeat('b', 64);
        $foreign = LandingSurface::withoutGlobalScopes()->create(['org_id' => 0, 'surface_key' => 'articles_index', 'locale' => 'fr', 'title' => 'Foreign owner', 'payload_json' => []]);
        $foreignBefore = $foreign->fresh()->getAttributes();
        $plan = $service->surface($p, 'surface-plan', false, '', 0, $sha);
        $stage = $service->surface($p, 'surface-stage', true, $plan['state_sha256'], $actor->id, $sha);
        $this->assertCount(2, $stage['new_surface_ids']);
        $this->assertSame($stage['new_surface_ids'], array_column($stage['surface_records'], 'id'));
        $before = LandingSurface::withoutGlobalScopes()->get()->toArray();
        $plan = $service->surface($p, 'surface-plan', false, '', 0, $sha);
        $repeat = $service->surface($p, 'surface-stage', true, $plan['state_sha256'], $actor->id, $sha);
        $this->assertSame([], $repeat['new_surface_ids']);
        $this->assertSame($stage['surface_records'], $repeat['surface_records']);
        $this->assertSame($before, LandingSurface::withoutGlobalScopes()->get()->toArray());
        $this->assertSame($foreignBefore, $foreign->fresh()->getAttributes());
        $this->refused(fn () => $service->surface($p, 'surface-plan', false, '', 0, str_repeat('c', 64)), 'blog_surface_task_provenance_required');
        foreach (AuditLog::withoutGlobalScopes()->get() as $audit) {
            $meta = $audit->meta_json;
            $meta['surface_records'][0]['id'] = 99999;
            $audit->update(['meta_json' => $meta]);
        }
        $this->refused(fn () => $service->surface($p, 'surface-plan', false, '', 0, $sha), 'blog_surface_task_provenance_required');
        $this->assertSame($before, LandingSurface::withoutGlobalScopes()->get()->toArray());
    }

    public function test_second_locale_validation_failure_rolls_back_first_surface_creation(): void
    {
        $this->fixtures();
        $actor = $this->actor();
        $p = $this->surfaces();
        $service = app(Workspace::class);
        $plan = $service->surface($p, 'surface-plan', false, '', 0, str_repeat('b', 64));
        $p['surfaces'][1]['blog_v1']['featured_article_ids'] = [241];
        $this->refused(fn () => $service->surface($p, 'surface-stage', true, $plan['state_sha256'], $actor->id, str_repeat('b', 64)), 'blog_surface_featured_locale_invalid');
        $this->assertSame(0, LandingSurface::withoutGlobalScopes()->where('surface_key', 'articles_index')->whereIn('locale', ['en', 'zh-CN'])->count());
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_surface_actual_id_owner_and_native_state_must_match_the_trusted_stage_audit(): void
    {
        $this->fixtures();
        $actor = $this->actor();
        $p = $this->surfaces();
        $service = app(Workspace::class);
        $sha = str_repeat('b', 64);
        $plan = $service->surface($p, 'surface-plan', false, '', 0, $sha);
        $stage = $service->surface($p, 'surface-stage', true, $plan['state_sha256'], $actor->id, $sha);
        $surface = LandingSurface::withoutGlobalScopes()->findOrFail($stage['new_surface_ids'][0]);
        \Illuminate\Support\Facades\DB::table('landing_surfaces')->where('id', $surface->id)->update(['description' => 'Unreviewed concurrent change']);
        $this->refused(fn () => $service->surface($p, 'surface-plan', false, '', 0, $sha), 'blog_surface_task_provenance_required');
        \Illuminate\Support\Facades\DB::table('landing_surfaces')->where('id', $surface->id)->update(['description' => $surface->description]);
        $audit = AuditLog::withoutGlobalScopes()->where('action', 'blog_v1_surface-stage')->firstOrFail();
        $original = $audit->meta_json;
        $audit->update(['meta_json' => [...$original, 'authorized_operator_id' => 99999]]);
        $this->refused(fn () => $service->surface($p, 'surface-plan', false, '', 0, $sha), 'blog_surface_task_provenance_required');
        $audit->update(['meta_json' => $original]);
        \Illuminate\Support\Facades\DB::table('landing_surfaces')->where('id', $surface->id)->update(['id' => 99999]);
        $this->refused(fn () => $service->surface($p, 'surface-plan', false, '', 0, $sha), 'blog_surface_task_provenance_required');
        $this->assertSame(2, LandingSurface::withoutGlobalScopes()->where('surface_key', 'articles_index')->whereIn('locale', ['en', 'zh-CN'])->count());
    }

    public function test_claim_drift_after_first_publication_rolls_back_the_entire_cohort_and_cache(): void
    {
        $p = $this->fixtures();
        $actor = $this->stageAndApprove($p);
        $before = app(Workspace::class)->snapshot();
        $realAudit = app(AuditLogger::class);
        $calls = 0;
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andReturnUsing(function (...$arguments) use ($realAudit, &$calls): void {
            $realAudit->log(...$arguments);
            if (++$calls === 1) {
                ArticleEditorialPackageImport::withoutGlobalScopes()->where('article_id', 31)
                    ->update(['claim_result_json' => json_encode(['status' => 'blocked', 'matches' => []])]);
            }
        });
        $key = \App\Http\Controllers\API\V0_5\SEO\SitemapSourceController::CACHE_KEY_FRESH;
        \Illuminate\Support\Facades\Cache::put($key, ['unchanged' => 'public-cache'], 300);
        $this->refused(fn () => app(Workspace::class)->promote($p, Hash::hash($before), $actor->id), 'blog_claim_blocked');
        $this->assertSame($before, app(Workspace::class)->snapshot());
        $this->assertSame(['unchanged' => 'public-cache'], \Illuminate\Support\Facades\Cache::get($key));
    }

    private function stageAndApprove(array $package): AdminUser
    {
        $actor = $this->actor();
        $service = app(Workspace::class);
        $service->stage($package, Hash::hash($service->snapshot()), $actor->id);
        foreach (array_diff(Workspace::IDS, [10]) as $id) {
            app(ArticleTranslationWorkflowService::class)->approveEditorialWorkingRevision(Article::withoutGlobalScopes()->findOrFail($id), $actor->id);
        }

        return $actor;
    }

    private function fixtures(): array
    {
        foreach ([1, 3, 6, 11] as $id) {
            $category = new ArticleCategory;
            $category->forceFill(['id' => $id, 'org_id' => 0, 'slug' => 'category-'.$id, 'name' => 'Category '.$id, 'is_active' => true])->save();
        }
        $cats = [3 => 1, 10 => 1, 31 => 3, 40 => 11, 204 => 6, 221 => null, 240 => null, 241 => null];
        foreach (Workspace::IDS as $id) {
            $locale = $id < 100 ? 'zh-CN' : 'en';
            $body = $locale === 'zh-CN' ? '## 阅读说明'.str_repeat('这是完整的中文阅读内容。', 240) : "## Reader content\n\nExisting synthetic public text.";
            $a = new Article;
            $a->forceFill(['id' => $id, 'org_id' => 0, 'locale' => $locale, 'slug' => 'article-'.$id,
                'title' => 'Title '.$id, 'excerpt' => 'Original excerpt', 'content_md' => $body, 'category_id' => $cats[$id],
                'translation_group_id' => 'fixture-'.$id, 'translation_status' => 'source', 'source_locale' => $locale,
                'status' => 'published', 'is_public' => true, 'is_indexable' => $locale === 'zh-CN', 'published_at' => now()->subWeek()])->save();
            $r = ArticleTranslationRevision::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $id, 'source_article_id' => $id,
                'locale' => $locale, 'source_locale' => $locale, 'translation_group_id' => $a->translation_group_id, 'revision_number' => 1,
                'revision_status' => 'published', 'title' => $a->title, 'excerpt' => $a->excerpt, 'content_md' => $a->content_md,
                'seo_title' => 'SEO '.$id, 'seo_description' => 'SEO description', 'source_version_hash' => $a->source_version_hash,
                'reviewed_by' => 1, 'reviewed_at' => now()->subWeek(), 'approved_at' => now()->subWeek(), 'published_at' => now()->subWeek()]);
            $a->forceFill(['working_revision_id' => $r->id, 'published_revision_id' => $r->id])->saveQuietly();
            ArticleSeoMeta::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $id, 'locale' => $locale,
                'canonical_url' => 'https://fermatmind.com/'.($locale === 'en' ? 'en' : 'zh').'/articles/'.$a->slug,
                'seo_title' => $r->seo_title, 'seo_description' => $r->seo_description, 'is_indexable' => $a->is_indexable,
                'robots' => $a->is_indexable ? 'index,follow' : 'noindex,nofollow',
                'schema_json' => ['editorial_package_v1' => ['schema_enabled' => false, 'hreflang_hold' => true]]]);
        }

        return $this->package();
    }

    private function package(): array
    {
        $rows = [];
        foreach (Workspace::IDS as $id) {
            $a = Article::withoutGlobalScopes()->findOrFail($id);
            $before = $a->publishedRevision->only(['title', 'excerpt', 'content_md', 'seo_title', 'seo_description']);
            $after = $before;
            if ($id !== 10) {
                $after['content_md'] .= "\n\nA synthetic candidate paragraph.";
            }
            $rows[] = ['article_id' => $id, 'locale' => $a->locale, 'slug' => $a->slug, 'canonical_url' => $a->seoMeta->canonical_url,
                'base_published_revision_id' => $a->published_revision_id, 'before_fields' => $before, 'after_fields' => $after,
                'classification_change' => ['before' => $a->category_id]];
        }

        return ['articles' => $rows];
    }

    private function surfaces(): array
    {
        return ['schema' => 'blog_v1_landing_surfaces.v1', 'surfaces' => array_map(fn ($locale) => [
            'locale' => $locale, 'title' => 'Synthetic blog '.$locale, 'description' => 'Synthetic CMS description',
            'blog_v1' => ['schema_version' => 1, 'categories' => [['slug' => 'category-1', 'line_key' => 'personality-and-self-understanding',
                'name' => 'Category label', 'description' => 'Category description']], 'featured_article_ids' => [$locale === 'en' ? 241 : 3]],
        ], ['en', 'zh-CN'])];
    }

    private function actor(): AdminUser
    {
        $actor = AdminUser::withoutGlobalScopes()->firstOrCreate(['id' => 1], ['name' => 'Synthetic owner', 'email' => 'blog-owner@example.test', 'password' => 'test-secret', 'is_active' => 1]);
        $role = Role::firstOrCreate(['name' => 'blog-test-owner']);
        $permission = Permission::firstOrCreate(['name' => PermissionNames::ADMIN_CONTENT_PUBLISH]);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $actor->roles()->syncWithoutDetaching([$role->id]);
        config(['review_governance.mode' => 'solo_owner', 'review_governance.solo_owner_admin_user_id' => $actor->id]);

        return $actor;
    }

    private function refused(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Expected fail-closed refusal: '.$reason);
        } catch (RuntimeException $error) {
            $this->assertSame($reason, $error->getMessage());
        }
    }
}
