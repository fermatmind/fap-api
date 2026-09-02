<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Analytics\SeoConversionDailyBuilder;
use App\Services\SeoIntel\OpsDashboard\SeoConversionFunnelReadService;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoConversionFunnelOpsReadoutTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seo_intel_read_admin_can_query_public_funnel_without_session_dimension(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_SEO_INTEL_READ]);

        $this->insertDailyRow([
            'url' => 'https://fermatmind.com/en/articles/personality-types',
            'url_hash' => sha1('https://fermatmind.com/en/articles/personality-types'),
            'source_url' => 'https://fermatmind.com/en/articles/personality-types',
            'source_url_hash' => sha1('https://fermatmind.com/en/articles/personality-types'),
            'source_article' => 'personality-types',
            'source_article_hash' => sha1('personality-types'),
            'target_test' => '/en/tests/mbti-personality-test-16-personality-types',
            'target_test_hash' => sha1('/en/tests/mbti-personality-test-16-personality-types'),
            'session_id_hash' => hash('sha256', 'seo_sess_abc123'),
            'landing_pv_count' => 3,
            'article_to_test_click_count' => 2,
            'start_test_count' => 1,
            'complete_test_count' => 1,
            'view_result_count' => 1,
            'return_public_content_count' => 1,
        ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/ops/seo-intel/conversion-funnel?group_by=url')
            ->assertOk()
            ->assertJsonPath('meta.contract_version', 'seo-dash-api-01.v1')
            ->assertJsonPath('data.group_by', 'url')
            ->assertJsonPath('data.recent_rows.0.group_key', '/en/articles/personality-types')
            ->assertJsonPath('data.recent_rows.0.metrics.landing_pv_count', 3)
            ->assertJsonPath('data.recent_rows.0.metrics.article_to_test_click_count', 2)
            ->assertJsonPath('data.recent_rows.0.metrics.start_test_count', 1)
            ->assertJsonPath('data.recent_rows.0.metrics.complete_test_count', 1)
            ->assertJsonPath('data.recent_rows.0.metrics.view_result_count', 1)
            ->assertJsonPath('data.recent_rows.0.metrics.return_public_content_count', 1)
            ->assertJsonPath('data.measurement_state', 'production_healthy')
            ->assertJsonPath('data.available_windows', [7, 28, 90])
            ->assertJsonPath('data.privacy.raw_session_id_exposed', false);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/ops/seo-intel/conversion-funnel?group_by=article&source_article=personality-types')
            ->assertOk()
            ->assertJsonPath('data.group_by', 'article')
            ->assertJsonPath('data.recent_rows.0.group_key', 'personality-types')
            ->assertJsonPath('data.recent_rows.0.metrics.article_to_test_click_count', 2)
            ->assertJsonPath('data.recent_rows.0.metrics.start_test_count', 1);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/ops/seo-intel/conversion-funnel?group_by=test&target_test=/en/tests/mbti-personality-test-16-personality-types')
            ->assertOk()
            ->assertJsonPath('data.group_by', 'test')
            ->assertJsonPath('data.recent_rows.0.group_key', '/en/tests/mbti-personality-test-16-personality-types')
            ->assertJsonPath('data.recent_rows.0.metrics.complete_test_count', 1);

        $sessionResponse = $this->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/ops/seo-intel/conversion-funnel?group_by=session&session_id_hash=forbidden')
            ->assertOk()
            ->assertJsonPath('data.group_by', 'url');

        $json = $sessionResponse->getContent();
        $this->assertStringNotContainsString('seo_sess_abc123', $json);
        $this->assertStringNotContainsString('session_id_hash', $json);
    }

    #[Test]
    public function conversion_funnel_readout_does_not_expose_private_paths_or_sensitive_queries(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_OPS_READ]);

        $this->insertDailyRow([
            'url' => 'https://fermatmind.com/en/articles/personality-types?token=secret',
            'url_hash' => sha1('https://fermatmind.com/en/articles/personality-types'),
            'source_url' => 'https://fermatmind.com/en/articles/personality-types?email=person@example.com',
            'source_url_hash' => sha1('https://fermatmind.com/en/articles/personality-types'),
            'source_article' => 'personality-types',
            'source_article_hash' => sha1('personality-types'),
            'target_test' => '/en/tests/mbti-personality-test-16-personality-types?attempt_id=raw_attempt',
            'target_test_hash' => sha1('/en/tests/mbti-personality-test-16-personality-types'),
            'session_id_hash' => hash('sha256', 'seo_sess_safe'),
            'landing_pv_count' => 1,
        ]);
        $this->insertDailyRow([
            'url' => 'https://fermatmind.com/en/results/raw-result-id',
            'url_hash' => sha1('https://fermatmind.com/en/results/raw-result-id'),
            'source_article' => 'private-leak',
            'source_article_hash' => sha1('private-leak'),
            'session_id_hash' => hash('sha256', 'seo_sess_private'),
            'view_result_count' => 9,
        ]);

        $response = $this->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/ops/seo-intel/conversion-funnel?group_by=url')
            ->assertOk()
            ->assertJsonPath('data.recent_rows.0.url_path', '/en/articles/personality-types')
            ->assertJsonPath('data.recent_rows.0.target_test_path', '/en/tests/mbti-personality-test-16-personality-types')
            ->assertJsonPath('data.totals.view_result_count', 0)
            ->assertJsonPath('data.privacy.private_path_policy', 'result_order_share_pay_history_excluded');

        $json = $response->getContent();
        foreach ([
            'secret',
            'person@example.com',
            'raw_attempt',
            'raw-result-id',
            '/en/results',
            'private-leak',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    #[Test]
    public function conversion_funnel_is_scoped_to_trusted_org_and_rejects_private_dimensions_for_every_grouping(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_SEO_INTEL_READ]);

        $this->insertDailyRow([
            'org_id' => 41,
            'source_article' => 'allowed-article',
            'source_article_hash' => sha1('allowed-article'),
            'landing_pv_count' => 3,
        ]);
        $this->insertDailyRow([
            'org_id' => 42,
            'source_article' => 'other-org-article',
            'source_article_hash' => sha1('other-org-article'),
            'landing_pv_count' => 99,
        ]);
        $this->insertDailyRow([
            'org_id' => 41,
            'url' => 'https://fermatmind.com/en/results/private-result',
            'url_hash' => sha1('https://fermatmind.com/en/results/private-result'),
            'source_article' => 'private-url-article',
            'source_article_hash' => sha1('private-url-article'),
            'landing_pv_count' => 7,
        ]);
        $this->insertDailyRow([
            'org_id' => 41,
            'source_url' => 'https://fermatmind.com/en/orders/private-order',
            'source_url_hash' => sha1('https://fermatmind.com/en/orders/private-order'),
            'source_article' => 'private-source-article',
            'source_article_hash' => sha1('private-source-article'),
            'landing_pv_count' => 8,
        ]);
        $this->insertDailyRow([
            'org_id' => 41,
            'target_test' => '/en/share/private-share',
            'target_test_hash' => sha1('/en/share/private-share'),
            'source_article' => 'private-target-article',
            'source_article_hash' => sha1('private-target-article'),
            'landing_pv_count' => 9,
        ]);

        foreach (['article', 'test', 'url'] as $groupBy) {
            $response = $this->withSession(['ops_org_id' => 41])
                ->actingAs($admin, (string) config('admin.guard', 'admin'))
                ->getJson('/api/v0.5/ops/seo-intel/conversion-funnel?group_by='.$groupBy)
                ->assertOk()
                ->assertJsonPath('data.totals.landing_pv_count', 3);

            $json = $response->getContent();
            foreach ([
                'other-org-article',
                'private-url-article',
                'private-source-article',
                'private-target-article',
                'private-result',
                'private-order',
                'private-share',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $json);
            }
        }
    }

    #[Test]
    public function stale_funnel_returns_measurement_hold_with_null_metrics(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_SEO_INTEL_READ]);
        $this->insertDailyRow([
            'landing_pv_count' => 9,
            'last_refreshed_at' => now()->subDays(3),
        ]);

        $response = $this->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/ops/seo-intel/conversion-funnel?window_days=7')
            ->assertOk()
            ->assertJsonPath('data.measurement_state', 'MEASUREMENT_HOLD')
            ->assertJsonPath('data.totals.landing_pv_count', null)
            ->assertJsonPath('data.recent_rows.0.metrics.landing_pv_count', null)
            ->assertJsonPath('data.stage_status.return_public_content.status', 'MEASUREMENT_HOLD');

        $this->assertStringNotContainsString('session_id_hash', $response->getContent());
    }

    #[Test]
    public function fresh_zero_event_receipt_exposes_real_zero_metrics_without_synthetic_rows(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_SEO_INTEL_READ]);
        $now = now();
        DB::table('analytics_seo_conversion_refresh_runs')->insert([
            'run_uid' => (string) Str::uuid(),
            'trigger_mode' => 'scheduled',
            'status' => 'success',
            'from_date' => $now->toDateString(),
            'to_date' => $now->toDateString(),
            'org_scope_count' => 0,
            'attempted_rows' => 0,
            'skipped_rows' => 0,
            'deleted_rows' => 0,
            'upserted_rows' => 0,
            'receipt_json' => '{}',
            'started_at' => $now,
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/ops/seo-intel/conversion-funnel')
            ->assertOk()
            ->assertJsonPath('data.measurement_state', 'production_healthy')
            ->assertJsonPath('data.freshness.latest_attempt_status', 'success')
            ->assertJsonPath('data.freshness.latest_trigger_mode', 'scheduled')
            ->assertJsonPath('data.totals.landing_pv_count', 0)
            ->assertJsonPath('data.totals.return_public_content_count', 0)
            ->assertJsonPath('data.recent_rows', []);
    }

    #[Test]
    public function fresh_bounded_public_org_zero_receipt_exposes_real_zero_metrics(): void
    {
        config(['app.git_sha' => str_repeat('e', 40)]);
        app(SeoConversionDailyBuilder::class)->refresh(
            now()->subDays(89),
            now(),
            [0],
        );

        $read = app(SeoConversionFunnelReadService::class)->read(0);

        $this->assertSame('production_healthy', $read['measurement_state']);
        $this->assertSame('success', data_get($read, 'freshness.latest_attempt_status'));
        $this->assertSame(0, data_get($read, 'totals.landing_pv_count'));
        $this->assertSame([], $read['recent_rows']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function insertDailyRow(array $overrides): void
    {
        DB::table('analytics_seo_conversion_daily')->insert(array_merge([
            'day' => now()->toDateString(),
            'org_id' => 0,
            'url' => 'https://fermatmind.com/en/articles/personality-types',
            'url_hash' => sha1('https://fermatmind.com/en/articles/personality-types'),
            'lang' => 'en',
            'page_type' => 'article',
            'source_url' => null,
            'source_url_hash' => '',
            'source_article' => '',
            'source_article_hash' => '',
            'target_test' => null,
            'target_test_hash' => '',
            'scale_id' => 'MBTI',
            'form_id' => 'mbti_144',
            'session_id_hash' => '',
            'referrer_host' => 'www.google.com',
            'referrer_host_hash' => sha1('www.google.com'),
            'landing_pv_count' => 0,
            'article_to_test_click_count' => 0,
            'start_test_count' => 0,
            'complete_test_count' => 0,
            'view_result_count' => 0,
            'return_public_content_count' => 0,
            'last_refreshed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'seo_'.Str::lower(Str::random(6)),
            'email' => 'seo_'.Str::lower(Str::random(6)).'@example.test',
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
}
