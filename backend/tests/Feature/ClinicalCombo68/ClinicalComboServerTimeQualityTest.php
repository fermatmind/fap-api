<?php

declare(strict_types=1);

namespace Tests\Feature\ClinicalCombo68;

use Tests\Feature\ClinicalCombo68\Concerns\BuildsClinicalComboScorerInput;
use Tests\TestCase;

final class ClinicalComboServerTimeQualityTest extends TestCase
{
    use BuildsClinicalComboScorerInput;

    public function test_quality_completion_seconds_uses_server_side_time_diff(): void
    {
        $ctxA = [
            'started_at' => '2026-02-22T00:00:00Z',
            'submitted_at' => '2026-02-22T00:05:15Z',
            'duration_ms' => 1,
        ];
        $ctxB = [
            'started_at' => '2026-02-22T00:00:00Z',
            'submitted_at' => '2026-02-22T00:05:15Z',
            'duration_ms' => 999999999,
        ];

        $a = $this->scoreClinical([], $ctxA);
        $b = $this->scoreClinical([], $ctxB);

        $this->assertSame(315, (int) data_get($a, 'quality.completion_time_seconds'));
        $this->assertSame(315, (int) data_get($b, 'quality.completion_time_seconds'));
    }
}

