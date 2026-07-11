<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ImmutableGithubActionsTest extends TestCase
{
    public function test_every_external_workflow_action_uses_an_immutable_commit_sha(): void
    {
        $repoRoot = dirname(base_path());
        $workflowPaths = array_merge(
            glob($repoRoot.'/.github/workflows/*.yml') ?: [],
            glob($repoRoot.'/.github/workflows/*.yaml') ?: [],
        );
        sort($workflowPaths);

        $violations = [];
        foreach ($workflowPaths as $workflowPath) {
            $source = file_get_contents($workflowPath);
            $this->assertIsString($source, $workflowPath);
            $relative = ltrim(str_replace($repoRoot, '', $workflowPath), DIRECTORY_SEPARATOR);
            $violations = array_merge($violations, $this->immutableUsesViolations($source, $relative));
        }

        $this->assertNotEmpty($workflowPaths);
        $this->assertSame(
            [],
            $violations,
            "External GitHub Actions must use a 40-hex commit SHA and retain a release-tag comment.\n".implode("\n", $violations)
        );
    }

    #[DataProvider('usesFixtureProvider')]
    public function test_scanner_rejects_mutable_refs_and_accepts_local_or_pinned_actions(
        string $source,
        array $expectedViolations,
    ): void {
        $this->assertSame($expectedViolations, $this->immutableUsesViolations($source, 'fixture.yml'));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function usesFixtureProvider(): iterable
    {
        yield 'mutable major tag' => [
            "steps:\n  - uses: actions/checkout@v6\n",
            ['fixture.yml:2 => actions/checkout@v6 (expected immutable 40-hex SHA)'],
        ];

        yield 'branch ref' => [
            "steps:\n  - uses: owner/action@main\n",
            ['fixture.yml:2 => owner/action@main (expected immutable 40-hex SHA)'],
        ];

        yield 'missing release comment' => [
            "steps:\n  - uses: actions/checkout@df4cb1c069e1874edd31b4311f1884172cec0e10\n",
            ['fixture.yml:2 => actions/checkout@df4cb1c069e1874edd31b4311f1884172cec0e10 (missing release-tag comment)'],
        ];

        yield 'local action' => [
            "steps:\n  - uses: ./.github/actions/local-check\n",
            [],
        ];

        yield 'immutable action' => [
            "steps:\n  - uses: actions/checkout@df4cb1c069e1874edd31b4311f1884172cec0e10 # v6\n",
            [],
        ];
    }

    /**
     * @return list<string>
     */
    private function immutableUsesViolations(string $source, string $sourceName): array
    {
        $violations = [];
        $lines = preg_split('/\R/', $source) ?: [];

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(?:-\s*)?uses:\s*([^\s#]+)(?:\s+#\s*(\S.*))?$/', $line, $match) !== 1) {
                continue;
            }

            $uses = $match[1];
            if (str_starts_with($uses, './')) {
                continue;
            }

            if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.\/-]+@[a-f0-9]{40}$/', $uses) !== 1) {
                $violations[] = sprintf(
                    '%s:%d => %s (expected immutable 40-hex SHA)',
                    $sourceName,
                    $index + 1,
                    $uses,
                );

                continue;
            }

            $releaseComment = trim((string) ($match[2] ?? ''));
            if (preg_match('/^v[0-9]+(?:\.[0-9]+){0,2}\b/', $releaseComment) !== 1) {
                $violations[] = sprintf(
                    '%s:%d => %s (missing release-tag comment)',
                    $sourceName,
                    $index + 1,
                    $uses,
                );
            }
        }

        sort($violations);

        return $violations;
    }
}
