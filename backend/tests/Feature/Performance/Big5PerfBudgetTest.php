<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use Tests\TestCase;

final class Big5PerfBudgetTest extends TestCase
{
    public function test_big5_perf_config_has_required_budget_contract(): void
    {
        $cfg = config('big5_perf');
        $this->assertIsArray($cfg);

        $budget = $cfg['budget_ms'] ?? null;
        $this->assertIsArray($budget);
        foreach (['questions_p95_ms', 'submit_p95_ms', 'report_free_p95_ms', 'report_full_p95_ms'] as $key) {
            $this->assertArrayHasKey($key, $budget);
            $this->assertGreaterThan(0, (int) $budget[$key]);
        }

        $this->assertArrayHasKey('error_rate_max', $cfg);
        $this->assertGreaterThanOrEqual(0.0, (float) $cfg['error_rate_max']);
        $this->assertLessThan(1.0, (float) $cfg['error_rate_max']);

        $smoke = $cfg['smoke'] ?? null;
        $this->assertIsArray($smoke);
        $this->assertSame('BIG5_OCEAN', (string) ($smoke['target_scale'] ?? ''));
        $this->assertSame('MBTI', (string) ($smoke['fallback_scale'] ?? ''));
        $this->assertContains('questions', (array) ($smoke['required_metrics'] ?? []));
        $this->assertContains('report_full', (array) ($smoke['optional_metrics'] ?? []));
    }

    public function test_perf_scripts_exist_and_are_executable(): void
    {
        $smoke = base_path('scripts/loadtest/big5_smoke.sh');
        $verify = base_path('scripts/ci/verify_big5_perf.sh');

        $this->assertFileExists($smoke);
        $this->assertFileExists($verify);
        $this->assertTrue(is_executable($smoke));
        $this->assertTrue(is_executable($verify));
    }
}
