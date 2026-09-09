<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Domain\Career\Display\CareerContentV3FactResolver;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use App\Domain\Career\Display\CareerPageProjector;
use PHPUnit\Framework\TestCase;

final class CareerPageProjectorTest extends TestCase
{
    private function projector(): CareerPageProjector
    {
        $package = new CareerContentV3AuthorityPackage;

        return new CareerPageProjector(new CareerContentV3CanonicalReader($package, dirname(__DIR__, 5)), $package, new CareerContentV3FactResolver);
    }

    public function test_every_locale_file_projects_without_database_display_data(): void
    {
        $root = dirname(__DIR__, 5).'/content_assets/career/current';
        $manifest = json_decode(file_get_contents($root.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $projector = $this->projector();
        foreach ($manifest['files'] as $entry) {
            $source = json_decode(file_get_contents($root.'/'.$entry['path']), true, 512, JSON_THROW_ON_ERROR);
            $page = $projector->project($source);
            self::assertSame($entry['source_content_sha256'], $page['source_content_sha256']);
            self::assertSame(array_column($source['blocks'], 'id'), array_column($page['content']['blocks'], 'id'));
            self::assertCount(5, $page['hero']['metrics']);
            self::assertArrayNotHasKey('presentation_v2', $page);
            self::assertArrayNotHasKey('page', $page);
        }
    }

    public function test_actor_hourly_pay_is_not_annualized_and_china_slot_stays_missing(): void
    {
        $page = $this->projector()->read('actors', 'zh-CN');
        self::assertSame('美国时薪中位数', $page['hero']['metrics'][0]['label']);
        self::assertSame('29.05美元/小时', $page['hero']['metrics'][0]['fact']['display_value']);
        self::assertSame('missing', $page['hero']['metrics'][4]['availability']);
        self::assertNull($page['hero']['metrics'][4]['fact']);
        self::assertCount(4, $page['content']['blocks'][0]['items']);
        self::assertStringNotContainsString('这行就稳', CareerCurrentAuthorityPackage::encodeCanonical($page));
    }

    public function test_missing_file_and_wrong_locale_fail_closed(): void
    {
        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        $this->projector()->read('actors', 'fr');
    }

    public function test_metric_cannot_reference_an_unknown_fact(): void
    {
        $source = json_decode(file_get_contents(dirname(__DIR__, 5).'/content_assets/career/current/careers/actors/zh-CN.json'), true, 512, JSON_THROW_ON_ERROR);
        $source['hero']['metrics'][0]['fact_ref'] = 'absent';
        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        $this->projector()->project($source);
    }
}
