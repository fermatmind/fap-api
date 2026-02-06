<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Assessment\Drivers;

use App\Services\Assessment\Drivers\GenericLikertDriver;
use Tests\TestCase;

final class GenericLikertDriverTest extends TestCase
{
    public function test_reverse_and_weight_are_applied(): void
    {
        $driver = new GenericLikertDriver();

        $spec = [
            'options_score_map' => ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5],
            'default_value' => 3,
            'dimensions' => [
                'D1' => [
                    'items' => [
                        'Q1' => ['weight' => 1, 'reverse' => false],
                        'Q2' => ['weight' => 2, 'reverse' => true],
                        'Q3' => -1,
                    ],
                ],
            ],
        ];

        $answers = [
            ['qid' => 'Q1', 'code' => 'A'],
            ['qid' => 'Q2', 'code' => 'A'],
            ['qid' => 'Q3', 'code' => 'E'],
        ];

        $res = $driver->score($answers, $spec, []);

        $this->assertSame(12.0, $res->rawScore);
        $this->assertSame(12.0, $res->finalScore);

        $items = $res->breakdownJson['items'] ?? [];
        $this->assertCount(3, $items);
        $this->assertTrue((bool) ($items[1]['reverse'] ?? false));
        $this->assertSame(5.0, (float) ($items[1]['effective_value'] ?? 0.0));
    }
}
