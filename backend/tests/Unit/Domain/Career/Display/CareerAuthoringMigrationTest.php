<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerAuthoringMigration;
use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use App\Domain\Career\Display\CareerCurrentAuthorityReleaseIntent;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class CareerAuthoringMigrationTest extends TestCase
{
    public function test_dry_run_atomic_package_update_repeated_execution_and_corruption(): void
    {
        $files = new Filesystem;
        $root = sys_get_temp_dir().'/career-authoring-test-'.bin2hex(random_bytes(8));
        $current = $root.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH;
        $source = dirname(__DIR__, 5);
        $files->makeDirectory(dirname($current), 0700, true);
        $files->copyDirectory($source.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH, $current);
        $files->copy($source.'/'.CareerCurrentAuthorityReleaseIntent::RELATIVE_PATH, $root.'/'.CareerCurrentAuthorityReleaseIntent::RELATIVE_PATH);
        try {
            $before = $this->hashes($root);
            $migration = new CareerAuthoringMigration;
            $dryRun = $migration->run($root);
            self::assertFalse($dryRun['written']);
            self::assertSame($before, $this->hashes($root));
            $result = $migration->run($root, true);
            self::assertSame('PASS', $result['status']);
            self::assertSame(1046, $result['zh_pages']);
            self::assertSame(144, $result['enhanced']);
            self::assertSame(902, $result['legacy']);
            $after = $this->hashes($root);
            foreach ($before as $path => $hash) {
                if (str_ends_with($path, '/en.json')) {
                    self::assertSame($hash, $after[$path]);
                }
            }
            $reader = new CareerContentV3CanonicalReader(new CareerContentV3AuthorityPackage, $root);
            self::assertArrayNotHasKey('authoring_structure', $reader->page('accountants-and-auditors', 'zh-CN'));
            self::assertArrayNotHasKey('authoring_structure', $reader->page('actors', 'zh-CN'));
            self::assertSame(0, $migration->run($root, true)['changed_files']);
            self::assertSame($after, $this->hashes($root));
            // A late, corrupted locale cannot cause earlier files to be partially installed.
            $path = $current.'/careers/zoologists-and-wildlife-biologists/zh-CN.json';
            file_put_contents($path, '{"broken":true}');
            $corrupt = $this->hashes($root);
            try {
                $migration->run($root, true);
                self::fail('Corrupt input must fail before any writes');
            } catch (CareerCurrentAuthorityPackageFailure) {
                self::assertSame($corrupt, $this->hashes($root));
            }
        } finally {
            $files->deleteDirectory($root);
        }
    }

    private function hashes(string $root): array
    {
        $hashes = [];
        foreach (glob($root.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/careers/*/*.json') as $path) {
            $hashes[$path] = hash_file('sha256', $path);
        }
        foreach ([CareerCurrentAuthorityPackage::RELATIVE_PATH.'/manifest.json', CareerCurrentAuthorityReleaseIntent::RELATIVE_PATH] as $relative) {
            $hashes[$root.'/'.$relative] = hash_file('sha256', $root.'/'.$relative);
        }

        return $hashes;
    }
}
