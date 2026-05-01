<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Contracts\Cms\CmsMachineTranslationProvider;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupportArticle;
use App\Services\Cms\CmsTranslationWorkflowException;
use App\Services\Cms\RowBackedRevisionWorkspace;
use App\Services\Cms\SiblingTranslationWorkflowService;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CmsTranslationShadowRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeSupportArticleTranslationProvider::$calls = 0;
        config()->set('services.cms_translation.providers.support_article', FakeSupportArticleTranslationProvider::class);
        app()->bind(FakeSupportArticleTranslationProvider::class, fn (): FakeSupportArticleTranslationProvider => new FakeSupportArticleTranslationProvider);
    }

    public function test_machine_translation_blocks_unapproved_source_before_provider_call(): void
    {
        $workflow = app(SiblingTranslationWorkflowService::class);
        $source = $this->createSourceSupportArticle();
        $source->forceFill([
            'status' => SupportArticle::STATUS_DRAFT,
            'review_state' => SupportArticle::REVIEW_DRAFT,
            'published_at' => null,
        ])->save();

        try {
            $workflow->createMachineDraft('support_article', $source->fresh(), 'en');
            $this->fail('Expected source disclosure guard to block the machine translation draft.');
        } catch (CmsTranslationWorkflowException $exception) {
            $this->assertContains('source row not published', $exception->blockers());
            $this->assertContains('source row not approved', $exception->blockers());
            $this->assertContains('source row adapter publication check failed', $exception->blockers());
        }

        $this->assertSame(0, FakeSupportArticleTranslationProvider::$calls);
        $this->assertDatabaseMissing('support_articles', [
            'translation_group_id' => (string) $source->translation_group_id,
            'locale' => 'en',
        ]);
    }

    public function test_publish_requires_approved_working_revision(): void
    {
        $workflow = app(SiblingTranslationWorkflowService::class);
        $source = $this->createSourceSupportArticle();
        $target = $workflow->createMachineDraft('support_article', $source, 'en');

        try {
            $workflow->publishTranslation('support_article', $target->fresh());
            $this->fail('Expected publish guard to require an approved working revision.');
        } catch (CmsTranslationWorkflowException $exception) {
            $this->assertSame('Only approved translation revisions can be published.', $exception->getMessage());
            $this->assertContains('working revision is not approved', $exception->blockers());
        }

        $target = $target->fresh();
        $this->assertSame(SupportArticle::STATUS_DRAFT, (string) $target->status);
        $this->assertSame(SupportArticle::TRANSLATION_STATUS_MACHINE_DRAFT, (string) $target->translation_status);
        $this->assertNull($target->published_revision_id);
    }

    public function test_resync_creates_new_working_revision_without_overwriting_public_row(): void
    {
        $workspace = app(RowBackedRevisionWorkspace::class);
        $workflow = app(SiblingTranslationWorkflowService::class);

        $source = $this->createSourceSupportArticle();
        $target = $this->createPublishedTargetTranslation($source);
        $workspace->ensureInitialRevision('support_article', $target);
        $target = $target->fresh();

        $publishedRevisionId = (int) $target->published_revision_id;
        $publishedBody = (string) $target->body_md;

        $source->forceFill([
            'title' => 'Updated zh source',
            'body_md' => 'Updated zh source body',
            'body_html' => '<p>Updated zh source body</p>',
        ])->save();

        $resynced = $workflow->resyncFromSource('support_article', $target->fresh());

        $this->assertSame($publishedRevisionId, (int) $resynced->published_revision_id);
        $this->assertNotSame($publishedRevisionId, (int) $resynced->working_revision_id);
        $this->assertSame($publishedBody, (string) $resynced->body_md);
        $this->assertDatabaseHas('support_articles', [
            'id' => (int) $resynced->id,
            'body_md' => $publishedBody,
            'published_revision_id' => $publishedRevisionId,
        ]);

        $editorRecord = $workspace->editorRecord('support_article', $resynced->fresh());
        $this->assertSame('Resynced machine draft body', (string) $editorRecord->body_md);
        $this->assertSame('Resynced machine draft title', (string) $editorRecord->title);

        $this->getJson('/api/v0.5/support/articles/recover-report?locale=en')
            ->assertOk()
            ->assertJsonPath('article.body_md', $publishedBody)
            ->assertJsonPath('article.title', 'Published EN title');

        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $this->withSession(['ops_org_id' => 0])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/internal/support-articles/recover-report?locale=en')
            ->assertOk()
            ->assertJsonPath('article.body_md', 'Resynced machine draft body')
            ->assertJsonPath('article.title', 'Resynced machine draft title');
    }

    public function test_publish_promotes_working_revision_to_public_row(): void
    {
        $workspace = app(RowBackedRevisionWorkspace::class);
        $workflow = app(SiblingTranslationWorkflowService::class);

        $source = $this->createSourceSupportArticle();
        $target = $this->createPublishedTargetTranslation($source);
        $workspace->ensureInitialRevision('support_article', $target);

        $source->forceFill([
            'title' => 'Updated zh source',
            'body_md' => 'Updated zh source body',
            'body_html' => '<p>Updated zh source body</p>',
        ])->save();

        $resynced = $workflow->resyncFromSource('support_article', $target->fresh());
        $publishedRevisionId = (int) $resynced->published_revision_id;

        $workflow->promoteToHumanReview('support_article', $resynced->fresh());
        $workflow->approveTranslation('support_article', $resynced->fresh());
        $published = $workflow->publishTranslation('support_article', $resynced->fresh());

        $this->assertNotSame($publishedRevisionId, (int) $published->published_revision_id);
        $this->assertSame((int) $published->working_revision_id, (int) $published->published_revision_id);
        $this->assertSame('Resynced machine draft body', (string) $published->body_md);

        $this->getJson('/api/v0.5/support/articles/recover-report?locale=en')
            ->assertOk()
            ->assertJsonPath('article.body_md', 'Resynced machine draft body')
            ->assertJsonPath('article.title', 'Resynced machine draft title');
    }

    private function createSourceSupportArticle(): SupportArticle
    {
        return SupportArticle::query()->create([
            'org_id' => 0,
            'slug' => 'recover-report',
            'title' => '中文源文',
            'summary' => '源文摘要',
            'body_md' => '源文正文',
            'body_html' => '<p>源文正文</p>',
            'support_category' => 'reports',
            'support_intent' => 'recover_report',
            'locale' => 'zh-CN',
            'translation_group_id' => 'support-recover-report',
            'source_locale' => 'zh-CN',
            'translation_status' => SupportArticle::TRANSLATION_STATUS_SOURCE,
            'status' => SupportArticle::STATUS_PUBLISHED,
            'review_state' => SupportArticle::REVIEW_APPROVED,
            'published_at' => now(),
            'seo_title' => '中文 SEO',
            'seo_description' => '中文 SEO 摘要',
            'canonical_path' => '/support/recover-report',
        ]);
    }

    private function createPublishedTargetTranslation(SupportArticle $source): SupportArticle
    {
        return SupportArticle::query()->create([
            'org_id' => 0,
            'slug' => 'recover-report',
            'title' => 'Published EN title',
            'summary' => 'Published EN summary',
            'body_md' => 'Published EN body',
            'body_html' => '<p>Published EN body</p>',
            'support_category' => 'reports',
            'support_intent' => 'recover_report',
            'locale' => 'en',
            'translation_group_id' => (string) $source->translation_group_id,
            'source_locale' => 'zh-CN',
            'translation_status' => SupportArticle::TRANSLATION_STATUS_PUBLISHED,
            'source_content_id' => (int) $source->id,
            'translated_from_version_hash' => (string) $source->source_version_hash,
            'status' => SupportArticle::STATUS_PUBLISHED,
            'review_state' => SupportArticle::REVIEW_APPROVED,
            'published_at' => now(),
            'seo_title' => 'Published EN SEO',
            'seo_description' => 'Published EN SEO description',
            'canonical_path' => '/support/recover-report',
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'admin_'.Str::lower(Str::random(6)),
            'email' => 'admin_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        if ($permissions === []) {
            return $admin;
        }

        $role = Role::query()->create([
            'name' => 'role_'.Str::lower(Str::random(8)),
            'guard_name' => (string) config('admin.guard', 'admin'),
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->create([
                'name' => $permissionName,
                'guard_name' => (string) config('admin.guard', 'admin'),
            ]);
            $role->permissions()->attach($permission);
        }

        $admin->roles()->attach($role);

        return $admin;
    }
}

final class FakeSupportArticleTranslationProvider implements CmsMachineTranslationProvider
{
    public static int $calls = 0;

    public function supports(string $contentType): bool
    {
        return $contentType === 'support_article';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function unavailableReason(string $contentType): ?string
    {
        return null;
    }

    public function translate(string $contentType, object $sourceRecord, array $normalizedSource, string $targetLocale): array
    {
        self::$calls++;

        return [
            'title' => 'Resynced machine draft title',
            'summary' => 'Resynced machine draft summary',
            'body_md' => 'Resynced machine draft body',
            'seo_title' => 'Resynced machine draft SEO',
            'seo_description' => 'Resynced machine draft SEO description',
        ];
    }
}
