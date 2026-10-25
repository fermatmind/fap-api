<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Console\Commands\UpdateArticlePublicReaderMetadata as NativeUpdater;
use App\Filament\Ops\Resources\ArticleResource\Pages\EditArticle;
use App\Filament\Ops\Support\ArticlePublishedFaqWorkspace;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ArticlePublishedFaqWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_action_updates_answers_without_republishing_or_changing_other_authority(): void
    {
        $this->login();
        $article = $this->article();
        $before = NativeUpdater::state($article);
        $data = app(ArticlePublishedFaqWorkspace::class)->prepare($article);
        $data['faq_items'][0]['answer'] = 'The revised answer from the reviewed article.';
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        $component = Livewire::test(EditArticle::class, ['record' => $article->id])->mountAction('updatePublishedFaq');
        $form = $component->get('mountedActionsData')[0];
        $key = array_key_first($form['faq_items']);
        $form['faq_items'][$key]['answer'] = $data['faq_items'][0]['answer'];
        $component->setActionData($form)->callMountedAction()->assertHasNoActionErrors()
            ->assertNotified(__('ops.resources.articles.published_faq.success'))->assertRedirect();
        $article = $article->fresh();
        $after = NativeUpdater::state($article);
        $this->assertSame($before['history'], $after['history']);
        $this->assertSame($before['article'], $after['article']);
        $this->assertSame(Arr::except($before['seo'], ['schema_json', 'updated_at']), Arr::except($after['seo'], ['schema_json', 'updated_at']));
        $this->assertSame('keep', data_get($article->seoMeta->schema_json, 'editorial_package_v1.opaque_review'));
        $this->assertSame('Original second answer.', data_get($article->seoMeta->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items.1.answer'));
        $this->assertSame('faq_one', data_get($article->seoMeta->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items.0.key'));
        $this->assertSame('noindex,nofollow', $article->seoMeta->robots);
        $this->assertFalse($article->is_indexable);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'article_public_reader_metadata_updated')->count());
        $this->getJson('/api/v0.5/articles/native-faq?locale=en&org_id=0')->assertOk()
            ->assertJsonPath('answer_surface_v1.faq_blocks.0.answer', $data['faq_items'][0]['answer']);
        Http::assertSentCount(1);
        $this->assertSame([], glob(storage_path('app/private/article-faq-inputs/*.json')) ?: []);
    }

    #[DataProvider('rejectCases')]
    public function test_scope_permissions_and_concurrent_state_fail_before_writes(string $case): void
    {
        $this->login();
        $article = $this->article();
        $workspace = app(ArticlePublishedFaqWorkspace::class);
        $data = $workspace->prepare($article);
        $data['faq_items'][0]['answer'] = 'A revised answer.';
        if ($case === 'stale') {
            $article->seoMeta->update(['seo_title' => 'Concurrent SEO edit']);
        } elseif ($case === 'draft') {
            $article->update(['working_revision_id' => null]);
        } elseif ($case === 'writer') {
            $this->login('admin.content.write');
        } elseif ($case === 'no_mfa') {
            session()->forget('ops_admin_totp_verified_user_id');
        } elseif ($case === 'cache_missing') {
            config(['ops.content_release_observability.cache_invalidation_urls' => []]);
        } elseif ($case === 'question') {
            $data['faq_items'][0]['question'] = 'Another question?';
        } elseif ($case === 'count') {
            array_pop($data['faq_items']);
        } elseif ($case === 'order') {
            $data['faq_items'] = array_reverse($data['faq_items']);
        } elseif ($case === 'extra_field') {
            $data['faq_items'][0]['schema_enabled'] = true;
        } elseif ($case === 'empty') {
            $data['faq_items'][0]['answer'] = ' ';
        } elseif ($case === 'long') {
            $data['faq_items'][0]['answer'] = str_repeat('a', 10001);
        }
        $before = NativeUpdater::state($article->fresh());
        try {
            $workspace->update($article, $data);
            $this->fail('Invalid FAQ update accepted: '.$case);
        } catch (ValidationException|HttpException) {
            $this->assertSame($before, NativeUpdater::state($article->fresh()));
        }
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    public static function rejectCases(): array
    {
        return array_map(static fn (string $case): array => [$case], ['stale', 'draft', 'writer', 'no_mfa', 'cache_missing', 'question', 'count', 'order', 'extra_field', 'empty', 'long']);
    }

    public function test_native_audit_failure_rolls_back_and_removes_temporary_package(): void
    {
        $this->login();
        $article = $this->article();
        $before = NativeUpdater::state($article);
        $workspace = app(ArticlePublishedFaqWorkspace::class);
        $data = $workspace->prepare($article);
        $data['faq_items'][0]['answer'] = 'A revised answer.';
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit_failed'));
        try {
            $workspace->update($article, $data);
            $this->fail('Failed audit accepted');
        } catch (ValidationException) {
            $this->assertSame($before, NativeUpdater::state($article->fresh()));
        }
        Http::assertNothingSent();
        $this->assertSame([], glob(storage_path('app/private/article-faq-inputs/*.json')) ?: []);
    }

    public function test_cache_failure_reports_committed_answer_and_refuses_old_snapshot(): void
    {
        $this->login();
        Http::swap((new \Illuminate\Http\Client\Factory)->fake(['*' => Http::response(['ok' => false], 503)]));
        $article = $this->article();
        $workspace = app(ArticlePublishedFaqWorkspace::class);
        $data = $workspace->prepare($article);
        $data['faq_items'][0]['answer'] = 'A revised answer.';
        $this->assertSame(['ok' => false, 'metadata' => 'committed', 'cache_closeout' => 'failed'], $workspace->update($article, $data));
        $this->assertSame($data['faq_items'][0]['answer'], data_get($article->fresh()->seoMeta->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items.0.answer'));
        $this->expectException(ValidationException::class);
        $workspace->update($article, $data);
    }

    public function test_schema_faq_list_overrides_long_fallback_without_numeric_merge(): void
    {
        $this->login();
        $article = $this->article();
        $variants = $article->cover_image_variants;
        $variants['editorial_package_v1']['answer_surface_v1']['faq_items'] = array_fill(0, 8, ['question' => 'Old fallback?', 'answer' => 'Old fallback.']);
        $article->update(['cover_image_variants' => $variants]);
        $data = app(ArticlePublishedFaqWorkspace::class)->prepare($article);
        $this->assertCount(2, $data['faq_items']);
        $data['faq_items'][0]['answer'] = 'A revised answer.';
        $this->assertTrue(app(ArticlePublishedFaqWorkspace::class)->update($article, $data)['ok']);
        $this->assertJsonValueSame($variants, $article->fresh()->cover_image_variants);
    }

    #[DataProvider('existingLongInventories')]
    public function test_existing_long_inventory_updates_answers_through_native_action(int $count): void
    {
        $this->login();
        $article = $this->article();
        $schema = $article->seoMeta->schema_json;
        $schema['editorial_package_v1']['answer_surface_v1']['faq_items'] = array_map(
            static fn (int $index): array => ['key' => 'faq_'.$index, 'question' => 'Question '.$index.'?', 'answer' => 'Answer '.$index.'.'],
            range(1, $count),
        );
        $schema['editorial_package_v1']['answer_surface_v1']['faq_items'][0] = [
            'key' => 'faq_1', 'q' => 'Question 1?', 'a' => 'Answer 1.', 'opaque' => ['source' => 'preserved'],
        ];
        $article->seoMeta->update(['schema_json' => $schema]);
        $before = NativeUpdater::state($article->fresh());
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        $component = Livewire::test(EditArticle::class, ['record' => $article->id])->mountAction('updatePublishedFaq');
        $form = $component->get('mountedActionsData')[0];
        $this->assertCount($count, $form['faq_items']);
        $key = array_key_first($form['faq_items']);
        $form['faq_items'][$key]['answer'] = 'When eligible, read the Beta reference; keep missing values unavailable.';
        $lastKey = array_key_last($form['faq_items']);
        $form['faq_items'][$lastKey]['answer'] = 'The last reviewed answer remains visible.';
        $component->setActionData($form)->callMountedAction()->assertHasNoActionErrors()
            ->assertNotified(__('ops.resources.articles.published_faq.success'))->assertRedirect();
        $after = NativeUpdater::state($article->fresh());
        $this->assertSame($before['article'], $after['article']);
        $this->assertSame($before['history'], $after['history']);
        $items = data_get($article->fresh()->seoMeta->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items');
        $expected = $schema['editorial_package_v1']['answer_surface_v1']['faq_items'];
        $expected[0]['a'] = $form['faq_items'][$key]['answer'];
        $expected[$count - 1]['answer'] = $form['faq_items'][$lastKey]['answer'];
        $this->assertSame($expected, $items);
        $this->getJson('/api/v0.5/articles/native-faq?locale=en&org_id=0')->assertOk()
            ->assertJsonCount($count, 'answer_surface_v1.faq_blocks')
            ->assertJsonPath('answer_surface_v1.faq_blocks.0.answer', $expected[0]['a'])
            ->assertJsonPath('answer_surface_v1.faq_blocks.'.($count - 1).'.answer', $expected[$count - 1]['answer'])
            ->assertJsonPath('answer_surface_v1.faq_blocks.'.($count - 1).'.question', $expected[$count - 1]['question']);
        $this->getJson('/api/v0.5/articles/native-faq/seo?locale=en&org_id=0')->assertOk()
            ->assertJsonCount($count, 'jsonld.hasPart.0.mainEntity')
            ->assertJsonPath('jsonld.hasPart.0.mainEntity.'.($count - 1).'.acceptedAnswer.text', $expected[$count - 1]['answer']);
    }

    public static function existingLongInventories(): array
    {
        return [[7], [8], [9]];
    }

    public function test_disabled_or_oversized_inventory_stays_outside_this_action(): void
    {
        $this->login();
        $article = $this->article();
        $schema = $article->seoMeta->schema_json;
        $schema['editorial_package_v1']['answer_surface_visibility'] = 'disabled';
        $article->seoMeta->update(['schema_json' => $schema]);
        $before = NativeUpdater::state($article->fresh());
        try {
            app(ArticlePublishedFaqWorkspace::class)->prepare($article);
            $this->fail('Disabled FAQ was editable');
        } catch (ValidationException) {
            $this->assertSame($before, NativeUpdater::state($article->fresh()));
        }
        $schema['editorial_package_v1']['answer_surface_visibility'] = 'visible';
        $schema['editorial_package_v1']['answer_surface_v1']['faq_items'] = array_fill(0, 10, ['question' => 'Question?', 'answer' => 'Answer.']);
        $article->seoMeta->update(['schema_json' => $schema]);
        $this->expectException(ValidationException::class);
        app(ArticlePublishedFaqWorkspace::class)->prepare($article);
    }

    public function test_write_and_release_without_publish_cannot_open_or_execute_faq_action(): void
    {
        $this->login('admin.content.write');
        auth('admin')->user()->roles()->first()->permissions()->attach(Permission::firstOrCreate(['name' => 'admin.content.release'], ['description' => 'Release only']));
        $article = $this->article();
        $before = NativeUpdater::state($article);
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        Livewire::test(EditArticle::class, ['record' => $article->id])->assertActionHidden('updatePublishedFaq');
        try {
            app(ArticlePublishedFaqWorkspace::class)->update($article, []);
            $this->fail('Release permission incorrectly admitted');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, NativeUpdater::state($article->fresh()));
        Http::assertNothingSent();
    }

    private function login(string $permission = 'admin.owner'): void
    {
        config(['admin.totp.enabled' => true,
            'ops.content_release_observability.cache_invalidation_urls' => ['https://cache.example.test/revalidate'],
            'ops.content_release_observability.cache_invalidation_secret' => 'fixture-only-secret']);
        $role = Role::create(['name' => 'faq-editor-'.uniqid()]);
        $role->permissions()->attach(Permission::firstOrCreate(['name' => $permission], ['description' => $permission]));
        $actor = AdminUser::create(['name' => 'FAQ operator', 'email' => uniqid().'@example.test', 'password' => 'test-only', 'is_active' => true]);
        $actor->roles()->attach($role);
        $this->actingAs($actor, 'admin');
        session(['ops_admin_totp_verified_user_id' => $actor->id]);
        Http::fake(['*' => Http::response(['ok' => true, 'revalidated_paths' => ['/en', '/en/articles', '/en/articles/native-faq', '/llms.txt', '/llms-full.txt', '/zh/articles', '/zh/articles/native-faq'], 'rejected_paths' => [], 'invalidated_tags' => ['article-detail:en:native-faq', 'article-seo:en:native-faq']])]);
    }

    private function article(): Article
    {
        $article = Article::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => 'native-faq', 'locale' => 'en', 'source_locale' => 'en', 'translation_status' => 'source', 'translation_group_id' => 'native-faq-group', 'title' => 'Published article', 'excerpt' => 'Existing excerpt', 'content_md' => "## Existing body\n\nPublished content.", 'status' => 'published', 'is_public' => true, 'is_indexable' => false, 'sitemap_eligible' => false, 'llms_eligible' => false, 'published_at' => now()]);
        $revision = ArticleTranslationRevision::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $article->id, 'translation_group_id' => 'native-faq-group', 'locale' => 'en', 'source_locale' => 'en', 'revision_number' => 1, 'revision_status' => 'published', 'title' => $article->title, 'content_md' => $article->content_md, 'published_at' => now()]);
        $article->forceFill(['working_revision_id' => $revision->id, 'published_revision_id' => $revision->id])->saveQuietly();
        ArticleSeoMeta::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $article->id, 'locale' => 'en', 'seo_title' => 'Existing SEO', 'seo_description' => 'Existing description', 'canonical_url' => '/en/articles/native-faq', 'robots' => 'noindex,nofollow', 'schema_json' => ['editorial_package_v1' => ['opaque_review' => 'keep', 'answer_surface_policy' => 'editor_supplied', 'answer_surface_visibility' => 'visible', 'answer_surface_v1' => ['faq_items' => [['key' => 'faq_one', 'question' => 'Original question?', 'answer' => 'Original answer.'], ['key' => 'faq_two', 'question' => 'Second question?', 'answer' => 'Original second answer.']]]]]]);

        return $article->fresh(['publishedRevision', 'seoMeta']);
    }
}
