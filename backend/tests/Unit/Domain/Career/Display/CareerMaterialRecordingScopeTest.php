<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Domain\Career\Display\CareerContentV3PageUpdater;
use App\Domain\Career\Display\CareerCurrentIdentity;
use Tests\TestCase;

final class CareerMaterialRecordingScopeTest extends TestCase
{
    public function test_material_recording_scope_excludes_order_fillers_and_keeps_english_placeholder(): void
    {
        $slug = 'material-recording-clerks';
        $identity = app(CareerCurrentIdentity::class);
        self::assertSame(['43-5061.00', '43-5071.00', '43-5111.00'], array_column($identity->definition($slug)['occupations'], 'code'));
        $reader = app(CareerContentV3CanonicalReader::class);
        $en = $reader->page($slug, 'en');
        self::assertSame('Material Recording Clerks', $en['subject']['name']);
        self::assertSame('legacy', $en['content_state']);
        self::assertSame([], $en['blocks']);
        $zh = $reader->page($slug, 'zh-CN');
        self::assertSame('物料记录文员', $zh['subject']['name']);
        self::assertSame('enhanced', $zh['content_state']);
        $facts = json_encode($zh['fact_register'], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('53-7065', $facts);
        self::assertStringContainsString('43-5111', $facts);
        $public = $identity->projectPayload([
            'slug' => $slug, 'title' => 'Stock Clerks And Order Fillers',
            'ontology' => ['crosswalks' => [['source_code' => '53-7065.00']]],
            'truth_summary' => ['median_pay_usd_annual' => 1],
        ], 'zh-CN');
        self::assertSame('物料记录文员', $public['title']);
        self::assertNotContains('53-7065.00', array_column($public['ontology']['crosswalks'], 'source_code'));
        self::assertNull($public['truth_summary']['median_pay_usd_annual']);
        self::assertFalse(app(CareerContentV3PageUpdater::class)->update(base_path(), $slug, 'zh-CN', false)['changed']);
    }
}
