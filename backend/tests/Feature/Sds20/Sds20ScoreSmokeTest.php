<?php

declare(strict_types=1);

namespace Tests\Feature\Sds20;

use Tests\Feature\Sds20\Concerns\BuildsSds20ScorerInput;
use Tests\TestCase;

final class Sds20ScoreSmokeTest extends TestCase
{
    use BuildsSds20ScorerInput;

    public function test_score_smoke_returns_full_dto_contract(): void
    {
        $dto = $this->scoreSds();

        $this->assertSame('SDS_20', (string) ($dto['scale_code'] ?? ''));
        $this->assertSame('v2.0_Factor_Logic', (string) ($dto['engine_version'] ?? ''));

        $this->assertIsArray($dto['quality'] ?? null);
        $this->assertIsArray($dto['scores'] ?? null);
        $this->assertIsArray(data_get($dto, 'scores.global'));
        $this->assertIsArray(data_get($dto, 'scores.factors'));
        $this->assertIsArray($dto['report_tags'] ?? null);

        foreach (['psycho_affective', 'somatic', 'psychomotor', 'cognitive'] as $factor) {
            $this->assertArrayHasKey($factor, (array) data_get($dto, 'scores.factors', []));
            $this->assertArrayHasKey('score', (array) data_get($dto, 'scores.factors.'.$factor, []));
            $this->assertArrayHasKey('max', (array) data_get($dto, 'scores.factors.'.$factor, []));
            $this->assertArrayHasKey('severity', (array) data_get($dto, 'scores.factors.'.$factor, []));
        }

        $this->assertArrayHasKey('version_snapshot', $dto);
    }
}
