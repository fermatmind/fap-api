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
        self::assertSame(4, $scan['counts']['verified_files']);
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
        $entry = ['path' => 'page.json', 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'source_content_sha256' => str_repeat('a', 64)];
        $authority = ['root' => $root, 'slugs' => ['accountants-and-auditors'], 'entries' => ['accountants-and-auditors' => ['en' => $entry, 'zh-CN' => $entry]]];
        try {
            foreach (['missing', 'truncated', 'symlink'] as $failure) {
                @unlink($root.'/page.json');
                if ($failure === 'truncated') {
                    file_put_contents($root.'/page.json', '{}');
                } elseif ($failure === 'symlink') {
                    file_put_contents($root.'/source.json', $bytes);
                    symlink($root.'/source.json', $root.'/page.json');
                }
                try {
                    app(CareerCurrentAuthorityParity::class)->scanFiles($authority);
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
