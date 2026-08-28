<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CareerContentV3AuthorityPackageTest extends TestCase
{
    #[DataProvider('failureProvider')]
    public function test_package_fails_closed_on_manifest_inventory_and_page_drift(callable $mutate, string $safeCode): void
    {
        $root = $this->fixture();
        try {
            $mutate($root, $this);
            $this->expectException(CareerCurrentAuthorityPackageFailure::class);
            $this->expectExceptionMessage($safeCode);
            $this->package()->loadRoot($root);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    /** @return iterable<string,array{callable,string}> */
    public static function failureProvider(): iterable
    {
        yield 'missing locale file' => [static function (string $root): void {
            unlink($root.'/careers/actors/zh-CN.json');
        }, 'CURRENT_CONTENT_V3_FILE_MISSING'];
        yield 'duplicate locale binding' => [static function (string $root): void {
            $manifest = self::read($root.'/manifest.json');
            $manifest['files'][1] = $manifest['files'][0];
            self::write($root.'/manifest.json', $manifest);
        }, 'CURRENT_CONTENT_V3_DUPLICATE_BINDING'];
        yield 'wrong locale' => [static function (string $root, self $test): void {
            $path = $root.'/careers/actors/en.json';
            $page = self::read($path);
            $page['locale'] = 'zh-CN';
            self::write($path, $page);
            $test->refreshManifest($root);
        }, 'CURRENT_CONTENT_V3_FILE_IDENTITY_MISMATCH'];
        yield 'damaged json' => [static function (string $root): void {
            $bytes = "{\n";
            file_put_contents($root.'/careers/actors/en.json', $bytes);
            $manifest = self::read($root.'/manifest.json');
            $manifest['files'][0]['bytes'] = strlen($bytes);
            $manifest['files'][0]['sha256'] = hash('sha256', $bytes);
            $projection = $manifest;
            unset($projection['aggregate_sha256']);
            $manifest['aggregate_sha256'] = CareerCurrentAuthorityPackage::hashValue($projection);
            self::write($root.'/manifest.json', $manifest);
        }, 'CURRENT_CONTENT_V3_JSON_INVALID'];
        yield 'hash drift' => [static function (string $root): void {
            file_put_contents($root.'/careers/actors/en.json', " \n", FILE_APPEND);
        }, 'CURRENT_CONTENT_V3_FILE_HASH_MISMATCH'];
        yield 'unknown primitive' => [static function (string $root, self $test): void {
            $path = $root.'/careers/actors/en.json';
            $page = self::read($path);
            $page['blocks'][0]['items'][0]['type'] = 'raw-html';
            self::write($path, $page);
            $test->refreshManifest($root);
        }, 'CURRENT_CONTENT_V3_INVALID'];
        yield 'duplicate block id' => [static function (string $root, self $test): void {
            $path = $root.'/careers/actors/en.json';
            $page = self::read($path);
            $page['blocks'][] = $page['blocks'][0];
            self::write($path, $page);
            $test->refreshManifest($root);
        }, 'CURRENT_CONTENT_V3_INVALID'];
        yield 'duplicate item id' => [static function (string $root, self $test): void {
            $path = $root.'/careers/actors/en.json';
            $page = self::read($path);
            $page['blocks'][0]['items'][] = $page['blocks'][0]['items'][0];
            self::write($path, $page);
            $test->refreshManifest($root);
        }, 'CURRENT_CONTENT_V3_INVALID'];
        yield 'unknown canonical slug' => [static function (string $root): void {
            mkdir($root.'/careers/unknown-career');
            $manifest = self::read($root.'/manifest.json');
            foreach (['en', 'zh-CN'] as $index => $locale) {
                $old = $root.'/careers/actors/'.$locale.'.json';
                $page = self::read($old);
                $page['subject']['canonical_slug'] = 'unknown-career';
                $newRelative = 'careers/unknown-career/'.$locale.'.json';
                self::write($root.'/'.$newRelative, $page);
                unlink($old);
                $bytes = (string) file_get_contents($root.'/'.$newRelative);
                $manifest['files'][$index]['canonical_slug'] = 'unknown-career';
                $manifest['files'][$index]['path'] = $newRelative;
                $manifest['files'][$index]['bytes'] = strlen($bytes);
                $manifest['files'][$index]['sha256'] = hash('sha256', $bytes);
            }
            rmdir($root.'/careers/actors');
            $manifest['set_hashes']['slug_set_sha256'] = CareerCurrentAuthorityPackage::hashValue(['unknown-career']);
            $manifest['set_hashes']['locale_page_set_sha256'] = CareerCurrentAuthorityPackage::hashValue([
                'unknown-career|en', 'unknown-career|zh-CN',
            ]);
            $projection = $manifest;
            unset($projection['aggregate_sha256']);
            $manifest['aggregate_sha256'] = CareerCurrentAuthorityPackage::hashValue($projection);
            self::write($root.'/manifest.json', $manifest);
        }, 'CURRENT_CONTENT_V3_COVERAGE_INVALID'];
        yield 'invalid source url' => [static function (string $root, self $test): void {
            $path = $root.'/careers/actors/en.json';
            $page = self::read($path);
            $page['blocks'][0]['items'][0] = [
                'id' => 'sources-1', 'copy_key' => 'career.item.sources', 'type' => 'sources',
                'availability' => 'available', 'data' => ['entries' => [[
                    'id' => 'source-1', 'name' => 'Source', 'url' => 'javascript:alert(1)', 'details' => [],
                ]]],
            ];
            self::write($path, $page);
            $test->refreshManifest($root);
        }, 'CURRENT_CONTENT_V3_INVALID'];
        yield 'path traversal' => [static function (string $root): void {
            $manifest = self::read($root.'/manifest.json');
            $manifest['files'][0]['path'] = '../en.json';
            self::write($root.'/manifest.json', $manifest);
        }, 'CURRENT_CONTENT_V3_FILE_DECLARATION_INVALID'];
        yield 'symlink' => [static function (string $root): void {
            $path = $root.'/careers/actors/en.json';
            $copy = $root.'/en-copy.json';
            copy($path, $copy);
            unlink($path);
            symlink($copy, $path);
        }, 'CURRENT_CONTENT_V3_FILE_MISSING'];
        yield 'undeclared file' => [static function (string $root): void {
            file_put_contents($root.'/careers/actors/extra.json', "{}\n");
        }, 'CURRENT_CONTENT_V3_UNDECLARED_FILE'];
        yield 'root manifest corruption' => [static function (string $root): void {
            file_put_contents($root.'/manifest.json', "{\n");
        }, 'CURRENT_CONTENT_V3_MANIFEST_INVALID'];
    }

    public function test_single_invalid_block_is_isolated_from_reader_content(): void
    {
        $content = $this->page('en');
        $content['blocks'][] = [
            'id' => 'bad', 'copy_key' => 'career.block.bad', 'content_state' => 'legacy',
            'availability' => 'available', 'items' => [[
                'id' => 'bad-1', 'copy_key' => 'career.item.bad', 'type' => 'raw-html',
                'availability' => 'available', 'data' => ['html' => '<script>bad</script>'],
            ]],
        ];
        $reader = new CareerContentV3CanonicalReader($this->package());
        $result = $reader->isolateInvalidBlocks($content);

        self::assertSame(['bad'], $result['isolated_block_ids']);
        self::assertCount(1, $result['content']['blocks']);
        self::assertStringNotContainsString('script', CareerCurrentAuthorityPackage::encodeCanonical($result['content']));
    }

    private function fixture(): string
    {
        $root = tempnam(sys_get_temp_dir(), 'career-v3-package-');
        self::assertIsString($root);
        unlink($root);
        self::assertTrue(mkdir($root.'/careers/actors', 0700, true));
        foreach (['en', 'zh-CN'] as $locale) {
            self::write($root.'/careers/actors/'.$locale.'.json', $this->page($locale));
        }
        $this->refreshManifest($root);

        return $root;
    }

    private function refreshManifest(string $root): void
    {
        $files = [];
        $semantic = [];
        $compatibility = [];
        foreach (['en', 'zh-CN'] as $locale) {
            $path = 'careers/actors/'.$locale.'.json';
            $bytes = (string) file_get_contents($root.'/'.$path);
            $page = json_decode($bytes, true);
            $sourceHash = is_array($page) && is_string($page['source_content_sha256'] ?? null)
                ? $page['source_content_sha256'] : str_repeat('a', 64);
            $projectionHash = hash('sha256', 'projection-'.$locale);
            $files[] = [
                'bytes' => strlen($bytes), 'canonical_slug' => 'actors',
                'legacy_projection_sha256' => $projectionHash,
                'legacy_row_sha256' => hash('sha256', 'row'), 'locale' => $locale, 'path' => $path,
                'sha256' => hash('sha256', $bytes), 'source_content_sha256' => $sourceHash,
            ];
            $semantic[] = $sourceHash;
            $compatibility[] = $projectionHash;
        }
        $localePages = ['actors|en', 'actors|zh-CN'];
        $manifest = [
            'aggregate_sha256' => '', 'authority_path' => 'backend/content_assets/career/current',
            'compiler_version' => CareerContentV3AuthorityPackage::COMPILER_VERSION,
            'contract_version' => CareerContentV3AuthorityPackage::CONTRACT_VERSION,
            'coverage' => ['slugs' => 1, 'locales' => 2, 'locale_pages' => 2, 'files' => 2, 'enhanced_locale_pages' => 0, 'legacy_locale_pages' => 2],
            'files' => $files, 'locales' => ['en', 'zh-CN'],
            'schema_version' => CareerContentV3AuthorityPackage::SCHEMA_VERSION,
            'set_hashes' => [
                'legacy_projection_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue($compatibility),
                'legacy_versionless_projection_sha256' => str_repeat('b', 64),
                'locale_page_set_sha256' => CareerCurrentAuthorityPackage::hashValue($localePages),
                'slug_set_sha256' => CareerCurrentAuthorityPackage::hashValue(['actors']),
                'source_semantic_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue($semantic),
            ],
            'source_registry_sha256' => str_repeat('c', 64),
        ];
        $projection = $manifest;
        unset($projection['aggregate_sha256']);
        $manifest['aggregate_sha256'] = CareerCurrentAuthorityPackage::hashValue($projection);
        self::write($root.'/manifest.json', $manifest);
    }

    /** @return array<string,mixed> */
    private function page(string $locale): array
    {
        return [
            'contract_version' => 'career.detail.content.v3', 'locale' => $locale,
            'subject' => ['canonical_slug' => 'actors', 'name' => $locale === 'en' ? 'Actors' : '演员', 'summary' => null],
            'content_state' => 'legacy', 'source_content_sha256' => str_repeat($locale === 'en' ? 'a' : 'b', 64),
            'blocks' => [[
                'id' => 'profile', 'copy_key' => 'career.block.profile', 'content_state' => 'legacy',
                'availability' => 'available', 'items' => [[
                    'id' => 'profile-1', 'copy_key' => 'career.item.definition', 'type' => 'prose',
                    'availability' => 'available', 'data' => ['paragraphs' => ['Body.']],
                ]],
            ]],
        ];
    }

    private function package(): CareerContentV3AuthorityPackage
    {
        return new CareerContentV3AuthorityPackage(1, 2, 0, CareerCurrentAuthorityPackage::hashValue(['actors']));
    }

    /** @return array<string,mixed> */
    private static function read(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $value */
    private static function write(string $path, array $value): void
    {
        file_put_contents($path, CareerCurrentAuthorityPackage::encodePrettyCanonical($value));
    }

    private function deleteDirectory(string $root): void
    {
        if (! is_dir($root)) {
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
}
