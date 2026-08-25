<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageLoader;
use App\Domain\Career\Display\CareerShardedCurrentAuthorityPackage;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CareerCurrentAuthorityPackageLoaderTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = dirname(__DIR__, 6);
        require_once $this->repoRoot.'/.agents/skills/fap-api-career-canonical-builder/scripts/split_legacy_current.php';
    }

    public function test_installed_manifest_explicitly_selects_sharded_read_without_legacy_fallback(): void
    {
        ini_set('memory_limit', '2048M');
        $legacyPackage = new CareerCurrentAuthorityPackage;
        $loader = new CareerCurrentAuthorityPackageLoader(
            $legacyPackage,
            new CareerShardedCurrentAuthorityPackage($legacyPackage),
        );
        $installed = $loader->load($this->repoRoot.'/backend');
        self::assertSame('sharded', $installed['summary']['source_format']);

        $candidateRoot = $this->temporaryDirectory('career-loader-candidate-');
        $fixtureBackend = $this->temporaryDirectory('career-loader-backend-');
        try {
            (new \CareerLegacyCurrentSharder)->split(
                $this->repoRoot,
                $this->repoRoot.'/backend/content_assets/career/current/assets.jsonl',
                $this->repoRoot.'/backend/content_assets/career/current/manifest.json',
                $candidateRoot,
            );
            $currentRoot = $fixtureBackend.'/content_assets/career/current';
            self::assertTrue(mkdir($currentRoot, 0700, true));
            copy($candidateRoot.'/manifest.json', $currentRoot.'/manifest.json');
            foreach (\CareerLegacyCurrentSharder::MODULES as $module) {
                self::assertTrue(mkdir($currentRoot.'/'.$module, 0700));
                foreach (glob($candidateRoot.'/'.$module.'/shard-*.jsonl') ?: [] as $shard) {
                    copy($shard, $currentRoot.'/'.$module.'/'.basename($shard));
                }
            }
            file_put_contents($currentRoot.'/assets.jsonl', "legacy fallback must not be read\n");

            $sharded = $loader->load($fixtureBackend);
            self::assertSame('sharded', $sharded['summary']['source_format']);
            self::assertSame(1046, $sharded['summary']['career_count']);
            self::assertSame(2092, $sharded['summary']['locale_page_count']);
            self::assertSame($installed['slugs'], $sharded['slugs']);
            self::assertSame(
                CareerCurrentAuthorityPackage::hashValue($installed['rows']),
                CareerCurrentAuthorityPackage::hashValue($sharded['rows']),
            );
            self::assertSame($installed['summary']['assets_sha256'], $sharded['summary']['assets_sha256']);
            self::assertSame($installed['summary']['full_asset_set_sha256'], $sharded['summary']['full_asset_set_sha256']);

            $firstShard = $currentRoot.'/identity/shard-00.jsonl';
            $original = (string) file_get_contents($firstShard);
            file_put_contents($firstShard, '['.substr($original, 1));
            $this->assertFailure('CURRENT_SHARDED_HASH_MISMATCH', fn () => $loader->load($fixtureBackend));
            file_put_contents($firstShard, $original);

            $manifestPath = $currentRoot.'/manifest.json';
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $manifest['contract_version'] = 'career.unknown.v1';
            file_put_contents($manifestPath, CareerCurrentAuthorityPackage::encodePrettyCanonical($manifest));
            $this->assertFailure('CURRENT_MANIFEST_CONTRACT_UNSUPPORTED', fn () => $loader->load($fixtureBackend));
        } finally {
            $this->deleteTemporaryDirectory($candidateRoot);
            $this->deleteTemporaryDirectory($fixtureBackend);
        }
    }

    private function temporaryDirectory(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        self::assertIsString($path);
        unlink($path);
        self::assertTrue(mkdir($path, 0700));

        return $path;
    }

    private function deleteTemporaryDirectory(string $root): void
    {
        $real = realpath($root);
        $temporaryRoot = realpath(sys_get_temp_dir());
        if (! is_string($real) || ! is_string($temporaryRoot) || ! str_starts_with($real, $temporaryRoot.'/')) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($real);
    }

    private function assertFailure(string $safeCode, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected '.$safeCode);
        } catch (CareerCurrentAuthorityPackageFailure $failure) {
            self::assertSame($safeCode, $failure->safeCode);
        }
    }
}
