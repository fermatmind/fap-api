<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Ledger;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class SeoLedgerTransitionService
{
    public function __construct(
        private readonly ?string $connectionName = null,
        private readonly SeoLedgerTransitionPolicy $policy = new SeoLedgerTransitionPolicy,
    ) {}

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function transition(string $ledgerId, string $toState, string $idempotencyKey, array $context): array
    {
        if (! Str::isUuid($ledgerId) || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 160) {
            throw new InvalidArgumentException('Invalid ledger transition identity.');
        }

        return $this->connection()->transaction(function () use ($ledgerId, $toState, $idempotencyKey, $context): array {
            $existing = $this->connection()->table('seo_change_ledger_events')
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                $existingEvidence = json_decode((string) $existing->evidence_json, true);
                $sameContext = is_array($existingEvidence)
                    && hash_equals((string) ($existingEvidence['context_fingerprint'] ?? ''), $this->contextFingerprint($context));
                if (! hash_equals((string) $existing->ledger_id, $ledgerId)
                    || ! hash_equals((string) $existing->to_state, $toState)
                    || ! $sameContext) {
                    throw new RuntimeException('Idempotency key collision.');
                }

                return $this->result($existing, true);
            }

            $ledger = $this->connection()->table('seo_change_ledgers')
                ->where('ledger_id', $ledgerId)
                ->lockForUpdate()
                ->first();
            if ($ledger === null) {
                throw new RuntimeException('SEO ledger record not found.');
            }

            $fromState = (string) $ledger->current_state;
            $decision = $this->policy->evaluate($fromState, $toState, $context);
            $sequence = (int) $ledger->transition_sequence + 1;
            $occurredAt = now('UTC');
            $eventId = (string) Str::uuid();
            $allowed = ($decision['allowed'] ?? false) === true;
            $eventType = $allowed ? 'transition_applied' : 'transition_denied';
            $actor = $this->actor($context);
            $evidence = [
                'schema_version' => SeoLedgerTransitionPolicy::VERSION,
                'allowed' => $allowed,
                'denial_class' => $decision['denial_class'] ?? null,
                'denial_code' => $decision['denial_code'] ?? null,
                'context_fingerprint' => $this->contextFingerprint($context),
                'l3_enabled' => false,
                'l4_enabled' => false,
                'search_submission_allowed' => false,
            ];
            $evidenceJson = json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $this->connection()->table('seo_change_ledger_events')->insert([
                'event_id' => $eventId,
                'ledger_id' => $ledgerId,
                'sequence' => $sequence,
                'idempotency_key' => $idempotencyKey,
                'event_type' => $eventType,
                'from_state' => $fromState,
                'to_state' => $toState,
                'denial_code' => $decision['denial_code'] ?? null,
                'actor_json' => json_encode($actor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'evidence_json' => $evidenceJson,
                'evidence_hash' => hash('sha256', $evidenceJson),
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
            ]);

            $updates = ['transition_sequence' => $sequence, 'updated_at' => $occurredAt];
            if ($allowed) {
                $updates['current_state'] = $toState;
                if (in_array($toState, ['closed', 'rejected'], true)) {
                    $updates['close_reason'] = $this->closeReason($context['close_reason'] ?? null);
                }
            }
            $this->connection()->table('seo_change_ledgers')->where('ledger_id', $ledgerId)->update($updates);

            $event = $this->connection()->table('seo_change_ledger_events')->where('event_id', $eventId)->first();

            return $this->result($event, false);
        }, 3);
    }

    /** @return array<string,mixed> */
    private function result(object $event, bool $idempotentReplay): array
    {
        return [
            'event_id' => (string) $event->event_id,
            'ledger_id' => (string) $event->ledger_id,
            'sequence' => (int) $event->sequence,
            'allowed' => (string) $event->event_type === 'transition_applied',
            'from_state' => (string) $event->from_state,
            'to_state' => (string) $event->to_state,
            'denial_code' => $event->denial_code === null ? null : (string) $event->denial_code,
            'idempotent_replay' => $idempotentReplay,
            'l3_enabled' => false,
            'l4_enabled' => false,
            'search_submission_allowed' => false,
        ];
    }

    /** @param array<string,mixed> $context @return array<string,string> */
    private function actor(array $context): array
    {
        $role = is_string($context['actor_role'] ?? null)
            && preg_match('/\A[A-Za-z0-9_.:-]{1,80}\z/', $context['actor_role']) === 1
                ? $context['actor_role']
                : 'unknown';
        $reference = is_string($context['actor_ref'] ?? null) ? $context['actor_ref'] : 'unknown';

        return ['role' => $role, 'opaque_ref_hash' => hash('sha256', 'seo-ledger-actor|'.$reference)];
    }

    /** @param array<string,mixed> $context */
    private function contextFingerprint(array $context): string
    {
        $safe = array_intersect_key($context, array_flip([
            'public_scope',
            'authority_known',
            'page_family',
            'page_family_classified',
            'evidence_state',
            'evidence_fresh',
            'open_p0_or_p1',
            'rollback_available',
            'search_submission_requested',
            'blast_radius_within_signed_scope',
        ]));
        ksort($safe);

        return hash('sha256', json_encode($safe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function closeReason(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 512) {
            throw new InvalidArgumentException('A sanitized close reason is required.');
        }

        return trim($value);
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection($this->connectionName ?? (string) config('seo_intel.connection', 'seo_intel'));
    }
}
