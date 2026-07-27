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
        self::assertStringContainsString('group: deploy-${{ github.repository }}-production', $workflow);
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
        self::assertStringContainsString('__AT_SOURCE_PRESTATE_JSON_B64__', $workflow);
        self::assertStringContainsString('mbti-index52-at-source-prestate-2026-07-27.json', $workflow);
        self::assertStringContainsString('09ccf33ba462b53da57087667e948069f8b22d7a4f48fa4134a357d71716d95f', $workflow);
        self::assertStringContainsString('e3d256d930135bd228055b40a4bf9c6441a35e3e89252f08028065e490e8b402', $workflow);
        self::assertStringContainsString('< "$RUNNER_TEMP/mbti-index52-preflight.php"', $workflow);
        self::assertStringContainsString('remote_file_write_count: 0', $workflow);
        self::assertStringContainsString('production_write_execution: false', $workflow);
        self::assertStringNotContainsString('scp ', $workflow);
        self::assertStringNotContainsString('rsync ', $workflow);
        self::assertStringNotContainsString('--execute', $workflow);
        self::assertStringNotContainsString('method: POST', $workflow);
        self::assertStringNotContainsString('workflow_dispatch:', str_replace("  workflow_dispatch:\n", '', $workflow));

        self::assertStringContainsString("file_exists(\$deployPath.'/.dep/deploy.lock')", $runner);
        self::assertStringContainsString('$requiredRuntimeDirectories', $runner);
        self::assertStringContainsString("storage/framework/testing'", $runner);
        self::assertStringContainsString("bootstrap/cache'", $runner);
        self::assertStringContainsString('Read-only Laravel bootstrap directory precondition mismatch.', $runner);
        self::assertStringContainsString('if (! is_dir($directory))', $runner);
        self::assertStringContainsString('$packageManifestPath', $runner);
        self::assertStringContainsString('$serviceManifestPath', $runner);
        self::assertStringContainsString("vendor/composer/installed.php'", $runner);
        self::assertStringContainsString("composer.lock'", $runner);
        self::assertStringContainsString('Read-only Laravel bootstrap cache freshness mismatch.', $runner);
        self::assertStringContainsString("array_keys(\$serviceManifest) !== ['providers', 'eager', 'deferred', 'when']", $runner);
        self::assertStringContainsString('$expectedControlPlaneSha', $runner);
        self::assertStringContainsString('$expectedActiveRevision', $runner);
        self::assertStringContainsString('StreamedMbtiIndex52', $runner);
        self::assertStringContainsString('$atSourcePrestate', $runner);
        self::assertStringContainsString('MbtiIndex52ProjectionRepairPackage($atSourcePrestate)', $runner);
        self::assertStringNotContainsString('class_exists(', $runner);
        self::assertStringContainsString('writes_committed', $runner);
        self::assertStringContainsString('false', $runner);
        self::assertStringNotContainsString('->save(', $runner);
        self::assertStringNotContainsString('DB::transaction', $runner);
        self::assertStringNotContainsString('mbti_index52_control_plane_sha', $service);
        self::assertStringContainsString('assertReleaseBinding($expectedControlPlaneSha, $expectedActiveRevision)', $service);
        self::assertStringContainsString('runtimeActiveRevision()', $service);
    }

    public function test_streamed_class_namespace_replacement_matches_both_sources(): void
    {
        foreach ([
            dirname(__DIR__, 2).'/app/Services/Cms/MbtiIndex52ProjectionRepairPackage.php',
            dirname(__DIR__, 2).'/app/Services/Cms/MbtiIndex52ProjectionRepairService.php',
        ] as $path) {
            $source = (string) preg_replace('/^<\?php\s*/', '', (string) file_get_contents($path));
            $isolated = str_replace(
                'namespace App\\Services\\Cms;',
                'namespace App\\Services\\Cms\\StreamedMbtiIndex52;',
                $source,
                $replacementCount,
            );

            self::assertSame(1, $replacementCount, $path);
            self::assertStringContainsString(
                'namespace App\\Services\\Cms\\StreamedMbtiIndex52;',
                $isolated,
            );
        }
    }
}
