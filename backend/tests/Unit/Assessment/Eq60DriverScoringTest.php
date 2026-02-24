<?php

declare(strict_types=1);

namespace Tests\Unit\Assessment;

use App\Services\Assessment\Drivers\Eq60Driver;
use Tests\TestCase;

final class Eq60DriverScoringTest extends TestCase
{
    public function test_reverse_scoring_moves_in_opposite_direction_vs_forward_item(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);

        /** @var Eq60Driver $driver */
        $driver = app(Eq60Driver::class);

        $baseline = $driver->score($this->buildAnswers('A'), [], [
            'pack_id' => 'EQ_60',
            'dir_version' => 'v1',
        ]);

        $forwardHigher = $driver->score($this->buildAnswers('A', [1 => 'E']), [], [
            'pack_id' => 'EQ_60',
            'dir_version' => 'v1',
        ]);

        $reverseHigher = $driver->score($this->buildAnswers('A', [5 => 'E']), [], [
            'pack_id' => 'EQ_60',
            'dir_version' => 'v1',
        ]);

        $baselineTotal = (int) $baseline->finalScore;
        $forwardHigherTotal = (int) $forwardHigher->finalScore;
        $reverseHigherTotal = (int) $reverseHigher->finalScore;

        $this->assertSame(124, $baselineTotal);
        $this->assertSame($baselineTotal + 4, $forwardHigherTotal);
        $this->assertSame($baselineTotal - 4, $reverseHigherTotal);

        $baselineSa = (int) data_get($baseline->breakdownJson, 'dim_scores.SA', 0);
        $this->assertSame(31, $baselineSa);
        $this->assertSame($baselineSa + 4, (int) data_get($forwardHigher->breakdownJson, 'dim_scores.SA', 0));
        $this->assertSame($baselineSa - 4, (int) data_get($reverseHigher->breakdownJson, 'dim_scores.SA', 0));
    }

    /**
     * @param array<int,string> $overrides
     * @return list<array{question_id:string,code:string}>
     */
    private function buildAnswers(string $defaultCode, array $overrides = []): array
    {
        $answers = [];
        for ($i = 1; $i <= 60; $i++) {
            $answers[] = [
                'question_id' => (string) $i,
                'code' => strtoupper(trim((string) ($overrides[$i] ?? $defaultCode))),
            ];
        }

        return $answers;
    }
}
