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
        self::assertSame(28, $package['summary']['components_per_page']);
        self::assertSame('v4.3', data_get($package, 'manifest.structural_contract.asset_version'));
        self::assertSame(2092, data_get($package, 'manifest.structured_components_v1.zh_published_component_count'));
        self::assertSame(2092, data_get($package, 'manifest.structured_components_v1.en_unavailable_component_count'));
        self::assertSame(data_get($package, 'manifest.files.0.sha256'), $package['summary']['assets_sha256']);
        self::assertSame(
            CareerCurrentAuthorityPackage::declaredAssetsSha256(dirname(__DIR__, 5)),
            $package['summary']['assets_sha256'],
        );
        self::assertArrayNotHasKey('software-developers', $package['rows']);
        self::assertSame(4184, data_get($package, 'manifest.superseded_source_coverage.workbuddy_block_count'));
        self::assertSame(0, data_get($package, 'manifest.superseded_source_coverage.workbuddy_block_mismatch_count'));
        self::assertSame(576, data_get($package, 'manifest.superseded_source_coverage.missing_12_original_component_count'));
        self::assertSame(0, data_get($package, 'manifest.superseded_source_coverage.missing_12_component_mismatch_count'));
        self::assertCount(
            isset($package['manifest']['presentation_v1']) ? 13 : 12,
            data_get($package, 'manifest.public_projection_field_set_sha256'),
        );
        self::assertSame('pass', data_get($package, 'manifest.export_evidence.exporter_result'));
        self::assertSame('failure', data_get($package, 'manifest.export_evidence.workflow_conclusion'));
    }

    public function test_publish_runner_loads_authority_classes_before_validating_execution_contract(): void
    {
        $backendRoot = dirname(__DIR__, 5);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', $backendRoot.'/scripts/operations/career_current_authority_publish.php'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $backendRoot,
            ['CAREER_CURRENT_PUBLISH_BACKEND_ROOT' => $backendRoot],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(1, proc_close($process));
        self::assertSame('', $stderr);
        $receipt = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('FAIL_CURRENT_AUTHORITY_PUBLISH', $receipt['status'] ?? null);
        self::assertSame('CURRENT_PUBLISH_EXECUTION_CONTRACT_INVALID', $receipt['safe_error_code'] ?? null);
    }

    public function test_publish_runner_rejects_an_asset_hash_not_declared_by_the_manifest(): void
    {
        $backendRoot = dirname(__DIR__, 5);
        $releaseSha = str_repeat('a', 40);
        $assetsSha256 = str_repeat('b', 64);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', $backendRoot.'/scripts/operations/career_current_authority_publish.php'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $backendRoot,
            [
                'CAREER_CURRENT_PUBLISH_BACKEND_ROOT' => $backendRoot,
                'CAREER_CURRENT_PUBLISH_EXECUTE' => '1',
                'CAREER_CURRENT_PUBLISH_RELEASE_SHA' => $releaseSha,
                'CAREER_CURRENT_PUBLISH_RELEASE_NAME' => 'release-test',
                'CAREER_CURRENT_PUBLISH_ASSETS_SHA256' => $assetsSha256,
                'CAREER_CURRENT_PUBLISH_OPERATION_KEY' => hash('sha256', 'career-current-authority|'.$releaseSha.'|'.$assetsSha256),
                'CAREER_CURRENT_PUBLISH_WORKFLOW_RUN_ID' => '1',
                'CAREER_CURRENT_PUBLISH_WORKFLOW_RUN_ATTEMPT' => '1',
            ],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(1, proc_close($process));
        self::assertSame('', $stderr);
        $receipt = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('CURRENT_PUBLISH_EXECUTION_CONTRACT_INVALID', $receipt['safe_error_code'] ?? null);
        self::assertSame('confirmed_zero_write', $receipt['write_commit_state'] ?? null);
        self::assertSame(0, array_sum($receipt['write_counts'] ?? []));
    }

    public function test_deploy_binds_publisher_runtime_changes_to_a_full_scan(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 6).'/.github/workflows/deploy.yml');

        foreach ([
            'backend/app/Domain/Career/Display/CareerCurrentAuthorityPackage.php',
            'backend/app/Domain/Career/Display/CareerCurrentAuthorityPublisher.php',
            'backend/scripts/operations/career_current_authority_publish.php',
        ] as $path) {
            self::assertGreaterThanOrEqual(2, substr_count($workflow, $path));
        }
        self::assertStringContainsString(
            '.public_readback.verified_slug_count == 1046',
            $workflow,
        );
        self::assertStringContainsString(
            '.public_readback.verified_locale_page_count == 2092',
            $workflow,
        );
    }

    public function test_current_page_contract_rejects_legacy_unknown_and_placeholder_structures(): void
    {
        $page = array_fill_keys(CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER, ['value' => 'verified']);
        $payload = ['en' => $page, 'zh' => $page];
        self::assertTrue(CareerDisplayAssetComponentContract::hasExactPagesForVersion($payload, 'v4.2'));

        $missing = $payload;
        unset($missing['en']['career_path_block']);
        self::assertFalse(CareerDisplayAssetComponentContract::hasExactPagesForVersion($missing, 'v4.2'));

        $unknown = $payload;
        $unknown['zh']['sections'] = [];
        self::assertFalse(CareerDisplayAssetComponentContract::hasExactPagesForVersion($unknown, 'v4.2'));

        $placeholder = $payload;
        $placeholder['en']['hero'] = ['content_available' => false];
        self::assertFalse(CareerDisplayAssetComponentContract::hasExactPagesForVersion($placeholder, 'v4.2'));
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
