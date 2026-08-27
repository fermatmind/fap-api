<?php

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Decision\SeoDecisionCardContract;
use App\Services\SeoIntel\Decision\SeoDecisionLifecycleMaterializer;
use App\Services\SeoIntel\Decision\SeoDecisionLifecyclePolicy;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SeoPlatform09DecisionLifecycleMaterializerTest extends TestCase
{
    private const LEDGER_ID = '00000000-0000-4000-8000-000000000091';

    #[Test]
    public function lifecycle_contract_exposes_only_the_seven_authoritative_states(): void
    {
        $preflight = json_decode(
            (string) file_get_contents(base_path('docs/seo/generated/seo-platform-09-preflight.v1.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('seo.decision_lifecycle.v1', SeoDecisionLifecyclePolicy::VERSION);
        $this->assertSame($preflight['lifecycle_contract']['states'], SeoDecisionLifecyclePolicy::STATES);
        $this->assertSame([], SeoDecisionLifecyclePolicy::ALLOWED_TRANSITIONS['superseded']);
    }

    #[Test]
    public function duplicate_evidence_and_idempotent_replay_do_not_create_duplicate_cards_or_events(): void
    {
        $this->setUpAuthority();
        $service = new SeoDecisionLifecycleMaterializer('seo_intel');
        $card = $this->card('a');
        $evidence = $this->evidence();

        $first = $service->materialize($card, 'candidate', 'candidate-a-v1', $evidence);
        $replay = $service->materialize($card, 'candidate', 'candidate-a-v1', $evidence);
        $duplicate = $service->materialize($card, 'candidate', 'candidate-a-duplicate', $evidence);

        $this->assertFalse($first['idempotent_replay']);
        $this->assertTrue($replay['idempotent_replay']);
        $this->assertFalse($replay['duplicate_evidence']);
        $this->assertTrue($duplicate['idempotent_replay']);
        $this->assertTrue($duplicate['duplicate_evidence']);
        $this->assertSame($first['decision_revision_id'], $duplicate['decision_revision_id']);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_decision_cards')->count());
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_change_ledger_events')->count());
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_change_ledgers')->value('transition_sequence'));
    }

    #[Test]
    public function fresh_changed_evidence_creates_one_deterministic_revision_and_append_only_audit(): void
    {
        $this->setUpAuthority();
        $service = new SeoDecisionLifecycleMaterializer('seo_intel');
        $first = $service->materialize($this->card('b'), 'candidate', 'candidate-b-v1', $this->evidence());
        $changed = $this->card('b', '2');
        $second = $service->materialize($changed, 'candidate', 'candidate-b-v2', $this->evidence());

        $this->assertSame($first['decision_card_id'], $second['decision_card_id']);
        $this->assertNotSame($first['decision_revision_id'], $second['decision_revision_id']);
        $this->assertSame(2, $second['revision_number']);
        $this->assertSame(2, DB::connection('seo_intel')->table('seo_decision_cards')->count());
        $this->assertSame(2, DB::connection('seo_intel')->table('seo_change_ledger_events')->count());
        $this->assertSame(
            $second['decision_revision_id'],
            DB::connection('seo_intel')->table('seo_current_decision_cards')->value('decision_revision_id'),
        );
        $this->assertSame(
            ['candidate', 'candidate'],
            DB::connection('seo_intel')->table('seo_decision_cards')->orderBy('revision_number')->pluck('status')->all(),
        );
    }

    #[Test]
    public function close_fails_without_complete_current_revision_recovery_proof(): void
    {
        $this->setUpAuthority();
        $service = new SeoDecisionLifecycleMaterializer('seo_intel');
        $this->advanceToRecoveryPending($service, 'c');

        $this->expectException(RuntimeException::class);
        $service->materialize(
            $this->card('c', '5', ['close_reason' => 'Recovery verified.']),
            'closed',
            'close-c-denied',
            $this->evidence(['all_backing_resolved' => true]),
        );
    }

    #[Test]
    public function complete_recovery_proof_closes_and_recurrence_creates_a_new_candidate_revision(): void
    {
        $this->setUpAuthority();
        $service = new SeoDecisionLifecycleMaterializer('seo_intel');
        $this->advanceToRecoveryPending($service, 'd');
        $proof = $this->evidence([
            'all_backing_resolved' => true,
            'direct_recovery_proven' => true,
            'recovery_evidence_fresh' => true,
            'authority_revision' => 'authority-v1',
            'runtime_revision' => 'runtime-v1',
        ]);

        $closed = $service->materialize(
            $this->card('d', '5', ['close_reason' => 'Fresh public readback proves recovery.']),
            'closed',
            'close-d-v5',
            $proof,
        );
        $recurred = $service->materialize(
            $this->card('d', '6'),
            'candidate',
            'recur-d-v6',
            $this->evidence(['recurrence_proven' => true]),
        );

        $this->assertSame('closed', $closed['status']);
        $this->assertSame('candidate', $recurred['status']);
        $this->assertSame(6, $recurred['revision_number']);
        $this->assertSame(6, DB::connection('seo_intel')->table('seo_change_ledger_events')->count());
        $events = DB::connection('seo_intel')->table('seo_change_ledger_events')->orderBy('sequence')->get();
        $this->assertSame(range(1, 6), $events->pluck('sequence')->map(fn ($value): int => (int) $value)->all());
        $this->assertTrue((bool) data_get(json_decode((string) $events[4]->evidence_json, true), 'recovery_proof_complete'));
    }

    #[Test]
    public function stale_evidence_can_only_materialize_a_held_card_and_illegal_transitions_fail_closed(): void
    {
        $this->setUpAuthority();
        $service = new SeoDecisionLifecycleMaterializer('seo_intel');
        $held = $service->materialize($this->card('e'), 'held', 'held-e-v1', $this->evidence(['evidence_fresh' => false]));

        $this->assertSame('held', $held['status']);
        $this->expectException(RuntimeException::class);
        $service->materialize($this->card('e', '2'), 'in_progress', 'illegal-e-v2', $this->evidence());
    }

    private function advanceToRecoveryPending(SeoDecisionLifecycleMaterializer $service, string $suffix): void
    {
        $service->materialize($this->card($suffix), 'candidate', "candidate-$suffix-v1", $this->evidence());
        $service->materialize($this->card($suffix, '2'), 'selected', "selected-$suffix-v2", $this->evidence());
        $service->materialize($this->card($suffix, '3'), 'in_progress', "progress-$suffix-v3", $this->evidence());
        $service->materialize($this->card($suffix, '4'), 'recovery_pending', "recovery-$suffix-v4", $this->evidence());
    }

    private function setUpAuthority(): void
    {
        config(['database.connections.seo_intel' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        DB::purge('seo_intel');
        (require database_path('migrations/seo_intel/2026_08_27_010000_create_seo_change_ledger_tables.php'))->up();
        (require database_path('migrations/seo_intel/2026_08_27_020000_create_seo_decision_card_authority.php'))->up();
        DB::connection('seo_intel')->table('seo_change_ledgers')->insert([
            'ledger_id' => self::LEDGER_ID,
            'schema_version' => 'seo.change_ledger.v1',
            'idempotency_key' => 'ledger-for-decision-lifecycle',
            'change_type' => 'seo_decision',
            'hypothesis' => 'Bounded SEO decision lifecycle.',
            'rationale' => 'Test authority binding.',
            'owner_actor_json' => '{}',
            'current_state' => 'evidence_ready',
            'transition_sequence' => 0,
            'created_at' => '2026-08-27 00:00:00',
            'updated_at' => '2026-08-27 00:00:00',
        ]);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function card(string $suffix, string $evidenceSuffix = '1', array $overrides = []): array
    {
        return array_merge([
            'schema_version' => SeoDecisionCardContract::VERSION,
            'cluster_uid' => 'seo_cluster_'.str_repeat($suffix, 48),
            'ledger_id' => self::LEDGER_ID,
            'detector' => 'technical_authority',
            'root_cause' => 'canonical_mismatch',
            'page_family' => 'personality_hub',
            'locale' => 'zh-CN',
            'authority_revision' => 'authority-v1',
            'runtime_revision' => 'runtime-v1',
            'cache_revision' => null,
            'release_revision' => null,
            'affected_unique_url_count' => 1,
            'evidence_state' => 'verified',
            'evidence_freshness' => 'fresh',
            'measurement_state' => 'READY',
            'measurement_independent' => true,
            'business_priority' => 'L1',
            'risk_tier' => 'P2',
            'estimated_fix_cost' => 'bounded',
            'priority_score' => 75.0,
            'highest_allowed_action' => 'L2',
            'next_step' => 'Review the bounded decision.',
            'owner' => 'seo_ops',
            'first_observed_at' => '2026-08-27 00:00:00',
            'last_observed_at' => '2026-08-27 00:00:00',
            'expires_at' => '2026-08-28 00:00:00',
            'status' => 'candidate',
            'close_reason' => null,
            'selection_revision' => null,
            'evidence_hash' => hash('sha256', "evidence-$suffix-$evidenceSuffix"),
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function evidence(array $overrides = []): array
    {
        return array_merge(['evidence_fresh' => true], $overrides);
    }
}
