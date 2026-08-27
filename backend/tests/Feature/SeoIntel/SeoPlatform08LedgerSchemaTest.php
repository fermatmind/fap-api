<?php

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Ledger\SeoChangeLedgerContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeoPlatform08LedgerSchemaTest extends TestCase
{
    #[Test]
    public function contract_freezes_the_authoritative_fields_and_states(): void
    {
        $preflight = json_decode(
            (string) file_get_contents(base_path('docs/seo/generated/seo-platform-08-preflight.v1.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(23, count(SeoChangeLedgerContract::FIELDS));
        $this->assertSame(
            array_column($preflight['record_contract']['fields'], 'id'),
            SeoChangeLedgerContract::FIELDS,
        );
        $this->assertSame(13, count(SeoChangeLedgerContract::STATES));
        $this->assertSame(
            $preflight['state_machine']['states'],
            SeoChangeLedgerContract::STATES,
        );
    }

    #[Test]
    public function expand_migration_creates_dedicated_idempotent_append_only_tables(): void
    {
        $this->configureConnection();
        Schema::connection('seo_intel')->create('experiment_assignments', function (Blueprint $table): void {
            $table->id();
            $table->string('private_subject');
        });

        $migration = $this->migration();
        $migration->up();

        $schema = Schema::connection('seo_intel');
        $this->assertTrue($schema->hasTable('seo_change_ledgers'));
        $this->assertTrue($schema->hasTable('seo_change_ledger_events'));
        $this->assertTrue($schema->hasTable('experiment_assignments'));

        foreach ($this->ledgerColumns() as $column) {
            $this->assertTrue($schema->hasColumn('seo_change_ledgers', $column), $column);
        }

        foreach (['event_id', 'ledger_id', 'sequence', 'idempotency_key', 'from_state', 'to_state', 'evidence_hash', 'occurred_at'] as $column) {
            $this->assertTrue($schema->hasColumn('seo_change_ledger_events', $column), $column);
        }

        $this->assertUniqueConstraint('seo_change_ledgers', ['ledger_id']);
        $this->assertUniqueConstraint('seo_change_ledgers', ['idempotency_key']);
        $this->assertUniqueConstraint('seo_change_ledger_events', ['event_id']);
        $this->assertUniqueConstraint('seo_change_ledger_events', ['idempotency_key']);
        $this->assertUniqueConstraint('seo_change_ledger_events', ['ledger_id', 'sequence']);

        $migration->up();
        $migration->down();
        $this->assertTrue($schema->hasTable('seo_change_ledgers'));
        $this->assertTrue($schema->hasTable('seo_change_ledger_events'));

        DB::disconnect('seo_intel');
    }

    #[Test]
    public function migration_is_forward_only_and_never_reuses_private_product_tables(): void
    {
        $source = (string) file_get_contents(database_path('migrations/seo_intel/2026_08_27_010000_create_seo_change_ledger_tables.php'));

        $this->assertStringContainsString('Expand-only rollback compatibility', $source);
        $this->assertStringNotContainsString('dropIfExists', $source);
        $this->assertStringNotContainsString('experiment_assignments', $source);
        $this->assertStringNotContainsString('experiment_rollout', $source);
        $this->assertStringNotContainsString('attempt', $source);
        $this->assertStringNotContainsString('user_agent', $source);
        $this->assertStringNotContainsString('raw_query', $source);
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
        return require database_path('migrations/seo_intel/2026_08_27_010000_create_seo_change_ledger_tables.php');
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

    private function ledgerColumns(): array
    {
        return array_map(
            fn (string $field): string => match ($field) {
                'source', 'public_url_cohort', 'baseline_window', 'primary_metric',
                'guardrail_metrics', 'observation_window', 'canary_scope', 'blast_radius',
                'public_runtime_readback', 'gsc_funnel_evidence_state', 'rollback_plan',
                'owner_actor', 'approval_policy_decision' => $field.'_json',
                default => $field,
            },
            SeoChangeLedgerContract::FIELDS,
        );
    }
}
