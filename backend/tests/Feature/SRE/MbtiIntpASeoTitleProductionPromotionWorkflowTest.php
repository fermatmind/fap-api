<?php

declare(strict_types=1);

namespace Tests\Feature\SRE;

use Tests\TestCase;

final class MbtiIntpASeoTitleProductionPromotionWorkflowTest extends TestCase
{
    public function test_retired_manual_workflow_cannot_be_reintroduced(): void
    {
        $this->assertFileDoesNotExist(base_path('../.github/workflows/mbti-intp-a-seo-title-production-promotion.yml'));
    }
}
