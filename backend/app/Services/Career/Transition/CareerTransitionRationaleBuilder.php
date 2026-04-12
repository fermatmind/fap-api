<?php

declare(strict_types=1);

namespace App\Services\Career\Transition;

use App\Domain\Career\Transition\TransitionPathPayload;
use App\Models\Occupation;
use App\Models\OccupationTruthMetric;

final class CareerTransitionRationaleBuilder
{
    /**
     * @var list<string>
     */
    private const SAFE_CROSSWALK_MODES = [
        'exact',
        'direct_match',
        'trust_inheritance',
    ];

    /**
     * @param  list<string>  $steps
     * @param  array<string, mixed>  $targetReadiness
     * @return array<string, mixed>
     */
    public function build(Occupation $sourceOccupation, Occupation $targetOccupation, array $steps, array $targetReadiness = []): array
    {
        $normalizedSteps = TransitionPathPayload::from(['steps' => $steps])->steps;

        $rationaleCodes = [];
        foreach (TransitionPathPayload::allowedRationaleCodes() as $code) {
            if (in_array($code, $normalizedSteps, true)) {
                $rationaleCodes[] = $code;
            }
        }

        if ($sourceOccupation->family_id !== null && $sourceOccupation->family_id === $targetOccupation->family_id) {
            $rationaleCodes[] = TransitionPathPayload::RATIONALE_SAME_FAMILY_TARGET;
        }

        if (($targetReadiness['status'] ?? null) === 'publish_ready') {
            $rationaleCodes[] = TransitionPathPayload::RATIONALE_PUBLISH_READY_TARGET;
        }

        if (($targetReadiness['index_eligible'] ?? false) === true) {
            $rationaleCodes[] = TransitionPathPayload::RATIONALE_INDEX_ELIGIBLE_TARGET;
        }

        if (($targetReadiness['reviewer_status'] ?? null) === 'approved') {
            $rationaleCodes[] = TransitionPathPayload::RATIONALE_APPROVED_REVIEWER_TARGET;
        }

        $crosswalkMode = is_scalar($targetReadiness['crosswalk_mode'] ?? null)
            ? trim((string) $targetReadiness['crosswalk_mode'])
            : trim((string) ($targetOccupation->crosswalk_mode ?? ''));
        if (in_array($crosswalkMode, self::SAFE_CROSSWALK_MODES, true)) {
            $rationaleCodes[] = TransitionPathPayload::RATIONALE_SAFE_CROSSWALK_TARGET;
        }

        $delta = [];
        $sourceTruthMetric = $this->latestTruthMetric($sourceOccupation);
        $targetTruthMetric = $this->latestTruthMetric($targetOccupation);

        if ($sourceTruthMetric instanceof OccupationTruthMetric && $targetTruthMetric instanceof OccupationTruthMetric) {
            $delta = array_filter([
                TransitionPathPayload::DELTA_ENTRY_EDUCATION => $this->compareRankedField(
                    $sourceTruthMetric->entry_education,
                    $targetTruthMetric->entry_education,
                    $this->educationRank(...),
                ),
                TransitionPathPayload::DELTA_WORK_EXPERIENCE => $this->compareRankedField(
                    $sourceTruthMetric->work_experience,
                    $targetTruthMetric->work_experience,
                    $this->workExperienceRank(...),
                ),
                TransitionPathPayload::DELTA_TRAINING => $this->compareRankedField(
                    $sourceTruthMetric->on_the_job_training,
                    $targetTruthMetric->on_the_job_training,
                    $this->trainingRank(...),
                ),
            ], static fn (mixed $value): bool => is_array($value));
        }

        $tradeoffCodes = [];
        if (($delta[TransitionPathPayload::DELTA_ENTRY_EDUCATION]['direction'] ?? null) === TransitionPathPayload::DELTA_DIRECTION_HIGHER) {
            $tradeoffCodes[] = TransitionPathPayload::TRADEOFF_HIGHER_ENTRY_EDUCATION_REQUIRED;
        }
        if (($delta[TransitionPathPayload::DELTA_WORK_EXPERIENCE]['direction'] ?? null) === TransitionPathPayload::DELTA_DIRECTION_HIGHER) {
            $tradeoffCodes[] = TransitionPathPayload::TRADEOFF_HIGHER_WORK_EXPERIENCE_REQUIRED;
        }
        if (($delta[TransitionPathPayload::DELTA_TRAINING]['direction'] ?? null) === TransitionPathPayload::DELTA_DIRECTION_HIGHER) {
            $tradeoffCodes[] = TransitionPathPayload::TRADEOFF_HIGHER_TRAINING_REQUIRED;
        }

        $payload = [];
        if ($rationaleCodes !== []) {
            $payload['rationale_codes'] = array_values(array_unique($rationaleCodes));
        }
        if ($tradeoffCodes !== []) {
            $payload['tradeoff_codes'] = array_values(array_unique($tradeoffCodes));
        }
        if ($delta !== []) {
            $payload['delta'] = $delta;
        }

        return $payload;
    }

    private function latestTruthMetric(Occupation $occupation): ?OccupationTruthMetric
    {
        $truthMetric = $occupation->truthMetrics()
            ->orderByDesc('reviewed_at')
            ->orderByDesc('effective_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        return $truthMetric instanceof OccupationTruthMetric ? $truthMetric : null;
    }

    /**
     * @param  callable(string): ?int  $rankResolver
     * @return array{source_value:string,target_value:string,direction:string}|null
     */
    private function compareRankedField(?string $sourceValue, ?string $targetValue, callable $rankResolver): ?array
    {
        $source = trim((string) $sourceValue);
        $target = trim((string) $targetValue);
        if ($source === '' || $target === '') {
            return null;
        }

        $sourceRank = $rankResolver($source);
        $targetRank = $rankResolver($target);
        if (! is_int($sourceRank) || ! is_int($targetRank)) {
            return null;
        }

        $direction = match (true) {
            $targetRank > $sourceRank => TransitionPathPayload::DELTA_DIRECTION_HIGHER,
            $targetRank < $sourceRank => TransitionPathPayload::DELTA_DIRECTION_LOWER,
            default => TransitionPathPayload::DELTA_DIRECTION_SAME,
        };

        return [
            'source_value' => $source,
            'target_value' => $target,
            'direction' => $direction,
        ];
    }

    private function educationRank(string $value): ?int
    {
        $normalized = strtolower(trim($value));

        return match (true) {
            $normalized === 'none' => 0,
            str_contains($normalized, 'high school') => 1,
            str_contains($normalized, 'postsecondary nondegree') || str_contains($normalized, 'some college') => 2,
            str_contains($normalized, 'associate') => 3,
            str_contains($normalized, 'bachelor') => 4,
            str_contains($normalized, 'master') => 5,
            str_contains($normalized, 'doctoral') || str_contains($normalized, 'professional degree') => 6,
            default => null,
        };
    }

    private function workExperienceRank(string $value): ?int
    {
        $normalized = strtolower(trim($value));

        return match (true) {
            $normalized === 'none' => 0,
            str_contains($normalized, 'less than 5 years') => 1,
            str_contains($normalized, '5 years or more') => 2,
            default => null,
        };
    }

    private function trainingRank(string $value): ?int
    {
        $normalized = strtolower(trim($value));

        return match (true) {
            $normalized === 'none' => 0,
            str_contains($normalized, 'short-term') => 1,
            str_contains($normalized, 'moderate-term') => 2,
            str_contains($normalized, 'long-term') => 3,
            str_contains($normalized, 'apprenticeship') => 4,
            default => null,
        };
    }
}
