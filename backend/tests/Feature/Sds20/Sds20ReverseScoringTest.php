<?php

declare(strict_types=1);

namespace Tests\Feature\Sds20;

use Tests\Feature\Sds20\Concerns\BuildsSds20ScorerInput;
use Tests\TestCase;

final class Sds20ReverseScoringTest extends TestCase
{
    use BuildsSds20ScorerInput;

    public function test_reverse_scoring_changes_raw_total_as_expected(): void
    {
        $dto = $this->scoreSds(); // all A

        $this->assertSame(50, (int) data_get($dto, 'scores.global.raw', -1));
        $this->assertSame(62, (int) data_get($dto, 'scores.global.index_score', -1));
        $this->assertSame('mild_depression', (string) data_get($dto, 'scores.global.clinical_level', ''));
    }
}
