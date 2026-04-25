<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\AdminApprovalResource;
use App\Filament\Ops\Resources\ArticleCategoryResource;
use App\Filament\Ops\Resources\ArticleTagResource;
use App\Filament\Ops\Resources\InterpretationGuideResource;
use App\Filament\Ops\Resources\LandingSurfaceResource;
use App\Filament\Ops\Resources\MediaAssetResource;
use App\Filament\Ops\Resources\SupportArticleResource;
use App\Http\Middleware\SetOpsLocale;
use App\Models\AdminUser;
use App\Models\LandingSurface;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OpsCmsResourceI18nTest extends TestCase
{
    use RefreshDatabase;

    private const BACKEND_ROOT = __DIR__.'/../../..';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    #[DataProvider('localizedResourceProvider')]
    public function test_targeted_ops_resources_expose_localized_chinese_contracts(
        string $resourceClass,
        string $path,
        string $expectedLabel,
        array $forbidden,
        ?string $listPagePath = null,
    ): void {
        app()->setLocale('zh_CN');

        $this->assertSame($expectedLabel, $resourceClass::getNavigationLabel());
        $this->assertSame($expectedLabel, $resourceClass::getModelLabel());
        $this->assertSame($expectedLabel, $resourceClass::getPluralModelLabel());

        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OWNER,
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_WRITE,
            PermissionNames::ADMIN_APPROVAL_REVIEW,
        ]);
        $org = $this->createOrganization();

        $html = $this->withSession($this->opsSession($admin, $org, 'zh_CN'))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get($path.'?locale=zh-CN')
            ->assertOk()
            ->content();

        $this->assertStringContainsString($expectedLabel, $html);

        foreach ($forbidden as $phrase) {
            $this->assertStringNotContainsString(
                $phrase,
                $html,
                "Chinese ops resource page [{$path}] leaked English phrase [{$phrase}].",
            );
        }

        if ($listPagePath !== null) {
            $source = file_get_contents($listPagePath);
            $this->assertIsString($source);
            $this->assertStringContainsString("__('ops.actions.create_resource'", $source);
        }
    }

    public function test_landing_surface_edit_page_uses_localized_field_and_action_contracts(): void
    {
        app()->setLocale('zh_CN');

        $this->assertSame('落地页模块', LandingSurfaceResource::getNavigationLabel());
        $this->assertSame('落地页模块', LandingSurfaceResource::getModelLabel());
        $this->assertSame('落地页模块', LandingSurfaceResource::getPluralModelLabel());

        $resourceSource = file_get_contents(self::BACKEND_ROOT.'/app/Filament/Ops/Resources/LandingSurfaceResource.php');
        $this->assertIsString($resourceSource);

        foreach ([
            "__('ops.edit.fields.surface_key')",
            "__('ops.edit.fields.locale')",
            "__('ops.resources.articles.fields.title')",
            "__('ops.table.status')",
            "__('ops.table.public')",
        ] as $needle) {
            $this->assertStringContainsString($needle, $resourceSource);
        }

        $createPageSource = file_get_contents(self::BACKEND_ROOT.'/app/Filament/Ops/Resources/LandingSurfaceResource/Pages/CreateLandingSurface.php');
        $editPageSource = file_get_contents(self::BACKEND_ROOT.'/app/Filament/Ops/Resources/LandingSurfaceResource/Pages/EditLandingSurface.php');

        $this->assertIsString($createPageSource);
        $this->assertIsString($editPageSource);
        $this->assertStringContainsString("__('ops.actions.create_resource'", $createPageSource);
        $this->assertStringContainsString("__('ops.actions.back_to_resource_list'", $createPageSource);
        $this->assertStringContainsString("__('ops.actions.back_to_resource_list'", $editPageSource);
        $this->assertStringContainsString("__('ops.resources.articles.actions.save')", $editPageSource);

        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OWNER,
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $org = $this->createOrganization();

        $surface = LandingSurface::query()->create([
            'org_id' => 0,
            'surface_key' => 'homepage',
            'locale' => 'zh-CN',
            'title' => '首页',
            'description' => '首页落地页',
            'schema_version' => 'v1',
            'status' => LandingSurface::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'payload_json' => ['hero' => true],
        ]);

        $html = $this->withSession($this->opsSession($admin, $org, 'zh_CN'))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/landing-surfaces/'.$surface->id.'/edit?locale=zh-CN')
            ->assertOk()
            ->content();

        foreach (['Surface key', 'Is public', 'Back to Landing Surfaces'] as $phrase) {
            $this->assertStringNotContainsString($phrase, $html);
        }
    }

    /**
     * @return iterable<string, array{0:class-string,1:string,2:string,3:list<string>,4?:string|null}>
     */
    public static function localizedResourceProvider(): iterable
    {
        yield 'support articles' => [
            SupportArticleResource::class,
            '/ops/support-articles',
            '支持文章',
            ['Support Articles', 'Create Support Articles'],
            self::BACKEND_ROOT.'/app/Filament/Ops/Resources/SupportArticleResource/Pages/ListSupportArticles.php',
        ];

        yield 'interpretation guides' => [
            InterpretationGuideResource::class,
            '/ops/interpretation-guides',
            '解读指南',
            ['Interpretation Guides', 'Create Interpretation Guides'],
            self::BACKEND_ROOT.'/app/Filament/Ops/Resources/InterpretationGuideResource/Pages/ListInterpretationGuides.php',
        ];

        yield 'media assets' => [
            MediaAssetResource::class,
            '/ops/media-assets',
            '媒体库',
            ['Media Library', 'Create Media Library'],
            self::BACKEND_ROOT.'/app/Filament/Ops/Resources/MediaAssetResource/Pages/ListMediaAssets.php',
        ];

        yield 'article categories' => [
            ArticleCategoryResource::class,
            '/ops/article-categories',
            '分类',
            ['Categories', 'Create Categories'],
            self::BACKEND_ROOT.'/app/Filament/Ops/Resources/ArticleCategoryResource/Pages/ListArticleCategories.php',
        ];

        yield 'article tags' => [
            ArticleTagResource::class,
            '/ops/article-tags',
            '标签',
            ['Tags', 'Create Tags'],
            self::BACKEND_ROOT.'/app/Filament/Ops/Resources/ArticleTagResource/Pages/ListArticleTags.php',
        ];

        yield 'admin approvals' => [
            AdminApprovalResource::class,
            '/ops/admin-approvals',
            '审批',
            ['Approvals', 'Requested by', 'Approved by'],
            null,
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'ops_i18n_'.Str::lower(Str::random(6)),
            'email' => 'ops_i18n_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'ops_i18n_role_'.Str::lower(Str::random(8)),
            'guard_name' => (string) config('admin.guard', 'admin'),
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['guard_name' => (string) config('admin.guard', 'admin')]
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }

    private function createOrganization(): Organization
    {
        return Organization::query()->create([
            'name' => 'Ops Cms Resource I18n Org',
            'owner_user_id' => 1001,
            'status' => 'active',
            'domain' => 'ops-cms-resource-i18n.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'zh-CN',
        ]);
    }

    /**
     * @return array{ops_org_id:int,ops_admin_totp_verified_user_id:int}
     */
    private function opsSession(AdminUser $admin, Organization $selectedOrg, string $locale): array
    {
        return [
            'ops_org_id' => (int) $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
            SetOpsLocale::SESSION_KEY => $locale,
            SetOpsLocale::EXPLICIT_SESSION_KEY => true,
        ];
    }
}
