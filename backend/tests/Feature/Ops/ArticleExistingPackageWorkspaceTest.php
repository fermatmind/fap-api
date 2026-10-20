<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\ArticleResource\Pages\EditArticle;
use App\Filament\Ops\Support\ArticleExistingPackageWorkspace;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\Permission;
use App\Models\Role;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ArticleExistingPackageWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_import_updates_only_working_revision_and_replay_rejects_stale_baseline(): void
    {
        $this->login();
        $article = $this->article();
        $before = $article->getRawOriginal();
        $published = $article->publishedRevision->getRawOriginal();
        $seo = $article->seoMeta->getRawOriginal();
        $input = $this->transport($article);
        $result = app(ArticleExistingPackageWorkspace::class)->import($article, $input);
        $article->refresh()->load(['publishedRevision', 'workingRevision', 'seoMeta']);
        $this->assertNotSame($published['id'], $result['working_revision_id']);
        $this->assertSame($published, $article->publishedRevision->getRawOriginal());
        $this->assertSame($seo, $article->seoMeta->getRawOriginal());
        foreach (['status', 'is_public', 'is_indexable', 'sitemap_eligible', 'llms_eligible', 'slug', 'locale', 'translation_group_id', 'cover_image_url'] as $key) {
            $this->assertSame($before[$key], $article->getRawOriginal($key), $key);
        }
        $this->assertSame('human_review', $article->workingRevision->revision_status);
        $this->assertSame("## Reading the result\n\nCompare the tool and version before interpreting a changed personality label.", $article->workingRevision->content_md);
        $this->assertDatabaseHas('article_editorial_package_imports', ['article_id' => $article->id, 'content_track' => 'seo_content_package_existing_article_update']);
        $this->assertTemporaryInputsRemoved();

        try {
            app(ArticleExistingPackageWorkspace::class)->import($article, $input);
            $this->fail('Stale revision must not be imported twice.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('target_mismatch:expected_working_revision_id', $exception->getMessage());
        }
        $this->assertDatabaseCount('article_editorial_package_imports', 1);
        $this->assertDatabaseCount('article_translation_revisions', 2);

        // Restoring a published baseline must fork again and retain prior history.
        $oldWorking = $article->workingRevision->getRawOriginal();
        $article->forceFill(['working_revision_id' => $article->published_revision_id])->save();
        $this->login();
        app(ArticleExistingPackageWorkspace::class)->import($article, $this->transport($article->fresh(['workingRevision', 'publishedRevision'])));
        $this->assertSame($oldWorking, ArticleTranslationRevision::withoutGlobalScopes()->findOrFail($oldWorking['id'])->getRawOriginal());
        $this->assertDatabaseCount('article_translation_revisions', 3);
        $this->assertSame($published, $article->fresh('publishedRevision')->publishedRevision->getRawOriginal());
    }

    public function test_editor_action_uses_native_import_under_positive_ops_tenant_context(): void
    {
        $this->login();
        $article = $this->article();
        $input = $this->transport($article);
        app(OrgContext::class)->set(91, null, 'admin');
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        Livewire::test(EditArticle::class, ['record' => $article->id])
            ->assertActionVisible('importExistingPackage')
            ->callAction('importExistingPackage', ['package_json' => $input])
            ->assertHasNoActionErrors()
            ->assertRedirect();
        $this->assertSame('human_review', $article->fresh('workingRevision')->workingRevision->revision_status);
        $this->assertDatabaseCount('article_editorial_package_imports', 1);
        $this->assertTemporaryInputsRemoved();
    }

    public function test_content_reader_cannot_call_import_service_directly(): void
    {
        $this->login(PermissionNames::ADMIN_CONTENT_READ);
        $article = $this->article();
        try {
            app(ArticleExistingPackageWorkspace::class)->import($article, $this->transport($article));
            $this->fail('Read permission must not import a working revision.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('article_translation_revisions', 1);
        $this->assertDatabaseCount('article_editorial_package_imports', 0);
    }

    public function test_rejected_package_displays_error_on_the_action_input_without_writes(): void
    {
        $this->login();
        $article = $this->article();
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        Livewire::test(EditArticle::class, ['record' => $article->id])
            ->callAction('importExistingPackage', ['package_json' => '{'])
            ->assertHasActionErrors(['package_json']);
        $this->assertDatabaseCount('article_translation_revisions', 1);
        $this->assertDatabaseCount('article_editorial_package_imports', 0);
    }

    public function test_native_markdown_heading_normalization_remains_compatible(): void
    {
        $this->login();
        $article = $this->article();
        app(ArticleExistingPackageWorkspace::class)->import($article, $this->transport($article, 'body_h1'));
        $body = $article->fresh('workingRevision')->workingRevision->content_md;
        $this->assertStringStartsWith('## Reading the result', $body);
        $this->assertDatabaseCount('article_editorial_package_imports', 1);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_or_stale_packages_make_no_writes(string $case): void
    {
        $this->login();
        $article = $this->article();
        $before = $article->getRawOriginal();
        $input = $this->transport($article, $case);
        try {
            app(ArticleExistingPackageWorkspace::class)->import($article, $input);
            $this->fail('Invalid transport must be rejected: '.$case);
        } catch (ValidationException) {
            $this->assertSame($before, $article->fresh()->getRawOriginal());
        }
        $this->assertDatabaseCount('article_translation_revisions', 1);
        $this->assertDatabaseCount('article_editorial_package_imports', 0);
        $this->assertTemporaryInputsRemoved();
    }

    public static function invalidInputs(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'wrong_article', 'wrong_org', 'wrong_locale', 'wrong_group', 'old_pointer',
            'working_hash', 'published_hash', 'missing_baseline', 'bad_digest',
            'bad_manifest_digest', 'extra_file', 'duplicate_file', 'traversal',
            'native_file_traversal', 'private_body_url', 'malformed_json', 'oversize',
        ]);
    }

    public function test_native_transaction_failure_rolls_back_revision_and_import_gate_and_removes_inputs(): void
    {
        $this->login();
        $article = $this->article();
        $before = $article->getRawOriginal();
        $event = 'eloquent.creating: '.ArticleEditorialPackageImport::class;
        Event::listen($event, static function (): void {
            throw new RuntimeException('Injected native gate persistence failure');
        });
        try {
            app(ArticleExistingPackageWorkspace::class)->import($article, $this->transport($article));
            $this->fail('Injected native transaction failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected native gate persistence failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame($before, $article->fresh()->getRawOriginal());
        $this->assertDatabaseCount('article_translation_revisions', 1);
        $this->assertDatabaseCount('article_editorial_package_imports', 0);
        $this->assertTemporaryInputsRemoved();
    }

    private function transport(Article $article, string $case = ''): string
    {
        $slug = $article->slug;
        $route = '/en/articles/'.$slug;
        $group = $article->translation_group_id;
        $page = 'pages/en-'.$slug.'.md';
        $identity = ['target_article_id' => $article->id, 'translation_group_id' => $group, 'locale' => 'en', 'slug' => $slug, 'canonical_url' => $route, 'preserve_slug' => true, 'create_new_article' => false, 'create_new_slug' => false];
        $holds = ['schema_hold' => true, 'hreflang_hold' => true, 'search_submission_allowed' => false, 'revalidation_allowed' => false, 'sitemap_change_allowed' => false, 'llms_change_allowed' => false];
        $cms = ['operation_type' => 'update_existing_article', ...$identity, ...$holds, 'title' => 'Understanding a changed personality result', 'meta_title' => 'How to read a changed personality result', 'meta_description' => 'Compare instruments, versions and context before interpreting different personality labels.', 'excerpt' => 'A changed label needs context.', 'claim_gate_status' => 'not_reviewed', 'body_markdown_file' => $case === 'native_file_traversal' ? '../../outside.md' : $page];
        $files = [
            'manifest.json' => ['package_name' => 'mbti-working-update', ...$identity, ...$holds, 'operation_type' => 'update_existing_article', 'schema_enabled' => false, 'schema_generation_allowed' => false, 'hreflang_enabled' => false, 'hreflang_enablement_allowed' => false, 'pages' => [['locale' => 'en', 'file' => $page]]],
            'contracts/ARTICLE_IDENTITY_LOCK.json' => $identity,
            'contracts/PRIVATE_URL_GUARD.json' => ['forbidden_paths' => ['/result', '/results', '/orders', '/order', '/share', '/pay', '/payment', '/history', '/take'], 'forbidden_query_keys' => ['result_id', 'order_id', 'payment_id', 'token', 'score', 'user_id', 'report_id']],
            'cms/CMS_IMPORT_UPDATE_DRAFT_en_'.$slug.'.json' => $cms,
            $page => ($case === 'body_h1' ? '# ' : '## ')."Reading the result\n\nCompare the tool and version before interpreting a changed personality label.".($case === 'private_body_url' ? '\n[Private report](/results?token=synthetic)' : ''),
            'review/claim_gate.md' => "claim_gate_status: not_reviewed\n",
            'review/operator_review.md' => "operator_review_required: true\n",
        ];
        $manifest = ['article_id' => $article->id, 'org_id' => 0, 'expected_org_id' => 0, 'locale' => 'en', 'expected_slug' => $slug, 'expected_canonical' => $route, 'translation_group_id' => $group, 'expected_working_revision_id' => $article->working_revision_id, 'expected_published_revision_id' => $article->published_revision_id, 'expected_working_body_sha256' => hash('sha256', $article->workingRevision->getRawOriginal('content_md')), 'expected_published_body_sha256' => hash('sha256', $article->publishedRevision->getRawOriginal('content_md')), 'files' => []];
        $mutations = ['wrong_article' => ['article_id', 9999], 'wrong_org' => ['expected_org_id', 1], 'wrong_locale' => ['locale', 'zh-CN'], 'wrong_group' => ['translation_group_id', 'other-group'], 'old_pointer' => ['expected_working_revision_id', 9999], 'working_hash' => ['expected_working_body_sha256', str_repeat('0', 64)], 'published_hash' => ['expected_published_body_sha256', str_repeat('0', 64)]];
        if (isset($mutations[$case])) {
            [$key, $value] = $mutations[$case];
            $manifest[$key] = $value;
        }
        if ($case === 'missing_baseline') {
            unset($manifest['expected_published_body_sha256']);
        }
        $uploads = [];
        foreach ($files as $path => $contents) {
            $bytes = is_array($contents) ? json_encode($contents, JSON_THROW_ON_ERROR) : $contents;
            if ($case === 'traversal' && $path === $page) {
                $path = 'pages/../../outside.md';
            }
            $manifest['files'][] = ['path' => $path, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
            $uploads[] = ['path' => $path, 'content_base64' => base64_encode($bytes)];
        }
        if ($case === 'bad_digest') {
            $uploads[0]['content_base64'] = base64_encode(str_repeat('x', strlen(base64_decode($uploads[0]['content_base64']))));
        }
        if ($case === 'extra_file') {
            $uploads[] = ['path' => 'extra.json', 'content_base64' => base64_encode('{}')];
        } elseif ($case === 'duplicate_file') {
            $uploads[1] = $uploads[0];
        }
        $raw = json_encode($manifest, JSON_THROW_ON_ERROR);
        if ($case === 'malformed_json') {
            return '{';
        }
        if ($case === 'oversize') {
            return str_repeat('x', ArticleExistingPackageWorkspace::MAX_INPUT_BYTES + 1);
        }

        return json_encode(['format' => 'article.existing-update.transport.v1', 'manifest_base64' => base64_encode($raw), 'manifest_sha256' => $case === 'bad_manifest_digest' ? str_repeat('0', 64) : hash('sha256', $raw), 'files' => $uploads], JSON_THROW_ON_ERROR);
    }

    private function article(): Article
    {
        $article = Article::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => 'mbti-working-package', 'locale' => 'en', 'source_locale' => 'en', 'translation_status' => 'source', 'translation_group_id' => 'tg_mbti_working_test', 'title' => 'Published title', 'excerpt' => 'Published excerpt', 'content_md' => "## Published body\n\nOriginal text.", 'status' => 'published', 'is_public' => true, 'is_indexable' => true, 'sitemap_eligible' => true, 'llms_eligible' => true, 'published_at' => now()->subDay()]);
        $revision = ArticleTranslationRevision::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $article->id, 'translation_group_id' => $article->translation_group_id, 'locale' => 'en', 'source_locale' => 'en', 'revision_number' => 1, 'revision_status' => 'published', 'title' => $article->title, 'excerpt' => $article->excerpt, 'content_md' => $article->content_md, 'seo_title' => 'Published SEO', 'seo_description' => 'Published description', 'published_at' => now()->subDay()]);
        $article->forceFill(['working_revision_id' => $revision->id, 'published_revision_id' => $revision->id])->save();
        ArticleSeoMeta::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $article->id, 'locale' => 'en', 'seo_title' => 'Published SEO', 'seo_description' => 'Published description', 'canonical_url' => 'https://fermatmind.com/en/articles/'.$article->slug, 'robots' => 'index,follow', 'is_indexable' => true]);

        return $article->fresh(['workingRevision', 'publishedRevision', 'seoMeta']);
    }

    private function login(string $permission = PermissionNames::ADMIN_OWNER): void
    {
        $role = Role::query()->create(['name' => 'article-package-'.uniqid()]);
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['description' => $permission]));
        $admin = AdminUser::query()->create(['name' => 'Package operator', 'email' => uniqid().'@example.test', 'password' => 'test-only-password', 'is_active' => true]);
        $admin->roles()->attach($role);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
    }

    private function assertTemporaryInputsRemoved(): void
    {
        $root = storage_path('app/private/article-update-inputs');
        $this->assertSame([], is_dir($root) ? File::directories($root) : []);
    }
}
