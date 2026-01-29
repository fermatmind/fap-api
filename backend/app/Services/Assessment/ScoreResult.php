<?php

namespace App\Services\Assessment;

final class ScoreResult
{
    public function __construct(
        public float $rawScore,
        public float $finalScore,
        public array $breakdownJson = [],
        public ?string $typeCode = null,
        public ?array $axisScoresJson = null,
        public ?array $normedJson = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'raw_score' => $this->rawScore,
            'final_score' => $this->finalScore,
            'breakdown_json' => $this->breakdownJson,
            'type_code' => $this->typeCode,
            'axis_scores_json' => $this->axisScoresJson,
            'normed_json' => $this->normedJson,
        ];
    }
}
