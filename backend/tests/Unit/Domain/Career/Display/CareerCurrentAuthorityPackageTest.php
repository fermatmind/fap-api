<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageLoader;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use App\Domain\Career\Display\CareerShardedCurrentAuthorityPackage;
use PHPUnit\Framework\TestCase;

final class CareerCurrentAuthorityPackageTest extends TestCase
{
    public function test_operation_binding_rejects_a_sharded_manifest_outside_the_current_authority_path(): void
    {
        $backendRoot = sys_get_temp_dir().'/career-current-binding-'.bin2hex(random_bytes(8));
        $authorityRoot = $backendRoot.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH;
        self::assertTrue(mkdir($authorityRoot, 0755, true));
        file_put_contents($authorityRoot.'/manifest.json', json_encode([
            'contract_version' => CareerShardedCurrentAuthorityPackage::CONTRACT_VERSION,
            'authority_path' => 'backend/content_assets/career/candidate',
            'aggregate_sha256' => str_repeat('a', 64),
        ], JSON_THROW_ON_ERROR));

        try {
            CareerCurrentAuthorityPackage::declaredAssetsSha256($backendRoot);
            self::fail('An out-of-path sharded manifest must not bind a publish operation.');
        } catch (CareerCurrentAuthorityPackageFailure $failure) {
            self::assertSame('CURRENT_MANIFEST_INVALID', $failure->safeCode);
        } finally {
            unlink($authorityRoot.'/manifest.json');
            rmdir($authorityRoot);
            rmdir(dirname($authorityRoot));
            rmdir(dirname($authorityRoot, 2));
            rmdir($backendRoot);
        }
    }

    public function test_it_validates_the_complete_current_authority_and_locked_provenance(): void
    {
        ini_set('memory_limit', '2048M');

        $legacyContract = new CareerCurrentAuthorityPackage;
        $package = (new CareerCurrentAuthorityPackageLoader(
            $legacyContract,
            new CareerShardedCurrentAuthorityPackage($legacyContract),
        ))->load(dirname(__DIR__, 5));

        self::assertSame(1046, $package['summary']['career_count']);
        self::assertSame(2092, $package['summary']['locale_page_count']);
        self::assertSame(
            count(CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS),
            $package['summary']['components_per_page'],
        );
        self::assertSame('sharded', $package['summary']['source_format']);
        self::assertSame('career.sharded_current.manifest.v1', $package['manifest']['contract_version']);
        self::assertCount(640, $package['manifest']['shards']);
        self::assertSame([], $package['manifest']['registries']);
        self::assertSame(
            CareerCurrentAuthorityPackage::declaredAssetsSha256(dirname(__DIR__, 5)),
            $package['manifest']['aggregate_sha256'],
        );
        self::assertSame(
            $package['manifest']['versionless_projection_sha256'],
            $package['summary']['versionless_projection_sha256'],
        );
        self::assertSame(
            CareerCurrentAuthorityPackage::hashValue(array_values($package['rows'])),
            $package['summary']['versionless_projection_sha256'],
        );
        foreach ($package['rows'] as $row) {
            self::assertSame(['en', 'zh'], array_keys($row['metadata_json']['presentation_v2']));
            self::assertArrayHasKey('presentation_v2', $legacyContract->publicProjection($row, 'en'));
            self::assertArrayHasKey('presentation_v2', $legacyContract->publicProjection($row, 'zh-CN'));
            self::assertSame('career.detail.content.v3', $legacyContract->publicProjection($row, 'en')['content_v3']['contract_version']);
            self::assertSame('career.detail.content.v3', $legacyContract->publicProjection($row, 'zh-CN')['content_v3']['contract_version']);
        }
        self::assertArrayNotHasKey('software-developers', $package['rows']);
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
            self::assertGreaterThanOrEqual(1, substr_count($workflow, $path));
        }
        self::assertStringContainsString(
            '.public_readback.verified_slug_count == 1046',
            $workflow,
        );
        self::assertStringContainsString('startswith("backend/content_assets/career/current/")', $workflow);
        self::assertStringContainsString('verify_career_current_authority_release.sh', $workflow);
        self::assertStringContainsString('CAREER_CURRENT_PUBLISH_SOURCE_MERGE_SHA', $workflow);
        self::assertStringContainsString('CAREER_CURRENT_PUBLISH_MANIFEST_SHA256', $workflow);
        self::assertStringContainsString('CAREER_CURRENT_PUBLISH_VERSIONLESS_PROJECTION_SHA256', $workflow);
        self::assertStringContainsString('.source_merge_sha == $source', $workflow);
        self::assertStringContainsString('.manifest_sha256 == $manifest', $workflow);
        self::assertStringContainsString(
            '.public_readback.verified_locale_page_count == 2092',
            $workflow,
        );
    }

    public function test_current_page_contract_rejects_legacy_unknown_and_placeholder_structures(): void
    {
        $page = array_fill_keys(CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS, ['value' => 'verified']);
        $row = ['label' => 'label', 'value' => 'value', 'alternate_value' => null, 'secondary_value' => null];
        $page['career_quick_answers_block'] = [
            'availability' => 'published', 'schema_version' => 'career.quick_answers.v1',
            'heading' => '职业速答',
            'items' => array_map(static fn (string $key): array => [
                'key' => $key, 'question' => $key.' question', 'answer' => $key.' answer',
                'table' => ['rows' => [$row]],
            ], ['qa3', 'qa2', 'qa1']),
        ];
        $page['onet_structured_fields_block'] = [
            'availability' => 'published', 'schema_version' => 'career.onet_structured_fields.v1',
            'heading' => 'O*NET 结构化字段', 'rows' => [$row],
        ];
        $payload = ['en' => $page, 'zh' => $page];
        $payload['en']['career_quick_answers_block']['heading'] = 'Career quick answers';
        $payload['en']['onet_structured_fields_block']['heading'] = 'O*NET structured fields';
        $componentOrder = CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS;
        self::assertTrue(CareerDisplayAssetComponentContract::hasDeclaredPages($payload, $componentOrder));

        $missing = $payload;
        unset($missing['en']['career_path_block']);
        self::assertFalse(CareerDisplayAssetComponentContract::hasDeclaredPages($missing, $componentOrder));

        $unknown = $payload;
        $unknown['zh']['sections'] = [];
        self::assertFalse(CareerDisplayAssetComponentContract::hasDeclaredPages($unknown, $componentOrder));

        $placeholder = $payload;
        $placeholder['en']['hero'] = ['content_available' => false];
        self::assertFalse(CareerDisplayAssetComponentContract::hasDeclaredPages($placeholder, $componentOrder));
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
