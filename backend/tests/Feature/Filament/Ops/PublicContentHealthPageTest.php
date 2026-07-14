<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Ops;

use App\Filament\Ops\Pages\PublicContentHealthPage;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Ops\PublicContentRuntimeMetricsService;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PublicContentHealthPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        config()->set('public_content_observability.cache_store', 'array');
        config()->set('public_content_observability.probe.cache_store', 'array');
        config()->set('public_content_observability.probe.base_url', 'https://probe.example.test');
        config()->set('public_content_observability.probe.enabled', true);
        Cache::store('array')->flush();
    }

    #[DataProvider('authorizedPermissionProvider')]
    public function test_authorized_permission_can_open_page_without_org_selection(string $permission): void
    {
        $admin = $this->createAdminWithPermissions([$permission]);

        $this->withSession($this->opsSession($admin))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/public-content-health?locale=en')
            ->assertOk()
            ->assertSee('Public content health');
    }

    public function test_content_only_permission_cannot_open_page(): void
    {

        $contentOnly = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);

        $this->withSession($this->opsSession($contentOnly))
            ->actingAs($contentOnly, (string) config('admin.guard', 'admin'))
            ->get('/ops/public-content-health?locale=en')
            ->assertForbidden();
    }

    /** @return array<string, array{string}> */
    public static function authorizedPermissionProvider(): array
    {
        return [
            'owner' => [PermissionNames::ADMIN_OWNER],
            'operator' => [PermissionNames::ADMIN_OPS_READ],
            'analyst' => [PermissionNames::ADMIN_EVENTS_READ],
        ];
    }

    public function test_page_renders_aggregate_runtime_cache_probe_and_safe_publication_readback(): void
    {
        $metrics = app(PublicContentRuntimeMetricsService::class);
        $metrics->record('mbti', 'L1', 'en', 200, 80.0);
        $metrics->record('big_five', 'L2', 'en', 404, 120.0);
        $metrics->record('career_industries', 'L3', 'en', 200, 60.0);

        Http::fake(function (Request $request) {
            return match (true) {
                str_contains($request->url(), '/personality/intj-a') => Http::response([
                    'profile' => [
                        'published_at' => '2026-07-01T00:00:00Z',
                        'updated_at' => '2026-07-13T00:00:00Z',
                    ],
                    'mbti_public_projection_v1' => ['display_type' => 'INTJ-A'],
                    'private_payload' => 'must-not-render',
                ], 200, ['X-Fermat-Public-Read-Cache' => 'fresh']),
                str_contains($request->url(), '/personality-content-assets/') => Http::response([
                    'personality_public_content_asset_v1' => [
                        'contract_version' => 'personality.public_content_asset.v1',
                        'launch_state' => 'published',
                        'review_state' => 'approved',
                        'published_at' => '2026-07-01T00:00:00Z',
                        'updated_at' => '2026-07-13T00:00:00Z',
                    ],
                ], 200, ['X-Fermat-Public-Read-Cache' => 'miss']),
                default => Http::response([
                    'authority_version' => 'career.industry_directory.v1',
                    'bundle_version' => 'career.industry_directory.v1',
                    'locale' => 'en',
                    'public_detail_indexable_count' => 1048,
                    'industry_count' => 23,
                ]),
            };
        });

        foreach (range(1, 3) as $_) {
            $this->assertSame(0, Artisan::call('public-content:probe-delivery', ['--json' => true]));
        }

        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OPS_READ,
        ]);

        $this->withSession($this->opsSession($admin))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/public-content-health?locale=en')
            ->assertOk()
            ->assertSee('Health overview')
            ->assertSee('Runtime delivery aggregates')
            ->assertSee('Fixed delivery and cache probes')
            ->assertSee('Publication readback')
            ->assertSee('MBTI')
            ->assertSee('Big Five')
            ->assertSee('Career industries')
            ->assertSee('INTJ-A')
            ->assertSee('personality.public_content_asset.v1')
            ->assertSee('career.industry_directory.v1')
            ->assertDontSee('must-not-render')
            ->assertDontSee('probe.example.test');
    }

    public function test_storage_failures_render_bounded_unavailable_states_without_exception_details(): void
    {
        config()->set('public_content_observability.cache_store', 'missing-store');
        config()->set('public_content_observability.probe.cache_store', 'missing-store');
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_EVENTS_READ,
        ]);

        $this->withSession($this->opsSession($admin))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/public-content-health?locale=en')
            ->assertOk()
            ->assertSee('Runtime metrics unavailable')
            ->assertSee('Probe storage unavailable')
            ->assertDontSee('Cache store [missing-store] is not defined');
    }

    public function test_observed_failed_publication_readback_is_not_reported_as_no_data(): void
    {
        Http::fake(function (Request $request) {
            return match (true) {
                str_contains($request->url(), '/personality/intj-a') => Http::response([
                    'profile' => [
                        'published_at' => '2026-07-01T00:00:00Z',
                        'updated_at' => '2026-07-13T00:00:00Z',
                    ],
                    'mbti_public_projection_v1' => ['display_type' => 'INTJ-A'],
                ], 200, ['X-Fermat-Public-Read-Cache' => 'fresh']),
                str_contains($request->url(), '/personality-content-assets/') => Http::response([
                    'personality_public_content_asset_v1' => [
                        'contract_version' => 'personality.public_content_asset.v1',
                    ],
                ], 200, ['X-Fermat-Public-Read-Cache' => 'fresh']),
                default => Http::response([
                    'authority_version' => 'career.industry_directory.v1',
                    'bundle_version' => 'career.industry_directory.v1',
                    'locale' => 'en',
                    'public_detail_indexable_count' => 1048,
                    'industry_count' => 23,
                ]),
            };
        });

        foreach ([0, 1, 0] as $expectedExitCode) {
            $this->assertSame($expectedExitCode, Artisan::call('public-content:probe-delivery', ['--json' => true]));
        }

        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(PublicContentHealthPage::class)
            ->assertSet('publicationCards.0.status_state', 'healthy')
            ->assertSet('publicationCards.1.status_state', 'failed')
            ->assertSet('publicationCards.2.status_state', 'healthy');
    }

    public function test_page_source_exposes_no_mutating_control(): void
    {
        $view = file_get_contents(resource_path('views/filament/ops/pages/public-content-health.blade.php'));

        $this->assertIsString($view);
        $this->assertStringNotContainsString('<form', $view);
        $this->assertStringNotContainsString('wire:click', $view);
        $this->assertStringNotContainsString('x-filament::button', $view);

        foreach (['warm', 'purge', 'publish', 'retryWrite', 'grantPermission', 'deploy'] as $method) {
            $this->assertFalse(method_exists(PublicContentHealthPage::class, $method));
        }
    }

    /** @param list<string> $permissions */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'health_'.Str::lower(Str::random(6)),
            'email' => 'health_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);
        $role = Role::query()->create([
            'name' => 'health_role_'.Str::lower(Str::random(8)),
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

    /** @return array<string, mixed> */
    private function opsSession(AdminUser $admin): array
    {
        return [
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
            'ops_locale' => 'en',
            'ops_locale_explicit' => true,
        ];
    }
}
