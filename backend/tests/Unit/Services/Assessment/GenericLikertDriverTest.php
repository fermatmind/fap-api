<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Assessment;

use App\Services\Assessment\Drivers\GenericLikertDriver;
use Tests\TestCase;

final class GenericLikertDriverTest extends TestCase
{
    public function test_items_map_supports_reverse_and_weight_object_rule(): void
    {
        $driver = new GenericLikertDriver();

        $spec = [
            'options_score_map' => ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4],
            'default_value' => 0,
            'dimensions' => [
                'D1' => [
                    'items_map' => [
                        'Q1' => ['weight' => 2, 'reverse' => true],
                    ],
                ],
            ],
        ];

        $answers = [
            ['qid' => 'Q1', 'code' => 'A'],
        ];

        $result = $driver->score($answers, $spec, []);
        $items = $result->breakdownJson['items'] ?? [];

        $this->assertSame(8.0, $result->rawScore);
        $this->assertSame(8.0, $result->finalScore);
        $this->assertCount(1, $items);
        $this->assertSame(1.0, (float) ($items[0]['raw_value'] ?? 0.0));
        $this->assertSame(4.0, (float) ($items[0]['effective_value'] ?? 0.0));
        $this->assertTrue((bool) ($items[0]['reverse'] ?? false));
        $this->assertSame(8.0, (float) ($items[0]['weighted_value'] ?? 0.0));
        $this->assertSame(8.0, (float) ($result->breakdownJson['dim_scores']['D1'] ?? 0.0));
        $this->assertSame(8.0, (float) ($result->breakdownJson['dimensions']['D1']['score'] ?? 0.0));
    }

    public function test_items_map_numeric_rule_is_weight_only(): void
    {
        $driver = new GenericLikertDriver();

        $spec = [
            'option_scores' => ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4],
            'default_value' => 0,
            'dimensions' => [
                'D1' => [
                    'items_map' => [
                        'Q2' => 3,
                    ],
                ],
            ],
        ];

        $answers = [
            ['question_id' => 'Q2', 'code' => 'B'],
        ];

        $result = $driver->score($answers, $spec, []);
        $items = $result->breakdownJson['items'] ?? [];

        $this->assertSame(6.0, $result->rawScore);
        $this->assertSame(6.0, $result->finalScore);
        $this->assertCount(1, $items);
        $this->assertSame(2.0, (float) ($items[0]['raw_value'] ?? 0.0));
        $this->assertSame(2.0, (float) ($items[0]['effective_value'] ?? 0.0));
        $this->assertFalse((bool) ($items[0]['reverse'] ?? true));
        $this->assertSame(6.0, (float) ($items[0]['weighted_value'] ?? 0.0));
        $this->assertSame(6.0, (float) ($result->breakdownJson['dim_scores']['D1'] ?? 0.0));
    }
}
