<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Http\Middleware\SetOpsLocale;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationManager;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OpsCustomPagesI18nContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(NavigationManager::class);
        app()->forgetInstance('ops.navigation_locale');

        parent::tearDown();
    }

    /**
     * @param  list<string>  $zhForbidden
     * @param  list<string>  $enForbidden
     */
    #[DataProvider('customPageProvider')]
    public function test_custom_ops_pages_render_from_explicit_locale_copy(string $path, array $zhForbidden, array $enForbidden): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OWNER,
            PermissionNames::ADMIN_OPS_READ,
            PermissionNames::ADMIN_MENU_SUPPORT,
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_WRITE,
            PermissionNames::ADMIN_CONTENT_RELEASE,
            PermissionNames::ADMIN_APPROVAL_REVIEW,
        ]);
        $org = $this->createOrganization('Ops Custom I18n Org');

        $zhHtml = $this->withSession($this->opsSession($admin, $org, 'zh_CN'))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get($path.'?locale=zh-CN')
            ->assertOk()
            ->content();

        foreach ($zhForbidden as $phrase) {
            $this->assertStringNotContainsString(
                $phrase,
                $zhHtml,
                "Chinese Ops page [{$path}] leaked English UI phrase [{$phrase}].",
            );
        }

        $enHtml = $this->withSession($this->opsSession($admin, $org, 'en'))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get($path.'?locale=en')
            ->assertOk()
            ->content();

        foreach ($enForbidden as $phrase) {
            $this->assertStringNotContainsString(
                $phrase,
                $enHtml,
                "English Ops page [{$path}] leaked Chinese UI phrase [{$phrase}].",
            );
        }
    }

    /**
     * @return iterable<string,array{0:string,1:list<string>,2:list<string>}>
     */
    public static function customPageProvider(): iterable
    {
        yield 'question analytics' => [
            '/ops/question-analytics',
            ['Authority Scope', 'Option Distribution', 'Dropoff / Completion', 'Only completed attempts'],
            ['权威范围', '选项分布', '流失 / 完成', '仅完成作答'],
        ];

        yield 'editorial operations' => [
            '/ops/editorial-operations',
            ['Editorial operations', 'Operations snapshot', 'Editorial surfaces', 'Release Queue'],
            ['内容编辑运营', '运营快照', '编辑界面', '发布队列'],
        ];

        yield 'editorial review' => [
            '/ops/editorial-review',
            ['Editorial review', 'Review queue', 'Approval boundary', 'Assign owner'],
            ['内容审核', '审核队列', '审批边界', '分配负责人'],
        ];

        yield 'content release' => [
            '/ops/content-release',
            ['Content release', 'Release workspace', 'Release review', 'Needs Workflow'],
            ['内容发布', '发布工作台', '发布审核', '需要工作流'],
        ];

        yield 'content search' => [
            '/ops/content-search',
            ['Content search', 'Search by title / slug / excerpt / category / tag', 'Start with a content search'],
            ['内容搜索', '按标题 / slug / 摘要 / 分类 / 标签搜索', '先开始内容搜索'],
        ];

        yield 'content metrics' => [
            '/ops/content-metrics',
            ['Content metrics', 'Metrics contract', 'Freshness and pressure', 'Latest record'],
            ['内容指标', '指标契约', '新鲜度与压力', '最新记录'],
        ];

        yield 'seo operations' => [
            '/ops/seo-operations',
            ['SEO operations', 'Issue focus', 'Attention queue', 'No SEO issues match the current filters.'],
            ['SEO运营', '问题焦点', '关注队列', '当前筛选下没有匹配的 SEO 问题。'],
        ];

        yield 'post release observability' => [
            '/ops/post-release-observability',
            ['Post-release observability', 'Release telemetry', 'Recently published records', 'No publish audits yet'],
            ['发布后可观测性', '发布遥测', '最近发布记录', '暂无发布审计'],
        ];

        yield 'global search' => [
            '/ops/global-search',
            ['Global search', 'Support workspace', 'Start with a search', 'Search by order_no / attempt_id / share_id / user_email'],
            ['全局搜索', '支持工作台', '先开始搜索', '按 order_no / attempt_id / share_id / user_email 搜索'],
        ];

        yield 'order lookup' => [
            '/ops/order-lookup',
            ['Order lookup', 'Search for an order', 'Payment events', 'Benefit grants'],
            ['订单查询', '搜索订单', '支付事件', '权益发放'],
        ];

        yield 'reports' => [
            '/ops/reports',
            ['Report Snapshot', 'Report Snapshots', 'PDF ready', 'Unlock status'],
            ['报告快照', 'PDF 就绪', '解锁状态'],
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'ops_'.Str::lower(Str::random(6)),
            'email' => 'ops_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'role_'.Str::lower(Str::random(8)),
            'description' => null,
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => null],
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }

    private function createOrganization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'owner_user_id' => random_int(1000, 9999),
            'status' => 'active',
            'domain' => Str::slug($name).'.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);
    }

    /**
     * @return array{ops_org_id:int,ops_admin_totp_verified_user_id:int,ops_locale:string,ops_locale_explicit:bool}
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
