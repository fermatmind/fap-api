<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerCurrentZhBatchMaterializer;
use App\Domain\Career\Compilation\CareerCurrentZhBatchPreparer;
use App\Domain\Career\Compilation\CareerTenBlockCompileFailure;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use Tests\TestCase;

final class CareerCurrentZhBatchMaterializerTest extends TestCase
{
    private const SOURCE = '/Users/rainie/Desktop/1046个职业/career-pages';

    public function test_full_batch_contract_is_deterministic_and_ordered_when_canonical_source_is_available(): void
    {
        if (! is_dir(self::SOURCE)) {
            self::markTestSkipped('The immutable Desktop canonical source is not mounted in CI.');
        }

        ini_set('memory_limit', '1024M');
        $roots = [];
        try {
            $planRoot = $roots[] = $this->temporaryDirectory('career-zh-plan-');
            $plan = app(CareerCurrentZhBatchPreparer::class)->prepare(
                self::SOURCE,
                $planRoot,
                50,
                str_repeat('a', 40),
                base_path(),
            );
            self::assertSame(CareerCurrentZhBatchMaterializer::EXPECTED_SOURCE_AGGREGATE_SHA256, $plan['manifest']['source_aggregate_sha256']);
            self::assertSame(21, $plan['report']['batch_count']);
            self::assertSame(1046, $plan['report']['target_union_count']);
            self::assertSame([], $plan['report']['duplicate_target_slugs']);
            self::assertSame([], $plan['report']['missing_target_slugs']);
            self::assertSame([], $plan['report']['unexpected_target_slugs']);

            $assetsPath = base_path(CareerCurrentAuthorityPackage::RELATIVE_PATH.'/assets.jsonl');
            $baseAssetsSha = (string) hash_file('sha256', $assetsPath);
            $sourceBefore = $plan['manifest']['source_aggregate_sha256'];
            $materializer = app(CareerCurrentZhBatchMaterializer::class);
            $package = app(CareerCurrentAuthorityPackage::class);
            $current = $package->load(base_path());
            $completed = 0;
            foreach ($plan['manifest']['per_slug'] as $slug => $entry) {
                $batchOrdinal = (int) substr((string) $entry['batch_identity'], -3);
                $hash = CareerCurrentAuthorityPackage::hashValue($package->publicProjection($current['rows'][$slug], 'zh-CN'));
                if (hash_equals((string) $entry['zh_projection_sha256'], $hash)) {
                    $completed = max($completed, $batchOrdinal);
                }
            }
            if ($completed === 21) {
                self::assertSame(1046, $current['summary']['career_count']);
                self::assertSame(2092, $current['summary']['locale_page_count']);
                self::assertSame(count(CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS), $current['summary']['components_per_page']);

                return;
            }
            $firstOrdinal = $completed + 1;
            $firstBatchId = sprintf('batch-%03d', $firstOrdinal);
            $firstTargetCount = (int) $plan['report']['batch_sizes'][$firstOrdinal - 1];

            $firstOutput = $roots[] = $this->temporaryDirectory('career-zh-next-batch-a-');
            $first = $materializer->materialize(
                self::SOURCE,
                $planRoot,
                $firstBatchId,
                $baseAssetsSha,
                base_path(),
                $firstOutput,
                false,
                'testing',
            );
            self::assertSame($firstTargetCount, $first['diff']['changed_zh_locale_pages']);
            self::assertSame(0, $first['diff']['changed_en_locale_pages']);
            self::assertSame(0, $first['diff']['non_target_row_changes']);
            self::assertFalse($first['diff']['software_developers_included']);
            self::assertSame(1046, $first['report']['package']['career_count']);
            self::assertSame(2092, $first['report']['package']['locale_page_count']);
            self::assertSame(count(CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS), $first['report']['package']['components_per_page']);
            self::assertSame(0, $first['report']['database_writes']);
            self::assertSame(0, $first['report']['cache_writes']);
            self::assertSame(0, $first['report']['cms_writes']);
            self::assertSame(0, $first['report']['sitemap_writes']);
            self::assertSame(0, $first['report']['discoverability_writes']);
            self::assertSame(0, $first['report']['search_submissions']);
            self::assertSame($baseAssetsSha, hash_file('sha256', $assetsPath));

            $secondOutput = $roots[] = $this->temporaryDirectory('career-zh-next-batch-b-');
            $materializer->materialize(
                self::SOURCE,
                $planRoot,
                $firstBatchId,
                $baseAssetsSha,
                base_path(),
                $secondOutput,
                false,
                'testing',
            );
            self::assertFileEquals($firstOutput.'/candidate/assets.jsonl', $secondOutput.'/candidate/assets.jsonl');
            self::assertFileEquals($firstOutput.'/candidate/manifest.json', $secondOutput.'/candidate/manifest.json');

            $batchOneBackend = $roots[] = $this->temporaryDirectory('career-zh-next-backend-');
            $packageRoot = $batchOneBackend.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH;
            self::assertTrue(mkdir($packageRoot, 0700, true));
            self::assertTrue(copy($firstOutput.'/candidate/assets.jsonl', $packageRoot.'/assets.jsonl'));
            self::assertTrue(copy($firstOutput.'/candidate/manifest.json', $packageRoot.'/manifest.json'));
            $batchOneSha = (string) hash_file('sha256', $packageRoot.'/assets.jsonl');
            if ($firstOrdinal < 21) {
                $secondBatchId = sprintf('batch-%03d', $firstOrdinal + 1);
                $batchTwoOutput = $roots[] = $this->temporaryDirectory('career-zh-following-batch-');
                $batchTwo = $materializer->materialize(
                    self::SOURCE,
                    $planRoot,
                    $secondBatchId,
                    $batchOneSha,
                    $batchOneBackend,
                    $batchTwoOutput,
                    false,
                    'testing',
                );
                self::assertSame((int) $plan['report']['batch_sizes'][$firstOrdinal], $batchTwo['diff']['changed_zh_locale_pages']);
                self::assertSame(0, $batchTwo['diff']['changed_en_locale_pages']);
                self::assertSame(0, $batchTwo['diff']['non_target_row_changes']);
            }

            $wrongBaseOutput = $roots[] = $this->temporaryDirectory('career-zh-wrong-base-');
            $this->expectFailure('CURRENT_ZH_BASE_ASSETS_HASH_MISMATCH', fn () => $materializer->materialize(
                self::SOURCE,
                $planRoot,
                'batch-001',
                str_repeat('0', 64),
                base_path(),
                $wrongBaseOutput,
                false,
                'testing',
            ));
            $skippedOutput = $roots[] = $this->temporaryDirectory('career-zh-skipped-');
            $this->expectFailure('CURRENT_ZH_BATCH_SEQUENCE_INVALID', fn () => $materializer->materialize(
                self::SOURCE,
                $planRoot,
                sprintf('batch-%03d', min(21, $firstOrdinal + 1)),
                $baseAssetsSha,
                base_path(),
                $skippedOutput,
                false,
                'testing',
            ));
            $duplicateOutput = $roots[] = $this->temporaryDirectory('career-zh-duplicate-');
            $this->expectFailure('CURRENT_ZH_BATCH_ALREADY_MATERIALIZED', fn () => $materializer->materialize(
                self::SOURCE,
                $planRoot,
                $firstBatchId,
                $batchOneSha,
                $batchOneBackend,
                $duplicateOutput,
                false,
                'testing',
            ));

            self::assertSame($sourceBefore, app(CareerCurrentZhBatchPreparer::class)->inspectSource(self::SOURCE)['aggregate_sha256']);
        } finally {
            foreach (array_reverse($roots) as $root) {
                $this->removeTree($root);
            }
        }
    }

    public function test_command_exposes_no_alternate_current_package_write_path(): void
    {
        $source = (string) file_get_contents(base_path('app/Console/Commands/CareerMaterializeCurrentZhBatch.php'));

        self::assertStringNotContainsString('--target=', $source);
        self::assertStringContainsString("['local', 'testing']", (string) file_get_contents(
            base_path('app/Domain/Career/Compilation/CareerCurrentZhBatchMaterializer.php'),
        ));
        self::assertStringContainsString('$slug === \'software-developers\'', (string) file_get_contents(
            base_path('app/Domain/Career/Compilation/CareerCurrentZhBatchMaterializer.php'),
        ));
    }

    public function test_it_rejects_a_source_with_the_wrong_aggregate(): void
    {
        $sourceRoot = $this->temporaryDirectory('career-zh-wrong-source-');
        $planRoot = $this->temporaryDirectory('career-zh-unused-plan-');
        $outputRoot = $this->temporaryDirectory('career-zh-unused-output-');
        try {
            $this->expectFailure('CURRENT_ZH_SOURCE_HASH_MISMATCH', fn () => app(CareerCurrentZhBatchMaterializer::class)->materialize(
                $sourceRoot,
                $planRoot,
                'batch-001',
                str_repeat('0', 64),
                base_path(),
                $outputRoot,
                false,
                'testing',
            ));
        } finally {
            $this->removeTree($outputRoot);
            $this->removeTree($planRoot);
            $this->removeTree($sourceRoot);
        }
    }

    private function expectFailure(string $safeCode, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected materialization failure '.$safeCode);
        } catch (CareerTenBlockCompileFailure $failure) {
            self::assertSame($safeCode, $failure->safeCode);
        }
    }

    private function temporaryDirectory(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        self::assertIsString($path);
        self::assertTrue(unlink($path));
        self::assertTrue(mkdir($path, 0700));

        return $path;
    }

    private function removeTree(string $root): void
    {
        if (! is_dir($root) || is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }
}
