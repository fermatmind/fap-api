<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\ArticlePublishControlled;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use App\Services\Audit\AuditLogger;
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

final class ArticlePublishControlledCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_owner_runs_the_native_new_article_command_with_exact_saved_state(): void
    {
        $this->loginPublisher();
        config(['ops.content_release_observability.cache_invalidation_urls' => ['https://cache.example.test/api/content-release/revalidate'], 'ops.content_release_observability.cache_invalidation_secret' => 'fixture-only-secret']);
        Http::fake(['*' => Http::response(self::frontendArticleReceipt())]);
        $article = $this->createControlledDraft();
        $target = (int) $article->working_revision_id;
        $workspace = app(\App\Filament\Ops\Support\ArticleControlledPublicationWorkspace::class);
        $prepared = $workspace->prepare($article);
        $this->assertTrue(json_decode($prepared['preflight'], true)['ok']);
        \Filament\Facades\Filament::setCurrentPanel(app(\Filament\PanelRegistry::class)->get('ops'));
        \Livewire\Livewire::test(\App\Filament\Ops\Resources\ArticleResource\Pages\EditArticle::class, ['record' => $article->id])
            ->callAction('publishControlled', ['target_hash' => $prepared['target_hash'], 'preview_approved' => true, 'make_indexable' => true])
            ->assertHasNoActionErrors()->assertRedirect()
            ->assertNotified(__('ops.resources.articles.controlled_publish.success'));
        $this->assertSame($target, (int) $article->fresh()->published_revision_id);
        $this->assertTrue($article->fresh()->is_public);
        $this->assertTrue($article->fresh()->is_indexable);
        $this->assertDatabaseHas('audit_logs', ['action' => 'codex_controlled_article_publish', 'actor_admin_id' => auth('admin')->id()]);
    }

    #[DataProvider('newArticleCacheFailures')]
    public function test_editor_new_article_reports_committed_cache_failures_without_republishing(string $case): void
    {
        $this->loginPublisher();
        config(['ops.content_release_observability.cache_invalidation_urls' => ['https://cache.example.test/api/content-release/revalidate'], 'ops.content_release_observability.cache_invalidation_secret' => 'fixture-only-secret']);
        $article = $this->createControlledDraft();
        $workspace = app(\App\Filament\Ops\Support\ArticleControlledPublicationWorkspace::class);
        $prepared = $workspace->prepare($article);
        if ($case === 'post_commit_exception') {
            \Illuminate\Support\Facades\File::partialMock()->shouldReceive('ensureDirectoryExists')->once()
                ->andThrow(new RuntimeException('Synthetic post-commit reader failure.'));
        }
        Http::fake(['*' => Http::response($case === 'bad_receipt' ? ['ok' => true] : ['ok' => false], $case === 'http_503' ? 503 : 200)]);
        $data = ['target_hash' => $prepared['target_hash'], 'preview_approved' => true, 'make_indexable' => true];
        $result = $workspace->publish($article, $data);
        $this->assertFalse($result['ok']);
        $this->assertSame('committed', $result['publication']);
        $this->assertSame('failed', $result['cache_closeout']);
        $this->assertSame((int) $article->working_revision_id, (int) $article->fresh()->published_revision_id);
        try {
            $workspace->publish($article, $data);
            $this->fail('A committed publication must not replay its old snapshot.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertStringContainsString('article_publication_state_changed', $exception->getMessage());
        }
        $this->assertDatabaseCount('article_translation_revisions', 1);
    }

    public static function newArticleCacheFailures(): array
    {
        return [['http_503'], ['bad_receipt'], ['post_commit_exception']];
    }

    public function test_editor_new_article_rejects_missing_cache_configuration_before_publication(): void
    {
        $this->loginPublisher();
        config(['ops.content_release_observability.cache_invalidation_urls' => []]);
        $article = $this->createControlledDraft();
        $workspace = app(\App\Filament\Ops\Support\ArticleControlledPublicationWorkspace::class);
        $prepared = $workspace->prepare($article);
        $this->assertFalse(json_decode($prepared['preflight'], true)['ok']);
        try {
            $workspace->publish($article, ['target_hash' => $prepared['target_hash'], 'preview_approved' => true, 'make_indexable' => true]);
            $this->fail('A missing cache transport must block the new path.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertStringContainsString('single_article_cache_configuration_required', $exception->getMessage());
        }
        $this->assertNull($article->fresh()->published_revision_id);
    }

    private static function frontendArticleReceipt(): array
    {
        return ['ok' => true, 'revalidated_paths' => [
            '/zh', '/zh/articles', '/zh/articles/controlled-publish-draft', '/llms.txt', '/llms-full.txt',
            '/zh/topics/mbti', '/zh/tests/holland-career-interest-test-riasec',
            '/en/articles', '/en/articles/controlled-publish-draft',
        ], 'rejected_paths' => [], 'invalidated_tags' => [
            'article-detail:zh-CN:controlled-publish-draft', 'article-seo:zh-CN:controlled-publish-draft',
        ]];
    }

    #[DataProvider('editorRejects')]
    public function test_editor_rejects_invalid_authority_or_preconditions_without_publication(string $case): void
    {
        $this->loginPublisher();
        $article = $this->createControlledDraft();
        $workspace = app(\App\Filament\Ops\Support\ArticleControlledPublicationWorkspace::class);
        $prepared = $workspace->prepare($article);
        $data = ['target_hash' => $prepared['target_hash'], 'preview_approved' => true, 'make_indexable' => true];
        if ($case === 'no_preview') {
            $data['preview_approved'] = false;
        } elseif ($case === 'stale_body') {
            $article->workingRevision->forceFill(['content_md' => $article->workingRevision->content_md."\n\nChanged."])->saveQuietly();
        } elseif ($case === 'stale_seo') {
            $article->seoMeta->forceFill(['og_title' => 'Changed metadata'])->saveQuietly();
        } elseif ($case === 'foreign_org') {
            $article->forceFill(['org_id' => 91])->saveQuietly();
        } elseif ($case === 'bad_locale') {
            $article->forceFill(['locale' => 'fr'])->saveQuietly();
        } elseif ($case === 'no_mfa') {
            session()->forget('ops_admin_totp_verified_user_id');
        } elseif ($case === 'writer' || $case === 'reader') {
            $this->loginPublisher('admin.content.'.($case === 'writer' ? 'write' : 'read'));
        } elseif ($case === 'unreviewed') {
            $article->workingRevision->forceFill(['revision_status' => 'human_review'])->saveQuietly();
            $data['target_hash'] = \App\Support\ArticleSourceTargetSnapshot::publicationHash($article);
        } elseif ($case === 'missing_import') {
            ArticleEditorialPackageImport::withoutGlobalScopes()->where('article_id', $article->id)->delete();
            $data['target_hash'] = \App\Support\ArticleSourceTargetSnapshot::publicationHash($article);
        }
        try {
            $workspace->publish($article, $data);
            $this->fail('Publication must reject '.$case);
        } catch (\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertNull($article->fresh()->published_revision_id);
            $this->assertFalse($article->fresh()->is_public);
        }
    }

    public static function editorRejects(): array
    {
        return array_map(fn ($case) => [$case], ['no_preview', 'stale_body', 'stale_seo', 'foreign_org', 'bad_locale', 'no_mfa', 'writer', 'reader', 'unreviewed', 'missing_import']);
    }

    public function test_native_transaction_rejects_metadata_changed_after_editor_preflight(): void
    {
        $article = $this->createControlledDraft();
        $hash = \App\Support\ArticleSourceTargetSnapshot::publicationHash($article);
        $command = app(ArticlePublishControlled::class);
        $plan = (new ReflectionMethod($command, 'preflightArticle'))->invoke($command, (int) $article->id, [], true);
        $article->seoMeta->forceFill(['og_title' => 'Concurrent edit'])->saveQuietly();
        try {
            (new ReflectionMethod($command, 'publishPlannedArticle'))->invoke($command, $plan,
                app(ArticlePublishService::class), app(AuditLogger::class), 'unused-after-conflict', true, $hash);
            $this->fail('The transaction must reject a changed snapshot.');
        } catch (RuntimeException $exception) {
            $this->assertSame('article_publication_state_changed', $exception->getMessage());
        }
        $this->assertNull($article->fresh()->published_revision_id);
        $this->assertFalse($article->fresh()->is_indexable);
    }

    public function test_legacy_batch_reports_only_the_current_failed_target_as_uncommitted(): void
    {
        $this->fakeContentReleaseEndpoint();
        $first = $this->createControlledDraft(['slug' => 'first-controlled-draft']);
        $second = $this->createControlledDraft(['slug' => 'second-controlled-draft']);
        $dispatcher = ArticleTranslationRevision::getEventDispatcher();
        ArticleTranslationRevision::setEventDispatcher(clone $dispatcher);
        try {
            ArticleTranslationRevision::updating(function ($revision) use ($second): void {
                if ((int) $revision->id === (int) $second->working_revision_id && $revision->revision_status === 'published') {
                    throw new RuntimeException('Synthetic second-target transaction failure.');
                }
            });
            $this->assertSame(1, Artisan::call('articles:publish-controlled', [
                '--article' => [(string) $first->id, (string) $second->id], '--make-indexable' => true, '--json' => true,
                '--confirm' => "I explicitly approve Codex to publish article ids {$first->id},{$second->id} after preflight passes.",
            ]));
            $result = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame([(int) $first->id], $result['published_article_ids']);
            $this->assertSame('publish_preflight_failed', $result['errors'][0]['code']);
            $this->assertStringContainsString('Synthetic second-target transaction failure', $result['errors'][0]['message']);
            $this->assertTrue($first->fresh()->is_public);
            $this->assertNull($second->fresh()->published_revision_id);
        } finally {
            ArticleTranslationRevision::setEventDispatcher($dispatcher);
        }
    }

    private function loginPublisher(string $permission = 'admin.owner'): void
    {
        config(['admin.totp.enabled' => true]);
        $role = \App\Models\Role::create(['name' => 'publisher-'.uniqid()]);
        $role->permissions()->attach(\App\Models\Permission::firstOrCreate(['name' => $permission], ['description' => $permission]));
        $admin = \App\Models\AdminUser::create(['name' => 'Publisher', 'email' => uniqid().'@example.test', 'password' => 'test-only-password', 'is_active' => true]);
        $admin->roles()->attach($role);
        $this->actingAs($admin, 'admin');
        session(['ops_admin_totp_verified_user_id' => (int) $admin->id]);
    }

    #[DataProvider('taxonomyContexts')]
    public function test_preflight_uses_the_selected_articles_taxonomy_org_instead_of_ops_context(string $case): void
    {
        $article = $this->createControlledDraft();
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
        Artisan::call('articles:publish-controlled', ['--article' => [(string) $article->id], '--dry-run' => true, '--make-indexable' => true, '--json' => true]);
        $plan = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
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
        $this->fakeContentReleaseEndpoint();
        $article = $this->createControlledDraft();
        $revisionId = (int) $article->working_revision_id;
        app(OrgContext::class)->set(91, null, 'admin');
        $this->assertSame(0, Artisan::call('articles:publish-controlled', [
            '--article' => [(string) $article->id], '--make-indexable' => true,
            '--confirm' => "I explicitly approve Codex to publish article id {$article->id} after preflight passes.",
            '--json' => true,
        ]), Artisan::output());
        $article->refresh();
        $this->assertTrue($article->is_public);
        $this->assertSame($revisionId, (int) $article->published_revision_id);
    }

    public static function taxonomyContexts(): array
    {
        return [['valid'], ['foreign_rows'], ['foreign_pivot'], ['missing'], ['mixed_foreign_pivot'], ['mixed_foreign_tag'], ['mixed_dangling']];
    }

    public function test_dry_run_reports_exact_confirmation_without_publishing(): void
    {
        $article = $this->createControlledDraft();

        $this->artisan('articles:publish-controlled', [
            '--article' => [(string) $article->id],
            '--dry-run' => true,
            '--make-indexable' => true,
        ])
            ->expectsOutputToContain('ok=1')
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain("expected_confirmation=I explicitly approve Codex to publish article id {$article->id} after preflight passes.")
            ->expectsOutputToContain('published_article_ids=')
            ->assertExitCode(0);

        $article->refresh();
        $this->assertSame('draft', (string) $article->status);
        $this->assertFalse((bool) $article->is_public);
        $this->assertNull($article->published_revision_id);
    }

    public function test_dry_run_rejects_short_body_and_forbidden_draft_markers(): void
    {
        $article = $this->createControlledDraft([
            'body' => "## Draft\n\nBig Five Authority V2 draft candidate pending manual review.\n\n短模板。",
        ]);

        $this->artisan('articles:publish-controlled', [
            '--article' => [(string) $article->id],
            '--dry-run' => true,
            '--make-indexable' => true,
        ])
            ->expectsOutputToContain('ok=0')
            ->expectsOutputToContain('forbidden_draft_marker')
            ->expectsOutputToContain('body_han_characters_below_minimum')
            ->assertExitCode(1);

        $article->refresh();
        $this->assertSame('draft', (string) $article->status);
        $this->assertNull($article->published_revision_id);
    }

    #[DataProvider('bodyOnlyEditorialContracts')]
    public function test_body_only_editorial_requires_the_explicit_imported_contract(string $case): void
    {
        $this->fakeContentReleaseEndpoint();
        $article = $this->createControlledDraft([
            'body' => "An editorial comparison of documented result scope.\n\n"
                .str_repeat('Each questionnaire answers a different question; keep the provider and version with the result. ', 75),
            'locale' => 'en',
        ]);
        $schema = $article->seoMeta->schema_json;
        $schema['editorial_package_v1']['content_track'] = 'editorial_journal';
        $schema['editorial_package_v1']['answer_surface_policy'] = 'none';
        $schema['editorial_package_v1']['answer_surface_visibility'] = 'disabled';
        $schema['editorial_package_v1']['answer_surface_v1'] = [];
        if ($case === 'missing_policy') {
            unset($schema['editorial_package_v1']['answer_surface_policy']);
        } elseif ($case === 'visible_surface') {
            $schema['editorial_package_v1']['answer_surface_visibility'] = 'above_body';
        } elseif ($case === 'nonempty_surface') {
            $schema['editorial_package_v1']['answer_surface_v1'] = ['quick_answer' => 'A separate answer'];
        } elseif ($case === 'metadata_track_mismatch') {
            $schema['editorial_package_v1']['content_track'] = 'evergreen_knowledge';
        }
        $article->seoMeta->update(['schema_json' => $schema]);
        ArticleEditorialPackageImport::withoutGlobalScopes()->where('article_id', $article->id)
            ->update(['content_track' => $case === 'import_track_mismatch' ? 'evergreen_knowledge' : 'editorial_journal']);

        $options = ['--article' => [(string) $article->id], '--make-indexable' => true, '--json' => true];
        if ($case === 'valid') {
            $options['--confirm'] = "I explicitly approve Codex to publish article id {$article->id} after preflight passes.";
        } else {
            $options['--dry-run'] = true;
        }
        $exit = Artisan::call('articles:publish-controlled', $options);
        $result = Artisan::output();
        $this->assertSame($case === 'valid' ? 0 : 1, $exit, $result);
        if ($case === 'valid') {
            $this->assertTrue($article->fresh()->is_public);
            $this->assertSame((int) $article->working_revision_id, (int) $article->fresh()->published_revision_id);
        } else {
            $this->assertStringContainsString('faq_items_missing', $result);
            $this->assertNull($article->fresh()->published_revision_id);
        }
    }

    public static function bodyOnlyEditorialContracts(): array
    {
        return array_map(fn ($case) => [$case], ['valid', 'missing_policy', 'visible_surface', 'nonempty_surface', 'metadata_track_mismatch', 'import_track_mismatch']);
    }

    public function test_dry_run_uses_working_revision_instead_of_stale_article_and_seo_projections(): void
    {
        $article = $this->createControlledDraft();
        $article->forceFill([
            'title' => 'draft candidate stale projection',
            'excerpt' => 'pending manual review stale projection',
        ])->save();
        $article->seoMeta?->forceFill([
            'seo_title' => 'draft candidate stale SEO projection',
            'seo_description' => 'pending manual review stale SEO projection',
        ])->save();

        $command = app(ArticlePublishControlled::class);
        $preflight = new ReflectionMethod($command, 'preflightArticle');
        $plan = $preflight->invoke($command, (int) $article->id, [], true);

        $this->assertIsArray($plan);
        $this->assertTrue((bool) $plan['ok']);
        $this->assertTrue((bool) data_get($plan, 'editorial_completeness.ok'));
        $this->assertSame([], data_get($plan, 'editorial_completeness.matched_forbidden_markers'));
    }

    public function test_controlled_publish_requires_acknowledged_boundary_warnings_and_exact_confirmation(): void
    {
        $this->fakeContentReleaseEndpoint();
        $article = $this->createControlledDraft([
            'claim_result_json' => [
                'status' => 'warning',
                'matches' => [
                    [
                        'field' => 'body_markdown',
                        'code' => 'claim_boundary_forbidden_phrase',
                        'text' => '最适合',
                        'boundary_context' => true,
                    ],
                ],
            ],
            'import_status' => ArticleEditorialPackageImport::STATUS_WARNING,
        ]);
        $confirmation = "I explicitly approve Codex to publish article id {$article->id} after preflight passes.";

        $this->artisan('articles:publish-controlled', [
            '--article' => [(string) $article->id],
            '--confirm' => $confirmation,
            '--make-indexable' => true,
        ])
            ->expectsOutputToContain('ok=0')
            ->expectsOutputToContain('claim_warning_ack_required')
            ->assertExitCode(1);

        $article->refresh();
        $this->assertSame('draft', (string) $article->status);
        $this->assertNull($article->published_revision_id);

        $this->artisan('articles:publish-controlled', [
            '--article' => [(string) $article->id],
            '--confirm' => $confirmation,
            '--ack-claim-warning' => [(string) $article->id],
            '--make-indexable' => true,
        ])
            ->expectsOutputToContain('ok=1')
            ->expectsOutputToContain('published_article_ids='.(string) $article->id)
            ->assertExitCode(0);

        $article->refresh();
        $this->assertSame('published', (string) $article->status);
        $this->assertTrue((bool) $article->is_public);
        $this->assertTrue((bool) $article->is_indexable);
        $this->assertSame((int) $article->working_revision_id, (int) $article->published_revision_id);
        $this->assertSame(ArticleTranslationRevision::STATUS_PUBLISHED, (string) $article->publishedRevision?->revision_status);
        $this->assertSame('index,follow', (string) $article->seoMeta?->robots);
        $this->assertTrue((bool) $article->seoMeta?->is_indexable);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'codex_controlled_article_publish',
            'target_type' => 'article',
            'target_id' => (string) $article->id,
            'result' => 'success',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'content_release_publish',
            'target_type' => 'article',
            'target_id' => (string) $article->id,
            'result' => 'success',
        ]);

        Http::assertSent(function ($request): bool {
            $paths = (array) data_get($request->data(), 'cache_signal.paths', []);

            return $request->url() === 'https://cache.example.test/api/content-release/revalidate'
                && in_array('/zh/articles/controlled-publish-draft', $paths, true)
                && in_array('/llms.txt', $paths, true);
        });
    }

    public function test_non_boundary_claim_warning_is_not_publishable_even_when_acknowledged(): void
    {
        $article = $this->createControlledDraft([
            'claim_result_json' => [
                'status' => 'warning',
                'matches' => [
                    [
                        'field' => 'body_markdown',
                        'code' => 'claim_boundary_forbidden_phrase',
                        'text' => '最适合',
                        'boundary_context' => false,
                    ],
                ],
            ],
            'import_status' => ArticleEditorialPackageImport::STATUS_WARNING,
        ]);
        $confirmation = "I explicitly approve Codex to publish article id {$article->id} after preflight passes.";

        $this->artisan('articles:publish-controlled', [
            '--article' => [(string) $article->id],
            '--confirm' => $confirmation,
            '--ack-claim-warning' => [(string) $article->id],
            '--make-indexable' => true,
        ])
            ->expectsOutputToContain('ok=0')
            ->expectsOutputToContain('claim_warning_not_boundary_context')
            ->assertExitCode(1);

        $article->refresh();
        $this->assertSame('draft', (string) $article->status);
        $this->assertNull($article->published_revision_id);
    }

    public function test_controlled_publish_rejects_unapproved_working_revision_without_self_approving(): void
    {
        $article = $this->createControlledDraft([
            'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'reviewed_by' => 7,
            'reviewed_at' => now()->subMinutes(5),
            'approved_at' => null,
        ]);
        $confirmation = "I explicitly approve Codex to publish article id {$article->id} after preflight passes.";

        $this->artisan('articles:publish-controlled', [
            '--article' => [(string) $article->id],
            '--confirm' => $confirmation,
            '--make-indexable' => true,
        ])
            ->expectsOutputToContain('ok=0')
            ->expectsOutputToContain('revision_not_editorially_approved')
            ->assertExitCode(1);

        $article->refresh();
        $this->assertSame('draft', (string) $article->status);
        $this->assertNull($article->published_revision_id);
        $this->assertSame(ArticleTranslationRevision::STATUS_HUMAN_REVIEW, (string) $article->workingRevision?->revision_status);
        $this->assertNull($article->workingRevision?->approved_at);
    }

    public function test_controlled_publish_rejects_approved_revision_without_review_metadata(): void
    {
        $article = $this->createControlledDraft([
            'reviewed_by' => null,
            'reviewed_at' => null,
            'approved_at' => null,
        ]);
        $confirmation = "I explicitly approve Codex to publish article id {$article->id} after preflight passes.";

        $this->artisan('articles:publish-controlled', [
            '--article' => [(string) $article->id],
            '--confirm' => $confirmation,
            '--make-indexable' => true,
        ])
            ->expectsOutputToContain('ok=0')
            ->expectsOutputToContain('revision_review_missing')
            ->expectsOutputToContain('revision_approval_missing')
            ->assertExitCode(1);

        $article->refresh();
        $this->assertSame('draft', (string) $article->status);
        $this->assertNull($article->published_revision_id);
    }

    public function test_controlled_publish_rejects_stale_and_already_published_revisions(): void
    {
        foreach ([ArticleTranslationRevision::STATUS_STALE, ArticleTranslationRevision::STATUS_PUBLISHED] as $status) {
            $article = $this->createControlledDraft([
                'slug' => 'controlled-publish-'.$status,
                'revision_status' => $status,
                'published_at' => $status === ArticleTranslationRevision::STATUS_PUBLISHED ? now()->subMinute() : null,
            ]);
            $confirmation = "I explicitly approve Codex to publish article id {$article->id} after preflight passes.";

            $this->artisan('articles:publish-controlled', [
                '--article' => [(string) $article->id],
                '--confirm' => $confirmation,
                '--make-indexable' => true,
            ])
                ->expectsOutputToContain('ok=0')
                ->expectsOutputToContain('invalid_revision_status')
                ->assertExitCode(1);

            $article->refresh();
            $this->assertSame('draft', (string) $article->status);
            $this->assertNull($article->published_revision_id);
        }
    }

    public function test_controlled_publish_rejects_archived_articles(): void
    {
        $article = $this->createControlledDraft([
            'lifecycle_state' => Article::LIFECYCLE_ARCHIVED,
        ]);
        $confirmation = "I explicitly approve Codex to publish article id {$article->id} after preflight passes.";

        $this->artisan('articles:publish-controlled', [
            '--article' => [(string) $article->id],
            '--confirm' => $confirmation,
            '--make-indexable' => true,
        ])
            ->expectsOutputToContain('ok=0')
            ->expectsOutputToContain('article_lifecycle_not_publishable')
            ->assertExitCode(1);

        $article->refresh();
        $this->assertSame('draft', (string) $article->status);
        $this->assertNull($article->published_revision_id);
    }

    public function test_controlled_publish_revalidates_review_gate_inside_transaction_before_publishing(): void
    {
        $article = $this->createControlledDraft(['slug' => 'controlled-publish-revalidate']);
        $command = app(ArticlePublishControlled::class);
        $preflight = new ReflectionMethod($command, 'preflightArticle');
        $publish = new ReflectionMethod($command, 'publishPlannedArticle');

        $plan = $preflight->invoke($command, (int) $article->id, [], true);
        $this->assertIsArray($plan);
        $this->assertTrue((bool) $plan['ok']);

        ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereKey((int) $article->working_revision_id)
            ->update(['revision_status' => ArticleTranslationRevision::STATUS_STALE]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_revision_status');

        try {
            $publish->invoke(
                $command,
                $plan,
                app(ArticlePublishService::class),
                app(AuditLogger::class),
                "I explicitly approve Codex to publish article id {$article->id} after preflight passes.",
                true
            );
        } finally {
            $article->refresh();
            $this->assertSame('draft', (string) $article->status);
            $this->assertNull($article->published_revision_id);
            $this->assertSame(ArticleTranslationRevision::STATUS_STALE, (string) $article->workingRevision?->revision_status);
        }
    }

    public function test_controlled_publish_revalidates_content_completeness_inside_transaction(): void
    {
        $article = $this->createControlledDraft(['slug' => 'controlled-publish-content-revalidate']);
        $command = app(ArticlePublishControlled::class);
        $preflight = new ReflectionMethod($command, 'preflightArticle');
        $publish = new ReflectionMethod($command, 'publishPlannedArticle');

        $plan = $preflight->invoke($command, (int) $article->id, [], true);
        $this->assertIsArray($plan);
        $this->assertTrue((bool) $plan['ok']);

        ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereKey((int) $article->working_revision_id)
            ->update([
                'content_md' => 'Big Five Authority V2 draft candidate pending manual review.',
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forbidden_draft_marker');

        try {
            $publish->invoke(
                $command,
                $plan,
                app(ArticlePublishService::class),
                app(AuditLogger::class),
                "I explicitly approve Codex to publish article id {$article->id} after preflight passes.",
                true
            );
        } finally {
            $article->refresh();
            $this->assertSame('draft', (string) $article->status);
            $this->assertNull($article->published_revision_id);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createControlledDraft(array $overrides = []): Article
    {
        $category = ArticleCategory::query()->withoutGlobalScopes()->firstOrCreate(
            ['org_id' => 0, 'slug' => 'career-development'],
            ['name' => '职业发展', 'is_active' => true]
        );
        $tag = ArticleTag::query()->withoutGlobalScopes()->firstOrCreate(
            ['org_id' => 0, 'slug' => 'riasec'],
            ['name' => 'RIASEC', 'is_active' => true]
        );
        $body = (string) ($overrides['body'] ?? (
            "## Controlled Publish Draft\n\n## 执行摘要\n\n"
            .str_repeat('这篇文章通过具体情境解释人格测量边界并提供可复盘的行动建议。', 120)
            ."\n\n## FAQ\n\n### Q\n\n这是结构化参考，不是固定身份或结果保证。"
        ));
        $bodyHash = hash('sha256', preg_replace("/\r\n?/", "\n", trim($body)));
        $locale = (string) ($overrides['locale'] ?? 'zh-CN');
        $slug = (string) ($overrides['slug'] ?? 'controlled-publish-draft');
        $revisionStatus = (string) ($overrides['revision_status'] ?? ArticleTranslationRevision::STATUS_APPROVED);
        $reviewedBy = array_key_exists('reviewed_by', $overrides) ? $overrides['reviewed_by'] : 7;
        $reviewedAt = array_key_exists('reviewed_at', $overrides) ? $overrides['reviewed_at'] : now()->subMinutes(10);
        $approvedAt = array_key_exists('approved_at', $overrides) ? $overrides['approved_at'] : now()->subMinutes(5);

        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'category_id' => (int) $category->id,
            'slug' => $slug,
            'locale' => $locale,
            'title' => 'Controlled Publish Draft',
            'excerpt' => 'Controlled excerpt',
            'content_md' => $body,
            'cover_image_url' => '/storage/articles/controlled.svg',
            'cover_image_alt' => 'Controlled cover alt',
            'cover_image_width' => 1200,
            'cover_image_height' => 675,
            'status' => (string) ($overrides['status'] ?? 'draft'),
            'lifecycle_state' => $overrides['lifecycle_state'] ?? Article::LIFECYCLE_ACTIVE,
            'is_public' => false,
            'is_indexable' => false,
        ]);
        $article->tags()->attach((int) $tag->id, ['org_id' => 0]);

        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => $locale,
            'source_locale' => $locale,
            'revision_number' => 1,
            'revision_status' => $revisionStatus,
            'title' => 'Controlled Publish Draft',
            'excerpt' => 'Controlled excerpt',
            'content_md' => $body,
            'seo_title' => 'Controlled SEO Title',
            'seo_description' => 'Controlled SEO description',
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => $reviewedAt,
            'approved_at' => $approvedAt,
            'published_at' => $overrides['published_at'] ?? null,
        ]);
        $article->forceFill(['working_revision_id' => (int) $revision->id])->save();

        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => $locale,
            'seo_title' => 'Controlled SEO Title',
            'seo_description' => 'Controlled SEO description',
            'canonical_url' => "https://example.test/zh/articles/{$article->slug}",
            'og_title' => 'Controlled OG Title',
            'og_description' => 'Controlled OG description',
            'og_image_url' => 'https://example.test/storage/articles/controlled.svg',
            'robots' => 'noindex,nofollow',
            'schema_json' => [
                'editorial_package_v1' => [
                    'sensitivity_level' => (string) ($overrides['sensitivity_level'] ?? 'career_sensitive'),
                    'cta_slots' => [
                        ['slot_id' => 'cta_primary', 'href' => '/zh/tests/holland-career-interest-test-riasec'],
                    ],
                    'answer_surface_v1' => [
                        'faq_items' => [
                            ['question' => 'Q', 'answer' => 'A'],
                        ],
                    ],
                    'target_topics' => ['mbti'],
                    'target_tests' => ['holland-career-interest-test-riasec'],
                ],
            ],
            'is_indexable' => false,
        ]);

        ArticleEditorialPackageImport::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'slug' => $slug,
            'locale' => $locale,
            'title' => 'Controlled Publish Draft',
            'content_track' => 'evergreen_knowledge',
            'status' => (string) ($overrides['import_status'] ?? ArticleEditorialPackageImport::STATUS_IMPORTED),
            'intended_status' => 'review_pending',
            'claim_result_json' => $overrides['claim_result_json'] ?? ['status' => 'passed', 'matches' => []],
            'exactness_json' => ['status' => 'passed', 'body_hash' => $bodyHash],
            'references_json' => ['status' => 'complete', 'count' => 1],
            'media_json' => ['status' => 'complete'],
            'graph_json' => ['status' => 'complete', 'target_topics' => ['mbti']],
            'answer_surface_json' => ['status' => 'complete'],
            'body_hash' => $bodyHash,
            'heading_sequence_json' => ['1:Controlled Publish Draft', '2:执行摘要', '2:FAQ'],
            'references_count' => 1,
        ]);

        return $article->fresh(['workingRevision', 'publishedRevision', 'seoMeta', 'category', 'tags']) ?? $article;
    }

    private function fakeContentReleaseEndpoint(?callable $onRequest = null): void
    {
        config()->set('ops.content_release_observability.cache_invalidation_urls', [
            'https://cache.example.test/api/content-release/revalidate',
        ]);
        config()->set('ops.content_release_observability.cache_invalidation_secret', 'release-secret');
        Http::fake([
            'https://cache.example.test/*' => function ($request) use ($onRequest) {
                if ($onRequest !== null) {
                    $onRequest($request);
                }

                return Http::response(['ok' => true], 200);
            },
        ]);
    }
}
