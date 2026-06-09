<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
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
    public function seo_intel_read_admin_can_query_conversion_funnel_by_url_article_test_and_session(): void
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
            ->getJson('/api/v0.5/ops/seo-intel/conversion-funnel?group_by=session')
            ->assertOk()
            ->assertJsonPath('data.group_by', 'session')
            ->assertJsonPath('data.recent_rows.0.group_key', hash('sha256', 'seo_sess_abc123'));

        $json = $sessionResponse->getContent();
        $this->assertStringNotContainsString('seo_sess_abc123', $json);
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

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function insertDailyRow(array $overrides): void
    {
        DB::table('analytics_seo_conversion_daily')->insert(array_merge([
            'day' => '2026-06-09',
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
            'last_refreshed_at' => '2026-06-09 00:00:00',
            'created_at' => '2026-06-09 00:00:00',
            'updated_at' => '2026-06-09 00:00:00',
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
