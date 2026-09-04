<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Services\Ops\SchedulerEvidenceMonitorService;
use App\Services\Ops\SchedulerHeartbeatService;
use App\Services\SeoIntel\Decision\SeoWeeklyDecisionReceiptService;
use App\Services\SeoIntel\Decision\SeoWeeklyDecisionReceiptValidator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SchedulerEvidenceMonitorTest extends TestCase
{
    private string $heartbeatPath;

    private string $statePath;

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = bin2hex(random_bytes(8));
        $this->heartbeatPath = sys_get_temp_dir().'/scheduler-evidence-heartbeat-'.$suffix.'.json';
        $this->statePath = sys_get_temp_dir().'/scheduler-evidence-state-'.$suffix.'.json';
        config([
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'ops.alert.webhook' => 'https://alerts.example.test/scheduler',
        ]);
        DB::purge('seo_intel');
        $this->createReceiptTables();
    }

    protected function tearDown(): void
    {
        @unlink($this->heartbeatPath);
        @unlink($this->statePath);
        parent::tearDown();
    }

    #[Test]
    public function before_grace_deadline_is_not_due_when_heartbeat_is_healthy(): void
    {
        $now = CarbonImmutable::parse('2026-09-10T13:50:00Z');
        $heartbeat = new SchedulerHeartbeatService($this->heartbeatPath);
        $heartbeat->record('completed', 0, $now);

        $receipt = $this->monitor($heartbeat)->evaluate(false, $now);

        $this->assertSame('pass', $receipt['status']);
        $this->assertSame('healthy', $receipt['heartbeat']['reason']);
        $this->assertSame('not_due', $receipt['weekly']['state']);
        $this->assertSame('2026-09-10T14:00:00Z', $receipt['weekly']['due_at']);
    }

    #[Test]
    public function versioned_capability_waits_for_its_first_natural_slot(): void
    {
        $now = CarbonImmutable::parse('2026-09-04T18:17:00Z');
        $heartbeat = new SchedulerHeartbeatService($this->heartbeatPath);
        $heartbeat->record('completed', 0, $now);

        $receipt = $this->monitor($heartbeat)->evaluate(false, $now);

        $this->assertSame('pass', $receipt['status']);
        $this->assertSame('not_due', $receipt['weekly']['state']);
        $this->assertSame('capability_activation_pending', $receipt['weekly']['reason']);
        $this->assertSame('2026-09-10T14:00:00Z', $receipt['weekly']['due_at']);
    }

    #[Test]
    public function grace_expiry_accepts_only_the_exact_natural_receipt_contract(): void
    {
        $now = CarbonImmutable::parse('2026-09-10T14:01:00Z');
        $heartbeat = new SchedulerHeartbeatService($this->heartbeatPath);
        $heartbeat->record('completed', 0, $now);
        $expected = $this->insertNaturalReceipt(CarbonImmutable::parse('2026-09-10T13:45:00Z'));

        $receipt = $this->monitor($heartbeat)->evaluate(false, $now);

        $this->assertSame('pass', $receipt['status']);
        $this->assertSame('healthy', $receipt['weekly']['state']);
        $this->assertSame($expected['selection_revision'], $receipt['weekly']['selection_revision']);
        $this->assertSame($expected['receipt_hash'], $receipt['weekly']['receipt_hash']);
        $this->assertSame([], $receipt['weekly']['mismatch_codes']);
        $this->assertSame(SeoWeeklyDecisionReceiptValidator::HASH_ALGORITHM, $receipt['weekly']['receipt_hash_algorithm']);
        $this->assertSame(
            $receipt['weekly']['capability_revision'],
            $receipt['weekly']['scheduler_contract_revision_alias'],
        );
        $this->assertSame('scheduled', $receipt['weekly']['trigger']);
        $this->assertTrue($receipt['weekly']['manual_receipts_excluded']);
    }

    #[Test]
    public function receipt_hash_or_manual_exclusion_mismatch_fails_closed(): void
    {
        $now = CarbonImmutable::parse('2026-09-10T14:01:00Z');
        $heartbeat = new SchedulerHeartbeatService($this->heartbeatPath);
        $heartbeat->record('completed', 0, $now);
        $this->insertNaturalReceipt(CarbonImmutable::parse('2026-09-10T13:45:00Z'));
        DB::connection('seo_intel')->table('seo_weekly_decision_capability_receipts')
            ->update(['receipt_hash' => str_repeat('0', 64)]);

        $hashMismatch = $this->monitor($heartbeat)->evaluate(false, $now);
        $this->assertSame('fail', $hashMismatch['status']);
        $this->assertSame('receipt_contract_mismatch', $hashMismatch['weekly']['reason']);
        $this->assertContains('capability_receipt_hash_mismatch', $hashMismatch['weekly']['mismatch_codes']);

        DB::connection('seo_intel')->table('seo_weekly_decision_capability_receipts')->delete();
        $this->insertNaturalReceipt(
            CarbonImmutable::parse('2026-09-10T13:45:00Z'),
            manualReceiptsExcluded: false,
            replaceSelection: true,
        );
        $manualMismatch = $this->monitor($heartbeat)->evaluate(false, $now);
        $this->assertSame('fail', $manualMismatch['status']);
        $this->assertSame('receipt_contract_mismatch', $manualMismatch['weekly']['reason']);
        $this->assertContains('capability_manual_exclusion_mismatch', $manualMismatch['weekly']['mismatch_codes']);
        $this->assertContains('selection_manual_exclusion_mismatch', $manualMismatch['weekly']['mismatch_codes']);
    }

    #[Test]
    public function database_json_key_reordering_does_not_break_canonical_receipt_hashes(): void
    {
        $now = CarbonImmutable::parse('2026-09-10T14:01:00Z');
        $heartbeat = new SchedulerHeartbeatService($this->heartbeatPath);
        $heartbeat->record('completed', 0, $now);
        $this->insertNaturalReceipt(CarbonImmutable::parse('2026-09-10T13:45:00Z'));

        foreach (['seo_weekly_decision_receipts', 'seo_weekly_decision_capability_receipts'] as $table) {
            $row = DB::connection('seo_intel')->table($table)->first();
            $payload = json_decode((string) $row->receipt_json, true, 32, JSON_THROW_ON_ERROR);
            $reordered = array_reverse($payload, true);
            DB::connection('seo_intel')->table($table)->update([
                'receipt_json' => json_encode($reordered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]);
        }

        $receipt = $this->monitor($heartbeat)->evaluate(false, $now);

        $this->assertSame('pass', $receipt['status']);
        $this->assertSame('natural_receipt_verified', $receipt['weekly']['reason']);
        $this->assertSame([], $receipt['weekly']['mismatch_codes']);
    }

    #[Test]
    public function heartbeat_alerts_once_on_failure_and_once_on_recovery(): void
    {
        Http::fake();
        $healthyAt = CarbonImmutable::parse('2026-09-10T13:50:00Z');
        $heartbeat = new SchedulerHeartbeatService($this->heartbeatPath);
        $heartbeat->record('completed', 0, $healthyAt);
        $monitor = $this->monitor($heartbeat);
        $monitor->evaluate(true, $healthyAt);

        $firstFailure = $monitor->evaluate(true, $healthyAt->addSeconds(181));
        $repeatFailure = $monitor->evaluate(true, $healthyAt->addSeconds(240));
        $heartbeat->record('completed', 0, $healthyAt->addSeconds(241));
        $recovery = $monitor->evaluate(true, $healthyAt->addSeconds(241));

        $this->assertSame('failed', $firstFailure['alerts']['heartbeat_transition']);
        $this->assertSame('none', $repeatFailure['alerts']['heartbeat_transition']);
        $this->assertSame('recovered', $recovery['alerts']['heartbeat_transition']);
        Http::assertSentCount(2);
    }

    #[Test]
    public function missing_weekly_receipt_alert_is_deduplicated_by_week_and_capability(): void
    {
        Http::fake();
        $now = CarbonImmutable::parse('2026-09-10T14:01:00Z');
        $heartbeat = new SchedulerHeartbeatService($this->heartbeatPath);
        $heartbeat->record('completed', 0, $now);
        $monitor = $this->monitor($heartbeat);

        $first = $monitor->evaluate(true, $now);
        $second = $monitor->evaluate(true, $now->addMinute());

        $this->assertSame('weekly_receipt_missing', $first['weekly']['reason']);
        $this->assertTrue($first['alerts']['weekly_alert_sent']);
        $this->assertFalse($second['alerts']['weekly_alert_sent']);
        Http::assertSentCount(1);
    }

    #[Test]
    public function nightly_archives_scheduler_evidence_once_daily_without_notifications(): void
    {
        $workflow = (string) file_get_contents(base_path('../.github/workflows/nightly.yml'));

        $this->assertStringNotContainsString('*/5 * * * *', $workflow);
        $this->assertSame(1, substr_count($workflow, '- cron: "17 18 * * *"'));
        $this->assertStringContainsString('scheduler-evidence-monitor-${{ github.run_id }}-${{ github.run_attempt }}', $workflow);
        $this->assertStringContainsString('timeout-minutes: 5', $workflow);
        $this->assertGreaterThanOrEqual(4, substr_count($workflow, "github.event.schedule == '17 18 * * *'"));
        $this->assertStringContainsString('group: nightly-${{ github.repository }}', $workflow);
        $this->assertStringContainsString('environment: production', $workflow);
        $this->assertStringContainsString('ops:scheduler-evidence-monitor --json', $workflow);
        $this->assertStringNotContainsString('ops:scheduler-evidence-monitor --notify', $workflow);
        $this->assertStringContainsString('deployment_action == false', $workflow);
        $this->assertStringContainsString('lkg_action == false', $workflow);
    }

    private function monitor(SchedulerHeartbeatService $heartbeat): SchedulerEvidenceMonitorService
    {
        return new SchedulerEvidenceMonitorService($heartbeat, 'seo_intel', $this->statePath);
    }

    /** @return array{selection_revision:string,receipt_hash:string} */
    private function insertNaturalReceipt(
        CarbonImmutable $slot,
        bool $manualReceiptsExcluded = true,
        bool $replaceSelection = false,
    ): array {
        $selectionRevision = 'seo_weekly_'.$slot->format('o-\WW').'_'.str_repeat('a', 16);
        $releaseSha = str_repeat('b', 40);
        $capabilityRevision = SeoWeeklyDecisionReceiptService::capabilityRevision();
        $selection = [
            'schema_version' => SeoWeeklyDecisionReceiptService::SELECTION_CONTRACT_VERSION,
            'receipt_hash_algorithm' => SeoWeeklyDecisionReceiptValidator::HASH_ALGORITHM,
            'status' => 'scheduled_completed',
            'trigger' => 'scheduled',
            'iso_week' => $slot->format('o-\WW'),
            'selection_revision' => $selectionRevision,
            'release_sha' => $releaseSha,
            'scheduled_for' => $slot->format('Y-m-d\TH:i:s\Z'),
            'decision_count' => 0,
            'decision_card_ids' => [],
            'decision_revision_ids' => [],
            'manual_receipts_excluded' => $manualReceiptsExcluded,
        ];
        $selectionJson = json_encode($selection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if ($replaceSelection) {
            DB::connection('seo_intel')->table('seo_weekly_decision_receipts')->delete();
        }
        DB::connection('seo_intel')->table('seo_weekly_decision_receipts')->insert([
            'receipt_id' => '11111111-1111-5111-a111-111111111111',
            'selection_revision' => $selectionRevision,
            'iso_week' => $selection['iso_week'],
            'release_sha' => $releaseSha,
            'scheduled_for' => $slot,
            'decision_count' => 0,
            'decision_card_ids_json' => '[]',
            'decision_revision_ids_json' => '[]',
            'receipt_json' => $selectionJson,
            'receipt_hash' => SeoWeeklyDecisionReceiptValidator::hash($selection),
            'created_at' => $slot,
        ]);
        $capability = array_merge($selection, [
            'schema_version' => SeoWeeklyDecisionReceiptService::CONTRACT_VERSION,
            'capability_version' => SeoWeeklyDecisionReceiptService::CAPABILITY_VERSION,
            'capability_revision' => $capabilityRevision,
        ]);
        $capabilityJson = json_encode($capability, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $receiptHash = SeoWeeklyDecisionReceiptValidator::hash($capability);
        DB::connection('seo_intel')->table('seo_weekly_decision_capability_receipts')->insert([
            'receipt_id' => '22222222-2222-5222-a222-222222222222',
            'selection_revision' => $selectionRevision,
            'capability_revision' => $capabilityRevision,
            'iso_week' => $selection['iso_week'],
            'evidence_release_sha' => $releaseSha,
            'scheduled_for' => $slot,
            'decision_count' => 0,
            'decision_card_ids_json' => '[]',
            'decision_revision_ids_json' => '[]',
            'receipt_json' => $capabilityJson,
            'receipt_hash' => $receiptHash,
            'created_at' => $slot,
        ]);

        return ['selection_revision' => $selectionRevision, 'receipt_hash' => $receiptHash];
    }

    private function createReceiptTables(): void
    {
        Schema::connection('seo_intel')->create('seo_weekly_decision_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('receipt_id')->unique();
            $table->string('selection_revision', 80)->unique();
            $table->string('iso_week', 8);
            $table->char('release_sha', 40);
            $table->dateTime('scheduled_for');
            $table->unsignedTinyInteger('decision_count');
            $table->json('decision_card_ids_json');
            $table->json('decision_revision_ids_json');
            $table->json('receipt_json');
            $table->char('receipt_hash', 64);
            $table->timestamp('created_at');
        });
        Schema::connection('seo_intel')->create('seo_weekly_decision_capability_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('receipt_id')->unique();
            $table->string('selection_revision', 80);
            $table->char('capability_revision', 64);
            $table->string('iso_week', 8);
            $table->char('evidence_release_sha', 40);
            $table->dateTime('scheduled_for');
            $table->unsignedTinyInteger('decision_count');
            $table->json('decision_card_ids_json');
            $table->json('decision_revision_ids_json');
            $table->json('receipt_json');
            $table->char('receipt_hash', 64);
            $table->timestamp('created_at');
        });
    }
}
