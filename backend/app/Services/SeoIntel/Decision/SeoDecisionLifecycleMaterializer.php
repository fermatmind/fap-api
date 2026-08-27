<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Decision;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class SeoDecisionLifecycleMaterializer
{
    public function __construct(
        private readonly string $connection = 'seo_intel',
        private readonly SeoDecisionLifecyclePolicy $policy = new SeoDecisionLifecyclePolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    public function materialize(array $card, string $targetState, string $idempotencyKey, array $evidence): array
    {
        $this->validateInput($card, $targetState, $idempotencyKey);
        $eventKey = 'decision-lifecycle:'.$idempotencyKey;

        return $this->db()->transaction(function () use ($card, $targetState, $eventKey, $evidence): array {
            $existingEvent = $this->db()->table('seo_change_ledger_events')
                ->where('idempotency_key', $eventKey)
                ->first();
            if ($existingEvent !== null) {
                return $this->replay($existingEvent, $card, $targetState, $evidence);
            }

            $ledger = $this->db()->table('seo_change_ledgers')
                ->where('ledger_id', $card['ledger_id'])
                ->lockForUpdate()
                ->first();
            if ($ledger === null) {
                throw new RuntimeException('SEO ledger record not found.');
            }

            $current = $this->currentCard((string) $card['cluster_uid']);
            if ($current !== null && ! hash_equals((string) $current->ledger_id, (string) $card['ledger_id'])) {
                throw new RuntimeException('Decision cluster ledger binding mismatch.');
            }
            if ($current !== null
                && hash_equals((string) $current->evidence_hash, (string) $card['evidence_hash'])
                && hash_equals((string) $current->status, $targetState)) {
                return $this->result($current, true, true);
            }

            $fromState = $current === null ? null : (string) $current->status;
            if (! $this->policy->allows($fromState, $targetState, $evidence)
                || ! $this->bindingsMatch($card, $evidence, $targetState)) {
                throw new RuntimeException('Decision lifecycle transition denied.');
            }

            $revisionNumber = $current === null ? 1 : ((int) $current->revision_number + 1);
            $decisionCardId = $current === null
                ? 'seo_decision_'.substr(hash('sha256', (string) $card['cluster_uid']), 0, 48)
                : (string) $current->decision_card_id;
            $decisionRevisionId = $this->deterministicUuid(implode('|', [
                (string) $card['cluster_uid'],
                (string) $revisionNumber,
                (string) $card['evidence_hash'],
                $targetState,
            ]));
            $now = now('UTC');
            $row = $this->row($card, $targetState, $eventKey, $decisionCardId, $decisionRevisionId, $revisionNumber, $now);

            $this->db()->table('seo_decision_cards')->insert($row);
            $this->db()->table('seo_current_decision_cards')->updateOrInsert(
                ['cluster_uid' => $card['cluster_uid']],
                [
                    'decision_card_id' => $decisionCardId,
                    'decision_revision_id' => $decisionRevisionId,
                    'updated_at' => $now,
                ],
            );

            $sequence = (int) $ledger->transition_sequence + 1;
            $eventEvidence = [
                'schema_version' => SeoDecisionLifecyclePolicy::VERSION,
                'decision_card_id' => $decisionCardId,
                'decision_revision_id' => $decisionRevisionId,
                'decision_revision_number' => $revisionNumber,
                'decision_from_state' => $fromState,
                'decision_to_state' => $targetState,
                'supersedes_revision_id' => $current?->decision_revision_id,
                'input_fingerprint' => $this->fingerprint($card, $targetState, $evidence),
                'recovery_proof_complete' => $targetState === 'closed',
                'l3_enabled' => false,
                'l4_enabled' => false,
                'search_submission_allowed' => false,
            ];
            $eventEvidenceJson = json_encode($eventEvidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $eventId = $this->deterministicUuid($eventKey.'|'.$card['ledger_id']);

            $this->db()->table('seo_change_ledger_events')->insert([
                'event_id' => $eventId,
                'ledger_id' => $card['ledger_id'],
                'sequence' => $sequence,
                'idempotency_key' => $eventKey,
                'event_type' => 'decision_card_revision_materialized',
                'from_state' => $ledger->current_state,
                'to_state' => $ledger->current_state,
                'denial_code' => null,
                'actor_json' => json_encode([
                    'role' => 'seo_decision_materializer',
                    'opaque_ref_hash' => hash('sha256', self::class),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'evidence_json' => $eventEvidenceJson,
                'evidence_hash' => hash('sha256', $eventEvidenceJson),
                'occurred_at' => $now,
                'created_at' => $now,
            ]);
            $this->db()->table('seo_change_ledgers')->where('ledger_id', $card['ledger_id'])->update([
                'transition_sequence' => $sequence,
                'updated_at' => $now,
            ]);

            return $this->result((object) $row, false, false);
        }, 3);
    }

    private function currentCard(string $clusterUid): ?object
    {
        return $this->db()->table('seo_current_decision_cards as current')
            ->join('seo_decision_cards as cards', function ($join): void {
                $join->on('cards.decision_revision_id', '=', 'current.decision_revision_id')
                    ->on('cards.decision_card_id', '=', 'current.decision_card_id')
                    ->on('cards.cluster_uid', '=', 'current.cluster_uid');
            })
            ->where('current.cluster_uid', $clusterUid)
            ->lockForUpdate()
            ->select('cards.*')
            ->first();
    }

    /** @param array<string, mixed> $card @param array<string, mixed> $evidence */
    private function replay(object $event, array $card, string $targetState, array $evidence): array
    {
        $stored = json_decode((string) $event->evidence_json, true);
        if (! is_array($stored)
            || ! hash_equals((string) ($stored['input_fingerprint'] ?? ''), $this->fingerprint($card, $targetState, $evidence))) {
            throw new RuntimeException('Decision lifecycle idempotency key collision.');
        }

        $revision = $this->db()->table('seo_decision_cards')
            ->where('decision_revision_id', $stored['decision_revision_id'] ?? '')
            ->first();
        if ($revision === null) {
            throw new RuntimeException('Decision lifecycle audit revision is unavailable.');
        }

        return $this->result($revision, true, false);
    }

    /** @param array<string, mixed> $card @param array<string, mixed> $evidence */
    private function bindingsMatch(array $card, array $evidence, string $targetState): bool
    {
        if ($targetState !== 'closed') {
            return true;
        }

        return hash_equals((string) $card['authority_revision'], (string) ($evidence['authority_revision'] ?? ''))
            && is_string($card['runtime_revision'] ?? null)
            && $card['runtime_revision'] !== ''
            && hash_equals((string) $card['runtime_revision'], (string) ($evidence['runtime_revision'] ?? ''));
    }

    /** @param array<string, mixed> $card */
    private function validateInput(array $card, string $targetState, string $idempotencyKey): void
    {
        $candidate = $card;
        $candidate['schema_version'] = SeoDecisionCardContract::VERSION;
        $candidate['decision_card_id'] = 'seo_decision_'.substr(hash('sha256', (string) ($card['cluster_uid'] ?? '')), 0, 48);
        $candidate['status'] = $targetState;
        $candidate['close_reason'] = $candidate['close_reason'] ?? null;
        if (! SeoDecisionCardContract::isCard($candidate)
            || ! Str::isUuid((string) ($card['ledger_id'] ?? ''))
            || ! preg_match('/\A[a-f0-9]{64}\z/', (string) ($card['evidence_hash'] ?? ''))
            || trim($idempotencyKey) === ''
            || strlen('decision-lifecycle:'.$idempotencyKey) > 160
            || ! in_array($targetState, SeoDecisionLifecyclePolicy::STATES, true)) {
            throw new InvalidArgumentException('Invalid decision lifecycle input.');
        }

        if ($targetState === 'closed'
            && (! is_string($card['close_reason'] ?? null)
                || trim($card['close_reason']) === ''
                || mb_strlen($card['close_reason']) > 512)) {
            throw new InvalidArgumentException('A sanitized close reason is required.');
        }

        foreach (['owner', 'runtime_revision'] as $field) {
            if (! array_key_exists($field, $card)) {
                throw new InvalidArgumentException('Decision lifecycle input is incomplete.');
            }
        }
    }

    /** @param array<string, mixed> $card @return array<string, mixed> */
    private function row(array $card, string $state, string $eventKey, string $cardId, string $revisionId, int $revision, mixed $now): array
    {
        $fields = [
            'detector', 'root_cause', 'page_family', 'locale', 'authority_revision', 'runtime_revision',
            'cache_revision', 'release_revision', 'affected_unique_url_count', 'evidence_state',
            'evidence_freshness', 'measurement_state', 'measurement_independent', 'business_priority',
            'risk_tier', 'estimated_fix_cost', 'priority_score', 'highest_allowed_action', 'next_step',
            'owner', 'first_observed_at', 'last_observed_at', 'expires_at', 'close_reason',
            'selection_revision', 'evidence_hash',
        ];
        $row = array_intersect_key($card, array_flip($fields));

        return array_merge($row, [
            'schema_version' => SeoDecisionCardContract::VERSION,
            'decision_card_id' => $cardId,
            'decision_revision_id' => $revisionId,
            'idempotency_key' => $eventKey,
            'cluster_uid' => $card['cluster_uid'],
            'revision_number' => $revision,
            'ledger_id' => $card['ledger_id'],
            'status' => $state,
            'close_reason' => $state === 'closed' ? $card['close_reason'] : null,
            'created_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $card @param array<string, mixed> $evidence */
    private function fingerprint(array $card, string $state, array $evidence): string
    {
        ksort($card);
        ksort($evidence);

        return hash('sha256', json_encode([$card, $state, $evidence], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function deterministicUuid(string $identity): string
    {
        $hex = hash('sha256', 'fermatmind-seo-decision|'.$identity);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-5'.substr($hex, 13, 3).'-a'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    /** @return array<string, mixed> */
    private function result(object $row, bool $idempotentReplay, bool $duplicateEvidence): array
    {
        return [
            'decision_card_id' => (string) $row->decision_card_id,
            'decision_revision_id' => (string) $row->decision_revision_id,
            'revision_number' => (int) $row->revision_number,
            'cluster_uid' => (string) $row->cluster_uid,
            'status' => (string) $row->status,
            'idempotent_replay' => $idempotentReplay,
            'duplicate_evidence' => $duplicateEvidence,
            'l3_enabled' => false,
            'l4_enabled' => false,
            'search_submission_allowed' => false,
        ];
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connection);
    }
}
