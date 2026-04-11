<?php

declare(strict_types=1);

namespace App\Domain\Career\Transition;

final class TransitionPathPayload
{
    public const STEP_SKILL_OVERLAP = 'skill_overlap';

    public const STEP_TASK_OVERLAP = 'task_overlap';

    public const STEP_TOOL_OVERLAP = 'tool_overlap';

    /**
     * @param  list<string>  $steps
     */
    private function __construct(
        public readonly array $steps,
    ) {}

    public static function from(mixed $payload): self
    {
        if (! is_array($payload)) {
            return new self([]);
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

        return new self(array_values(array_unique($steps)));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->steps === []) {
            return [];
        }

        return [
            'steps' => $this->steps,
        ];
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
}
