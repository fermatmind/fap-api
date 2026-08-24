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

    public function test_it_refreshes_only_computed_fields_and_is_deterministic_and_fail_closed(): void
    {
        ini_set('memory_limit', '1024M');

        $sourceBackendRoot = dirname(__DIR__, 5);
        $backendRoot = $this->copyCurrentPackage($sourceBackendRoot);
        $manifestPath = $backendRoot.'/content_assets/career/current/manifest.json';
        $assetsPath = $backendRoot.'/content_assets/career/current/assets.jsonl';
        $originalManifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $originalHistory = array_intersect_key($originalManifest, array_flip([
            'delivery_evidence',
            'export_evidence',
            'superseded_source_coverage',
            'superseded_sources',
        ]));

        $this->mutateFirstRow($assetsPath, static function (array &$row): void {
            $pages = &$row['page_payload_json'];
            if (is_array($pages['page'] ?? null)) {
                $pages = &$pages['page'];
            }
            self::appendToFirstString($pages['en']['definition_block']);
        });

        $package = new CareerCurrentAuthorityPackage;
        $refresher = new CareerCurrentAuthorityManifestRefresher($package);
        $check = $refresher->check($backendRoot);
        self::assertTrue($check['stale']);
        self::assertFalse($check['changed']);
        self::assertSame('STALE_CAREER_CURRENT_MANIFEST', $check['status']);

        try {
            $package->load($backendRoot);
            self::fail('A stale manifest must not load.');
        } catch (CareerCurrentAuthorityPackageFailure $failure) {
            self::assertSame('CURRENT_ASSETS_HASH_MISMATCH', $failure->safeCode);
        }

        $write = $refresher->write($backendRoot);
        self::assertSame('UPDATED_CAREER_CURRENT_MANIFEST', $write['status']);
        self::assertTrue($write['changed']);
        self::assertFalse($write['stale']);
        self::assertTrue(str_ends_with((string) file_get_contents($manifestPath), "\n"));

        $updatedManifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($originalHistory, array_intersect_key($updatedManifest, array_flip(array_keys($originalHistory))));
        self::assertNotSame(data_get($originalManifest, 'files.0.sha256'), data_get($updatedManifest, 'files.0.sha256'));
        self::assertNotSame(
            data_get($originalManifest, 'set_hashes.public_content_aggregate_sha256'),
            data_get($updatedManifest, 'set_hashes.public_content_aggregate_sha256'),
        );
        self::assertSame(1046, $package->load($backendRoot)['summary']['career_count']);

        $manifestBytes = file_get_contents($manifestPath);
        $secondWrite = $refresher->write($backendRoot);
        self::assertSame('PASS_CAREER_CURRENT_MANIFEST', $secondWrite['status']);
        self::assertFalse($secondWrite['changed']);
        self::assertSame($manifestBytes, file_get_contents($manifestPath));

        $this->mutateFirstRow($assetsPath, static function (array &$row): void {
            unset($row['metadata_json']['structured_components_v1']['locales']['en']['bindings'][0]['source_registry_key']);
        });
        $manifestBeforeInvalidWrite = file_get_contents($manifestPath);
        try {
            $refresher->write($backendRoot);
            self::fail('Invalid assets must not rewrite the manifest.');
        } catch (CareerCurrentAuthorityPackageFailure $failure) {
            self::assertSame('CURRENT_STRUCTURED_COMPONENT_LINEAGE_INVALID', $failure->safeCode);
        }
        self::assertSame($manifestBeforeInvalidWrite, file_get_contents($manifestPath));
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
