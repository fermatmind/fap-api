<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use App\Domain\Career\Display\CareerCurrentAuthorityParity;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

final class CareerInstalledFileIntegrityTest extends TestCase
{
    public function test_production_file_scan_verifies_installed_bytes_without_repeating_ci_body_validation(): void
    {
        $loader = app(\App\Domain\Career\Display\CareerCurrentAuthorityPackageLoader::class);
        $authority = $loader->indexForPublish(base_path());
        $authority['slugs'] = ['accountants-and-auditors', 'actors'];
        $pages = app(\App\Domain\Career\Display\CareerPageProjector::class);
        $installedLoader = \Mockery::mock(\App\Domain\Career\Display\CareerCurrentAuthorityPackageLoader::class);
        $installedLoader->shouldNotReceive('pageFromPublishIndex');
        $parity = new CareerCurrentAuthorityParity($installedLoader, app(\App\Domain\Career\Display\CareerJobDetailCanonicalCacheReader::class), $pages);
        $scan = $parity->scanFiles($authority);
        self::assertSame('ci_validated_installed_bytes', $scan['validation_method']);
        self::assertSame('file_authoritative', $scan['delivery_mode']);
        self::assertSame(3, $scan['counts']['locales']);
        self::assertSame(6, $scan['counts']['verified_files']);
        self::assertSame(0, $scan['cache_write_count']);
        $authority['entries']['actors']['zh-CN']['sha256'] = str_repeat('0', 64);
        $this->expectException(\App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure::class);
        $this->expectExceptionMessage('CURRENT_CONTENT_V3_FILE_HASH_MISMATCH');
        $parity->scanFiles($authority);
    }

    public function test_production_installed_integrity_rejects_missing_truncated_and_symlinked_files(): void
    {
        $root = sys_get_temp_dir().'/career-installed-'.bin2hex(random_bytes(8));
        mkdir($root);
        $bytes = '{"validated":"in-ci"}';
        $slug = 'accountants-and-auditors';
        $locales = ['en', 'zh-CN', 'zh-TW'];
        $entries = [];
        $parity = app(CareerCurrentAuthorityParity::class);
        try {
            foreach ($locales as $locale) {
                $path = $locale.'.json';
                file_put_contents($root.'/'.$path, $bytes);
                $entries[$locale] = ['path' => $path, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'source_content_sha256' => str_repeat('a', 64)];
            }
            $authority = [
                'root' => $root,
                'slugs' => [$slug],
                'manifest' => ['locales' => $locales],
                'entries' => [$slug => $entries],
            ];
            self::assertSame(3, $parity->scanFiles($authority)['counts']['verified_files']);

            $testedPath = $root.'/zh-TW.json';
            foreach (['missing', 'truncated', 'symlink'] as $failure) {
                if (is_file($testedPath)) {
                    unlink($testedPath);
                }
                if ($failure === 'truncated') {
                    file_put_contents($testedPath, '{}');
                } elseif ($failure === 'symlink') {
                    symlink($root.'/en.json', $testedPath);
                }
                clearstatcache();
                try {
                    $parity->scanFiles($authority);
                    self::fail('Installed integrity accepted '.$failure);
                } catch (\App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure $error) {
                    self::assertSame('CURRENT_CONTENT_V3_FILE_HASH_MISMATCH', $error->getMessage());
                }
            }
        } finally {
            (new Filesystem)->deleteDirectory($root);
        }
    }
}
