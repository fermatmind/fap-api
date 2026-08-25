<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\AdminUser;
use App\Services\SeoIntel\OpsDashboard\SeoDashboardApiReadService;
use App\Services\SeoIntel\OpsDashboard\SeoIssueWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SeoOperationsWorkspaceV2Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.seo_intel_workspace_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'seo_intel.connection' => 'seo_intel_workspace_test',
        ]);
        DB::purge('seo_intel_workspace_test');

        Schema::connection('seo_intel_workspace_test')->create('seo_gsc_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->text('canonical_url')->nullable();
            $table->char('query_hash', 64)->nullable();
            $table->string('query_display_masked', 255)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('source_engine', 64)->default('google');
            $table->string('device', 32)->nullable();
            $table->string('country', 16)->nullable();
            $table->string('search_type', 32)->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('ctr_ppm')->nullable();
            $table->unsignedInteger('average_position_milli')->nullable();
            $table->boolean('is_brand_query')->default(false);
            $table->string('query_type', 32)->default('unknown');
            $table->string('data_state', 32)->default('final');
            $table->timestamp('collected_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });

        Schema::connection('seo_intel_workspace_test')->create('seo_issue_queue', function (Blueprint $table): void {
            $table->id();
            $table->string('issue_uid', 128)->unique();
            $table->string('issue_type', 64);
            $table->string('severity', 32);
            $table->string('source_system', 64);
            $table->string('source_engine', 64)->nullable();
            $table->char('canonical_url_hash', 64)->nullable();
            $table->text('canonical_url')->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('page_entity_type', 64)->nullable();
            $table->string('status', 32);
            $table->string('lifecycle_state', 32);
            $table->unsignedBigInteger('owner_admin_user_id')->nullable();
            $table->timestamp('sla_due_at')->nullable();
            $table->text('operator_note')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('ignored_at')->nullable();
            $table->text('ignore_reason')->nullable();
            $table->timestamp('ignore_until')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by_admin_user_id')->nullable();
            $table->text('verification_note')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->string('summary', 512)->nullable();
            $table->string('recommendation', 512)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
    }

    public function test_gsc_metrics_are_empty_until_real_rows_exist_and_respect_filters(): void
    {
        $reader = new SeoDashboardApiReadService('seo_intel_workspace_test');
        $this->assertFalse((bool) $reader->searchPerformance()['connected']);

        DB::connection('seo_intel_workspace_test')->table('seo_gsc_daily')->insert([
            'report_date' => now()->toDateString(),
            'canonical_url' => 'https://fermatmind.com/zh/articles/seo-workspace',
            'query_display_masked' => 'seo 运营',
            'locale' => 'zh-CN',
            'device' => 'mobile',
            'country' => 'CHN',
            'clicks' => 12,
            'impressions' => 240,
            'ctr_ppm' => 50000,
            'average_position_milli' => 8500,
            'collected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $reader->searchPerformance(['days' => 28, 'device' => 'mobile', 'country' => 'CHN', 'locale' => 'zh-CN']);

        $this->assertTrue((bool) $result['connected']);
        $this->assertSame(12, data_get($result, 'totals.clicks'));
        $this->assertSame(240, data_get($result, 'totals.impressions'));
        $this->assertSame(5.0, data_get($result, 'totals.ctr_percent'));
        $this->assertSame(8.5, data_get($result, 'totals.average_position'));
        $this->assertSame('production_healthy', $result['measurement_state']);
        $this->assertSame([7, 28, 90], $result['available_windows']);
        $this->assertSame('articles_topics', data_get($result, 'breakdowns.page_family.0.dimension'));
        $this->assertSame(240, data_get($result, 'breakdowns.locale.0.impressions'));
        $this->assertSame('/zh/articles/seo-workspace', data_get($result, 'query_page_rows.0.canonical_path'));
        $desktop = $reader->searchPerformance(['device' => 'desktop']);
        $this->assertFalse((bool) $desktop['connected']);
        $this->assertSame('MEASUREMENT_HOLD', $desktop['measurement_state']);
        $this->assertNull(data_get($desktop, 'totals.clicks'));
    }

    public function test_gsc_aggregates_cover_the_complete_window_beyond_detail_row_limit(): void
    {
        $now = now();
        $rows = [];
        for ($index = 0; $index < 2001; $index++) {
            $rows[] = [
                'report_date' => $now->toDateString(),
                'canonical_url' => 'https://fermatmind.com/zh/articles/seo-'.$index,
                'query_display_masked' => 'query '.$index,
                'locale' => 'zh-CN',
                'device' => 'mobile',
                'country' => 'CHN',
                'search_type' => 'web',
                'clicks' => 1,
                'impressions' => 2,
                'ctr_ppm' => 500000,
                'average_position_milli' => 10000,
                'collected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::connection('seo_intel_workspace_test')->table('seo_gsc_daily')->insert($chunk);
        }

        $result = (new SeoDashboardApiReadService('seo_intel_workspace_test'))->searchPerformance(['days' => 90]);

        $this->assertSame(2001, data_get($result, 'totals.clicks'));
        $this->assertSame(4002, data_get($result, 'totals.impressions'));
        $this->assertSame(10.0, data_get($result, 'totals.average_position'));
        $this->assertSame(2001, data_get($result, 'daily.0.clicks'));
        $this->assertCount(25, $result['query_page_rows']);
    }

    public function test_issue_workflow_requires_fix_before_verify_and_records_owner_sla_and_verification(): void
    {
        $this->assertSame(['open', 'in_progress'], SeoIssueWorkflowService::allowedStatusesFor(SeoIssueWorkflowService::ACTION_ASSIGN));
        $this->assertSame(['resolved'], SeoIssueWorkflowService::allowedStatusesFor(SeoIssueWorkflowService::ACTION_VERIFY));

        DB::connection('seo_intel_workspace_test')->table('seo_issue_queue')->insert([
            'issue_uid' => 'issue-1',
            'issue_type' => 'canonical_drift',
            'severity' => 'high',
            'source_system' => 'drift_foundation',
            'status' => 'new',
            'lifecycle_state' => 'open',
            'detected_at' => now(),
            'metadata_json' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new SeoIssueWorkflowService;

        $actor = $this->actor();

        try {
            $service->transition('issue-1', SeoIssueWorkflowService::ACTION_VERIFY, $actor, 0, verificationNote: 'manual check');
            $this->fail('Verify must fail before the issue is marked fixed.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $service->transition('issue-1', SeoIssueWorkflowService::ACTION_ASSIGN, $actor, 0);
        $service->transition('issue-1', SeoIssueWorkflowService::ACTION_FIXED, $actor, 1, operatorNote: 'canonical corrected');
        $service->transition('issue-1', SeoIssueWorkflowService::ACTION_VERIFY, $actor, 2, verificationNote: 'manual source inspection passed');

        $row = DB::connection('seo_intel_workspace_test')->table('seo_issue_queue')->where('issue_uid', 'issue-1')->first();

        $this->assertSame('closed', $row->status);
        $this->assertSame('closed', $row->lifecycle_state);
        $this->assertNotNull($row->resolved_at);
        $this->assertSame(42, $row->owner_admin_user_id);
        $this->assertNotNull($row->sla_due_at);
        $this->assertSame(42, $row->verified_by_admin_user_id);
        $this->assertSame('manual source inspection passed', $row->verification_note);
        $this->assertSame(3, $row->lock_version);
    }

    public function test_workflow_fails_closed_for_stale_versions_invalid_ignore_and_unauthorized_actor(): void
    {
        $this->insertIssue('issue-guard');
        $service = new SeoIssueWorkflowService;
        $actor = $this->actor();

        $service->transition('issue-guard', SeoIssueWorkflowService::ACTION_ASSIGN, $actor, 0);

        $this->expectException(ValidationException::class);
        $service->transition('issue-guard', SeoIssueWorkflowService::ACTION_FIXED, $actor, 0);
    }

    public function test_ignore_requires_future_expiry_and_expired_ignore_reopens_with_new_version(): void
    {
        $this->insertIssue('issue-ignore');
        $service = new SeoIssueWorkflowService;
        $actor = $this->actor();

        try {
            $service->transition('issue-ignore', SeoIssueWorkflowService::ACTION_IGNORE, $actor, 0, ignoreReason: 'temporary');
            $this->fail('Ignore without a future expiry must fail.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $service->transition(
            'issue-ignore',
            SeoIssueWorkflowService::ACTION_IGNORE,
            $actor,
            0,
            ignoreReason: 'temporary external dependency',
            ignoredUntil: now()->addDay()->toDateString(),
        );
        DB::connection('seo_intel_workspace_test')->table('seo_issue_queue')
            ->where('issue_uid', 'issue-ignore')
            ->update(['ignore_until' => now()->subMinute()]);

        $results = $service->reopenExpiredIgnores($actor);
        $row = DB::connection('seo_intel_workspace_test')->table('seo_issue_queue')->where('issue_uid', 'issue-ignore')->first();

        $this->assertSame('ignore_expired_reopen', $results[0]['action']);
        $this->assertSame('open', $row->status);
        $this->assertNull($row->ignore_reason);
        $this->assertNull($row->ignore_until);
        $this->assertSame(2, $row->lock_version);
    }

    public function test_policy_rejects_actor_without_content_write_authority(): void
    {
        $this->insertIssue('issue-auth');
        $actor = new class extends AdminUser
        {
            public function hasPermission(string $permissionName): bool
            {
                return false;
            }
        };
        $actor->forceFill(['id' => 99, 'is_active' => 1]);

        $this->expectException(AuthorizationException::class);
        (new SeoIssueWorkflowService)->transition('issue-auth', SeoIssueWorkflowService::ACTION_ASSIGN, $actor, 0);
    }

    private function insertIssue(string $uid): void
    {
        DB::connection('seo_intel_workspace_test')->table('seo_issue_queue')->insert([
            'issue_uid' => $uid,
            'issue_type' => 'canonical_drift',
            'severity' => 'critical',
            'source_system' => 'drift_foundation',
            'status' => 'open',
            'lifecycle_state' => 'open',
            'detected_at' => now(),
            'metadata_json' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function actor(): AdminUser
    {
        $actor = new class extends AdminUser
        {
            public function hasPermission(string $permissionName): bool
            {
                return true;
            }
        };
        $actor->forceFill(['id' => 42, 'is_active' => 1]);

        return $actor;
    }
}
