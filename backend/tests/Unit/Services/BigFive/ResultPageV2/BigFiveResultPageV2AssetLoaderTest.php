<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\ContentAssets\BigFiveV2AssetPackageLoader;
use App\Services\BigFive\ResultPageV2\ContentAssets\BigFiveV2AssetManifestValidator;
use Tests\TestCase;

final class BigFiveResultPageV2AssetLoaderTest extends TestCase
{
    public function test_loader_inventories_repo_owned_staging_asset_packages(): void
    {
        $inventory = app(BigFiveV2AssetPackageLoader::class)->inventory();
        $packages = collect($inventory->packages)->keyBy('relativePath');

        $this->assertTrue($inventory->isValid(), implode("\n", $inventory->errors));
        $this->assertGreaterThanOrEqual(12, $packages->count());

        foreach ([
            'content_assets/big5/result_page_v2/governance/content_asset_factory_spec',
            'content_assets/big5/result_page_v2/governance/source_authority_v0_1',
            'content_assets/big5/result_page_v2/master_catalog/v0_1',
            'content_assets/big5/result_page_v2/core_body/v0_1',
            'content_assets/big5/result_page_v2/trait_band_assets/v0_1',
            'content_assets/big5/result_page_v2/coupling_assets/v0_1',
            'content_assets/big5/result_page_v2/facet_assets/v0_1',
            'content_assets/big5/result_page_v2/canonical_profiles/v0_1',
            'content_assets/big5/result_page_v2/scenario_action_assets/v0_1_1',
            'content_assets/big5/result_page_v2/route_matrix/v0_1_1',
            'content_assets/big5/result_page_v2/selector_ready_assets/v0_3_p0_full',
            'content_assets/big5/result_page_v2/selector_qa_policy/v0_1',
        ] as $path) {
            $this->assertTrue($packages->has($path), "Missing package inventory for {$path}");
        }
    }

    public function test_loader_reports_versions_files_and_staging_flags(): void
    {
        $inventory = app(BigFiveV2AssetPackageLoader::class)->inventory();
        $packages = collect($inventory->packages)->keyBy('relativePath');

        $routeMatrix = $packages->get('content_assets/big5/result_page_v2/route_matrix/v0_1_1');
        $this->assertNotNull($routeMatrix);
        $this->assertSame(20, $routeMatrix->fileCount);
        $this->assertContains('staging_only', $routeMatrix->runtimeUses);
        $this->assertFalse($routeMatrix->productionUseAllowed);
        $this->assertFalse($routeMatrix->readyForRuntime);
        $this->assertFalse($routeMatrix->readyForProduction);
        $this->assertContains('v0.1.1', $routeMatrix->versions);

        $selectorReady = $packages->get('content_assets/big5/result_page_v2/selector_ready_assets/v0_3_p0_full');
        $this->assertNotNull($selectorReady);
        $this->assertSame(6, $selectorReady->fileCount);
        $this->assertContains('staging_only', $selectorReady->runtimeUses);
    }

    public function test_loader_validates_sha256sums_for_packages_that_provide_them(): void
    {
        $inventory = app(BigFiveV2AssetPackageLoader::class)->inventory();
        $packagesWithChecksums = array_filter(
            $inventory->packages,
            static fn ($package): bool => $package->checksumFiles !== []
        );

        $this->assertNotEmpty($packagesWithChecksums);
        foreach ($packagesWithChecksums as $package) {
            $this->assertSame([], $package->errors, $package->relativePath);
        }
    }

    public function test_manifest_validator_rejects_production_and_runtime_ready_flags(): void
    {
        $validator = new BigFiveV2AssetManifestValidator();

        $errors = $validator->validateDocument([
            'runtime_use' => 'runtime',
            'production_use_allowed' => true,
            'nested' => [
                'ready_for_runtime' => true,
                'ready_for_production' => true,
            ],
        ], 'fixture.json');

        $this->assertContains('fixture.json.runtime_use must not be runtime or production', $errors);
        $this->assertContains('fixture.json.production_use_allowed must not be true', $errors);
        $this->assertContains('fixture.json.nested.ready_for_runtime must not be true', $errors);
        $this->assertContains('fixture.json.nested.ready_for_production must not be true', $errors);
    }

    public function test_loader_fails_closed_for_malformed_manifest_and_checksum(): void
    {
        $root = sys_get_temp_dir().'/big5-v2-asset-loader-test-'.bin2hex(random_bytes(4));
        $package = $root.'/content_assets/big5/result_page_v2/bad_package/v0_1';
        mkdir($package, 0777, true);
        file_put_contents($package.'/manifest.json', '{"runtime_use":"staging_only"');
        file_put_contents($package.'/SHA256SUMS', str_repeat('0', 64).'  missing.json'.PHP_EOL);

        try {
            $inventory = app(BigFiveV2AssetPackageLoader::class)->inventory($root.'/content_assets/big5/result_page_v2');
            $this->assertFalse($inventory->isValid());
            $errors = implode("\n", $inventory->packages[0]->errors);
            $this->assertStringContainsString('manifest.json is not valid JSON', $errors);
            $this->assertStringContainsString('checksum target missing: missing.json', $errors);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }
}
