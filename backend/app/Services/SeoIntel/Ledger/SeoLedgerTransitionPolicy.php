<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Ledger;

final class SeoLedgerTransitionPolicy
{
    public const VERSION = 'seo.change_ledger.transition.v1';

    private const EVIDENCE_GATED_STATES = [
        'evidence_ready',
        'policy_review',
        'approved',
        'canary_ready',
        'canary_running',
        'observing',
        'expanded',
        'closed',
    ];

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function evaluate(string $fromState, string $toState, array $context = []): array
    {
        if (! in_array($fromState, SeoChangeLedgerContract::STATES, true)
            || ! in_array($toState, SeoChangeLedgerContract::STATES, true)
            || ! in_array($toState, SeoChangeLedgerContract::ALLOWED_TRANSITIONS[$fromState] ?? [], true)) {
            return $this->denied($fromState, $toState, 'illegal_transition', 'transition');
        }

        if (in_array($toState, SeoChangeLedgerContract::DISABLED_WRITE_STATES, true)) {
            return $this->denied($fromState, $toState, 'capability_level_disabled', 'permission');
        }

        if (in_array($toState, self::EVIDENCE_GATED_STATES, true)) {
            $denial = $this->deterministicDenial($toState, $context);
            if ($denial !== null) {
                return $this->denied($fromState, $toState, $denial, 'deterministic');
            }
        }

        return [
            'schema_version' => self::VERSION,
            'allowed' => true,
            'from_state' => $fromState,
            'to_state' => $toState,
            'denial_class' => null,
            'denial_code' => null,
            'highest_enabled_level' => 'L2',
            'l3_enabled' => false,
            'l4_enabled' => false,
            'search_submission_allowed' => false,
        ];
    }

    /** @param array<string,mixed> $context */
    private function deterministicDenial(string $toState, array $context): ?string
    {
        if (($context['public_scope'] ?? null) !== true) {
            return 'private_url_or_entity';
        }
        if (($context['authority_known'] ?? null) !== true) {
            return 'authority_unknown';
        }
        if (($context['page_family_classified'] ?? null) !== true
            || in_array($context['page_family'] ?? null, ['unclassified', 'private_excluded'], true)) {
            return 'page_family_unclassified';
        }
        if (($context['evidence_fresh'] ?? null) !== true
            || ! in_array($context['evidence_state'] ?? null, ['verified', 'observed'], true)) {
            return 'evidence_insufficient_or_stale';
        }
        if (($context['open_p0_or_p1'] ?? false) === true) {
            return 'open_p0_or_p1';
        }
        if (in_array($toState, ['approved', 'canary_ready', 'canary_running', 'observing', 'expanded', 'closed'], true)
            && ($context['rollback_available'] ?? null) !== true) {
            return 'rollback_unavailable';
        }
        if (($context['search_submission_requested'] ?? false) === true) {
            return 'search_submission_requested';
        }
        if (in_array($toState, ['approved', 'canary_ready', 'canary_running', 'observing', 'expanded'], true)
            && ($context['blast_radius_within_signed_scope'] ?? null) !== true) {
            return 'blast_radius_outside_signed_scope';
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function denied(string $fromState, string $toState, string $code, string $class): array
    {
        return [
            'schema_version' => self::VERSION,
            'allowed' => false,
            'from_state' => $fromState,
            'to_state' => $toState,
            'denial_class' => $class,
            'denial_code' => $code,
            'highest_enabled_level' => 'L2',
            'l3_enabled' => false,
            'l4_enabled' => false,
            'search_submission_allowed' => false,
        ];
    }
}
