<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3BatchUpdater;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use App\Domain\Career\Display\CareerCurrentAuthorityReleaseIntent;
use Tests\Support\CareerCurrentPackageFixture;
use Tests\TestCase;

final class CareerContentV3BatchUpdaterTest extends TestCase
{
    public function test_two_locales_commit_as_one_valid_package_and_repeat_deterministically(): void
    {
        [$root, $source, $selection] = $this->fixture();
        try {
            $updater = app(CareerContentV3BatchUpdater::class);
            $dryRun = $updater->update($root, $source, array_reverse($selection), false);
            self::assertSame(2, $dryRun['changed_locale_pages']);
            self::assertFalse($dryRun['written']);
            $result = $updater->update($root, $source, $selection, true);
            self::assertSame($dryRun['source_selection_sha256'], $result['source_selection_sha256']);
            self::assertSame($dryRun['aggregate_sha256'], $result['aggregate_sha256']);
            self::assertSame(['actors|en', 'actors|zh-CN'], $result['changed_pages']);
            self::assertTrue($result['written']);
            self::assertSame(0, $result['database_writes']);
            self::assertSame(0, $result['search_submissions']);
            self::assertSame(1046, app(CareerCurrentAuthorityReleaseIntent::class)->verify($root)['package']['slug_count']);
            $repeat = $updater->update($root, $source, $selection, true);
            self::assertSame(0, $repeat['changed_locale_pages']);
            self::assertFalse($repeat['written']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_wrong_source_hash_fails_before_any_current_file_changes(): void
    {
        [$root, $source, $selection] = $this->fixture();
        try {
            $selection[1]['sha256'] = str_repeat('0', 64);
            $paths = [$root.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/careers/actors/en.json',
                $root.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/manifest.json',
                $root.'/'.CareerCurrentAuthorityReleaseIntent::RELATIVE_PATH];
            $before = array_map(static fn (string $path): string => hash_file('sha256', $path), $paths);
            try {
                app(CareerContentV3BatchUpdater::class)->update($root, $source, $selection, true);
                self::fail('The batch must reject a mismatched source hash.');
            } catch (CareerCurrentAuthorityPackageFailure $error) {
                self::assertSame('CURRENT_CONTENT_V3_BATCH_SOURCE_HASH_MISMATCH', $error->safeCode);
            }
            self::assertSame($before, array_map(static fn (string $path): string => hash_file('sha256', $path), $paths));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_invalid_second_locale_never_applies_the_first_locale(): void
    {
        [$root, $source, $selection] = $this->fixture();
        try {
            $zhPath = $source.'/careers/actors/zh-CN.json';
            $invalid = json_decode((string) file_get_contents($zhPath), true, 512, JSON_THROW_ON_ERROR);
            $invalid['subject']['canonical_slug'] = 'different';
            $bytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($invalid);
            file_put_contents($zhPath, $bytes);
            $selection[1]['sha256'] = hash('sha256', $bytes);
            $before = hash_file('sha256', $root.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/careers/actors/en.json');
            try {
                app(CareerContentV3BatchUpdater::class)->update($root, $source, $selection, true);
                self::fail('The batch must reject an invalid second page.');
            } catch (CareerCurrentAuthorityPackageFailure $error) {
                self::assertSame('CURRENT_CONTENT_V3_BATCH_IDENTITY_INVALID', $error->safeCode);
            }
            self::assertSame($before, hash_file('sha256', $root.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/careers/actors/en.json'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    /** @return array{string,string,list<array{slug:string,locale:string,path:string,sha256:string}>} */
    private function fixture(): array
    {
        $root = CareerCurrentPackageFixture::create(base_path());
        $sourceCurrent = $root.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH;
        $source = $root.'/source';
        mkdir($source, 0700, true);

        $selection = [];
        foreach (['en', 'zh-CN'] as $locale) {
            $path = 'careers/actors/'.$locale.'.json';
            $page = json_decode((string) file_get_contents($sourceCurrent.'/'.$path), true, 512, JSON_THROW_ON_ERROR);
            $page['subject']['name'] .= ' test';
            $bytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($page);
            $sourcePath = $source.'/'.$path;
            if (! is_dir(dirname($sourcePath))) {
                mkdir(dirname($sourcePath), 0700, true);
            }
            file_put_contents($sourcePath, $bytes);
            $selection[] = ['slug' => 'actors', 'locale' => $locale, 'path' => $path, 'sha256' => hash('sha256', $bytes)];
        }

        return [$root, $source, $selection];
    }

    private function removeDirectory(string $root): void
    {
        app(\Illuminate\Filesystem\Filesystem::class)->deleteDirectory($root);
    }
}
