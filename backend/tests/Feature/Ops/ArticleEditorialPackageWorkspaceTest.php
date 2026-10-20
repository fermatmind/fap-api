<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\ArticleResource\Pages\EditArticle;
use App\Filament\Ops\Support\ArticleEditorialPackageWorkspace;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Cms\ArticleTranslationRevisionWorkspace;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ArticleEditorialPackageWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_import_preserves_verified_cover_and_creates_private_review_gate(): void
    {
        $this->login();
        [$article, $asset] = $this->draft();
        $variants = $article->cover_image_variants;
        $input = $this->transport($article, $asset);
        $result = app(ArticleEditorialPackageWorkspace::class)->import($article, $input);
        $article->refresh()->load(['workingRevision', 'seoMeta']);
        $this->assertSame((int) $article->id, $result['article_id']);
        $this->assertSame('human_review', $result['working_revision_status']);
        $this->assertSame('draft', $article->status);
        $this->assertFalse($article->is_public);
        $this->assertFalse($article->is_indexable);
        $this->assertNull($article->published_revision_id);
        $this->assertSame($this->package($article)['body_markdown'], $article->workingRevision->content_md);
        foreach ($variants as $key => $variant) {
            $this->assertSame($variant, $article->cover_image_variants[$key]);
        }
        $this->assertSame(1600, $article->cover_image_width);
        $this->assertSame(900, $article->cover_image_height);
        $this->assertSame($variants['og']['url'], $article->seoMeta->og_image_url);
        $this->assertSame('noindex,nofollow', $article->seoMeta->robots);
        $import = ArticleEditorialPackageImport::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('imported', $import->status);
        $this->assertSame((int) auth('admin')->id(), $import->imported_by);
        $this->assertSame(1, $import->references_count);
        $this->assertSame('editor_supplied', $import->answer_surface_json['policy']);
        $this->assertArrayNotHasKey('package', $result);
        $this->assertTemporaryInputsRemoved();
        $this->getJson('/api/v0.5/articles/'.$article->slug.'?locale=en')->assertNotFound();
        try {
            app(ArticleEditorialPackageWorkspace::class)->import($article, $input);
            $this->fail('The same transport cannot replay an old baseline.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('draft_baseline_mismatch', $exception->getMessage());
        }
        $this->assertDatabaseCount('article_editorial_package_imports', 1);
        $this->assertDatabaseCount('articles', 1);
    }

    public function test_editor_action_imports_under_positive_ops_tenant_context(): void
    {
        $this->login();
        [$article, $asset] = $this->draft();
        $input = $this->transport($article, $asset);
        app(OrgContext::class)->set(91, null, 'admin');
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        Livewire::test(EditArticle::class, ['record' => $article->id])
            ->assertActionVisible('importEditorialPackage')
            ->callAction('importEditorialPackage', ['package_json' => $input])
            ->assertHasNoActionErrors()->assertRedirect();
        $this->assertSame('human_review', $article->fresh('workingRevision')->workingRevision->revision_status);
        $this->assertDatabaseCount('article_editorial_package_imports', 1);
        $this->assertTemporaryInputsRemoved();
    }

    public function test_invalid_json_displays_on_the_action_without_an_import_gate(): void
    {
        $this->login();
        [$article] = $this->draft();
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        Livewire::test(EditArticle::class, ['record' => $article->id])
            ->callAction('importEditorialPackage', ['package_json' => '{'])
            ->assertHasActionErrors(['package_json']);
        $this->assertDatabaseCount('article_editorial_package_imports', 0);
        $this->assertTemporaryInputsRemoved();
    }

    public function test_reader_cannot_import_or_see_the_action(): void
    {
        $this->login(PermissionNames::ADMIN_CONTENT_READ);
        [$article] = $this->draft();
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        Livewire::test(EditArticle::class, ['record' => $article->id])->assertForbidden();
        try {
            app(ArticleEditorialPackageWorkspace::class)->import($article, '{}');
            $this->fail('A reader must not import.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('article_editorial_package_imports', 0);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_or_stale_transport_leaves_draft_and_gate_unchanged(string $case): void
    {
        $this->login();
        [$article, $asset] = $this->draft();
        $input = $this->transport($article, $asset, $case);
        $before = $this->state($article);
        try {
            app(ArticleEditorialPackageWorkspace::class)->import($article, $input);
            $this->fail('Invalid transport must reject: '.$case);
        } catch (ValidationException) {
            $this->assertSame($before, $this->state($article));
        }
        $this->assertDatabaseCount('article_editorial_package_imports', 0);
        $this->assertDatabaseCount('article_translation_revisions', 1);
        $this->assertDatabaseCount('articles', 1);
        $this->assertTemporaryInputsRemoved();
    }

    public static function invalidInputs(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'wrong_article', 'wrong_org', 'wrong_locale', 'wrong_group', 'old_revision',
            'missing_baseline', 'bad_digest', 'bad_encoding', 'malformed_json', 'oversize',
            'wrong_package_slug', 'wrong_package_locale', 'wrong_package_group', 'invalid_native_package',
            'changed_revision', 'changed_article', 'changed_seo', 'published_article',
            'unpublished_asset', 'private_asset', 'wrong_media_org', 'unverified_variant',
            'missing_variant', 'wrong_variant_url', 'wrong_variant_dimensions', 'wrong_cover', 'non_image_mime', 'private_disk',
        ]);
    }

    public function test_original_dimensions_saved_by_the_real_cover_selector_import_successfully(): void
    {
        $this->login();
        [$article, $asset] = $this->draft();
        $asset->forceFill(['width' => 2048, 'height' => 1152])->save();
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        Livewire::test(EditArticle::class, ['record' => $article->id])
            ->set('data.cover_media_asset_id', $asset->id)
            ->call('save')->assertHasNoFormErrors();
        $article->refresh()->load(['workingRevision', 'seoMeta']);
        $this->assertSame(2048, $article->cover_image_width);
        $this->assertSame(1600, $article->cover_image_variants['hero']['width']);
        app(ArticleEditorialPackageWorkspace::class)->import($article, $this->transport($article, $asset));
        $this->assertSame(2048, $article->refresh()->cover_image_width);
        $this->assertSame(1152, $article->cover_image_height);
        $this->assertDatabaseCount('article_editorial_package_imports', 1);
    }

    public function test_json_object_order_does_not_change_baseline(): void
    {
        $this->login();
        [$article, $asset] = $this->draft();
        $input = json_decode($this->transport($article, $asset), true, 64, JSON_THROW_ON_ERROR);
        $input['target'] = array_reverse($input['target'], true);
        app(ArticleEditorialPackageWorkspace::class)->import($article, json_encode($input, JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('article_editorial_package_imports', 1);
    }

    public function test_native_failure_restores_article_revision_media_and_seo_and_removes_inputs(): void
    {
        $this->login();
        [$article, $asset] = $this->draft();
        $input = $this->transport($article, $asset);
        $before = $this->state($article);
        $dispatcher = ArticleEditorialPackageImport::getEventDispatcher();
        ArticleEditorialPackageImport::setEventDispatcher(clone $dispatcher);
        ArticleEditorialPackageImport::creating(static function (): void {
            throw new RuntimeException('Injected native import gate failure');
        });
        try {
            app(ArticleEditorialPackageWorkspace::class)->import($article, $input);
            $this->fail('Native persistence failure must roll back.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('native_import_failed', $exception->getMessage());
        } finally {
            ArticleEditorialPackageImport::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, $this->state($article));
        $this->assertDatabaseCount('article_editorial_package_imports', 0);
        $this->assertDatabaseCount('article_translation_revisions', 1);
        $this->assertTemporaryInputsRemoved();
    }

    private function state(Article $article): array
    {
        return [$article->fresh()->getRawOriginal(), $article->workingRevision->fresh()->getRawOriginal(), $article->seoMeta->fresh()->getRawOriginal()];
    }

    private function transport(Article $article, MediaAsset $asset, ?string $case = null): string
    {
        $target = app(ArticleEditorialPackageWorkspace::class)->baseline($article);
        $package = $this->package($article);
        match ($case) {
            'wrong_article' => $target['article_id']++,
            'wrong_org' => $target['org_id'] = 41,
            'wrong_locale' => $target['locale'] = 'zh-CN',
            'wrong_group' => $target['translation_group_id'] = 'other-group',
            'old_revision' => $target['working_revision_id']++,
            'missing_baseline' => $target = [],
            'wrong_package_slug' => $package['slug'] = 'other-slug',
            'wrong_package_locale' => $package['locale'] = 'zh-CN',
            'wrong_package_group' => $package['translation_group_id'] = 'other-group',
            'invalid_native_package' => $package['references'] = [],
            'changed_revision' => $article->workingRevision->forceFill(['content_md' => 'Changed body'])->save(),
            'changed_article' => $article->forceFill(['cover_image_alt' => 'Changed alt'])->save(),
            'changed_seo' => $article->seoMeta->forceFill(['seo_title' => 'Changed SEO'])->save(),
            'published_article' => $article->forceFill(['status' => 'published', 'is_public' => true, 'published_revision_id' => $article->working_revision_id])->save(),
            'unpublished_asset' => $asset->forceFill(['status' => 'draft'])->save(),
            'private_asset' => $asset->forceFill(['is_public' => false])->save(),
            'wrong_media_org' => $asset->forceFill(['org_id' => 41])->save(),
            'unverified_variant' => $asset->variants()->where('variant_key', 'hero')->update(['cdn_status' => 'not_verified']),
            'missing_variant' => $asset->variants()->where('variant_key', 'preload')->delete(),
            'wrong_variant_url' => $asset->variants()->where('variant_key', 'card')->update(['url' => 'https://assets.fermatmind.com/different.jpg']),
            'wrong_variant_dimensions' => $asset->variants()->where('variant_key', 'card')->update(['width' => 801]),
            'non_image_mime' => $asset->variants()->where('variant_key', 'og')->update(['mime_type' => 'application/pdf']),
            'private_disk' => $asset->forceFill(['disk' => 'private'])->save(),
            'wrong_cover' => $package['cover_image'] = 'https://assets.fermatmind.com/different-hero.jpg',
            default => null,
        };
        $raw = json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($case === 'malformed_json') {
            return '{';
        }
        if ($case === 'oversize') {
            return str_repeat('x', ArticleEditorialPackageWorkspace::MAX_INPUT_BYTES + 1);
        }

        return json_encode([
            'format' => 'article.editorial-draft.transport.v1', 'target' => $target, 'media_asset_id' => (int) $asset->id,
            'package_base64' => $case === 'bad_encoding' ? '***' : base64_encode($raw),
            'package_sha256' => $case === 'bad_digest' ? str_repeat('0', 64) : hash('sha256', $raw),
        ], JSON_THROW_ON_ERROR);
    }

    private function draft(): array
    {
        $article = Article::withoutGlobalScopes()->create([
            'org_id' => 0, 'slug' => 'mbti-editorial-import-test', 'locale' => 'en', 'source_locale' => 'en',
            'translation_group_id' => 'tg_mbti_editorial_import_test', 'translation_status' => 'source',
            'title' => 'Old private title', 'excerpt' => 'Old private excerpt', 'content_md' => 'Old private body.',
            'status' => 'draft', 'is_public' => false, 'is_indexable' => false,
        ]);
        app(ArticleTranslationRevisionWorkspace::class)->saveWorkingRevision($article, ['title' => $article->title, 'excerpt' => $article->excerpt, 'content_md' => $article->content_md, 'working_revision_status' => 'machine_draft']);
        $asset = MediaAsset::withoutGlobalScopes()->create(['org_id' => 0, 'asset_key' => 'test/editorial/cover', 'url' => 'https://assets.fermatmind.com/source.jpg', 'width' => 1600, 'height' => 900, 'alt' => 'Media Library cover', 'status' => 'published', 'is_public' => true, 'sync_status' => 'synced', 'cdn_status' => 'verified']);
        $variants = [];
        foreach (['hero' => [1600, 900], 'card' => [800, 450], 'og' => [1200, 630], 'thumbnail' => [400, 225], 'preload' => [64, 36]] as $key => [$width, $height]) {
            $url = 'https://assets.fermatmind.com/editorial-cover-'.$key.'.jpg';
            MediaVariant::create(['media_asset_id' => $asset->id, 'variant_key' => $key, 'url' => $url, 'width' => $width, 'height' => $height, 'mime_type' => 'image/jpeg', 'sync_status' => 'synced', 'cdn_status' => 'verified', 'verified_at' => now()]);
            $variants[$key] = ['url' => $url, 'width' => $width, 'height' => $height, 'mime_type' => 'image/jpeg'];
        }
        $article->forceFill(['cover_image_url' => $variants['hero']['url'], 'cover_image_alt' => 'Old cover alt', 'cover_image_width' => 1600, 'cover_image_height' => 900, 'cover_image_variants' => $variants])->save();
        ArticleSeoMeta::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $article->id, 'locale' => 'en', 'seo_title' => 'Old private SEO', 'seo_description' => 'Old private description', 'canonical_url' => 'https://fermatmind.com/en/articles/'.$article->slug, 'og_image_url' => $variants['og']['url'], 'robots' => 'noindex,nofollow', 'is_indexable' => false]);

        return [$article->fresh(['workingRevision', 'seoMeta']), $asset];
    }

    private function package(Article $article): array
    {
        return [
            'package_version' => 'editorial_package.v1', 'title' => 'Reading a personality result', 'slug' => $article->slug,
            'locale' => 'en', 'translation_group_id' => $article->translation_group_id, 'author' => 'FermatMind', 'intended_status' => 'review_pending',
            'body_markdown' => "A result offers hypotheses to compare with everyday behavior.\n\n## Explore your result\n\nConsider a supporting example and a counterexample.\n\n## Next step\n\nChoose one observation to check during the week.",
            'references' => ['Official instrument guide. https://www.myersbriggs.org/my-mbti-personality-type/take-the-mbti-instrument/'],
            'seo_title' => 'Reading a Personality Result', 'meta_description' => 'Use a result as a starting point for observing preferences and everyday behavior.',
            'excerpt' => 'Read a result with supporting examples and counterexamples.', 'canonical' => 'https://fermatmind.com/en/articles/'.$article->slug,
            'indexability' => false, 'content_track' => 'editorial_journal', 'category' => 'Personality Testing', 'tags' => ['MBTI'],
            'topic_cluster' => 'mbti', 'content_series' => 'mbti-result-reading', 'audience_intent' => 'self_understanding', 'commercial_priority' => 'medium',
            'signal_source' => 'MBTI', 'signal_type' => 'identity', 'decision_domains' => ['self'],
            'target_tests' => ['mbti-personality-test-16-personality-types'], 'target_topics' => ['mbti'], 'target_personality_pages' => [], 'target_career_pages' => [], 'target_reports' => [],
            'next_action' => 'start_mbti_test', 'internal_links' => ['/en/tests/mbti-personality-test-16-personality-types'],
            'graph_edges' => ['from_article_to_test' => ['mbti-personality-test-16-personality-types']], 'recommended_reverse_links' => [],
            'cover_image' => $article->cover_image_url, 'cover_image_alt' => 'A reader reflects on a personality overview.',
            'cover_image_prompt' => 'A reader examines two descriptions.', 'cover_image_style_tag' => 'editorial',
            'answer_surface_policy' => 'editor_supplied', 'answer_surface_v1' => ['quick_answer' => 'Use descriptions as prompts for observation.', 'faq_items' => [['question' => 'Does a type guarantee a result?', 'answer' => 'No. Examine the actual conditions and your experience.']]],
            'answer_surface_visibility' => 'below_body', 'cta_slots' => [['position' => 'after_body', 'label' => 'Compare questionnaires', 'href' => '/en/tests/mbti-personality-test-16-personality-types']],
            'primary_cta' => 'Compare questionnaires', 'secondary_cta' => '', 'freemium_entry' => 'mbti', 'report_upsell_allowed' => false,
            'claim_boundary_notes' => ['A type does not guarantee outcomes.'], 'claim_level' => 'descriptive', 'sensitivity_level' => 'normal',
            'medical_disclaimer_required' => false, 'ability_disclaimer_required' => false, 'external_references_required' => true, 'review_required_by' => ['editor'],
        ];
    }

    private function login(string $permission = PermissionNames::ADMIN_OWNER): void
    {
        $role = Role::create(['name' => 'editorial-import-'.uniqid()]);
        $role->permissions()->attach(Permission::firstOrCreate(['name' => $permission], ['description' => $permission]));
        $admin = AdminUser::create(['name' => 'Editorial operator', 'email' => uniqid().'@example.test', 'password' => 'test-only-password', 'is_active' => true]);
        $admin->roles()->attach($role);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
    }

    private function assertTemporaryInputsRemoved(): void
    {
        $root = storage_path('app/private/article-editorial-inputs');
        $this->assertSame([], is_dir($root) ? File::directories($root) : []);
    }
}
