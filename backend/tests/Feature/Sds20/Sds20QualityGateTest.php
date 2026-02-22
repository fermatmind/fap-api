<?php

declare(strict_types=1);

namespace Tests\Feature\Sds20;

use Tests\Feature\Sds20\Concerns\BuildsSds20ScorerInput;
use Tests\TestCase;

final class Sds20QualityGateTest extends TestCase
{
    use BuildsSds20ScorerInput;

    public function test_speeding_sets_quality_to_d_with_flag(): void
    {
        $dto = $this->scoreSds([], ['server_duration_seconds' => 20]);

        $this->assertSame('D', (string) data_get($dto, 'quality.level', ''));
        $this->assertContains('SPEEDING', (array) data_get($dto, 'quality.flags', []));
    }

    public function test_straightlining_sets_quality_to_c_when_not_speeding(): void
    {
        $dto = $this->scoreSds([], ['server_duration_seconds' => 98]); // all A => run=20

        $this->assertSame('C', (string) data_get($dto, 'quality.level', ''));
        $this->assertContains('STRAIGHTLINING', (array) data_get($dto, 'quality.flags', []));
    }
}
