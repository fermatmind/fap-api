<?php

declare(strict_types=1);

namespace Tests\Feature\Sds20;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Sds20\Concerns\BuildsSds20ScorerInput;
use Tests\TestCase;

final class Sds20PercentileNormsTest extends TestCase
{
    use BuildsSds20ScorerInput;
    use RefreshDatabase;

    public function test_percentile_and_norms_are_populated_when_active_norms_exist(): void
    {
        $this->artisan('norms:import --scale=SDS_20 --csv=resources/norms/sds/sds_norm_stats_seed.csv --activate=1')
            ->assertExitCode(0);

        $dto = $this->scoreSdsFromAnswers($this->buildAnswers('B'), [
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'duration_ms' => 98000,
        ]);

        $this->assertIsInt(data_get($dto, 'scores.global.percentile'));
        $this->assertSame('CALIBRATED', (string) data_get($dto, 'norms.status'));
        $this->assertSame('zh-CN_all_18-60', (string) data_get($dto, 'norms.group_id'));
        $this->assertNotSame('', (string) data_get($dto, 'version_snapshot.policy_hash'));
    }
}
