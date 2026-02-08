<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Assessment\Drivers;

use App\Services\Assessment\Drivers\GenericLikertDriver;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class GenericLikertDriverReverseAndWeightTest extends TestCase
{
    public function test_reverse_scoring_maps_raw_five_to_one_on_five_point_scale(): void
    {
        $driver = new GenericLikertDriver();

        $spec = [
            'scale_code' => 'MBTI',
            'options_score_map' => ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5],
            'dimensions' => [
                'D1' => [
                    'items_map' => [
                        'Q1' => ['weight' => 1.0, 'reverse' => true],
                    ],
                ],
            ],
        ];

        $answers = [
            ['question_id' => 'Q1', 'code' => 'E'],
        ];

        $result = $driver->score($answers, $spec, []);

        $this->assertSame(1.0, $result->rawScore);
        $this->assertSame(1.0, $result->finalScore);
        $this->assertSame(1.0, (float) ($result->breakdownJson['dim_scores']['D1'] ?? 0.0));
        $this->assertSame(1.0, (float) ($result->breakdownJson['items'][0]['effective_value'] ?? 0.0));
    }

    public function test_weighting_multiplies_dimension_score(): void
    {
        $driver = new GenericLikertDriver();

        $spec = [
            'scale_code' => 'MBTI',
            'options_score_map' => ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5],
            'dimensions' => [
                'D1' => [
                    'items_map' => [
                        'Q2' => ['weight' => 2.0, 'reverse' => false],
                    ],
                ],
            ],
        ];

        $answers = [
            ['question_id' => 'Q2', 'code' => 'B'],
        ];

        $result = $driver->score($answers, $spec, []);

        $this->assertSame(4.0, $result->rawScore);
        $this->assertSame(4.0, $result->finalScore);
        $this->assertSame(4.0, (float) ($result->breakdownJson['dim_scores']['D1'] ?? 0.0));
        $this->assertSame(4.0, (float) ($result->breakdownJson['items'][0]['weighted_value'] ?? 0.0));
    }

    public function test_invalid_answer_scores_zero_and_logs_warning_without_sensitive_fields(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context): bool {
                $this->assertSame('scoring_invalid_answer', $message);
                $this->assertIsArray($context);
                $this->assertSame('scoring_invalid_answer', $context['event'] ?? null);
                $this->assertSame('MBTI', $context['scale_code'] ?? null);
                $this->assertSame('Q3', $context['item_id'] ?? null);
                $this->assertSame('D1', $context['dimension'] ?? null);
                $this->assertArrayNotHasKey('answer', $context);
                $this->assertArrayNotHasKey('answers', $context);
                $this->assertArrayNotHasKey('option_scores', $context);
                $this->assertArrayNotHasKey('payload', $context);

                return true;
            });

        $driver = new GenericLikertDriver();

        $spec = [
            'scale_code' => 'MBTI',
            'options_score_map' => ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5],
            'dimensions' => [
                'D1' => [
                    'items_map' => [
                        'Q3' => ['weight' => 2.0, 'reverse' => true],
                    ],
                ],
            ],
        ];

        $answers = [
            ['question_id' => 'Q3', 'code' => 'Z'],
        ];

        $result = $driver->score($answers, $spec, []);

        $this->assertSame(0.0, $result->rawScore);
        $this->assertSame(0.0, $result->finalScore);
        $this->assertSame(0.0, (float) ($result->breakdownJson['dim_scores']['D1'] ?? 0.0));
        $this->assertSame(0.0, (float) ($result->breakdownJson['items'][0]['raw_value'] ?? 0.0));
        $this->assertSame(0.0, (float) ($result->breakdownJson['items'][0]['weighted_value'] ?? 0.0));
    }
}
