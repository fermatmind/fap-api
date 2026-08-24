<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\OpsDashboard\GscProductionCloseoutReadService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeoIntelGscProductionCloseoutReadServiceTest extends TestCase
{
    private const CONNECTION = 'seo_gsc_closeout_test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.'.self::CONNECTION => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'seo_intel.connection' => self::CONNECTION,
            'seo_intel.gsc_property_url' => 'sc-domain:fermatmind.com',
            'seo_intel.url_truth_inventory.backend_authority_canary_candidates' => [[
                'path' => '/en/articles/missing-authority',
                'locale' => 'en',
                'page_entity_type' => 'article',
                'entity_id_or_slug' => 'missing-authority',
                'source_authority' => 'backend_cms',
            ]],
        ]);
        DB::purge(self::CONNECTION);
        $this->createSchema();
        $this->seedReadModels();
    }

    public function test_read_surface_reconciles_full_detail_and_classifies_opaque_unmapped_hashes(): void
    {
        $result = (new GscProductionCloseoutReadService(self::CONNECTION))->read();

        $this->assertSame('verified', $result['state']);
        $this->assertSame('sc-domain:fermatmind.com', data_get($result, 'gsc_data_quality.property'));
        $this->assertSame('UTC', data_get($result, 'gsc_data_quality.timezone'));
        $this->assertSame(['web'], data_get($result, 'gsc_data_quality.filters.search_types'));
        $this->assertSame(4, data_get($result, 'gsc_data_quality.detail_snapshot.row_count'));
        $this->assertSame(4, data_get($result, 'gsc_data_quality.detail_snapshot.natural_unique_key_count'));
        $this->assertSame(0, data_get($result, 'gsc_data_quality.detail_snapshot.natural_key_duplicate_count'));
        $this->assertSame(10, data_get($result, 'gsc_data_quality.database_aggregate.clicks'));
        $this->assertSame(100, data_get($result, 'gsc_data_quality.database_aggregate.impressions'));
        $this->assertTrue(data_get($result, 'gsc_data_quality.aggregate_matches_detail'));
        $this->assertNull(data_get($result, 'gsc_data_quality.detail_read_limit'));
        $this->assertSame('production_unproven', data_get($result, 'gsc_data_quality.fresh_api_pagination_receipt'));

        $this->assertSame(3, data_get($result, 'unmapped_classification.unmapped_detail_row_count'));
        $this->assertSame(3, data_get($result, 'unmapped_classification.unique_normalized_canonical_url_count'));
        $this->assertSame(3, data_get($result, 'unmapped_classification.unique_query_page_date_combination_count'));
        $this->assertSame(2, data_get($result, 'unmapped_classification.page_family_distribution.Articles'));
        $this->assertSame(1, data_get($result, 'unmapped_classification.page_family_distribution.Other'));
        $this->assertSame(2, data_get($result, 'unmapped_classification.locale_distribution.en'));
        $this->assertSame(1, data_get($result, 'unmapped_classification.locale_distribution.unknown'));
        $this->assertSame(1, data_get($result, 'unmapped_classification.root_cause_distribution.host_canonical_normalization'));
        $this->assertSame(1, data_get($result, 'unmapped_classification.root_cause_distribution.current_url_truth_missing'));
        $this->assertSame(1, data_get($result, 'unmapped_classification.root_cause_distribution.unknown'));
        $this->assertSame(1, data_get($result, 'unmapped_classification.current_url_truth_missing_handoff_count'));
        $this->assertSame(1, data_get($result, 'unmapped_classification.current_url_truth_missing_distribution.page_family.articles_topics'));
        $this->assertSame(1, data_get($result, 'unmapped_classification.current_url_truth_missing_distribution.locale.en'));
        $this->assertSame(1, data_get($result, 'unmapped_classification.opaque_hash_fallback_count'));
    }

    public function test_quality_and_issue_queues_are_reconciled_without_row_equivalence_or_sensitive_output(): void
    {
        $result = (new GscProductionCloseoutReadService(self::CONNECTION))->read();

        $this->assertSame(3, data_get($result, 'queue_reconciliation.gsc_data_quality_queue.total_count'));
        $this->assertSame(2, data_get($result, 'queue_reconciliation.gsc_data_quality_queue.open_count'));
        $this->assertSame(1, data_get($result, 'queue_reconciliation.gsc_data_quality_queue.processed_count'));
        $this->assertSame(3, data_get($result, 'queue_reconciliation.gsc_data_quality_queue.unique_url_hash_count'));
        $this->assertSame(1, data_get($result, 'queue_reconciliation.gsc_data_quality_queue.distinct_root_cause_count'));
        $this->assertSame(2, data_get($result, 'queue_reconciliation.seo_issue_queue.total_count'));
        $this->assertSame(1, data_get($result, 'queue_reconciliation.seo_issue_queue.open_count'));
        $this->assertSame(1, data_get($result, 'queue_reconciliation.seo_issue_queue.processed_count'));
        $this->assertSame(2, data_get($result, 'queue_reconciliation.seo_issue_queue.distinct_root_cause_count'));
        $this->assertSame(1, data_get($result, 'queue_reconciliation.relationship.shared_unique_url_hash_count'));
        $this->assertFalse(data_get($result, 'queue_reconciliation.relationship.direct_foreign_key_or_row_equivalence'));
        $this->assertFalse(data_get($result, 'queue_reconciliation.relationship.quality_items_are_issue_clusters'));
        $this->assertFalse(data_get($result, 'boundaries.search_submission_allowed'));
        $this->assertFalse(data_get($result, 'boundaries.writes_attempted'));

        $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach (['missing-authority', 'existing-authority', 'private-token'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
        $this->assertDoesNotMatchRegularExpression('/[a-f0-9]{64}/', $encoded);
    }

    private function createSchema(): void
    {
        $schema = Schema::connection(self::CONNECTION);
        $schema->create('seo_urls', function (Blueprint $table): void {
            $table->id();
            $table->char('canonical_url_hash', 64)->unique();
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('source_authority', 64);
            $table->string('indexability_state', 64);
            $table->boolean('is_private_flow')->default(false);
        });
        $schema->create('seo_gsc_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->text('canonical_url')->nullable();
            $table->char('query_hash', 64)->nullable();
            $table->string('source_engine', 64);
            $table->string('device', 32)->nullable();
            $table->string('country', 16)->nullable();
            $table->string('search_type', 32)->nullable();
            $table->unsignedInteger('clicks');
            $table->unsignedInteger('impressions');
            $table->unsignedInteger('average_position_milli')->nullable();
            $table->json('metadata_json')->nullable();
        });
        $schema->create('seo_gsc_data_quality_queue', function (Blueprint $table): void {
            $table->id();
            $table->uuid('sync_run_uid');
            $table->date('report_date');
            $table->char('canonical_url_hash', 64);
            $table->string('issue_code', 128);
            $table->string('status', 32);
        });
        $schema->create('seo_issue_queue', function (Blueprint $table): void {
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
            $table->text('ignore_reason')->nullable();
            $table->timestamp('ignore_until')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by_admin_user_id')->nullable();
            $table->text('verification_note')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('detected_at')->nullable();
            $table->string('summary', 512)->nullable();
            $table->string('recommendation', 512)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
    }

    private function seedReadModels(): void
    {
        $connection = DB::connection(self::CONNECTION);
        $date = now('UTC')->subDays(3)->toDateString();
        $existing = 'https://fermatmind.com/en/articles/existing-authority';
        $missing = 'https://fermatmind.com/en/articles/missing-authority';
        $wwwVariant = 'https://www.fermatmind.com/en/articles/existing-authority';
        $unknownHash = hash('sha256', 'private-token-is-not-stored');
        $connection->table('seo_urls')->insert([
            'canonical_url_hash' => hash('sha256', $existing),
            'canonical_url' => $existing,
            'locale' => 'en',
            'page_entity_type' => 'article',
            'source_authority' => 'backend_cms',
            'indexability_state' => 'indexable',
            'is_private_flow' => false,
        ]);
        $rows = [
            [hash('sha256', $existing), $existing, 1, 10, 10000],
            [hash('sha256', $missing), null, 2, 20, 20000],
            [hash('sha256', $wwwVariant), null, 3, 30, 30000],
            [$unknownHash, null, 4, 40, 40000],
        ];
        foreach ($rows as $index => [$urlHash, $url, $clicks, $impressions, $position]) {
            $connection->table('seo_gsc_daily')->insert([
                'report_date' => $date,
                'canonical_url_hash' => $urlHash,
                'canonical_url' => $url,
                'query_hash' => hash('sha256', 'query-'.$index),
                'source_engine' => 'google',
                'device' => 'DESKTOP',
                'country' => 'USA',
                'search_type' => 'web',
                'clicks' => $clicks,
                'impressions' => $impressions,
                'average_position_milli' => $position,
                'metadata_json' => json_encode(['data_origin' => 'live_gsc_api'], JSON_THROW_ON_ERROR),
            ]);
        }
        foreach ([hash('sha256', $missing), hash('sha256', $wwwVariant), $unknownHash] as $index => $hash) {
            $connection->table('seo_gsc_data_quality_queue')->insert([
                'sync_run_uid' => '00000000-0000-4000-8000-00000000000'.($index + 1),
                'report_date' => $date,
                'canonical_url_hash' => $hash,
                'issue_code' => 'canonical_url_not_in_url_truth',
                'status' => $index === 2 ? 'resolved' : 'open',
            ]);
        }
        $this->insertIssue('issue-open', hash('sha256', $missing), 'open', 'open', 'missing_lastmod_for_indexable_url');
        $this->insertIssue('issue-resolved', hash('sha256', $existing), 'resolved', 'resolved', 'historical_metadata_gap');
    }

    private function insertIssue(string $uid, string $urlHash, string $status, string $lifecycle, string $rootCause): void
    {
        DB::connection(self::CONNECTION)->table('seo_issue_queue')->insert([
            'issue_uid' => $uid,
            'issue_type' => 'metadata_gap',
            'severity' => 'info',
            'source_system' => 'runtime_audit',
            'canonical_url_hash' => $urlHash,
            'status' => $status,
            'lifecycle_state' => $lifecycle,
            'detected_at' => now()->subDays(100),
            'metadata_json' => json_encode([
                'root_cause' => $rootCause,
                'authority_revision' => 'revision-1',
                'field' => 'lastmod',
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
