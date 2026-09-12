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

    public function test_actor_pay_keeps_hourly_and_historical_project_units(): void
    {
        $page = $this->projector()->read('actors', 'zh-CN');
        self::assertSame('美国时薪中位数', $page['hero']['metrics'][0]['label']);
        self::assertSame('29.05美元/小时', $page['hero']['metrics'][0]['fact']['display_value']);
        self::assertSame('available', $page['hero']['metrics'][4]['availability']);
        self::assertSame('中国历史单项目特约演员日价', $page['hero']['metrics'][4]['label']);
        self::assertSame('300–600元/天', $page['hero']['metrics'][4]['fact']['display_value']);
        self::assertStringContainsString('不代表群众演员或全国职业收入', $page['hero']['metrics'][4]['fact']['occupation_scope']);
        self::assertStringContainsString('不换算月薪', $page['hero']['metrics'][4]['fact']['derivation']);
        self::assertSame(['r2-pay-1'], $page['hero']['metrics'][4]['fact']['source_refs']);
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

    public function test_internal_import_markers_remain_in_the_file_but_never_become_public_body(): void
    {
        $source = json_decode(file_get_contents(dirname(__DIR__, 5).'/content_assets/career/current/careers/accountants-and-auditors/zh-CN.json'), true, 512, JSON_THROW_ON_ERROR);
        $page = $this->projector()->project($source);
        $publicIds = array_column(array_merge(...array_column($page['content']['blocks'], 'items')), 'id');
        $internalCount = 0;
        foreach ($source['blocks'] as $block) {
            foreach ($block['items'] as $item) {
                if (($item['visibility'] ?? 'public') === 'internal') {
                    $internalCount++;
                    self::assertNotContains($item['id'], $publicIds);
                } else {
                    self::assertContains($item['id'], $publicIds);
                }
            }
        }
        self::assertGreaterThan(0, $internalCount);
        self::assertStringNotContainsString('"paragraphs":["published"]', CareerCurrentAuthorityPackage::encodeCanonical($page));
    }
}
