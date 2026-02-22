<?php

declare(strict_types=1);

namespace Tests\Feature\ClinicalCombo68;

use Tests\Feature\ClinicalCombo68\Concerns\BuildsClinicalComboScorerInput;
use Tests\TestCase;

final class ClinicalComboScoreSmokeTest extends TestCase
{
    use BuildsClinicalComboScorerInput;

    public function test_score_smoke_returns_full_dto_contract(): void
    {
        $dto = $this->scoreClinical();

        $this->assertSame('CLINICAL_COMBO_68', (string) ($dto['scale_code'] ?? ''));
        $this->assertSame('v1.0_2026', (string) ($dto['engine_version'] ?? ''));
        $this->assertIsArray($dto['quality'] ?? null);
        $this->assertIsArray($dto['scores'] ?? null);
        $this->assertIsArray($dto['report_tags'] ?? null);

        $scores = (array) ($dto['scores'] ?? []);
        foreach (['depression', 'anxiety', 'stress', 'resilience', 'perfectionism', 'ocd'] as $dim) {
            $this->assertArrayHasKey($dim, $scores);
            $this->assertArrayHasKey('raw', (array) $scores[$dim]);
            $this->assertArrayHasKey('t_score', (array) $scores[$dim]);
            $this->assertArrayHasKey('level', (array) $scores[$dim]);
        }

        $this->assertIsArray(data_get($scores, 'perfectionism.sub_scores'));
        $this->assertIsArray($dto['version_snapshot'] ?? null);
    }
}

