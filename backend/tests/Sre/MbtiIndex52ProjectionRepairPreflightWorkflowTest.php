<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\TestCase;

final class MbtiIndex52ProjectionRepairPreflightWorkflowTest extends TestCase
{
    public function test_workflow_is_exact_latest_main_read_only_and_streamed(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 3).'/.github/workflows/mbti-index52-projection-repair-preflight.yml');
        $runner = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/seo/mbti_index52_projection_repair_streamed_preflight.php');

        self::assertStringContainsString('name: MBTI INDEX52 Projection Repair Preflight', $workflow);
        self::assertStringContainsString('environment: production', $workflow);
        self::assertStringContainsString('test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"', $workflow);
        self::assertStringContainsString('EXPECTED_ACTIVE_REVISION', $workflow);
        self::assertStringContainsString('EXPECTED_CONTROL_PLANE_SHA=$q_control', $workflow);
        self::assertStringContainsString('ea4801ff5af8d0d7279137d2f4ebbaa263bdc4a959f5c880ba023dd2d0fed641', $workflow);
        self::assertStringContainsString('59103fbf6eae4effc81fec844bbbf8d3ae8bb7100513407821074787a641253c', $workflow);
        self::assertStringContainsString('< "$RUNNER_TEMP/mbti-index52-preflight.php"', $workflow);
        self::assertStringContainsString('remote_file_write_count: 0', $workflow);
        self::assertStringContainsString('production_write_execution: false', $workflow);
        self::assertStringNotContainsString('scp ', $workflow);
        self::assertStringNotContainsString('rsync ', $workflow);
        self::assertStringNotContainsString('--execute', $workflow);
        self::assertStringNotContainsString('method: POST', $workflow);
        self::assertStringNotContainsString('workflow_dispatch:', str_replace("  workflow_dispatch:\n", '', $workflow));

        self::assertStringContainsString("file_exists(\$deployPath.'/.dep/deploy.lock')", $runner);
        self::assertStringContainsString('$expectedControlPlaneSha', $runner);
        self::assertStringContainsString('$expectedActiveRevision', $runner);
        self::assertStringContainsString('writes_committed', $runner);
        self::assertStringContainsString('false', $runner);
        self::assertStringNotContainsString('->save(', $runner);
        self::assertStringNotContainsString('DB::transaction', $runner);
    }
}
