<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\Support\CareerLegacyCodecFixture;
use Tests\Support\CareerShardedCurrentContractGate;

final class CareerLegacyCurrentSharderTest extends TestCase
{
    private string $repoRoot;

    private string $assetsPath;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();
        $sourceRepository = dirname(__DIR__, 6);
        require_once $sourceRepository.'/.agents/skills/fap-api-career-canonical-builder/scripts/split_legacy_current.php';
        $this->repoRoot = CareerLegacyCodecFixture::createRepository($sourceRepository);
        $this->assetsPath = $this->repoRoot.'/backend/content_assets/career/current/assets.jsonl';
        $this->manifestPath = $this->repoRoot.'/backend/content_assets/career/current/manifest.json';
    }

    protected function tearDown(): void
    {
        $this->deleteTemporaryDirectory($this->repoRoot);
        parent::tearDown();
    }

    public function test_it_splits_the_complete_legacy_current_deterministically_without_repository_writes(): void
    {
        ini_set('memory_limit', '2048M');
        $outputRoot = $this->temporaryDirectory();
        $assetsBefore = hash_file('sha256', $this->assetsPath);
        $manifestBefore = hash_file('sha256', $this->manifestPath);
        $statusBefore = $this->repositoryStatus();
        $sharder = new \CareerLegacyCurrentSharder;
        try {
            $first = $sharder->split($this->repoRoot, $this->assetsPath, $this->manifestPath, $outputRoot);
            $firstFiles = $this->fileDigests($outputRoot);
            $second = $sharder->split($this->repoRoot, $this->assetsPath, $this->manifestPath, $outputRoot);
            $secondFiles = $this->fileDigests($outputRoot);

            self::assertSame($firstFiles, $secondFiles);
            self::assertSame($first, $second);
            self::assertCount(645, $secondFiles);
            self::assertSame(1046, $second['coverage_report']['slugs']);
            self::assertSame(2092, $second['coverage_report']['locales']);
            self::assertSame(20920, $second['coverage_report']['module_rows']);
            self::assertSame(2092, array_values(array_unique($second['coverage_report']['rows_per_module']))[0]);
            self::assertSame(10, $second['coverage_report']['modules_per_slug_locale']);
            self::assertSame(640, $second['coverage_report']['shard_files']);
            self::assertSame(0, $second['coverage_report']['empty_shards']);
            self::assertSame(0, $second['coverage_report']['duplicate']);
            self::assertSame(0, $second['coverage_report']['missing']);
            self::assertSame(['page' => 1045, 'direct' => 1], $second['coverage_report']['legacy_wrapper_counts']);
            self::assertSame(0, $second['field_ownership_report']['unowned_fields']);
            self::assertSame(0, $second['field_ownership_report']['duplicate_fields']);
            self::assertSame(0, $second['field_ownership_report']['missing_fields']);
            self::assertSame(0, $second['field_ownership_report']['lossless_reconstruction_mismatch_count']);
            self::assertSame(0, $second['repository_zero_write_receipt']['repository_writes']);
            self::assertTrue($second['integrity_report']['lossless_reconstruction']);

            $candidateFiles = [];
            foreach ($second['manifest']['shards'] as $shard) {
                $candidateFiles[$shard['path']] = (string) file_get_contents($outputRoot.'/'.$shard['path']);
            }
            CareerShardedCurrentContractGate::assertCandidate($second['manifest'], $candidateFiles);
        } finally {
            $this->deleteTemporaryDirectory($outputRoot);
        }

        self::assertSame($assetsBefore, hash_file('sha256', $this->assetsPath));
        self::assertSame($manifestBefore, hash_file('sha256', $this->manifestPath));
        self::assertSame($statusBefore, $this->repositoryStatus());
    }

    public function test_it_rejects_invalid_lines_locales_components_slugs_paths_symlinks_and_baseline_drift(): void
    {
        $sharder = new \CareerLegacyCurrentSharder;
        $line = rtrim((string) fgets(fopen($this->assetsPath, 'rb')), "\r\n");
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        $nonCanonical = json_encode(array_reverse($row, true), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertNotSame($line, $nonCanonical);
        $this->assertFailure('LEGACY_ROW_NOT_CANONICAL', fn () => $sharder->decodeCanonicalRow($nonCanonical));

        $invalidLocale = $row;
        $invalidLocale['page_payload_json']['page']['fr'] = $invalidLocale['page_payload_json']['page']['en'];
        $this->assertFailure('LEGACY_LOCALE_SET_INVALID', fn () => $sharder->splitRow($invalidLocale));

        $unknownComponent = $row;
        $unknownComponent['page_payload_json']['page']['en']['unknown_component'] = [];
        $this->assertFailure('LEGACY_PAGE_COMPONENT_SET_INVALID', fn () => $sharder->splitRow($unknownComponent));

        $missingComponent = $row;
        unset($missingComponent['page_payload_json']['page']['zh']['faq_block']);
        $this->assertFailure('LEGACY_PAGE_COMPONENT_SET_INVALID', fn () => $sharder->splitRow($missingComponent));

        $previous = null;
        $seen = [];
        $sharder->acceptSlug('accountants-and-auditors', $previous, $seen);
        $this->assertFailure('LEGACY_DUPLICATE_SLUG', fn () => $sharder->acceptSlug('accountants-and-auditors', $previous, $seen));

        $unknownSlugs = array_map(static fn (int $index): string => sprintf('unknown-%04d', $index), range(0, 1045));
        $expectedSlugs = [];
        foreach (file($this->assetsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $assetLine) {
            $asset = json_decode($assetLine, true, 512, JSON_THROW_ON_ERROR);
            $expectedSlugs[] = $asset['canonical_slug'];
        }
        $this->assertFailure(
            'LEGACY_SLUG_SET_INVALID',
            fn () => $sharder->assertSlugSet(
                $unknownSlugs,
                hash('sha256', \CareerLegacyCurrentSharder::canonicalJson($expectedSlugs)),
            ),
        );

        $assetsHash = hash_file('sha256', $this->assetsPath);
        $manifestHash = hash_file('sha256', $this->manifestPath);
        $this->assertFailure(
            'LEGACY_BASELINE_DRIFT',
            fn () => $sharder->assertStableInputs(str_repeat('0', 64), $manifestHash, $this->assetsPath, $this->manifestPath),
        );

        $this->assertFailure(
            'CANDIDATE_OUTPUT_ESCAPE',
            fn () => $sharder->split($this->repoRoot, $this->assetsPath, $this->manifestPath, $this->repoRoot),
        );

        $target = $this->temporaryDirectory();
        $link = $target.'-link';
        self::assertTrue(symlink($target, $link));
        try {
            $this->assertFailure(
                'CANDIDATE_OUTPUT_ROOT_INVALID',
                fn () => $sharder->split($this->repoRoot, $this->assetsPath, $this->manifestPath, $link),
            );
        } finally {
            unlink($link);
            $this->deleteTemporaryDirectory($target);
        }

        self::assertIsString($assetsHash);
    }

    /** @return array<string,string> */
    private function fileDigests(string $root): array
    {
        $digests = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            self::assertFalse($entry->isLink());
            if ($entry->isFile()) {
                $relative = substr($entry->getPathname(), strlen($root) + 1);
                $digests[$relative] = hash_file('sha256', $entry->getPathname());
            }
        }
        ksort($digests, SORT_STRING);

        return $digests;
    }

    private function temporaryDirectory(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'career-sharder-');
        self::assertIsString($path);
        unlink($path);
        self::assertTrue(mkdir($path, 0700));

        return $path;
    }

    private function deleteTemporaryDirectory(string $root): void
    {
        if (! is_dir($root) || ! str_starts_with((string) realpath($root), (string) realpath(sys_get_temp_dir()).'/')) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($root);
    }

    private function repositoryStatus(): string
    {
        $lines = [];
        $exitCode = 0;
        exec(sprintf(
            'git -C %s status --porcelain=v1 --untracked-files=all',
            escapeshellarg($this->repoRoot),
        ), $lines, $exitCode);
        self::assertSame(0, $exitCode);

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    private function assertFailure(string $safeCode, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected '.$safeCode);
        } catch (RuntimeException $failure) {
            self::assertSame($safeCode, $failure->getMessage());
        }
    }
}
