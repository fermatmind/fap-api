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
        $service = (string) file_get_contents(dirname(__DIR__, 2).'/app/Services/Cms/MbtiIndex52ProjectionRepairService.php');

        self::assertStringContainsString('name: MBTI INDEX52 Projection Repair Preflight', $workflow);
        self::assertStringContainsString('environment: production', $workflow);
        self::assertStringContainsString('test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"', $workflow);
        self::assertSame(3, substr_count(
            $workflow,
            'test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"',
        ));
        self::assertStringContainsString('Revalidate latest main after production read', $workflow);
        self::assertStringContainsString('EXPECTED_ACTIVE_REVISION', $workflow);
        self::assertStringContainsString('EXPECTED_CONTROL_PLANE_SHA=$q_control', $workflow);
        self::assertStringContainsString('StreamedMbtiIndex52', $workflow);
        self::assertStringContainsString('$isolatedSource', $workflow);
        self::assertStringContainsString('ead55f273b755ac4d93f5d71246c10b374bb4e936fb3bbe1128872599a5c434b', $workflow);
        self::assertStringContainsString('1020c5b3cc9a2914f18d30805b9e4b326e3acbdb811eff5dcd52ff3e47b578ef', $workflow);
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
        self::assertStringContainsString('StreamedMbtiIndex52', $runner);
        self::assertStringNotContainsString('class_exists(', $runner);
        self::assertStringContainsString('writes_committed', $runner);
        self::assertStringContainsString('false', $runner);
        self::assertStringNotContainsString('->save(', $runner);
        self::assertStringNotContainsString('DB::transaction', $runner);
        self::assertStringNotContainsString('mbti_index52_control_plane_sha', $service);
        self::assertStringContainsString('assertReleaseBinding($expectedControlPlaneSha, $expectedActiveRevision)', $service);
        self::assertStringContainsString('runtimeActiveRevision()', $service);
    }
}
