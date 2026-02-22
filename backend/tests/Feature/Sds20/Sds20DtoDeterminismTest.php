<?php

declare(strict_types=1);

namespace Tests\Feature\Sds20;

use Tests\Feature\Sds20\Concerns\BuildsSds20ScorerInput;
use Tests\TestCase;

final class Sds20DtoDeterminismTest extends TestCase
{
    use BuildsSds20ScorerInput;

    public function test_same_input_produces_identical_dto(): void
    {
        $answers = [];
        $codes = ['A', 'B', 'C', 'D'];
        for ($i = 1; $i <= 20; $i++) {
            $answers[$i] = $codes[($i - 1) % 4];
        }

        $ctx = [
            'duration_ms' => 98000,
            'started_at' => '2026-02-22T12:00:00+08:00',
            'submitted_at' => '2026-02-22T12:01:38+08:00',
        ];

        $first = $this->scoreSdsFromAnswers($answers, $ctx);
        $second = $this->scoreSdsFromAnswers($answers, $ctx);

        $this->assertSame(
            json_encode($first, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($second, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
