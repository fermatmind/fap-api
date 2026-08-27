<?php

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Ledger\SeoChangeLedgerContract;
use App\Services\SeoIntel\Ledger\SeoLedgerTransitionPolicy;
use App\Services\SeoIntel\Ledger\SeoLedgerTransitionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SeoPlatform08StateMachineTest extends TestCase
{
    #[Test]
    public function policy_matches_the_authoritative_transitions_and_eight_denials(): void
    {
        $preflight = json_decode(
            (string) file_get_contents(base_path('docs/seo/generated/seo-platform-08-preflight.v1.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame($preflight['state_machine']['allowed_transitions'], SeoChangeLedgerContract::ALLOWED_TRANSITIONS);
        $this->assertSame($preflight['state_machine']['deterministic_denials'], SeoChangeLedgerContract::DETERMINISTIC_DENIALS);

        $mutations = [
            'private_url_or_entity' => ['public_scope' => false],
            'authority_unknown' => ['authority_known' => false],
            'page_family_unclassified' => ['page_family_classified' => false],
            'evidence_insufficient_or_stale' => ['evidence_fresh' => false],
            'open_p0_or_p1' => ['open_p0_or_p1' => true],
            'rollback_unavailable' => ['rollback_available' => false],
            'search_submission_requested' => ['search_submission_requested' => true],
            'blast_radius_outside_signed_scope' => ['blast_radius_within_signed_scope' => false],
        ];
        $policy = new SeoLedgerTransitionPolicy;

        foreach ($mutations as $expected => $mutation) {
            $decision = $policy->evaluate('policy_review', 'approved', $mutation + $this->validContext());
            $this->assertFalse($decision['allowed'], $expected);
            $this->assertSame('deterministic', $decision['denial_class'], $expected);
            $this->assertSame($expected, $decision['denial_code'], $expected);
        }
    }

    #[Test]
    public function illegal_and_l3_l4_transitions_fail_closed(): void
    {
        $policy = new SeoLedgerTransitionPolicy;

        $skip = $policy->evaluate('draft', 'approved', $this->validContext());
        $this->assertFalse($skip['allowed']);
        $this->assertSame('illegal_transition', $skip['denial_code']);

        $l3 = $policy->evaluate('canary_ready', 'canary_running', $this->validContext());
        $this->assertFalse($l3['allowed']);
        $this->assertSame('permission', $l3['denial_class']);
        $this->assertSame('capability_level_disabled', $l3['denial_code']);

        $l4 = $policy->evaluate('observing', 'expanded', $this->validContext());
        $this->assertFalse($l4['allowed']);
        $this->assertSame('capability_level_disabled', $l4['denial_code']);
        $this->assertFalse($l4['l3_enabled']);
        $this->assertFalse($l4['l4_enabled']);
        $this->assertFalse($l4['search_submission_allowed']);
    }

    #[Test]
    public function service_applies_or_denies_atomically_with_append_only_idempotent_audit(): void
    {
        $this->configureConnection();
        $migration = require database_path('migrations/seo_intel/2026_08_27_010000_create_seo_change_ledger_tables.php');
        $migration->up();
        $ledgerId = (string) Str::uuid();
        $now = now('UTC');
        DB::connection('seo_intel')->table('seo_change_ledgers')->insert([
            'ledger_id' => $ledgerId,
            'schema_version' => SeoChangeLedgerContract::SCHEMA_VERSION,
            'idempotency_key' => 'ledger:create:1',
            'change_type' => 'title',
            'hypothesis' => 'A falsifiable public hypothesis.',
            'rationale' => 'Public evidence rationale.',
            'owner_actor_json' => json_encode(['role' => 'owner', 'opaque_ref_hash' => str_repeat('a', 64)], JSON_THROW_ON_ERROR),
            'current_state' => 'draft',
            'transition_sequence' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $service = new SeoLedgerTransitionService('seo_intel');
        $context = $this->validContext() + ['actor_role' => 'owner', 'actor_ref' => 'private-actor-reference'];

        $applied = $service->transition($ledgerId, 'evidence_ready', 'transition:1', $context);
        $this->assertTrue($applied['allowed']);
        $this->assertSame(1, $applied['sequence']);
        $firstHash = (string) DB::connection('seo_intel')->table('seo_change_ledger_events')->value('evidence_hash');

        $replay = $service->transition($ledgerId, 'evidence_ready', 'transition:1', $context);
        $this->assertTrue($replay['idempotent_replay']);
        $this->assertSame($applied['event_id'], $replay['event_id']);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_change_ledger_events')->count());

        try {
            $service->transition($ledgerId, 'evidence_ready', 'transition:1', ['public_scope' => false] + $context);
            $this->fail('Expected an idempotency collision for changed transition context.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Idempotency key collision.', $exception->getMessage());
        }

        $denied = $service->transition(
            $ledgerId,
            'policy_review',
            'transition:2',
            ['search_submission_requested' => true] + $context,
        );
        $this->assertFalse($denied['allowed']);
        $this->assertSame('search_submission_requested', $denied['denial_code']);
        $this->assertSame('evidence_ready', DB::connection('seo_intel')->table('seo_change_ledgers')->value('current_state'));
        $this->assertSame(2, DB::connection('seo_intel')->table('seo_change_ledgers')->value('transition_sequence'));
        $this->assertSame(2, DB::connection('seo_intel')->table('seo_change_ledger_events')->count());
        $this->assertSame($firstHash, DB::connection('seo_intel')->table('seo_change_ledger_events')->orderBy('sequence')->value('evidence_hash'));

        $auditJson = json_encode(DB::connection('seo_intel')->table('seo_change_ledger_events')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-actor-reference', $auditJson);
        $this->assertStringNotContainsString('raw_url', $auditJson);
        $this->assertStringNotContainsString('raw_query', $auditJson);

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

    private function validContext(): array
    {
        return [
            'public_scope' => true,
            'authority_known' => true,
            'page_family' => 'tests',
            'page_family_classified' => true,
            'evidence_state' => 'verified',
            'evidence_fresh' => true,
            'open_p0_or_p1' => false,
            'rollback_available' => true,
            'search_submission_requested' => false,
            'blast_radius_within_signed_scope' => true,
        ];
    }
}
