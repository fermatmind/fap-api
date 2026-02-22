<?php

declare(strict_types=1);

namespace Tests\Feature\Sds20;

use Tests\Feature\Sds20\Concerns\BuildsSds20ScorerInput;
use Tests\TestCase;

final class Sds20DurationSpoofDoesNotChangeQualityTest extends TestCase
{
    use BuildsSds20ScorerInput;

    public function test_duration_spoof_does_not_change_quality_when_server_duration_exists(): void
    {
        $baseCtx = [
            'started_at' => '2026-02-22T00:00:00Z',
            'submitted_at' => '2026-02-22T00:05:15Z',
            'server_duration_seconds' => 315,
        ];

        $fastClient = $this->scoreSds([], $baseCtx + ['duration_ms' => 10]);
        $slowClient = $this->scoreSds([], $baseCtx + ['duration_ms' => 9_999_999]);

        $this->assertSame(315, (int) data_get($fastClient, 'quality.completion_time_seconds', 0));
        $this->assertSame(315, (int) data_get($slowClient, 'quality.completion_time_seconds', 0));
        $this->assertSame(
            (string) data_get($fastClient, 'quality.level', ''),
            (string) data_get($slowClient, 'quality.level', '')
        );
        $this->assertSame(
            (array) data_get($fastClient, 'quality.flags', []),
            (array) data_get($slowClient, 'quality.flags', [])
        );
        $this->assertSame(
            (bool) data_get($fastClient, 'quality.crisis_alert', false),
            (bool) data_get($slowClient, 'quality.crisis_alert', false)
        );
        $this->assertSame(
            (int) data_get($fastClient, 'scores.global.index_score', 0),
            (int) data_get($slowClient, 'scores.global.index_score', 0)
        );
    }
}
