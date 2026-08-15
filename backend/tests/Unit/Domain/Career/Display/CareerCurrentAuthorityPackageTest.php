<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use PHPUnit\Framework\TestCase;

final class CareerCurrentAuthorityPackageTest extends TestCase
{
    public function test_it_validates_the_complete_current_authority_and_locked_provenance(): void
    {
        ini_set('memory_limit', '1024M');

        $package = (new CareerCurrentAuthorityPackage)->load(dirname(__DIR__, 5));

        self::assertSame(1046, $package['summary']['career_count']);
        self::assertSame(2092, $package['summary']['locale_page_count']);
        self::assertSame(26, $package['summary']['components_per_page']);
        self::assertSame(CareerCurrentAuthorityPackage::ASSETS_SHA256, $package['summary']['assets_sha256']);
        self::assertArrayNotHasKey('software-developers', $package['rows']);
        self::assertSame(4184, data_get($package, 'manifest.superseded_source_coverage.workbuddy_block_count'));
        self::assertSame(0, data_get($package, 'manifest.superseded_source_coverage.workbuddy_block_mismatch_count'));
        self::assertSame(576, data_get($package, 'manifest.superseded_source_coverage.missing_12_original_component_count'));
        self::assertSame(0, data_get($package, 'manifest.superseded_source_coverage.missing_12_component_mismatch_count'));
        self::assertCount(12, data_get($package, 'manifest.public_projection_field_set_sha256'));
        self::assertSame('pass', data_get($package, 'manifest.export_evidence.exporter_result'));
        self::assertSame('failure', data_get($package, 'manifest.export_evidence.workflow_conclusion'));
    }

    public function test_current_page_contract_rejects_legacy_unknown_and_placeholder_structures(): void
    {
        $page = array_fill_keys(CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER, ['value' => 'verified']);
        $payload = ['en' => $page, 'zh' => $page];
        self::assertTrue(CareerDisplayAssetComponentContract::hasExactCurrentPages($payload));

        $missing = $payload;
        unset($missing['en']['career_path_block']);
        self::assertFalse(CareerDisplayAssetComponentContract::hasExactCurrentPages($missing));

        $unknown = $payload;
        $unknown['zh']['sections'] = [];
        self::assertFalse(CareerDisplayAssetComponentContract::hasExactCurrentPages($unknown));

        $placeholder = $payload;
        $placeholder['en']['hero'] = ['content_available' => false];
        self::assertFalse(CareerDisplayAssetComponentContract::hasExactCurrentPages($placeholder));
    }

    public function test_superseded_normal_display_writers_are_absent_and_explicit_exceptions_remain(): void
    {
        $backendRoot = dirname(__DIR__, 5);
        foreach ([
            'app/Console/Commands/CareerImportActorsDisplayAsset.php',
            'app/Console/Commands/CareerImportDetailReadyReplacementAuthority.php',
            'app/Console/Commands/CareerImportDetailReadyReplacementAuthoritySource.php',
            'app/Console/Commands/CareerImportSelectedDisplayAssets.php',
            'app/Console/Commands/CareerNormalizeLegacyDisplayAssets.php',
            'app/Domain/Career/Display/Career1046DisplayAssetReplacement.php',
            'app/Domain/Career/Display/CareerActorsCurrentRepair.php',
            'app/Domain/Career/Display/CareerCurrentAuthorityExporter.php',
        ] as $relativePath) {
            self::assertFileDoesNotExist($backendRoot.'/'.$relativePath);
        }

        self::assertFileExists($backendRoot.'/app/Domain/Career/Display/CareerCurrentAuthorityPublisher.php');
        self::assertFileExists($backendRoot.'/scripts/career/career_search_entry_thin_authority_repair.php');
        self::assertFileExists($backendRoot.'/app/Domain/GreenfieldBaseline/GreenfieldBaselineImporter.php');
    }
}
