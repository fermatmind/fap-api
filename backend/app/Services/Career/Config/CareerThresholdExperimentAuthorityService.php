<?php

declare(strict_types=1);

namespace App\Services\Career\Config;

use App\DTO\Career\CareerThresholdExperimentAuthority;
use App\DTO\Career\CareerThresholdExperimentSnapshot;
use App\Support\RuntimeConfig;

final class CareerThresholdExperimentAuthorityService
{
    public const DEFAULT_SNAPSHOT_KEY = 'career_default_v1';

    public const THRESHOLD_CONFIDENCE_PUBLISH_MIN = 60;

    public const THRESHOLD_CONFIDENCE_PROMOTION_CANDIDATE_MIN = 70;

    public const THRESHOLD_CONFIDENCE_STABLE_MIN = 75;

    public const THRESHOLD_WARNING_LOW_CONFIDENCE = 72;

    public const THRESHOLD_WARNING_HIGH_STRAIN = 70;

    public const THRESHOLD_WARNING_AI_RISK = 65;

    public const THRESHOLD_PROMOTION_NEXT_STEP_LINKS_MIN = 2;

    public const THRESHOLD_PROMOTION_STRONG_CLAIM_REQUIRED = true;

    public function buildAuthority(): CareerThresholdExperimentAuthority
    {
        return new CareerThresholdExperimentAuthority(
            snapshot: new CareerThresholdExperimentSnapshot(
                snapshotKey: $this->snapshotKey(),
                thresholds: $this->thresholds(),
                experiments: $this->experiments(),
            ),
        );
    }

    public function confidencePublishMin(): int
    {
        return (int) data_get($this->thresholds(), 'confidence.publish_min', self::THRESHOLD_CONFIDENCE_PUBLISH_MIN);
    }

    public function confidencePromotionCandidateMin(): int
    {
        return (int) data_get(
            $this->thresholds(),
            'confidence.promotion_candidate_min',
            self::THRESHOLD_CONFIDENCE_PROMOTION_CANDIDATE_MIN
        );
    }

    public function confidenceStableMin(): int
    {
        return (int) data_get($this->thresholds(), 'confidence.stable_min', self::THRESHOLD_CONFIDENCE_STABLE_MIN);
    }

    public function warningLowConfidenceThreshold(): int
    {
        return (int) data_get(
            $this->thresholds(),
            'warnings.low_confidence_threshold',
            self::THRESHOLD_WARNING_LOW_CONFIDENCE
        );
    }

    public function warningHighStrainThreshold(): int
    {
        return (int) data_get(
            $this->thresholds(),
            'warnings.high_strain_threshold',
            self::THRESHOLD_WARNING_HIGH_STRAIN
        );
    }

    public function warningAiRiskThreshold(): int
    {
        return (int) data_get($this->thresholds(), 'warnings.ai_risk_threshold', self::THRESHOLD_WARNING_AI_RISK);
    }

    public function promotionNextStepLinksMin(): int
    {
        return (int) data_get(
            $this->thresholds(),
            'promotion.next_step_links_min',
            self::THRESHOLD_PROMOTION_NEXT_STEP_LINKS_MIN
        );
    }

    public function promotionStrongClaimRequired(): bool
    {
        return (bool) data_get(
            $this->thresholds(),
            'promotion.strong_claim_required',
            self::THRESHOLD_PROMOTION_STRONG_CLAIM_REQUIRED
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function thresholds(): array
    {
        $configured = RuntimeConfig::raw('career.threshold_experiment.thresholds');
        if (is_array($configured)) {
            return array_replace_recursive($this->defaultThresholds(), $configured);
        }

        return $this->defaultThresholds();
    }

    /**
     * @return array<string, mixed>
     */
    public function experiments(): array
    {
        $configured = RuntimeConfig::raw('career.threshold_experiment.experiments');
        if (is_array($configured)) {
            return array_replace_recursive($this->defaultExperiments(), $configured);
        }

        return $this->defaultExperiments();
    }

    private function snapshotKey(): string
    {
        $configured = RuntimeConfig::value('career.threshold_experiment.snapshot_key', self::DEFAULT_SNAPSHOT_KEY);
        $normalized = is_string($configured) ? trim($configured) : '';

        return $normalized !== '' ? $normalized : self::DEFAULT_SNAPSHOT_KEY;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultThresholds(): array
    {
        return [
            'confidence' => [
                'publish_min' => self::THRESHOLD_CONFIDENCE_PUBLISH_MIN,
                'promotion_candidate_min' => self::THRESHOLD_CONFIDENCE_PROMOTION_CANDIDATE_MIN,
                'stable_min' => self::THRESHOLD_CONFIDENCE_STABLE_MIN,
            ],
            'warnings' => [
                'low_confidence_threshold' => self::THRESHOLD_WARNING_LOW_CONFIDENCE,
                'high_strain_threshold' => self::THRESHOLD_WARNING_HIGH_STRAIN,
                'ai_risk_threshold' => self::THRESHOLD_WARNING_AI_RISK,
            ],
            'promotion' => [
                'next_step_links_min' => self::THRESHOLD_PROMOTION_NEXT_STEP_LINKS_MIN,
                'strong_claim_required' => self::THRESHOLD_PROMOTION_STRONG_CLAIM_REQUIRED,
            ],
        ];
    }

    /**
     * @return array<string, array{enabled: bool, variant: string}>
     */
    private function defaultExperiments(): array
    {
        return [
            'career_warning_copy_v1' => [
                'enabled' => true,
                'variant' => 'control',
            ],
            'career_explorer_primary_path_v1' => [
                'enabled' => true,
                'variant' => 'jobs_first',
            ],
            'career_transition_emphasis_v1' => [
                'enabled' => true,
                'variant' => 'balanced',
            ],
        ];
    }
}
