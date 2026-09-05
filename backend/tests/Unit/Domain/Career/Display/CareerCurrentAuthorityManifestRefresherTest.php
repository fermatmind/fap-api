<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerCurrentAuthorityManifestRefresher;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use PHPUnit\Framework\TestCase;

final class CareerCurrentAuthorityManifestRefresherTest extends TestCase
{
    private ?string $temporaryRoot = null;

    protected function tearDown(): void
    {
        if ($this->temporaryRoot !== null) {
            $backendRoot = $this->temporaryRoot.'/backend';
            @unlink($backendRoot.'/content_assets/career/current/assets.jsonl');
            @unlink($backendRoot.'/content_assets/career/current/manifest.json');
            @unlink($backendRoot.'/content_assets/career/current/presentation-source-registry.json');
            @unlink($backendRoot.'/content_assets/career/current/structured-component-source-registry.json');
            @rmdir($backendRoot.'/content_assets/career/current');
            @rmdir($backendRoot.'/content_assets/career');
            @rmdir($backendRoot.'/content_assets');
            @rmdir($backendRoot);
            @rmdir($this->temporaryRoot);
        }

        parent::tearDown();
    }

    public function test_legacy_refresher_rejects_per_page_current_without_creating_flat_assets(): void
    {
        $backendRoot = dirname(__DIR__, 5);
        $manifestPath = $backendRoot.'/content_assets/career/current/manifest.json';
        $assetsPath = $backendRoot.'/content_assets/career/current/assets.jsonl';
        $manifestHash = hash_file('sha256', $manifestPath);
        self::assertFileDoesNotExist($assetsPath);
        $refresher = new CareerCurrentAuthorityManifestRefresher(new CareerCurrentAuthorityPackage);
        foreach (['check', 'write'] as $method) {
            try {
                $refresher->{$method}($backendRoot);
                self::fail('The legacy manifest refresher must not rewrite per-page Current authority.');
            } catch (CareerCurrentAuthorityPackageFailure $failure) {
                self::assertSame('CURRENT_PACKAGE_FILE_MISSING', $failure->safeCode);
            }
        }
        self::assertSame($manifestHash, hash_file('sha256', $manifestPath));
        self::assertFileDoesNotExist($assetsPath);
    }

    public function test_legacy_refresher_also_rejects_compiler_owned_sharded_manifests(): void
    {
        $this->temporaryRoot = sys_get_temp_dir().'/career-current-manifest-'.bin2hex(random_bytes(8));
        $backendRoot = $this->temporaryRoot.'/backend';
        $root = $backendRoot.'/content_assets/career/current';
        self::assertTrue(mkdir($root, 0700, true));
        $bytes = json_encode(['contract_version' => 'career.sharded_current.manifest.v1'], JSON_THROW_ON_ERROR);
        file_put_contents($root.'/manifest.json', $bytes);
        $refresher = new CareerCurrentAuthorityManifestRefresher(new CareerCurrentAuthorityPackage);
        foreach (['check', 'write'] as $method) {
            try {
                $refresher->{$method}($backendRoot);
                self::fail('The sharded manifest remains compiler owned.');
            } catch (CareerCurrentAuthorityPackageFailure $failure) {
                self::assertSame('CURRENT_SHARDED_MANIFEST_COMPILER_OWNED', $failure->safeCode);
            }
        }
        self::assertSame($bytes, file_get_contents($root.'/manifest.json'));
        self::assertFileDoesNotExist($root.'/assets.jsonl');
    }

    private function copyCurrentPackage(string $sourceBackendRoot): string
    {
        $this->temporaryRoot = sys_get_temp_dir().'/career-current-manifest-'.bin2hex(random_bytes(8));
        $backendRoot = $this->temporaryRoot.'/backend';
        $target = $backendRoot.'/content_assets/career/current';
        self::assertTrue(mkdir($target, 0700, true));
        self::assertTrue(copy($sourceBackendRoot.'/content_assets/career/current/assets.jsonl', $target.'/assets.jsonl'));
        self::assertTrue(copy($sourceBackendRoot.'/content_assets/career/current/manifest.json', $target.'/manifest.json'));
        self::assertTrue(copy($sourceBackendRoot.'/content_assets/career/current/presentation-source-registry.json', $target.'/presentation-source-registry.json'));
        self::assertTrue(copy($sourceBackendRoot.'/content_assets/career/current/structured-component-source-registry.json', $target.'/structured-component-source-registry.json'));

        return $backendRoot;
    }

    /** @param callable(array<string,mixed>&):void $mutator */
    private function mutateFirstRow(string $assetsPath, callable $mutator): void
    {
        $contents = file_get_contents($assetsPath);
        self::assertIsString($contents);
        $newline = strpos($contents, "\n");
        self::assertIsInt($newline);
        $row = json_decode(substr($contents, 0, $newline), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($row);
        $mutator($row);
        $updated = CareerCurrentAuthorityPackage::encodeCanonical($row)."\n".substr($contents, $newline + 1);
        self::assertSame(strlen($updated), file_put_contents($assetsPath, $updated, LOCK_EX));
    }

    private static function appendToFirstString(mixed &$value): bool
    {
        if (is_string($value) && $value !== '') {
            $value .= ' Manifest refresh fixture.';

            return true;
        }
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as &$item) {
            if (self::appendToFirstString($item)) {
                return true;
            }
        }

        return false;
    }
}
