<?php

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Decision\SeoDecisionCardContract;
use App\Services\SeoIntel\Decision\SeoDecisionCardReadService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeoPlatform09DecisionAuthorityTest extends TestCase
{
    #[Test]
    public function contract_matches_preflight_and_refuses_subject_fallback_identity(): void
    {
        $preflight = json_decode(
            (string) file_get_contents(base_path('docs/seo/generated/seo-platform-09-preflight.v1.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('seo.decision_card.v1', SeoDecisionCardContract::VERSION);
        $this->assertSame(
            $preflight['decision_card_contract']['required_fields'],
            SeoDecisionCardContract::REQUIRED_FIELDS,
        );
        $this->assertSame(
            $preflight['decision_card_contract']['forbidden_fields'],
            array_slice(SeoDecisionCardContract::FORBIDDEN_FIELDS, 0, 10),
        );
        $this->assertTrue(SeoDecisionCardContract::isClusterUid('seo_cluster_'.str_repeat('a', 48)));
        $this->assertFalse(SeoDecisionCardContract::isClusterUid('subject:homepage'));
        $this->assertFalse(SeoDecisionCardContract::isClusterUid(''));
    }

    #[Test]
    public function expand_migration_creates_one_authority_and_one_unique_current_pointer_model(): void
    {
        $this->configureConnection();
        Schema::connection('seo_intel')->create('seo_change_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('ledger_id')->unique();
        });

        $migration = $this->migration();
        $migration->up();
        $migration->up();

        $schema = Schema::connection('seo_intel');
        $this->assertTrue($schema->hasTable('seo_change_ledgers'));
        $this->assertTrue($schema->hasTable('seo_decision_cards'));
        $this->assertTrue($schema->hasTable('seo_current_decision_cards'));
        $this->assertUniqueConstraint('seo_decision_cards', ['decision_revision_id']);
        $this->assertUniqueConstraint('seo_decision_cards', ['idempotency_key']);
        $this->assertUniqueConstraint('seo_decision_cards', ['cluster_uid', 'revision_number']);
        $this->assertUniqueConstraint('seo_current_decision_cards', ['cluster_uid']);
        $this->assertUniqueConstraint('seo_current_decision_cards', ['decision_card_id']);
        $this->assertUniqueConstraint('seo_current_decision_cards', ['decision_revision_id']);

        foreach (['ledger_id', 'cluster_uid', 'revision_number', 'priority_score', 'selection_revision'] as $column) {
            $this->assertTrue($schema->hasColumn('seo_decision_cards', $column), $column);
        }

        $migration->down();
        $this->assertTrue($schema->hasTable('seo_decision_cards'));
        $this->assertTrue($schema->hasTable('seo_current_decision_cards'));
        DB::disconnect('seo_intel');
    }

    #[Test]
    public function current_read_model_is_empty_without_synthetic_cards_and_joins_only_authority_rows(): void
    {
        $this->configureConnection();
        $this->migration()->up();
        $service = new SeoDecisionCardReadService('seo_intel');

        $this->assertSame([
            'state' => 'verified_zero',
            'items' => [],
            'count' => 0,
            'read_only' => true,
        ], $service->snapshot());

        $clusterUid = 'seo_cluster_'.str_repeat('b', 48);
        $card = $this->card($clusterUid);
        DB::connection('seo_intel')->table('seo_decision_cards')->insert($card);
        DB::connection('seo_intel')->table('seo_current_decision_cards')->insert([
            'cluster_uid' => $clusterUid,
            'decision_card_id' => $card['decision_card_id'],
            'decision_revision_id' => $card['decision_revision_id'],
            'updated_at' => '2026-08-27 00:00:00',
        ]);

        $snapshot = $service->snapshot();
        $this->assertSame('available', $snapshot['state']);
        $this->assertSame(1, $snapshot['count']);
        $this->assertSame($clusterUid, $snapshot['items'][0]['cluster_uid']);
        $this->assertTrue(SeoDecisionCardContract::isCard($snapshot['items'][0]));
        foreach (SeoDecisionCardContract::FORBIDDEN_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $snapshot['items'][0]);
        }

        DB::disconnect('seo_intel');
    }

    #[Test]
    public function database_rejects_a_second_current_card_for_the_same_cluster(): void
    {
        $this->configureConnection();
        $this->migration()->up();
        $clusterUid = 'seo_cluster_'.str_repeat('c', 48);
        DB::connection('seo_intel')->table('seo_current_decision_cards')->insert([
            'cluster_uid' => $clusterUid,
            'decision_card_id' => 'seo_decision_first',
            'decision_revision_id' => '00000000-0000-4000-8000-000000000001',
            'updated_at' => '2026-08-27 00:00:00',
        ]);

        $this->expectException(QueryException::class);
        DB::connection('seo_intel')->table('seo_current_decision_cards')->insert([
            'cluster_uid' => $clusterUid,
            'decision_card_id' => 'seo_decision_second',
            'decision_revision_id' => '00000000-0000-4000-8000-000000000002',
            'updated_at' => '2026-08-27 00:00:01',
        ]);
    }

    #[Test]
    public function orphaned_or_weak_identity_current_pointers_fail_closed(): void
    {
        $this->configureConnection();
        $this->migration()->up();
        DB::connection('seo_intel')->table('seo_current_decision_cards')->insert([
            'cluster_uid' => 'subject:homepage',
            'decision_card_id' => 'seo_decision_orphan',
            'decision_revision_id' => '00000000-0000-4000-8000-000000000003',
            'updated_at' => '2026-08-27 00:00:00',
        ]);

        $this->assertSame('unavailable', (new SeoDecisionCardReadService('seo_intel'))->snapshot()['state']);
        DB::disconnect('seo_intel');
    }

    private function configureConnection(): void
    {
        config(['database.connections.seo_intel' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        DB::purge('seo_intel');
    }

    private function migration(): object
    {
        return require database_path('migrations/seo_intel/2026_08_27_020000_create_seo_decision_card_authority.php');
    }

    private function assertUniqueConstraint(string $table, array $columns): void
    {
        $indexes = Schema::connection('seo_intel')->getIndexes($table);
        $matching = array_filter(
            $indexes,
            fn (array $index): bool => ($index['unique'] ?? false) && ($index['columns'] ?? []) === $columns,
        );

        $this->assertNotEmpty($matching, $table.':'.implode(',', $columns));
    }

    /** @return array<string, mixed> */
    private function card(string $clusterUid): array
    {
        return [
            'schema_version' => SeoDecisionCardContract::VERSION,
            'decision_card_id' => 'seo_decision_'.str_repeat('d', 48),
            'decision_revision_id' => '00000000-0000-4000-8000-000000000004',
            'idempotency_key' => 'decision-card-test-v1',
            'cluster_uid' => $clusterUid,
            'revision_number' => 1,
            'ledger_id' => '00000000-0000-4000-8000-000000000005',
            'detector' => 'technical_authority',
            'root_cause' => 'canonical_mismatch',
            'page_family' => 'personality_hub',
            'locale' => 'zh-CN',
            'authority_revision' => 'authority-v1',
            'runtime_revision' => null,
            'cache_revision' => null,
            'release_revision' => null,
            'affected_unique_url_count' => 1,
            'evidence_state' => 'verified',
            'evidence_freshness' => 'fresh',
            'measurement_state' => 'MEASUREMENT_HOLD',
            'measurement_independent' => false,
            'business_priority' => 'L1',
            'risk_tier' => 'P2',
            'estimated_fix_cost' => 'bounded',
            'priority_score' => null,
            'highest_allowed_action' => 'L2',
            'next_step' => 'Review direct evidence.',
            'owner' => 'seo_ops',
            'first_observed_at' => '2026-08-27 00:00:00',
            'last_observed_at' => '2026-08-27 00:00:00',
            'expires_at' => '2026-08-28 00:00:00',
            'status' => 'held',
            'close_reason' => null,
            'selection_revision' => null,
            'evidence_hash' => str_repeat('e', 64),
            'created_at' => '2026-08-27 00:00:00',
        ];
    }
}
