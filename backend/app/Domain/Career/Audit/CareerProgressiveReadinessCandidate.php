<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class CareerProgressiveReadinessCandidate
{
    /**
     * @param  list<string>  $locales
     * @param  list<string>  $reasons
     */
    public function __construct(
        public readonly string $slug,
        public readonly int $sourcePosition,
        public readonly ?int $sourceRowNumber,
        public readonly bool $selected,
        public readonly bool $baselineExcluded,
        public readonly bool $sourceReady,
        public readonly array $locales,
        public readonly array $reasons = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'source_position' => $this->sourcePosition,
            'source_row_number' => $this->sourceRowNumber,
            'selected' => $this->selected,
            'baseline_excluded' => $this->baselineExcluded,
            'source_ready' => $this->sourceReady,
            'locales' => $this->locales,
            'reasons' => $this->reasons,
        ];
    }
}
