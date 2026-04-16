<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Domain\Career\IndexStateValue;
use App\DTO\Career\CareerFirstWaveIndexPolicyMember;
use App\DTO\Career\CareerFirstWaveLifecycleSummary;

final class CareerFirstWaveLifecycleSummaryService
{
    public const SUMMARY_VERSION = 'career.lifecycle.first_wave.v1';

    public const SCOPE = 'career_first_wave_10';

    public function __construct(
        private readonly FirstWaveManifestReader $manifestReader,
        private readonly FirstWavePublishReadyValidator $validator,
        private readonly CareerFirstWaveIndexPolicyEngine $indexPolicyEngine,
    ) {}

    public function build(): CareerFirstWaveLifecycleSummary
    {
        $manifest = $this->manifestReader->read();
        $report = $this->validator->validate();

        $rowsBySlug = [];
        foreach ((array) ($report['occupations'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = (string) ($row['canonical_slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $rowsBySlug[$slug] = $row;
        }

        $subjects = [];
        $manifestRowsBySlug = [];

        foreach ((array) ($manifest['occupations'] ?? []) as $occupation) {
            if (! is_array($occupation)) {
                continue;
            }

            $slug = (string) ($occupation['canonical_slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $manifestRowsBySlug[$slug] = $occupation;
            $row = $rowsBySlug[$slug] ?? [];
            $indexEligible = (bool) ($row['index_eligible'] ?? false);
            $publicIndexState = IndexStateValue::publicFacing((string) ($row['index_state'] ?? ''), $indexEligible);
            $subjects[] = [
                'canonical_slug' => $slug,
                'current_index_state' => (string) ($row['index_state'] ?? ''),
                'public_index_state' => $publicIndexState,
                'index_eligible' => $indexEligible,
                'reviewer_status' => $row['reviewer_status'] ?? null,
                'crosswalk_mode' => $row['crosswalk_mode'] ?? null,
                'allow_strong_claim' => (bool) ($row['allow_strong_claim'] ?? false),
                'confidence_score' => $row['confidence_score'] ?? null,
                'blocked_governance_status' => $row['blocked_governance_status'] ?? null,
                'next_step_links_count' => $row['next_step_links_count'] ?? 0,
                'trust_status' => $row['trust_status'] ?? null,
            ];
        }

        $authority = $this->indexPolicyEngine->build($subjects, self::SCOPE);

        $counts = [
            'total' => count($authority->members),
            CareerIndexLifecycleState::NOINDEX => $authority->counts[CareerIndexLifecycleState::NOINDEX] ?? 0,
            CareerIndexLifecycleState::PROMOTION_CANDIDATE => $authority->counts[CareerIndexLifecycleState::PROMOTION_CANDIDATE] ?? 0,
            CareerIndexLifecycleState::INDEXED => $authority->counts[CareerIndexLifecycleState::INDEXED] ?? 0,
            CareerIndexLifecycleState::DEMOTED => $authority->counts[CareerIndexLifecycleState::DEMOTED] ?? 0,
        ];
        $occupations = [];

        foreach ($authority->members as $member) {
            $manifestRow = $manifestRowsBySlug[$member->canonicalSlug] ?? [];

            $occupations[] = [
                'occupation_uuid' => (string) ($manifestRow['occupation_uuid'] ?? ''),
                'canonical_slug' => $member->canonicalSlug,
                'canonical_title_en' => (string) ($manifestRow['canonical_title_en'] ?? ''),
                'lifecycle_state' => $member->policyState,
                'public_index_state' => $member->publicIndexState,
                'index_eligible' => $member->indexEligible,
                'reviewer_status' => $member->policyEvidence['reviewer_status'] ?? null,
                'reason_codes' => $this->projectLifecycleReasonCodes($member),
            ];
        }

        return new CareerFirstWaveLifecycleSummary(
            summaryVersion: self::SUMMARY_VERSION,
            scope: self::SCOPE,
            counts: $counts,
            occupations: $occupations,
        );
    }

    /**
     * @return list<string>
     */
    private function projectLifecycleReasonCodes(CareerFirstWaveIndexPolicyMember $member): array
    {
        $reasonCodes = [];
        $has = array_flip($member->policyReasons);

        switch ($member->policyState) {
            case CareerIndexLifecycleState::INDEXED:
                $reasonCodes[] = 'indexed_ready';
                break;
            case CareerIndexLifecycleState::PROMOTION_CANDIDATE:
                $reasonCodes[] = 'publish_gate_candidate';
                break;
            case CareerIndexLifecycleState::DEMOTED:
                if (isset($has['demoted_review_regression'])) {
                    $reasonCodes[] = 'demoted_review_regression';
                }
                if (isset($has['demoted_trust_regression'])) {
                    $reasonCodes[] = 'demoted_trust_regression';
                }
                break;
            default:
                if (isset($has['publish_gate_hold'])) {
                    $reasonCodes[] = 'publish_gate_hold';
                }
                break;
        }

        if (isset($has['not_index_eligible']) && $member->policyState !== CareerIndexLifecycleState::INDEXED) {
            $reasonCodes[] = 'not_index_eligible';
        }

        if (isset($has['trust_limited'])) {
            $reasonCodes[] = 'trust_limited';
        }

        if ($reasonCodes === [] && $member->policyState === CareerIndexLifecycleState::NOINDEX) {
            $reasonCodes[] = 'not_index_eligible';
        }

        return array_values(array_unique($reasonCodes));
    }
}
