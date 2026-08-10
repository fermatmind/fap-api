<?php

declare(strict_types=1);

namespace Tests\Sre;

use JsonException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GithubActionsLifecycleInventoryTest extends TestCase
{
    /** @throws JsonException */
    #[Test]
    public function every_workflow_has_exactly_one_lifecycle_classification(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $inventory = json_decode(
            (string) file_get_contents($repoRoot.'/docs/operations/generated/github-actions-lifecycle.v1.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $actual = array_map(
            static fn (string $path): string => '.github/workflows/'.basename($path),
            glob($repoRoot.'/.github/workflows/*.yml') ?: [],
        );
        sort($actual);

        $active = array_column($inventory['active_workflows'], 'path');
        $retired = array_column($inventory['retired_workflows'], 'path');
        $classified = array_merge($active, $retired);
        sort($classified);

        self::assertSame($actual, $classified);
        self::assertSame($classified, array_values(array_unique($classified)));
        self::assertSame(count($actual), $inventory['workflow_file_count']);
    }

    /** @throws JsonException */
    #[Test]
    public function active_set_is_bounded_and_temporary_controls_are_explicit(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $inventory = json_decode(
            (string) file_get_contents($repoRoot.'/docs/operations/generated/github-actions-lifecycle.v1.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $active = $inventory['active_workflows'];
        self::assertLessThanOrEqual(14, count($active));
        self::assertSame(count($active), $inventory['phase_1_active_count']);
        self::assertSame(10, $inventory['target_active_count_after_convergence']);

        $temporary = array_values(array_filter(
            $active,
            static fn (array $entry): bool => $entry['lifecycle'] === 'temporary_project',
        ));
        self::assertCount(4, $temporary);
        foreach ($temporary as $entry) {
            self::assertStringContainsString('Active ', $entry['reason']);
            self::assertFileExists($repoRoot.'/'.$entry['path']);
        }
    }
}
