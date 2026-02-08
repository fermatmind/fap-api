<?php

declare(strict_types=1);

namespace Tests\Unit\Psychometrics;

use App\Services\Assessment\Drivers\GenericLikertDriver;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class GenericLikertDriverTest extends TestCase
{
    public function test_reverse_and_weight_are_applied_with_nested_rule(): void
    {
        $driver = new GenericLikertDriver();

        $spec = [
            'options_score_map' => ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5],
            'dimensions' => [
                'D1' => [
                    'items_map' => [
                        'Q1' => [
                            'rule' => ['weight' => 2.0, 'reverse' => true],
                        ],
                    ],
                ],
            ],
        ];

        $answers = [
            ['question_id' => 'Q1', 'code' => 'A'],
        ];

        $result = $driver->score($answers, $spec, []);

        $this->assertSame(10.0, $result->rawScore);
        $this->assertSame(10.0, $result->finalScore);
        $this->assertSame(10.0, (float) ($result->breakdownJson['dim_scores']['D1'] ?? 0.0));
        $this->assertSame(5.0, (float) ($result->breakdownJson['items'][0]['effective_value'] ?? 0.0));
        $this->assertSame(10.0, (float) ($result->breakdownJson['items'][0]['weighted_value'] ?? 0.0));
    }

    public function test_invalid_answer_logs_warning_and_scores_zero(): void
    {
        Log::spy();

        $driver = new GenericLikertDriver();

        $spec = [
            'options_score_map' => ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5],
            'dimensions' => [
                'D1' => [
                    'items_map' => [
                        'Q2' => [
                            'rule' => ['weight' => 2.0, 'reverse' => true],
                        ],
                    ],
                ],
            ],
        ];

        $answers = [
            ['question_id' => 'Q2', 'code' => 'Z'],
        ];

        $result = $driver->score($answers, $spec, []);

        $this->assertSame(0.0, $result->rawScore);
        $this->assertSame(0.0, $result->finalScore);
        $this->assertSame(0.0, (float) ($result->breakdownJson['dim_scores']['D1'] ?? 0.0));
        $this->assertSame(0.0, (float) ($result->breakdownJson['items'][0]['weighted_value'] ?? 0.0));

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Invalid answer option', \Mockery::on(function ($context): bool {
                return is_array($context)
                    && ($context['question'] ?? null) === 'Q2'
                    && ($context['answer'] ?? null) === 'Z';
            }));
    }
}
