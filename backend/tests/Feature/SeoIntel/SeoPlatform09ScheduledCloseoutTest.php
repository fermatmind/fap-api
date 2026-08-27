<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Decision\SeoDecisionCardContract;
use App\Services\SeoIntel\Decision\SeoDecisionCardReadService;
use App\Services\SeoIntel\Decision\SeoWeeklyDecisionCloseoutService;
use App\Services\SeoIntel\Decision\SeoWeeklyDecisionReceiptService;
use App\Services\SeoIntel\Decision\SeoWeeklyDecisionSelector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform09ScheduledCloseoutTest extends TestCase
{
    private const SHA = '1234567890abcdef1234567890abcdef12345678';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.git_sha' => self::SHA,
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('seo_intel');
        $this->migrateAuthority();
    }

    #[Test]
    public function manual_trigger_is_excluded_and_never_persists_a_receipt(): void
    {
        $receipt = $this->receiptService()->record('manual', CarbonImmutable::parse('2026-08-27T08:10:00Z'));

        $this->assertSame('MEASUREMENT_HOLD', $receipt['status']);
        $this->assertSame('natural_scheduler_receipt_required', $receipt['reason']);
        $this->assertFalse($receipt['persisted']);
        $this->assertTrue($receipt['manual_receipts_excluded']);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_weekly_decision_receipts')->count());

        Artisan::call('seo:weekly-decisions', ['--trigger' => 'manual', '--json' => true]);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_weekly_decision_receipts')->count());

        $outsideSlot = $this->receiptService()->record('scheduled', CarbonImmutable::parse('2026-08-27T05:29:59Z'));
        $this->assertSame('MEASUREMENT_HOLD', $outsideSlot['status']);
        $this->assertSame('outside_natural_scheduler_slot', $outsideSlot['reason']);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_weekly_decision_receipts')->count());
    }

    #[Test]
    public function natural_slot_is_idempotent_and_does_not_duplicate_selected_cards(): void
    {
        $this->seedLedgerAndCandidate();
        $slot = CarbonImmutable::parse('2026-08-27T08:10:00Z');
        $service = $this->receiptService();

        $first = $service->record('scheduled', $slot);
        $second = $service->record('scheduled', $slot);

        $this->assertSame('scheduled_completed', $first['status']);
        $this->assertSame('scheduled', $first['trigger']);
        $this->assertSame('2026-W35', $first['iso_week']);
        $this->assertSame(1, $first['decision_count']);
        $this->assertSame(1, $first['created_selection_revision_count']);
        $this->assertFalse($first['padded']);
        $this->assertFalse($first['idempotent_replay']);
        $this->assertTrue($second['idempotent_replay']);
        $this->assertSame($first['selection_revision'], $second['selection_revision']);
        $this->assertSame($first['receipt_hash'], $second['receipt_hash']);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_weekly_decision_receipts')->count());
        $this->assertSame(2, DB::connection('seo_intel')->table('seo_decision_cards')->count());
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_change_ledger_events')->count());
        $this->assertSame('selected', $this->selector()->snapshot($slot)['decisions'][0]['status']);
    }

    #[Test]
    public function closeout_requires_a_natural_exact_release_receipt_and_accepts_real_zero(): void
    {
        $slot = CarbonImmutable::parse('2026-08-27T08:10:00Z');
        $closeout = new SeoWeeklyDecisionCloseoutService($this->selector(), 'seo_intel');
        $pending = $closeout->evaluate(self::SHA, $slot);
        $this->assertSame('production_unproven', $pending['state']);
        $this->assertSame('natural_scheduled_receipt_pending', $pending['reason']);

        $receipt = $this->receiptService()->record('scheduled', $slot);
        $proven = $closeout->evaluate(self::SHA, $slot);

        $this->assertSame(0, $receipt['decision_count']);
        $this->assertSame('production_proven', $proven['state']);
        $this->assertSame(self::SHA, $proven['release_sha']);
        $this->assertSame(0, $proven['decision_count']);
        $this->assertTrue($proven['natural_scheduler_proven']);
        $this->assertTrue($proven['idempotent_selection_proven']);
        $this->assertTrue($proven['manual_receipts_excluded']);
        $this->assertTrue($proven['workbench_range_valid']);
        $this->assertTrue($proven['read_only']);
        $this->assertFalse($proven['l3_enabled']);
        $this->assertFalse($proven['l4_enabled']);
    }

    #[Test]
    public function migration_schedule_and_automatic_closeout_are_bounded_and_expand_only(): void
    {
        $schema = Schema::connection('seo_intel');
        $this->assertTrue($schema->hasTable('seo_weekly_decision_receipts'));
        $this->assertTrue($schema->hasColumn('seo_weekly_decision_receipts', 'selection_revision'));
        $this->assertTrue($schema->hasColumn('seo_weekly_decision_receipts', 'receipt_hash'));
        $migration = require database_path('migrations/seo_intel/2026_08_27_030000_create_seo_weekly_decision_receipts.php');
        $migration->down();
        $this->assertTrue($schema->hasTable('seo_weekly_decision_receipts'));

        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
        $this->assertMatchesRegularExpression('/seo:weekly-decisions --trigger=scheduled --json[\s\S]+weeklyOn\(4, \'08:10\'\)[\s\S]+withoutOverlapping\(120\)[\s\S]+name\(\'seo-weekly-decisions:\'[\s\S]+onOneServer\(\)/', $bootstrap);
        $this->assertSame(1, substr_count($bootstrap, 'seo:weekly-decisions --trigger=scheduled --json'));
        $deploy = (string) file_get_contents(base_path('../deploy.php'));
        $this->assertStringContainsString('seo:weekly-decision-production-closeout', $deploy);
        $this->assertStringContainsString('--wait-seconds=1200', $deploy);
        $this->assertStringContainsString('BASH, timeout: 1500);', $deploy);
        $this->assertStringContainsString('/api/v0.5/ops/seo-intel/weekly-decisions', $deploy);
        $this->assertStringNotContainsString('seo:weekly-decisions --trigger=scheduled', $deploy);
        $closeoutCommand = (string) file_get_contents(app_path('Console/Commands/SeoWeeklyDecisionCloseout.php'));
        $this->assertStringContainsString('waiting_for_natural_scheduler_receipt', $closeoutCommand);
        $this->assertStringContainsString('$nextHeartbeat = time() + 30', $closeoutCommand);
    }

    private function migrateAuthority(): void
    {
        (require database_path('migrations/seo_intel/2026_08_27_010000_create_seo_change_ledger_tables.php'))->up();
        (require database_path('migrations/seo_intel/2026_08_27_020000_create_seo_decision_card_authority.php'))->up();
        (require database_path('migrations/seo_intel/2026_08_27_030000_create_seo_weekly_decision_receipts.php'))->up();
    }

    private function selector(): SeoWeeklyDecisionSelector
    {
        return new SeoWeeklyDecisionSelector(new SeoDecisionCardReadService('seo_intel'));
    }

    private function receiptService(): SeoWeeklyDecisionReceiptService
    {
        return new SeoWeeklyDecisionReceiptService($this->selector(), 'seo_intel');
    }

    private function seedLedgerAndCandidate(): void
    {
        $ledgerId = '00000000-0000-4000-8000-000000000099';
        $clusterUid = 'seo_cluster_'.str_repeat('a', 48);
        $cardId = 'seo_decision_'.substr(hash('sha256', $clusterUid), 0, 48);
        $revisionId = '00000000-0000-4000-8000-000000000098';
        DB::connection('seo_intel')->table('seo_change_ledgers')->insert([
            'ledger_id' => $ledgerId,
            'schema_version' => 'seo.change_ledger.v1',
            'idempotency_key' => 'weekly-ledger',
            'change_type' => 'seo_decision',
            'hypothesis' => 'Weekly bounded decision.',
            'rationale' => 'Natural scheduler test.',
            'owner_actor_json' => '{}',
            'current_state' => 'evidence_ready',
            'created_at' => '2026-08-27 00:00:00',
            'updated_at' => '2026-08-27 00:00:00',
        ]);
        DB::connection('seo_intel')->table('seo_decision_cards')->insert([
            'schema_version' => SeoDecisionCardContract::VERSION,
            'decision_card_id' => $cardId,
            'decision_revision_id' => $revisionId,
            'idempotency_key' => 'weekly-candidate',
            'cluster_uid' => $clusterUid,
            'revision_number' => 1,
            'ledger_id' => $ledgerId,
            'detector' => 'technical_authority',
            'root_cause' => 'canonical_mismatch',
            'page_family' => 'personality_hub',
            'locale' => 'zh-CN',
            'authority_revision' => 'authority-v1',
            'runtime_revision' => 'runtime-v1',
            'affected_unique_url_count' => 1,
            'evidence_state' => 'verified',
            'evidence_freshness' => 'fresh',
            'measurement_state' => 'READY',
            'measurement_independent' => true,
            'business_priority' => 'L1',
            'risk_tier' => 'P2',
            'estimated_fix_cost' => 'bounded',
            'priority_score' => 80,
            'highest_allowed_action' => 'L2',
            'next_step' => 'Review the bounded decision.',
            'owner' => 'seo_ops',
            'first_observed_at' => '2026-08-27 00:00:00',
            'last_observed_at' => '2026-08-27 00:00:00',
            'expires_at' => '2026-08-28 00:00:00',
            'status' => 'candidate',
            'evidence_hash' => hash('sha256', 'weekly-candidate-evidence'),
            'created_at' => '2026-08-27 00:00:00',
        ]);
        DB::connection('seo_intel')->table('seo_current_decision_cards')->insert([
            'cluster_uid' => $clusterUid,
            'decision_card_id' => $cardId,
            'decision_revision_id' => $revisionId,
            'updated_at' => '2026-08-27 00:00:00',
        ]);
    }
}
