<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Domain\Career\IndexStateValue;
use App\Domain\Career\ReviewerStatus;

final class CareerIndexLifecycleCompiler
{
    public function __construct(
        private readonly FirstWavePublishGate $publishGate,
    ) {}

    /**
     * @param  array<string, mixed>  $subject
     * @return array{index_state:string,index_eligible:bool,reason_codes:list<string>}
     */
    public function compile(array $subject): array
    {
        $rawIndexState = strtolower(trim((string) ($subject['raw_index_state'] ?? $subject['index_state'] ?? '')));
        $indexEligible = (bool) ($subject['index_eligible'] ?? false);
        $previousState = strtolower(trim((string) ($subject['previous_index_state'] ?? '')));
        $crosswalkMode = strtolower(trim((string) ($subject['crosswalk_mode'] ?? '')));
        $reviewerStatus = strtolower(trim((string) ($subject['reviewer_status'] ?? '')));
        $allowStrongClaim = (bool) ($subject['allow_strong_claim'] ?? false);
        $reasonCodes = array_values(array_filter(
            array_map(
                static fn (mixed $code): string => is_string($code) ? trim($code) : '',
                (array) ($subject['reason_codes'] ?? [])
            ),
            static fn (string $code): bool => $code !== ''
        ));

        $gate = $this->publishGate->evaluate([
            'crosswalk_mode' => $crosswalkMode,
            'confidence_score' => $subject['confidence_score'] ?? 0,
            'reviewer_status' => $reviewerStatus,
            'index_state' => $rawIndexState,
            'index_eligible' => $indexEligible,
            'allow_strong_claim' => $allowStrongClaim,
        ]);

        $stable = (bool) $gate['publishable']
            && IndexStateValue::isIndexedLike($rawIndexState, $indexEligible)
            && in_array($crosswalkMode, ['exact', 'trust_inheritance'], true)
            && in_array($reviewerStatus, [ReviewerStatus::APPROVED, 'reviewed'], true)
            && $allowStrongClaim;

        if ($stable) {
            return [
                'index_state' => CareerIndexLifecycleState::INDEXED,
                'index_eligible' => true,
                'reason_codes' => $this->withLifecycleReason($reasonCodes, CareerIndexLifecycleState::INDEXED),
            ];
        }

        if ($this->shouldDemote($previousState, $rawIndexState, $indexEligible, $gate['classification'] ?? null, $reviewerStatus, $allowStrongClaim)) {
            return [
                'index_state' => CareerIndexLifecycleState::DEMOTED,
                'index_eligible' => false,
                'reason_codes' => $this->withLifecycleReason($reasonCodes, CareerIndexLifecycleState::DEMOTED, 'career_index_lifecycle_regressed'),
            ];
        }

        if (($gate['classification'] ?? null) === WaveClassification::CANDIDATE) {
            return [
                'index_state' => CareerIndexLifecycleState::PROMOTION_CANDIDATE,
                'index_eligible' => false,
                'reason_codes' => $this->withLifecycleReason($reasonCodes, CareerIndexLifecycleState::PROMOTION_CANDIDATE),
            ];
        }

        return [
            'index_state' => CareerIndexLifecycleState::NOINDEX,
            'index_eligible' => false,
            'reason_codes' => $this->withLifecycleReason($reasonCodes, CareerIndexLifecycleState::NOINDEX),
        ];
    }

    private function shouldDemote(
        string $previousState,
        string $rawIndexState,
        bool $indexEligible,
        mixed $gateClassification,
        string $reviewerStatus,
        bool $allowStrongClaim,
    ): bool {
        $priorIndexed = in_array($previousState, [IndexStateValue::INDEXABLE, CareerIndexLifecycleState::INDEXED], true);
        $priorCandidate = $previousState === CareerIndexLifecycleState::PROMOTION_CANDIDATE;

        $regressed = ! $indexEligible
            || in_array($rawIndexState, [IndexStateValue::TRUST_LIMITED, IndexStateValue::NOINDEX, IndexStateValue::UNAVAILABLE], true)
            || $gateClassification === WaveClassification::HOLD
            || ! in_array($reviewerStatus, [ReviewerStatus::APPROVED, 'reviewed'], true)
            || ! $allowStrongClaim;

        if ($priorIndexed && $regressed) {
            return true;
        }

        return $priorCandidate && (
            $gateClassification === WaveClassification::HOLD
            || in_array($rawIndexState, [IndexStateValue::NOINDEX, IndexStateValue::UNAVAILABLE], true)
            || ! $indexEligible
        );
    }

    /**
     * @param  list<string>  $reasonCodes
     * @return list<string>
     */
    private function withLifecycleReason(array $reasonCodes, string $state, ?string $extra = null): array
    {
        $reasonCodes[] = 'career_index_lifecycle_'.$state;
        if (is_string($extra) && $extra !== '') {
            $reasonCodes[] = $extra;
        }

        return array_values(array_unique($reasonCodes));
    }
}
