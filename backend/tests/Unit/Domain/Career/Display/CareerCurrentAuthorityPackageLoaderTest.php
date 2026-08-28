<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageLoader;
use App\Domain\Career\Display\CareerShardedCurrentAuthorityPackage;
use PHPUnit\Framework\TestCase;

final class CareerCurrentAuthorityPackageLoaderTest extends TestCase
{
    public function test_installed_manifest_selects_only_the_per_page_authority(): void
    {
        ini_set('memory_limit', '2048M');
        $backendRoot = dirname(__DIR__, 5);
        $loader = new CareerCurrentAuthorityPackageLoader(
            new CareerContentV3AuthorityPackage,
        );

        $installed = $loader->loadForPublish($backendRoot);

        self::assertSame('content_v3_per_page', $installed['summary']['source_format']);
        self::assertSame(1046, $installed['summary']['career_count']);
        self::assertSame(2092, $installed['summary']['locale_page_count']);
        self::assertCount(1046, $installed['pages']);
        self::assertCount(2092, $installed['manifest']['files']);
        self::assertDirectoryDoesNotExist($backendRoot.'/content_assets/career/current/identity');
    }

    public function test_runtime_loader_rejects_the_historical_sharded_contract(): void
    {
        $root = sys_get_temp_dir().'/career-v3-loader-'.bin2hex(random_bytes(8));
        $current = $root.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH;
        self::assertTrue(mkdir($current, 0700, true));
        file_put_contents($current.'/manifest.json', json_encode([
            'contract_version' => CareerShardedCurrentAuthorityPackage::CONTRACT_VERSION,
        ], JSON_THROW_ON_ERROR));

        try {
            (new CareerCurrentAuthorityPackageLoader(new CareerContentV3AuthorityPackage))->load($root);
            self::fail('Historical shards must not be accepted as Current runtime authority.');
        } catch (CareerCurrentAuthorityPackageFailure $failure) {
            self::assertSame('CURRENT_CONTENT_V3_AUTHORITY_REQUIRED', $failure->safeCode);
        } finally {
            unlink($current.'/manifest.json');
            rmdir($current);
            rmdir(dirname($current));
            rmdir(dirname($current, 2));
            rmdir($root);
        }
    }
}
