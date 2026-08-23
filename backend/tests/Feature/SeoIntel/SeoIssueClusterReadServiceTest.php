<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\OpsDashboard\SeoIssueClusterReadService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeoIssueClusterReadServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.seo_issue_cluster_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('seo_issue_cluster_test');

        Schema::connection('seo_issue_cluster_test')->create('seo_issue_queue', function (Blueprint $table): void {
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
            $table->string('summary', 512)->nullable();
            $table->string('recommendation', 512)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });

        Schema::connection('seo_issue_cluster_test')->create('seo_gsc_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->char('query_hash', 64)->nullable();
            $table->string('source_engine', 64)->default('google');
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->json('metadata_json')->nullable();
        });

        foreach (range(1, 3) as $index) {
            $this->insertIssue(
                uid: 'title-'.$index,
                type: 'missing_title',
                severity: $index === 3 ? 'high' : 'warning',
                url: 'https://fermatmind.com/en/articles/title-'.$index,
                metadata: [
                    'root_cause' => 'article_template_missing_title',
                    'template' => 'article_detail',
                    'field' => 'title',
                    'observation' => 'revision-'.$index,
                ],
            );
        }

        $this->insertIssue(
            uid: 'canonical-1',
            type: 'canonical_mismatch',
            severity: 'critical',
            url: 'https://fermatmind.com/en/articles/canonical-1',
            metadata: [
                'root_cause' => 'article_template_canonical',
                'template' => 'article_detail',
                'field' => 'canonical',
            ],
        );
    }

    public function test_duplicate_url_issues_collapse_into_deterministic_actionable_clusters(): void
    {
        $reader = new SeoIssueClusterReadService('seo_issue_cluster_test');
        $first = $reader->read();
        $second = $reader->read();

        $this->assertSame(2, $first['total_count']);
        $this->assertSame($first['rows'], $second['rows']);

        $titleCluster = collect($first['rows'])->firstWhere('field', 'title');
        $this->assertIsArray($titleCluster);
        $this->assertMatchesRegularExpression('/^seo-cluster:[0-9a-f]{64}$/', $titleCluster['cluster_uid']);
        $this->assertSame('article_template_missing_title', $titleCluster['root_cause']);
        $this->assertSame('article', $titleCluster['content_type']);
        $this->assertSame('article_detail', $titleCluster['template']);
        $this->assertSame('high', $titleCluster['severity']);
        $this->assertSame(3, $titleCluster['affected_url_count']);
        $this->assertSame(3, $titleCluster['issue_count']);
        $this->assertSame(3, $titleCluster['evidence_count']);
        $this->assertSame('open', $titleCluster['status']);
        $this->assertSame('cms_rule', $titleCluster['source']);
        $this->assertSame('Add a unique title in the Article template.', $titleCluster['recommendation']);
        $this->assertSame(4.0, data_get($titleCluster, 'priority.score'));
        $this->assertSame(12, data_get($titleCluster, 'priority.impact.total'));
        $this->assertFalse(data_get($titleCluster, 'priority.impact.gsc.included'));
        $this->assertSame('cms_technical_only_no_eligible_gsc', data_get($titleCluster, 'priority.impact.gsc.basis'));
        $this->assertSame(1.0, data_get($titleCluster, 'priority.confidence.value'));
        $this->assertSame(3, data_get($titleCluster, 'priority.effort.value'));
        $this->assertSame('impact_12_x_confidence_1.00_div_effort_3', data_get($titleCluster, 'priority.sort_reason'));
    }

    public function test_quality_gated_gsc_observations_add_real_impact_without_estimating_click_loss(): void
    {
        $titleUrl = 'https://fermatmind.com/en/articles/title-1';
        DB::connection('seo_issue_cluster_test')->table('seo_gsc_daily')->insert([
            'report_date' => now()->subDays(3)->toDateString(),
            'canonical_url_hash' => hash('sha256', $titleUrl),
            'query_hash' => hash('sha256', 'title query'),
            'source_engine' => 'google',
            'clicks' => 7,
            'impressions' => 120,
            'metadata_json' => json_encode(['data_origin' => 'live_gsc_api'], JSON_THROW_ON_ERROR),
        ]);

        $reader = new SeoIssueClusterReadService('seo_issue_cluster_test');
        $first = $reader->read();
        $second = $reader->read();
        $titleCluster = collect($first['rows'])->firstWhere('field', 'title');

        $this->assertSame($first['rows'], $second['rows']);
        $this->assertSame($titleCluster['cluster_uid'], data_get($first, 'rows.0.cluster_uid'));
        $this->assertSame(139, data_get($titleCluster, 'priority.impact.total'));
        $this->assertSame(7, data_get($titleCluster, 'priority.impact.gsc.clicks'));
        $this->assertSame(120, data_get($titleCluster, 'priority.impact.gsc.impressions'));
        $this->assertSame(127, data_get($titleCluster, 'priority.impact.gsc.points'));
        $this->assertSame('observed_clicks_plus_impressions_no_loss_estimate', data_get($titleCluster, 'priority.impact.gsc.basis'));
        $this->assertSame(46.33, data_get($titleCluster, 'priority.score'));
    }

    public function test_hundreds_of_repeated_url_rows_collapse_without_losing_export_members(): void
    {
        foreach (range(4, 250) as $index) {
            $this->insertIssue(
                uid: 'title-'.$index,
                type: 'missing_title',
                severity: 'warning',
                url: 'https://fermatmind.com/en/articles/title-'.$index,
                metadata: [
                    'root_cause' => 'article_template_missing_title',
                    'template' => 'article_detail',
                    'field' => 'title',
                    'observation' => 'revision-'.$index,
                ],
            );
        }

        $reader = new SeoIssueClusterReadService('seo_issue_cluster_test');
        $clusters = $reader->read();
        $titleCluster = collect($clusters['rows'])->firstWhere('field', 'title');
        $export = $reader->export();

        $this->assertSame(2, $clusters['total_count']);
        $this->assertSame(250, $titleCluster['affected_url_count']);
        $this->assertSame(250, $titleCluster['issue_count']);
        $this->assertCount(250, $export['urls'][$titleCluster['cluster_uid']]);
        $this->assertCount(251, collect($export['urls'])->flatten(1));
    }

    public function test_url_drilldown_is_server_paginated_without_losing_members(): void
    {
        $reader = new SeoIssueClusterReadService('seo_issue_cluster_test');
        $cluster = collect($reader->read()['rows'])->firstWhere('field', 'title');

        $pageOne = $reader->urls($cluster['cluster_uid'], page: 1, perPage: 2);
        $pageTwo = $reader->urls($cluster['cluster_uid'], page: 2, perPage: 2);

        $this->assertSame(3, $pageOne['total_count']);
        $this->assertSame(2, $pageOne['last_page']);
        $this->assertCount(2, $pageOne['rows']);
        $this->assertCount(1, $pageTwo['rows']);
        $this->assertCount(3, collect($pageOne['rows'])->concat($pageTwo['rows'])->unique('issue_uid'));
        $this->assertSame(['/en/articles/title-3', '/en/articles/title-2'], array_column($pageOne['rows'], 'canonical_path'));

        $export = $reader->export();
        $this->assertCount(2, $export['clusters']);
        $this->assertCount(4, collect($export['urls'])->flatten(1));
    }

    public function test_evidence_change_resolution_and_recurrence_preserve_cluster_identity(): void
    {
        $reader = new SeoIssueClusterReadService('seo_issue_cluster_test');
        $before = collect($reader->read()['rows'])->firstWhere('field', 'title');
        $beforeFingerprint = data_get($before, 'evidence.0.fingerprint');

        DB::connection('seo_issue_cluster_test')->table('seo_issue_queue')
            ->where('issue_uid', 'title-1')
            ->update([
                'metadata_json' => json_encode([
                    'root_cause' => 'article_template_missing_title',
                    'template' => 'article_detail',
                    'field' => 'title',
                    'observation' => 'changed-evidence',
                ], JSON_THROW_ON_ERROR),
                'updated_at' => '2026-08-23 10:00:00',
            ]);

        DB::connection('seo_issue_cluster_test')->table('seo_issue_queue')
            ->whereIn('issue_uid', ['title-1', 'title-2', 'title-3'])
            ->update(['status' => 'resolved', 'lifecycle_state' => 'resolved']);

        $resolved = collect($reader->read()['rows'])->firstWhere('field', 'title');
        $this->assertSame($before['cluster_uid'], $resolved['cluster_uid']);
        $this->assertNotSame($beforeFingerprint, data_get($resolved, 'evidence.0.fingerprint'));
        $this->assertSame('resolved', $resolved['status']);

        $this->insertIssue(
            uid: 'title-recurrence',
            type: 'missing_title',
            severity: 'high',
            url: 'https://fermatmind.com/en/articles/title-recurrence',
            metadata: [
                'root_cause' => 'article_template_missing_title',
                'template' => 'article_detail',
                'field' => 'title',
                'observation' => 'recurrence',
            ],
        );

        $recurred = collect($reader->read()['rows'])->firstWhere('field', 'title');
        $this->assertSame($before['cluster_uid'], $recurred['cluster_uid']);
        $this->assertSame('open', $recurred['status']);
        $this->assertSame(4, $recurred['issue_count']);
        $this->assertSame(4, $recurred['affected_url_count']);
    }

    /** @param array<string,mixed> $metadata */
    private function insertIssue(string $uid, string $type, string $severity, string $url, array $metadata): void
    {
        $sequence = (int) preg_replace('/\D+/', '', $uid);
        $minute = str_pad((string) min(59, max(1, $sequence)), 2, '0', STR_PAD_LEFT);

        DB::connection('seo_issue_cluster_test')->table('seo_issue_queue')->insert([
            'issue_uid' => $uid,
            'issue_type' => $type,
            'severity' => $severity,
            'source_system' => 'cms_rule',
            'canonical_url_hash' => hash('sha256', $url),
            'canonical_url' => $url,
            'locale' => 'en',
            'page_entity_type' => 'article',
            'status' => 'open',
            'lifecycle_state' => 'open',
            'detected_at' => '2026-08-23 09:'.$minute.':00',
            'summary' => 'Article title is missing.',
            'recommendation' => 'Add a unique title in the Article template.',
            'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => '2026-08-23 09:00:00',
            'updated_at' => '2026-08-23 09:'.$minute.':00',
        ]);
    }
}
