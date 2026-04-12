<?php

declare(strict_types=1);

namespace App\Domain\Career\Transition;

final class TransitionPathPayload
{
    public const STEP_SKILL_OVERLAP = 'skill_overlap';

    public const STEP_TASK_OVERLAP = 'task_overlap';

    public const STEP_TOOL_OVERLAP = 'tool_overlap';

    public const RATIONALE_SAME_FAMILY_TARGET = 'same_family_target';

    public const RATIONALE_PUBLISH_READY_TARGET = 'publish_ready_target';

    public const RATIONALE_INDEX_ELIGIBLE_TARGET = 'index_eligible_target';

    public const RATIONALE_APPROVED_REVIEWER_TARGET = 'approved_reviewer_target';

    public const RATIONALE_SAFE_CROSSWALK_TARGET = 'safe_crosswalk_target';

    public const TRADEOFF_HIGHER_ENTRY_EDUCATION_REQUIRED = 'higher_entry_education_required';

    public const TRADEOFF_HIGHER_WORK_EXPERIENCE_REQUIRED = 'higher_work_experience_required';

    public const TRADEOFF_HIGHER_TRAINING_REQUIRED = 'higher_training_required';

    public const DELTA_ENTRY_EDUCATION = 'entry_education_delta';

    public const DELTA_WORK_EXPERIENCE = 'work_experience_delta';

    public const DELTA_TRAINING = 'training_delta';

    public const DELTA_DIRECTION_SAME = 'same';

    public const DELTA_DIRECTION_HIGHER = 'higher';

    public const DELTA_DIRECTION_LOWER = 'lower';

    /**
     * @param  list<string>  $steps
     * @param  list<string>  $rationaleCodes
     * @param  list<string>  $tradeoffCodes
     * @param  array<string, array{source_value:string,target_value:string,direction:string}>  $delta
     */
    private function __construct(
        public readonly array $steps,
        public readonly array $rationaleCodes,
        public readonly array $tradeoffCodes,
        public readonly array $delta,
    ) {}

    public static function from(mixed $payload): self
    {
        if (! is_array($payload)) {
            return new self([], [], [], []);
        }

        $steps = [];
        foreach ((array) ($payload['steps'] ?? []) as $step) {
            if (! is_string($step)) {
                continue;
            }

            $normalized = trim($step);
            if ($normalized === '' || ! in_array($normalized, self::allowedStepLabels(), true)) {
                continue;
            }

            $steps[] = $normalized;
        }

        $rationaleCodes = [];
        foreach ((array) ($payload['rationale_codes'] ?? []) as $code) {
            if (! is_string($code)) {
                continue;
            }

            $normalized = trim($code);
            if ($normalized === '' || ! in_array($normalized, self::allowedRationaleCodes(), true)) {
                continue;
            }

            $rationaleCodes[] = $normalized;
        }

        $tradeoffCodes = [];
        foreach ((array) ($payload['tradeoff_codes'] ?? []) as $code) {
            if (! is_string($code)) {
                continue;
            }

            $normalized = trim($code);
            if ($normalized === '' || ! in_array($normalized, self::allowedTradeoffCodes(), true)) {
                continue;
            }

            $tradeoffCodes[] = $normalized;
        }

        $delta = [];
        foreach ((array) ($payload['delta'] ?? []) as $key => $entry) {
            if (! is_string($key) || ! in_array($key, self::allowedDeltaKeys(), true) || ! is_array($entry)) {
                continue;
            }

            $sourceValue = is_scalar($entry['source_value'] ?? null) ? trim((string) $entry['source_value']) : '';
            $targetValue = is_scalar($entry['target_value'] ?? null) ? trim((string) $entry['target_value']) : '';
            $direction = is_scalar($entry['direction'] ?? null) ? trim((string) $entry['direction']) : '';

            if ($sourceValue === '' || $targetValue === '' || ! in_array($direction, self::allowedDeltaDirections(), true)) {
                continue;
            }

            $delta[$key] = [
                'source_value' => $sourceValue,
                'target_value' => $targetValue,
                'direction' => $direction,
            ];
        }

        return new self(
            array_values(array_unique($steps)),
            array_values(array_unique($rationaleCodes)),
            array_values(array_unique($tradeoffCodes)),
            $delta,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->steps === []) {
            $payload = [];
        } else {
            $payload = [
                'steps' => $this->steps,
            ];
        }

        if ($this->rationaleCodes !== []) {
            $payload['rationale_codes'] = $this->rationaleCodes;
        }

        if ($this->tradeoffCodes !== []) {
            $payload['tradeoff_codes'] = $this->tradeoffCodes;
        }

        if ($this->delta !== []) {
            $payload['delta'] = $this->delta;
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    public static function allowedStepLabels(): array
    {
        return [
            self::STEP_SKILL_OVERLAP,
            self::STEP_TASK_OVERLAP,
            self::STEP_TOOL_OVERLAP,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedRationaleCodes(): array
    {
        return [
            self::STEP_SKILL_OVERLAP,
            self::STEP_TASK_OVERLAP,
            self::STEP_TOOL_OVERLAP,
            self::RATIONALE_SAME_FAMILY_TARGET,
            self::RATIONALE_PUBLISH_READY_TARGET,
            self::RATIONALE_INDEX_ELIGIBLE_TARGET,
            self::RATIONALE_APPROVED_REVIEWER_TARGET,
            self::RATIONALE_SAFE_CROSSWALK_TARGET,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedTradeoffCodes(): array
    {
        return [
            self::TRADEOFF_HIGHER_ENTRY_EDUCATION_REQUIRED,
            self::TRADEOFF_HIGHER_WORK_EXPERIENCE_REQUIRED,
            self::TRADEOFF_HIGHER_TRAINING_REQUIRED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedDeltaKeys(): array
    {
        return [
            self::DELTA_ENTRY_EDUCATION,
            self::DELTA_WORK_EXPERIENCE,
            self::DELTA_TRAINING,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedDeltaDirections(): array
    {
        return [
            self::DELTA_DIRECTION_SAME,
            self::DELTA_DIRECTION_HIGHER,
            self::DELTA_DIRECTION_LOWER,
        ];
    }
}
