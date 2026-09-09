<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerContentV3PageUpdater;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityReleaseIntent;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

final class CareerContentV3PageUpdaterTest extends TestCase
{
    public function test_dry_run_is_bounded_to_one_page_and_performs_no_writes(): void
    {
        $page = base_path(CareerCurrentAuthorityPackage::RELATIVE_PATH.'/careers/accountants-and-auditors/zh-CN.json');
        $manifest = base_path(CareerCurrentAuthorityPackage::RELATIVE_PATH.'/manifest.json');
        $intent = base_path(CareerCurrentAuthorityReleaseIntent::RELATIVE_PATH);
        $before = array_map('hash_file', array_fill(0, 3, 'sha256'), [$page, $manifest, $intent]);

        $result = app(CareerContentV3PageUpdater::class)->update(
            base_path(),
            'accountants-and-auditors',
            'zh-CN',
            false,
        );

        self::assertSame('PASS_CAREER_CONTENT_V3_PAGE_DRY_RUN', $result['status']);
        self::assertSame('accountants-and-auditors|zh-CN', $result['page']);
        self::assertFalse($result['written']);
        self::assertSame(0, $result['database_writes']);
        self::assertSame(0, $result['cache_writes']);
        self::assertSame(0, $result['discoverability_writes']);
        self::assertSame(0, $result['search_submissions']);
        self::assertSame($before, array_map('hash_file', array_fill(0, 3, 'sha256'), [$page, $manifest, $intent]));
    }

    public function test_page_state_transitions_refresh_coverage_and_remain_idempotent(): void
    {
        $root = sys_get_temp_dir().'/career-page-coverage-'.bin2hex(random_bytes(8));
        $files = new Filesystem;
        $current = $root.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH;
        $files->makeDirectory(dirname($current), 0700, true);
        $files->copyDirectory(base_path(CareerCurrentAuthorityPackage::RELATIVE_PATH), $current);
        $files->copy(base_path(CareerCurrentAuthorityReleaseIntent::RELATIVE_PATH), $root.'/'.CareerCurrentAuthorityReleaseIntent::RELATIVE_PATH);
        $paths = [$current.'/careers/actors/zh-CN.json', $current.'/manifest.json', $root.'/'.CareerCurrentAuthorityReleaseIntent::RELATIVE_PATH];
        try {
            $originalPage = (string) file_get_contents($paths[0]);
            $page = json_decode($originalPage, true, 512, JSON_THROW_ON_ERROR);
            $originalManifest = json_decode((string) file_get_contents($paths[1]), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('enhanced', $page['content_state']);
            $page['content_state'] = 'legacy';
            $page['blocks'] = [];
            $page['subject']['summary'] = null;
            unset($page['fact_register']);
            foreach ($page['hero']['metrics'] as &$metric) {
                $metric['availability'] = 'missing';
                $metric['fact_ref'] = null;
            }
            unset($metric);
            file_put_contents($paths[0], CareerCurrentAuthorityPackage::encodePrettyCanonical($page));
            $updater = app(CareerContentV3PageUpdater::class);
            $updater->update($root, 'actors', 'zh-CN', true);
            $package = (new CareerContentV3AuthorityPackage)->load($root);
            self::assertSame($originalManifest['coverage']['enhanced_locale_pages'] - 1, $package['manifest']['coverage']['enhanced_locale_pages']);
            self::assertSame($package['summary']['enhanced_locale_page_count'], $package['manifest']['coverage']['enhanced_locale_pages']);
            self::assertSame($package['summary']['legacy_locale_page_count'], $package['manifest']['coverage']['legacy_locale_pages']);

            file_put_contents($paths[0], $originalPage);
            $updater->update($root, 'actors', 'zh-CN', true);
            $restored = (new CareerContentV3AuthorityPackage)->load($root);
            self::assertSame($originalManifest, $restored['manifest']);
            $before = array_map('file_get_contents', $paths);
            self::assertFalse($updater->update($root, 'actors', 'zh-CN', true)['changed']);
            self::assertSame($before, array_map('file_get_contents', $paths));
        } finally {
            $files->deleteDirectory($root);
        }
    }
}
