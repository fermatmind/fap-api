<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Domain\Career\IndexStateValue;
use App\DTO\Career\CareerFirstWaveLifecycleSummary;

final class CareerFirstWaveLifecycleSummaryService
{
    public const SUMMARY_VERSION = 'career.lifecycle.first_wave.v1';

    public const SCOPE = 'career_first_wave_10';

    public function __construct(
        private readonly FirstWaveManifestReader $manifestReader,
        private readonly FirstWavePublishReadyValidator $validator,
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

        $counts = [
            'total' => 0,
            CareerIndexLifecycleState::NOINDEX => 0,
            CareerIndexLifecycleState::PROMOTION_CANDIDATE => 0,
            CareerIndexLifecycleState::INDEXED => 0,
            CareerIndexLifecycleState::DEMOTED => 0,
        ];
        $occupations = [];

        foreach ((array) ($manifest['occupations'] ?? []) as $occupation) {
            if (! is_array($occupation)) {
                continue;
            }

            $slug = (string) ($occupation['canonical_slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $row = $rowsBySlug[$slug] ?? [];
            $lifecycleState = $this->normalizeLifecycleState((string) ($row['index_state'] ?? ''));
            $indexEligible = (bool) ($row['index_eligible'] ?? false);
            $publicIndexState = IndexStateValue::publicFacing((string) ($row['index_state'] ?? ''), $indexEligible);
            $reviewerStatus = $this->normalizeNullableString($row['reviewer_status'] ?? null);
            $trustStatus = strtolower(trim((string) ($row['trust_status'] ?? '')));

            $counts['total']++;
            $counts[$lifecycleState]++;

            $occupations[] = [
                'occupation_uuid' => (string) ($occupation['occupation_uuid'] ?? ''),
                'canonical_slug' => $slug,
                'canonical_title_en' => (string) ($occupation['canonical_title_en'] ?? ''),
                'lifecycle_state' => $lifecycleState,
                'public_index_state' => $publicIndexState,
                'index_eligible' => $indexEligible,
                'reviewer_status' => $reviewerStatus,
                'reason_codes' => $this->curateReasonCodes(
                    lifecycleState: $lifecycleState,
                    publicIndexState: $publicIndexState,
                    indexEligible: $indexEligible,
                    reviewerStatus: $reviewerStatus,
                    trustStatus: $trustStatus,
                ),
            ];
        }

        return new CareerFirstWaveLifecycleSummary(
            summaryVersion: self::SUMMARY_VERSION,
            scope: self::SCOPE,
            counts: $counts,
            occupations: $occupations,
        );
    }

    private function normalizeLifecycleState(string $state): string
    {
        $normalized = strtolower(trim($state));

        return match ($normalized) {
            CareerIndexLifecycleState::NOINDEX,
            CareerIndexLifecycleState::PROMOTION_CANDIDATE,
            CareerIndexLifecycleState::INDEXED,
            CareerIndexLifecycleState::DEMOTED => $normalized,
            default => CareerIndexLifecycleState::NOINDEX,
        };
    }

    /**
     * @return list<string>
     */
    private function curateReasonCodes(
        string $lifecycleState,
        string $publicIndexState,
        bool $indexEligible,
        ?string $reviewerStatus,
        string $trustStatus,
    ): array {
        $reasonCodes = [];
        $reviewApproved = in_array($reviewerStatus, ['approved', 'reviewed'], true);

        switch ($lifecycleState) {
            case CareerIndexLifecycleState::INDEXED:
                $reasonCodes[] = 'indexed_ready';
                break;

            case CareerIndexLifecycleState::PROMOTION_CANDIDATE:
                $reasonCodes[] = 'publish_gate_candidate';
                if (! $reviewApproved) {
                    $reasonCodes[] = 'review_pending';
                }
                break;

            case CareerIndexLifecycleState::DEMOTED:
                if (! $reviewApproved) {
                    $reasonCodes[] = 'demoted_review_regression';
                }
                if (! $indexEligible || $publicIndexState !== IndexStateValue::INDEXABLE) {
                    $reasonCodes[] = 'demoted_trust_regression';
                }
                break;

            default:
                if ($trustStatus === WaveClassification::HOLD) {
                    $reasonCodes[] = 'publish_gate_hold';
                }
                break;
        }

        if (! $indexEligible && $lifecycleState !== CareerIndexLifecycleState::INDEXED) {
            $reasonCodes[] = 'not_index_eligible';
        }

        if ($publicIndexState === IndexStateValue::TRUST_LIMITED) {
            $reasonCodes[] = 'trust_limited';
        }

        if ($reasonCodes === [] && $lifecycleState === CareerIndexLifecycleState::NOINDEX) {
            $reasonCodes[] = 'not_index_eligible';
        }

        return array_values(array_unique($reasonCodes));
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }
}
