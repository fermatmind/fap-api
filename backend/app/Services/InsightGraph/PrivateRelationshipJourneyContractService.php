<?php

declare(strict_types=1);

namespace App\Services\InsightGraph;

final class PrivateRelationshipJourneyContractService
{
    private const JOURNEY_VERSION = 'private_relationship_journey.v1';

    private const JOURNEY_FINGERPRINT_VERSION = 'private_relationship_journey.fp.v1';

    private const PULSE_VERSION = 'dyadic_pulse_check.v1';

    private const JOURNEY_SCOPE = 'private_relationship_revisit';

    /**
     * @param  array<string,mixed>  $privateRelationship
     * @param  array<string,mixed>  $dyadicConsent
     * @param  array<string,mixed>  $overlay
     * @return array<string,mixed>
     */
    public function buildJourney(array $privateRelationship, array $dyadicConsent, array $overlay): array
    {
        if ($privateRelationship === []) {
            return [];
        }

        $accessState = $this->normalizeText($dyadicConsent['access_state'] ?? null, $privateRelationship['access_state'] ?? null);
        $consentRefreshRequired = (bool) ($dyadicConsent['consent_refresh_required'] ?? false);
        $completedDyadicActionKeys = $this->normalizeStringList($overlay['completed_dyadic_action_keys'] ?? []);
        if (in_array($accessState, ['awaiting_second_subject', 'private_access_revoked', 'private_access_expired'], true)) {
            $completedDyadicActionKeys = [];
        }

        $dyadicActionFocusKey = $this->resolveActionFocusKey($privateRelationship, $accessState);
        $journeyState = $this->resolveJourneyState($accessState, $consentRefreshRequired, $completedDyadicActionKeys, $overlay);
        $progressState = $this->resolveProgressState($accessState, $completedDyadicActionKeys, $overlay);
        $recommendedNextDyadicPulseKeys = $this->resolveRecommendedNextPulseKeys(
            $privateRelationship,
            $dyadicActionFocusKey,
            $journeyState,
            $accessState,
            $consentRefreshRequired,
            $completedDyadicActionKeys,
            $overlay
        );
        $revisitReorderReason = $this->resolveRevisitReorderReason(
            $journeyState,
            $accessState,
            $consentRefreshRequired,
            $completedDyadicActionKeys,
            $privateRelationship,
            $overlay
        );
        $lastDyadicPulseSignal = $this->normalizeText($overlay['last_dyadic_pulse_signal'] ?? null)
            ?? $this->deriveLastPulseSignal($journeyState, $consentRefreshRequired);

        $fingerprintSeed = [
            'journey_scope' => self::JOURNEY_SCOPE,
            'journey_state' => $journeyState,
            'progress_state' => $progressState,
            'dyadic_action_focus_key' => $dyadicActionFocusKey,
            'completed_dyadic_action_keys' => $completedDyadicActionKeys,
            'recommended_next_dyadic_pulse_keys' => $recommendedNextDyadicPulseKeys,
            'revisit_reorder_reason' => $revisitReorderReason,
            'last_dyadic_pulse_signal' => $lastDyadicPulseSignal,
            'relationship_fingerprint' => $this->normalizeText($privateRelationship['relationship_fingerprint'] ?? null),
            'consent_fingerprint' => $this->normalizeText($dyadicConsent['consent_fingerprint'] ?? null),
            'private_relationship_access_version' => $this->normalizeText($dyadicConsent['private_relationship_access_version'] ?? null),
        ];

        return [
            'journey_contract_version' => self::JOURNEY_VERSION,
            'journey_fingerprint_version' => self::JOURNEY_FINGERPRINT_VERSION,
            'journey_fingerprint' => sha1((string) json_encode($fingerprintSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'journey_scope' => self::JOURNEY_SCOPE,
            'journey_state' => $journeyState,
            'progress_state' => $progressState,
            'dyadic_action_focus_key' => $dyadicActionFocusKey,
            'completed_dyadic_action_keys' => $completedDyadicActionKeys,
            'recommended_next_dyadic_pulse_keys' => $recommendedNextDyadicPulseKeys,
            'revisit_reorder_reason' => $revisitReorderReason,
            'last_dyadic_pulse_signal' => $lastDyadicPulseSignal,
        ];
    }

    /**
     * @param  array<string,mixed>  $privateRelationship
     * @param  array<string,mixed>  $dyadicConsent
     * @param  array<string,mixed>  $journey
     * @param  array<string,mixed>  $overlay
     * @return array<string,mixed>
     */
    public function buildPulseCheck(array $privateRelationship, array $dyadicConsent, array $journey, array $overlay): array
    {
        if ($journey === []) {
            return [];
        }

        $pulseState = $this->resolvePulseState(
            $this->normalizeText($journey['journey_state'] ?? null),
            $this->normalizeText($journey['progress_state'] ?? null),
            $this->normalizeText($dyadicConsent['access_state'] ?? null),
            (bool) ($dyadicConsent['consent_refresh_required'] ?? false)
        );
        if ($pulseState === '') {
            return [];
        }

        $nextPulseTarget = $this->resolveNextPulseTarget(
            $pulseState,
            $journey,
            $privateRelationship,
            $dyadicConsent
        );

        return [
            'pulse_contract_version' => self::PULSE_VERSION,
            'pulse_state' => $pulseState,
            'pulse_prompt_keys' => $this->resolvePulsePromptKeys(
                $pulseState,
                $this->normalizeText($journey['dyadic_action_focus_key'] ?? null) ?? '',
                $overlay
            ),
            'pulse_feedback_mode' => $this->normalizeText($overlay['pulse_feedback_mode'] ?? null)
                ?? 'dyadic_event_feedback',
            'next_pulse_target' => $nextPulseTarget,
        ];
    }

    /**
     * @param  array<string,mixed>  $overlay
     * @return array<string,mixed>
     */
    public function applyJourneyMutation(
        array $overlay,
        string $action,
        string $accessState,
        string $dyadicActionFocusKey,
        array $completedDyadicActionKeys
    ): array {
        $normalizedAction = $this->normalizeText($action) ?? '';
        $overlay['updated_at'] = now()->toISOString();

        switch ($normalizedAction) {
            case 'continue_dyadic_action':
                if (! in_array($accessState, ['private_access_ready', 'private_access_partial', 'joined_public_only'], true)) {
                    return $overlay;
                }

                $completed = $this->normalizeStringList($overlay['completed_dyadic_action_keys'] ?? []);
                foreach ($completedDyadicActionKeys as $key) {
                    $completed[] = $key;
                }
                if ($dyadicActionFocusKey !== '') {
                    $completed[] = $dyadicActionFocusKey;
                }
                $completed = array_values(array_unique(array_filter($completed)));

                $overlay['completed_dyadic_action_keys'] = $completed;
                $overlay['journey_state'] = count($completed) > 1 ? 'practice_revisit' : 'practice_started';
                $overlay['progress_state'] = count($completed) > 1 ? 'repeatable' : 'warming_up';
                $overlay['last_dyadic_pulse_signal'] = 'continue_dyadic_action';
                $overlay['pulse_feedback_mode'] = 'protected_dyadic_ack';
                $overlay['revisit_reorder_reason'] = count($completed) > 1
                    ? 'resume_dyadic_practice'
                    : 'activate_first_dyadic_step';
                break;

            case 'acknowledge_dyadic_pulse':
                if (! in_array($accessState, ['private_access_ready', 'private_access_partial', 'joined_public_only'], true)) {
                    return $overlay;
                }

                $completed = $this->normalizeStringList($overlay['completed_dyadic_action_keys'] ?? []);
                $overlay['journey_state'] = $completed === [] ? 'practice_started' : 'practice_revisit';
                $overlay['progress_state'] = $completed === [] ? 'warming_up' : 'repeatable';
                $overlay['last_dyadic_pulse_signal'] = 'acknowledge_dyadic_pulse';
                $overlay['pulse_feedback_mode'] = 'protected_dyadic_ack';
                $overlay['revisit_reorder_reason'] = $completed === []
                    ? 'activate_first_dyadic_step'
                    : 'resume_dyadic_practice';
                break;
        }

        return $overlay;
    }

    /**
     * @param  array<string,mixed>  $privateRelationship
     */
    private function resolveActionFocusKey(array $privateRelationship, string $accessState): string
    {
        if (in_array($accessState, ['private_access_revoked', 'private_access_expired', 'awaiting_second_subject'], true)) {
            return '';
        }

        $actionPromptKey = $this->normalizeText(data_get($privateRelationship, 'private_action_prompt.key'));
        if ($actionPromptKey !== null) {
            return $actionPromptKey;
        }

        foreach ([
            $privateRelationship['communication_bridge_keys'] ?? [],
            $privateRelationship['decision_tension_keys'] ?? [],
            $privateRelationship['stress_interplay_keys'] ?? [],
            $privateRelationship['friction_keys'] ?? [],
            $privateRelationship['complement_keys'] ?? [],
        ] as $candidateKeys) {
            $keys = $this->normalizeStringList($candidateKeys);
            if ($keys !== []) {
                return $keys[0];
            }
        }

        return '';
    }

    /**
     * @param  list<string>  $completedDyadicActionKeys
     * @param  array<string,mixed>  $overlay
     */
    private function resolveJourneyState(
        string $accessState,
        bool $consentRefreshRequired,
        array $completedDyadicActionKeys,
        array $overlay
    ): string {
        $overlayState = $this->normalizeText($overlay['journey_state'] ?? null);
        if ($overlayState !== null && in_array($accessState, ['private_access_ready', 'private_access_partial', 'joined_public_only'], true)) {
            return $overlayState;
        }

        return match (true) {
            $accessState === 'awaiting_second_subject' => 'awaiting_partner',
            $accessState === 'private_access_revoked' => 'access_revoked',
            $accessState === 'private_access_expired' || $consentRefreshRequired => 'revisit_after_consent_refresh',
            $completedDyadicActionKeys !== [] => 'practice_revisit',
            default => 'ready_for_first_step',
        };
    }

    /**
     * @param  list<string>  $completedDyadicActionKeys
     * @param  array<string,mixed>  $overlay
     */
    private function resolveProgressState(string $accessState, array $completedDyadicActionKeys, array $overlay): string
    {
        $overlayState = $this->normalizeText($overlay['progress_state'] ?? null);
        if ($overlayState !== null && in_array($accessState, ['private_access_ready', 'private_access_partial', 'joined_public_only'], true)) {
            return $overlayState;
        }

        return match (true) {
            in_array($accessState, ['private_access_revoked', 'private_access_expired'], true) => 'restricted',
            count($completedDyadicActionKeys) >= 2 => 'repeatable',
            count($completedDyadicActionKeys) === 1 => 'warming_up',
            default => 'not_started',
        };
    }

    /**
     * @param  array<string,mixed>  $privateRelationship
     * @param  list<string>  $completedDyadicActionKeys
     * @param  array<string,mixed>  $overlay
     * @return list<string>
     */
    private function resolveRecommendedNextPulseKeys(
        array $privateRelationship,
        string $dyadicActionFocusKey,
        string $journeyState,
        string $accessState,
        bool $consentRefreshRequired,
        array $completedDyadicActionKeys,
        array $overlay
    ): array {
        $overlayKeys = $this->normalizeStringList($overlay['recommended_next_dyadic_pulse_keys'] ?? []);
        if ($overlayKeys !== [] && in_array($accessState, ['private_access_ready', 'private_access_partial', 'joined_public_only'], true)) {
            return $overlayKeys;
        }

        if ($accessState === 'awaiting_second_subject') {
            return ['dyadic_pulse.wait_for_partner'];
        }

        if ($accessState === 'private_access_revoked') {
            return [];
        }

        if ($accessState === 'private_access_expired' || $consentRefreshRequired) {
            return ['dyadic_pulse.refresh_private_access'];
        }

        if ($completedDyadicActionKeys === []) {
            return $this->uniqueStringList([
                $dyadicActionFocusKey,
                'dyadic_pulse.start_private_practice',
            ]);
        }

        if (($privateRelationship['friction_keys'] ?? []) !== []) {
            return $this->uniqueStringList([
                $dyadicActionFocusKey,
                $this->firstString($privateRelationship['friction_keys'] ?? []),
                'dyadic_pulse.review_tension_signal',
            ]);
        }

        return $this->uniqueStringList([
            $dyadicActionFocusKey,
            'dyadic_pulse.repeat_shared_action',
            'dyadic_pulse.name_next_step_together',
        ]);
    }

    /**
     * @param  list<string>  $completedDyadicActionKeys
     * @param  array<string,mixed>  $privateRelationship
     * @param  array<string,mixed>  $overlay
     */
    private function resolveRevisitReorderReason(
        string $journeyState,
        string $accessState,
        bool $consentRefreshRequired,
        array $completedDyadicActionKeys,
        array $privateRelationship,
        array $overlay
    ): string {
        $overlayReason = $this->normalizeText($overlay['revisit_reorder_reason'] ?? null);
        if ($overlayReason !== null && in_array($accessState, ['private_access_ready', 'private_access_partial', 'joined_public_only'], true)) {
            return $overlayReason;
        }

        return match (true) {
            $accessState === 'awaiting_second_subject' => 'await_partner_completion',
            $accessState === 'private_access_revoked' => 'respect_revoked_private_access',
            $accessState === 'private_access_expired' || $consentRefreshRequired => 'refresh_private_access',
            $completedDyadicActionKeys !== [] => 'resume_dyadic_practice',
            ($privateRelationship['friction_keys'] ?? []) !== [] => 'review_tension_signal',
            $journeyState === 'ready_for_first_step' => 'activate_first_dyadic_step',
            default => 'resume_private_relationship_focus',
        };
    }

    private function deriveLastPulseSignal(string $journeyState, bool $consentRefreshRequired): string
    {
        if ($consentRefreshRequired) {
            return 'consent_refresh_required';
        }

        return match ($journeyState) {
            'awaiting_partner' => 'awaiting_partner',
            'access_revoked' => 'private_access_revoked',
            'revisit_after_consent_refresh' => 'refresh_private_access',
            'practice_revisit' => 'resume_dyadic_practice',
            default => 'ready_for_first_step',
        };
    }

    private function resolvePulseState(
        string $journeyState,
        string $progressState,
        string $accessState,
        bool $consentRefreshRequired
    ): string {
        if ($accessState === 'private_access_revoked') {
            return '';
        }

        if ($accessState === 'awaiting_second_subject') {
            return 'wait_for_partner';
        }

        if ($accessState === 'private_access_expired' || $consentRefreshRequired) {
            return 'refresh_private_access';
        }

        return match ($journeyState) {
            'practice_revisit' => $progressState === 'repeatable'
                ? 'review_tension_signal'
                : 'repeat_shared_practice',
            'ready_for_first_step' => 'start_shared_practice',
            default => 'repeat_shared_practice',
        };
    }

    /**
     * @param  array<string,mixed>  $journey
     * @param  array<string,mixed>  $privateRelationship
     * @param  array<string,mixed>  $dyadicConsent
     */
    private function resolveNextPulseTarget(
        string $pulseState,
        array $journey,
        array $privateRelationship,
        array $dyadicConsent
    ): string {
        return match ($pulseState) {
            'refresh_private_access' => 'acknowledge_refresh',
            'wait_for_partner' => 'private_relationship_wait',
            'review_tension_signal' => $this->normalizeText(
                $this->firstString($privateRelationship['friction_keys'] ?? []),
                $journey['dyadic_action_focus_key'] ?? null
            ) ?? '',
            default => $this->normalizeText(
                $journey['dyadic_action_focus_key'] ?? null,
                data_get($privateRelationship, 'private_action_prompt.key'),
                data_get($dyadicConsent, 'consent_state')
            ) ?? '',
        };
    }

    /**
     * @param  array<string,mixed>  $overlay
     * @return list<string>
     */
    private function resolvePulsePromptKeys(string $pulseState, string $dyadicActionFocusKey, array $overlay): array
    {
        $overlayKeys = $this->normalizeStringList($overlay['pulse_prompt_keys'] ?? []);
        if ($overlayKeys !== []) {
            return $overlayKeys;
        }

        return match ($pulseState) {
            'wait_for_partner' => ['dyadic_pulse.wait_for_partner'],
            'refresh_private_access' => ['dyadic_pulse.refresh_private_access'],
            'review_tension_signal' => $this->uniqueStringList([
                'dyadic_pulse.review_tension_signal',
                $dyadicActionFocusKey,
            ]),
            'repeat_shared_practice' => $this->uniqueStringList([
                'dyadic_pulse.repeat_shared_action',
                $dyadicActionFocusKey,
            ]),
            default => $this->uniqueStringList([
                'dyadic_pulse.start_private_practice',
                $dyadicActionFocusKey,
            ]),
        };
    }

    private function normalizeText(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            $candidate = $this->normalizeText($value);
            if ($candidate !== null) {
                $normalized[] = $candidate;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function uniqueStringList(array $values): array
    {
        return $this->normalizeStringList($values);
    }

    private function firstString(mixed $values): ?string
    {
        $normalized = $this->normalizeStringList($values);

        return $normalized[0] ?? null;
    }
}
