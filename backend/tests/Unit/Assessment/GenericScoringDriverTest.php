<?php

namespace Tests\Unit\Assessment;

use App\Services\Assessment\Drivers\GenericScoringDriver;
use Tests\TestCase;

class GenericScoringDriverTest extends TestCase
{
    public function test_scores_mbti_dichotomy_with_type_rules(): void
    {
        $driver = new GenericScoringDriver();

        $spec = [
            'dimensions' => [
                'EI' => ['p1' => 'E', 'p2' => 'I'],
                'SN' => ['p1' => 'S', 'p2' => 'N'],
                'TF' => ['p1' => 'T', 'p2' => 'F'],
                'JP' => ['p1' => 'J', 'p2' => 'P'],
                'AT' => ['p1' => 'A', 'p2' => 'T'],
            ],
            'type_rules' => [
                'base_axes' => ['EI', 'SN', 'TF', 'JP'],
                'suffix_axis' => 'AT',
                'delimiter' => '-',
            ],
            'pci_levels' => [
                ['code' => 'slight', 'min' => 0, 'max' => 14],
                ['code' => 'moderate', 'min' => 15, 'max' => 30],
                ['code' => 'clear', 'min' => 31, 'max' => 50],
                ['code' => 'very_clear', 'min' => 51, 'max' => 100],
            ],
        ];

        $questions = [
            'items' => [
                $this->mbtiQuestion('Q1', 'EI', 'E'),
                $this->mbtiQuestion('Q2', 'SN', 'S'),
                $this->mbtiQuestion('Q3', 'TF', 'T'),
                $this->mbtiQuestion('Q4', 'JP', 'J'),
                $this->mbtiQuestion('Q5', 'AT', 'A'),
            ],
        ];

        $answers = [
            ['question_id' => 'Q1', 'code' => 'A'],
            ['question_id' => 'Q2', 'code' => 'A'],
            ['question_id' => 'Q3', 'code' => 'A'],
            ['question_id' => 'Q4', 'code' => 'A'],
            ['question_id' => 'Q5', 'code' => 'E'],
        ];

        $result = $driver->score($answers, $spec, ['questions' => $questions]);
        $axisScores = $result->axisScoresJson ?? [];

        $this->assertSame('ESTJ-T', $result->typeCode);
        $this->assertSame(100, $axisScores['scores_pct']['EI'] ?? null);
        $this->assertSame(0, $axisScores['scores_pct']['AT'] ?? null);
        $this->assertSame('T', $axisScores['winning_poles']['AT'] ?? null);
    }

    public function test_scores_trait_dimensions(): void
    {
        $driver = new GenericScoringDriver();

        $spec = [
            'dimensions' => [
                'O' => ['label' => 'Openness'],
            ],
        ];

        $questions = [
            'items' => [
                [
                    'question_id' => 'O1',
                    'dimension' => 'O',
                    'direction' => 1,
                    'options' => [
                        ['code' => '1', 'score' => 1],
                        ['code' => '2', 'score' => 2],
                        ['code' => '3', 'score' => 3],
                        ['code' => '4', 'score' => 4],
                        ['code' => '5', 'score' => 5],
                    ],
                ],
            ],
        ];

        $answers = [
            ['question_id' => 'O1', 'code' => '5'],
        ];

        $result = $driver->score($answers, $spec, ['questions' => $questions]);
        $axisScores = $result->axisScoresJson ?? [];

        $this->assertSame(100, $axisScores['scores_pct']['O'] ?? null);
        $this->assertSame(100, $axisScores['trait_scores']['O']['percent'] ?? null);
    }

    private function mbtiQuestion(string $qid, string $dimension, string $keyPole): array
    {
        return [
            'question_id' => $qid,
            'dimension' => $dimension,
            'key_pole' => $keyPole,
            'direction' => 1,
            'options' => [
                ['code' => 'A', 'score' => 2],
                ['code' => 'B', 'score' => 1],
                ['code' => 'C', 'score' => 0],
                ['code' => 'D', 'score' => -1],
                ['code' => 'E', 'score' => -2],
            ],
        ];
    }
}
