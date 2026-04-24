<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Http\Middleware\SetOpsLocale;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\ReportSnapshot;
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
    public function test_custom_ops_pages_render_from_explicit_locale_copy(
        string $path,
        string $zhExpectedTitle,
        string $enExpectedTitle,
        array $zhForbidden,
        array $enForbidden,
    ): void {
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

        $this->assertStringContainsString($zhExpectedTitle, $zhHtml);

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

        $this->assertStringContainsString($enExpectedTitle, $enHtml);

        foreach ($enForbidden as $phrase) {
            $this->assertStringNotContainsString(
                $phrase,
                $enHtml,
                "English Ops page [{$path}] leaked Chinese UI phrase [{$phrase}].",
            );
        }
    }

    public function test_report_detail_subpage_uses_explicit_locale_copy(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OWNER,
            PermissionNames::ADMIN_OPS_READ,
            PermissionNames::ADMIN_MENU_SUPPORT,
        ]);
        $org = $this->createOrganization('Ops Report Detail I18n Org');
        $snapshot = ReportSnapshot::query()->create([
            'org_id' => (int) $org->id,
            'attempt_id' => 'attempt_ops_i18n_detail',
            'order_no' => 'ord_ops_i18n_detail',
            'scale_code' => 'MBTI',
            'pack_id' => 'pack_i18n',
            'dir_version' => 'dir_i18n',
            'scoring_spec_version' => 'scoring_i18n',
            'report_engine_version' => 'engine_i18n',
            'snapshot_version' => 'snapshot_i18n',
            'report_json' => ['variant' => 'compact'],
            'report_free_json' => [],
            'report_full_json' => [],
            'status' => 'ready',
            'last_error' => null,
        ]);
        $path = '/ops/reports/'.$snapshot->getKey();

        $zhHtml = $this->withSession($this->opsSession($admin, $org, 'zh_CN'))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get($path.'?locale=zh-CN')
            ->assertOk()
            ->content();

        foreach (['Support diagnostic', 'Snapshot Summary', 'Report Job / Generation Status', 'Raw report payloads stay hidden'] as $phrase) {
            $this->assertStringNotContainsString($phrase, $zhHtml);
        }

        $enHtml = $this->withSession($this->opsSession($admin, $org, 'en'))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get($path.'?locale=en')
            ->assertOk()
            ->content();

        foreach (['支持诊断', '快照摘要', '报告任务 / 生成状态', '默认隐藏原始报告载荷'] as $phrase) {
            $this->assertStringNotContainsString($phrase, $enHtml);
        }
    }

    /**
     * @return iterable<string,array{0:string,1:string,2:string,3:list<string>,4:list<string>}>
     */
    public static function customPageProvider(): iterable
    {
        yield 'question analytics' => [
            '/ops/question-analytics',
            '题目分析',
            'Question Analytics',
            ['Authority Scope', 'Option Distribution', 'Dropoff / Completion', 'Only completed attempts'],
            ['权威范围', '选项分布', '流失 / 完成', '仅完成作答'],
        ];

        yield 'editorial operations' => [
            '/ops/editorial-operations',
            '内容编辑运营',
            'Editorial operations',
            ['Editorial Operations Page', 'Editorial operations', 'Operations snapshot', 'Editorial surfaces', 'Release Queue'],
            ['内容编辑运营', '运营快照', '编辑界面', '发布队列'],
        ];

        yield 'editorial review' => [
            '/ops/editorial-review',
            '内容审核',
            'Editorial review',
            ['Editorial Review Page', 'Editorial review', 'Review queue', 'Approval boundary', 'Assign owner'],
            ['内容审核', '审核队列', '审批边界', '分配负责人'],
        ];

        yield 'content release' => [
            '/ops/content-release',
            '内容发布',
            'Content release',
            ['Content Release Page', 'Content release', 'Release workspace', 'Release review', 'Needs Workflow'],
            ['内容发布', '发布工作台', '发布审核', '需要工作流'],
        ];

        yield 'content search' => [
            '/ops/content-search',
            '内容搜索',
            'Content search',
            ['Content Search Page', 'Content search', 'Search by title / slug / excerpt / category / tag', 'Start with a content search'],
            ['内容搜索', '按标题 / slug / 摘要 / 分类 / 标签搜索', '先开始内容搜索'],
        ];

        yield 'content metrics' => [
            '/ops/content-metrics',
            '内容指标',
            'Content metrics',
            ['Content Metrics Page', 'Content metrics', 'Metrics contract', 'Freshness and pressure', 'Latest record'],
            ['内容指标', '指标契约', '新鲜度与压力', '最新记录'],
        ];

        yield 'seo operations' => [
            '/ops/seo-operations',
            'SEO运营',
            'SEO operations',
            ['SEO Operations Page', 'Seo Operations Page', 'Issue focus', 'Attention queue', 'No SEO issues match the current filters.'],
            ['SEO运营', '问题焦点', '关注队列', '当前筛选下没有匹配的 SEO 问题。'],
        ];

        yield 'post release observability' => [
            '/ops/post-release-observability',
            '发布后可观测性',
            'Post-release observability',
            ['Post Release Observability Page', 'Post-release observability', 'Release telemetry', 'Recently published records', 'No publish audits yet'],
            ['发布后可观测性', '发布遥测', '最近发布记录', '暂无发布审计'],
        ];

        yield 'global search' => [
            '/ops/global-search',
            '全局搜索',
            'Global search',
            ['Global Search Page', 'Support workspace', 'Start with a search', 'Search by order_no / attempt_id / share_id / user_email'],
            ['全局搜索', '支持工作台', '先开始搜索', '按 order_no / attempt_id / share_id / user_email 搜索'],
        ];

        yield 'order lookup' => [
            '/ops/order-lookup',
            '订单查询',
            'Order lookup',
            ['Order Lookup Page', 'Order lookup', 'Search for an order', 'Payment events', 'Benefit grants'],
            ['订单查询', '搜索订单', '支付事件', '权益发放'],
        ];

        yield 'delivery tools' => [
            '/ops/delivery-tools',
            '交付工具',
            'Delivery tools',
            ['Delivery Tools', 'Delivery Tools Page', 'Support tools', 'Order number', 'Request status', 'No request submitted yet'],
            ['交付工具', '支持工具', '订单号', '请求状态', '尚未提交请求'],
        ];

        yield 'content workspace' => [
            '/ops/content-workspace',
            '内容工作台',
            'Content workspace',
            ['Content Workspace Page', 'Content workspace', 'Permission boundary', 'Access model', 'Content read', 'records'],
            ['内容工作台', '权限边界', '访问模型', '内容读取', '条记录'],
        ];

        yield 'reports' => [
            '/ops/reports',
            '报告快照',
            'Report Snapshots',
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
