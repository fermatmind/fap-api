<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\OpsDashboard\SeoDashboardApiReadService;
use App\Services\SeoIntel\OpsDashboard\SeoIssueWorkflowService;
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
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('ignored_at')->nullable();
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
        $this->assertSame('/zh/articles/seo-workspace', data_get($result, 'query_page_rows.0.canonical_path'));
        $this->assertFalse((bool) $reader->searchPerformance(['device' => 'desktop'])['connected']);
    }

    public function test_issue_workflow_requires_fix_before_verify_and_records_owner_sla_and_verification(): void
    {
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

        try {
            $service->transition('issue-1', SeoIssueWorkflowService::ACTION_VERIFY, 'SEO owner');
            $this->fail('Verify must fail before the issue is marked fixed.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $service->transition('issue-1', SeoIssueWorkflowService::ACTION_ASSIGN, 'SEO owner');
        $service->transition('issue-1', SeoIssueWorkflowService::ACTION_FIXED, 'SEO owner');
        $service->transition('issue-1', SeoIssueWorkflowService::ACTION_VERIFY, 'SEO owner');

        $row = DB::connection('seo_intel_workspace_test')->table('seo_issue_queue')->where('issue_uid', 'issue-1')->first();
        $metadata = json_decode((string) $row->metadata_json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('verified', $row->status);
        $this->assertSame('resolved', $row->lifecycle_state);
        $this->assertNotNull($row->resolved_at);
        $this->assertSame('SEO owner', data_get($metadata, 'ops_workflow.owner'));
        $this->assertNotNull(data_get($metadata, 'ops_workflow.sla_due_at'));
        $this->assertSame('passed_by_operator', data_get($metadata, 'ops_workflow.verification_result'));
    }
}
