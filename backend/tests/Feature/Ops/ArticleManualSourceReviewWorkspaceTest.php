<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\ArticleResource\Pages\CreateArticle;
use App\Filament\Ops\Resources\ArticleResource\Pages\EditArticle;
use App\Filament\Ops\Support\ArticleManualSourceReviewWorkspace;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ArticleManualSourceReviewWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_binds_exact_current_source_without_publishing_or_changing_editorial_content(): void
    {
        [$source, $target] = $this->fixture();
        $before = $target->getRawOriginal();
        $published = $target->publishedRevision->getRawOriginal();
        $working = $target->workingRevision->getRawOriginal();
        $seo = $target->seoMeta->getRawOriginal();
        $service = app(ArticleManualSourceReviewWorkspace::class);
        $input = $service->prepare($target);
        $service->confirm($target, [...$input, 'source_reviewed' => true]);

        $target->refresh()->load(['workingRevision', 'publishedRevision', 'seoMeta']);
        $this->assertSame($before, $target->getRawOriginal());
        $this->assertSame($published, $target->publishedRevision->getRawOriginal());
        $this->assertSame($seo, $target->seoMeta->getRawOriginal());
        foreach (['title', 'excerpt', 'content_md', 'seo_title', 'seo_description', 'revision_status', 'reviewed_by', 'reviewed_at', 'approved_at', 'published_at'] as $field) {
            $this->assertSame($working[$field], $target->workingRevision->getRawOriginal($field), $field);
        }
        $this->assertSame($source->source_version_hash, $target->workingRevision->translated_from_version_hash);
        $this->assertDatabaseCount('article_editorial_package_imports', 0);
        $audit = AuditLog::withoutGlobalScopes()->where('action', 'article_manual_source_reviewed')->sole();
        $this->assertSame((int) auth('admin')->id(), $audit->actor_admin_id);
        $this->assertSame($source->source_version_hash, data_get($audit->meta_json, 'reviewed_source_version_hash'));
        $this->assertNull($target->workingRevision->approved_at);
    }

    #[DataProvider('drifts')]
    public function test_drift_rejects_without_rebinding(string $surface): void
    {
        [$source, $target] = $this->fixture();
        $service = app(ArticleManualSourceReviewWorkspace::class);
        $input = $service->prepare($target);
        match ($surface) {
            'source_body' => $source->workingRevision->forceFill(['content_md' => '## Concurrent source edit'])->save(),
            'target_body' => $target->workingRevision->forceFill(['content_md' => '## Concurrent target edit'])->save(),
            'source_seo' => ArticleSeoMeta::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $source->id, 'locale' => 'zh-CN', 'seo_title' => 'Concurrent SEO']),
            'target_seo' => $target->seoMeta->forceFill(['robots' => 'index,follow'])->save(),
            'approved' => $target->workingRevision->forceFill(['revision_status' => 'approved', 'approved_at' => now()])->save(),
            'source_pointer' => $source->forceFill(['working_revision_id' => null])->saveQuietly(),
        };
        $this->assertRejected($service, $target, [...$input, 'source_reviewed' => true]);
        $this->assertSame(str_repeat('a', 64), $target->fresh('workingRevision')->workingRevision->translated_from_version_hash);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'article_manual_source_reviewed']);
    }

    public static function drifts(): array
    {
        return array_map(static fn (string $case): array => [$case], ['source_body', 'target_body', 'source_seo', 'target_seo', 'approved', 'source_pointer']);
    }

    public function test_permission_and_step_up_and_explicit_review_are_required(): void
    {
        [, $target] = $this->fixture();
        $service = app(ArticleManualSourceReviewWorkspace::class);
        $input = $service->prepare($target);
        $this->assertRejected($service, $target, $input);
        config(['admin.totp.enabled' => true]);
        try {
            $service->confirm($target, [...$input, 'source_reviewed' => true]);
            $this->fail('Missing step-up must reject.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        config(['admin.totp.enabled' => false]);
        auth('admin')->logout();
        try {
            $service->confirm($target, [...$input, 'source_reviewed' => true]);
            $this->fail('Missing actor must reject.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(str_repeat('a', 64), $target->fresh('workingRevision')->workingRevision->translated_from_version_hash);
    }

    public function test_audit_failure_rolls_back_the_source_binding(): void
    {
        [, $target] = $this->fixture();
        $service = app(ArticleManualSourceReviewWorkspace::class);
        $input = $service->prepare($target);
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('injected audit failure'));
        try {
            $service->confirm($target, [...$input, 'source_reviewed' => true]);
            $this->fail('Audit failure must escape and roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected audit failure', $exception->getMessage());
        }
        $this->assertSame(str_repeat('a', 64), $target->fresh('workingRevision')->workingRevision->translated_from_version_hash);
    }

    public function test_real_editor_action_and_robots_round_trip(): void
    {
        [$source, $target] = $this->fixture();
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        app(OrgContext::class)->set(17, null, 'admin');
        Livewire::test(EditArticle::class, ['record' => $target->id])
            ->assertFormSet(['robots' => 'noindex,nofollow'])
            ->fillForm(['robots' => 'index,follow'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('index,follow', $target->fresh('seoMeta')->seoMeta->robots);
        $this->assertSame(0, $target->fresh('seoMeta')->seoMeta->org_id);
        $this->assertSame('en', $target->fresh('seoMeta')->seoMeta->locale);
        $input = app(ArticleManualSourceReviewWorkspace::class)->prepare($target->fresh());
        Livewire::test(EditArticle::class, ['record' => $target->id])
            ->callAction('confirmManualSourceReview', [...$input, 'source_reviewed' => true])
            ->assertHasNoActionErrors()->assertRedirect();
        $this->assertSame($source->source_version_hash, $target->fresh('workingRevision')->workingRevision->translated_from_version_hash);
        $this->assertSame('human_review', $target->fresh('workingRevision')->workingRevision->revision_status);
    }

    public function test_prepared_hashes_and_preview_bindings_are_read_in_one_transaction(): void
    {
        [$source, $target] = $this->fixture();
        $outerLevel = DB::transactionLevel();
        $levels = [];
        DB::listen(function ($query) use (&$levels): void {
            if (str_starts_with(strtolower($query->sql), 'select')) {
                $levels[] = DB::transactionLevel();
            }
        });
        $input = app(ArticleManualSourceReviewWorkspace::class)->prepare($target);
        $this->assertNotEmpty($levels);
        $this->assertGreaterThan($outerLevel, min($levels));
        $context = json_decode($input['review_context'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($source->working_revision_id, $context['source_published_revision_id']);
        $this->assertStringContainsString('revision='.$source->working_revision_id.'&', $context['source_preview']);
        $this->assertStringContainsString('revision='.$target->working_revision_id.'&', $context['target_preview']);
    }

    public function test_robots_is_not_offered_or_dehydrated_on_the_create_form(): void
    {
        $this->fixture();
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        Livewire::test(CreateArticle::class)->assertFormFieldIsHidden('robots');
    }

    private function assertRejected(ArticleManualSourceReviewWorkspace $service, Article $target, array $input): void
    {
        try {
            $service->confirm($target, $input);
            $this->fail('Changed or unreviewed state must reject.');
        } catch (ValidationException) {
        }
    }

    private function fixture(): array
    {
        $admin = AdminUser::create(['name' => 'Owner', 'email' => 'manual-review@example.test', 'password' => bcrypt('fixture-only'), 'is_active' => true]);
        $role = Role::create(['name' => 'manual-source-review-owner', 'guard_name' => 'admin']);
        $permission = Permission::firstOrCreate(['name' => PermissionNames::ADMIN_OWNER], ['guard_name' => 'admin']);
        $role->permissions()->sync([$permission->id]);
        $admin->roles()->sync([$role->id]);
        $this->actingAs($admin, 'admin');
        $source = Article::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => 'manual-source-review', 'locale' => 'zh-CN', 'title' => '当前源文', 'excerpt' => '当前摘要', 'content_md' => '## 当前源文', 'status' => 'published', 'is_public' => true, 'is_indexable' => true]);
        $sourceRevision = ArticleTranslationRevision::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $source->id, 'source_article_id' => $source->id, 'translation_group_id' => $source->translation_group_id, 'locale' => 'zh-CN', 'source_locale' => 'zh-CN', 'revision_number' => 1, 'revision_status' => 'published', 'source_version_hash' => $source->source_version_hash, 'translated_from_version_hash' => $source->source_version_hash, 'title' => $source->title, 'excerpt' => $source->excerpt, 'content_md' => $source->content_md, 'published_at' => now()]);
        $source->forceFill(['working_revision_id' => $sourceRevision->id, 'published_revision_id' => $sourceRevision->id])->saveQuietly();
        $target = Article::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => $source->slug, 'locale' => 'en', 'source_locale' => 'zh-CN', 'source_article_id' => $source->id, 'translated_from_article_id' => $source->id, 'translation_group_id' => $source->translation_group_id, 'translated_from_version_hash' => str_repeat('a', 64), 'translation_status' => 'human_review', 'title' => 'Published English', 'excerpt' => 'Published excerpt', 'content_md' => '## Published body', 'status' => 'published', 'is_public' => true, 'is_indexable' => true]);
        $payload = ['org_id' => 0, 'article_id' => $target->id, 'source_article_id' => $source->id, 'translation_group_id' => $source->translation_group_id, 'locale' => 'en', 'source_locale' => 'zh-CN', 'source_version_hash' => str_repeat('a', 64), 'translated_from_version_hash' => str_repeat('a', 64), 'title' => 'English final article', 'excerpt' => 'A complete English summary.', 'content_md' => '## Reviewed English body', 'seo_title' => 'English SEO title', 'seo_description' => 'English SEO description'];
        $published = ArticleTranslationRevision::withoutGlobalScopes()->create([...$payload, 'revision_number' => 1, 'revision_status' => 'published', 'published_at' => now()]);
        $working = ArticleTranslationRevision::withoutGlobalScopes()->create([...$payload, 'revision_number' => 2, 'revision_status' => 'human_review']);
        $target->forceFill(['working_revision_id' => $working->id, 'published_revision_id' => $published->id])->saveQuietly();
        ArticleSeoMeta::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $target->id, 'locale' => 'en', 'seo_title' => $payload['seo_title'], 'seo_description' => $payload['seo_description'], 'canonical_url' => 'https://fermatmind.com/en/articles/'.$target->slug, 'robots' => 'noindex,nofollow']);

        return [$source->fresh(['workingRevision']), $target->fresh(['workingRevision', 'publishedRevision', 'seoMeta'])];
    }
}
