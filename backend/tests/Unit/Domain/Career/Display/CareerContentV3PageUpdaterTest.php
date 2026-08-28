<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3PageUpdater;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityReleaseIntent;
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
}
