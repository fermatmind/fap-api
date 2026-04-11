<?php

declare(strict_types=1);

namespace App\Domain\Career\Transition;

final class TransitionPathPayload
{
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
            if ($normalized === '') {
                continue;
            }

            $steps[] = $normalized;
        }

        return new self(array_values($steps));
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
}
