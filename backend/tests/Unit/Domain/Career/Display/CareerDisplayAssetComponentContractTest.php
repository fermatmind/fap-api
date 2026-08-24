<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use PHPUnit\Framework\TestCase;

final class CareerDisplayAssetComponentContractTest extends TestCase
{
    public function test_it_accepts_only_the_exact_24_26_and_28_component_orders(): void
    {
        self::assertTrue(CareerDisplayAssetComponentContract::supports(
            CareerDisplayAssetComponentContract::LEGACY_V4_2_ORDER,
        ));
        self::assertTrue(CareerDisplayAssetComponentContract::supports(
            CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER,
        ));
        self::assertTrue(CareerDisplayAssetComponentContract::supports(
            CareerDisplayAssetComponentContract::CURRENT_V4_3_ORDER,
        ));
        self::assertTrue(CareerDisplayAssetComponentContract::matchesVersion(
            CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER,
            'v4.2',
        ));
        self::assertTrue(CareerDisplayAssetComponentContract::matchesVersion(
            CareerDisplayAssetComponentContract::CURRENT_V4_3_ORDER,
            'v4.3',
        ));
        self::assertFalse(CareerDisplayAssetComponentContract::matchesVersion(
            CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER,
            'v4.3',
        ));
        self::assertFalse(CareerDisplayAssetComponentContract::supports(
            array_slice(CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER, 0, 25),
        ));

        $unknown = CareerDisplayAssetComponentContract::LEGACY_V4_2_ORDER;
        $unknown[5] = 'unknown_component';
        self::assertFalse(CareerDisplayAssetComponentContract::supports($unknown));

        $duplicate = CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER;
        $duplicate[11] = $duplicate[10];
        self::assertFalse(CareerDisplayAssetComponentContract::supports($duplicate));

        $reordered = CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER;
        [$reordered[10], $reordered[11]] = [$reordered[11], $reordered[10]];
        self::assertFalse(CareerDisplayAssetComponentContract::supports($reordered));
    }

    public function test_v43_requires_published_zh_and_exact_unavailable_en_structured_components(): void
    {
        $base = array_fill_keys(CareerDisplayAssetComponentContract::CURRENT_V4_3_ORDER, ['value' => 'verified']);
        $base['career_quick_answers_block'] = [
            'availability' => 'published',
            'schema_version' => 'career.quick_answers.v1',
            'heading' => '职业速答',
            'items' => array_map(static fn (string $key): array => [
                'key' => $key,
                'question' => $key.' question',
                'answer' => $key.' answer',
                'table' => ['rows' => [self::row()]],
            ], ['qa3', 'qa2', 'qa1']),
        ];
        $base['onet_structured_fields_block'] = [
            'availability' => 'published',
            'schema_version' => 'career.onet_structured_fields.v1',
            'heading' => 'O*NET 结构化字段',
            'rows' => [self::row()],
        ];
        $unavailable = ['availability' => 'unavailable', 'reason_code' => 'source_locale_unavailable'];
        $en = $base;
        $en['career_quick_answers_block'] = $unavailable;
        $en['onet_structured_fields_block'] = $unavailable;
        $payload = ['page' => ['en' => $en, 'zh' => $base]];

        self::assertTrue(CareerDisplayAssetComponentContract::hasExactPagesForVersion($payload, 'v4.3'));
        self::assertNull(CareerDisplayAssetComponentContract::pageFailureCodeForVersion($payload, 'v4.3'));

        $malformed = $payload;
        unset($malformed['page']['zh']['career_quick_answers_block']['items'][0]['table']['rows'][0]['alternate_value']);
        self::assertFalse(CareerDisplayAssetComponentContract::hasExactPagesForVersion($malformed, 'v4.3'));
        self::assertSame(
            'CURRENT_DISPLAY_SURFACE_V43_ZH_QUICK_ANSWER_TABLE_INVALID',
            CareerDisplayAssetComponentContract::pageFailureCodeForVersion($malformed, 'v4.3'),
        );

        $translated = $payload;
        $translated['page']['en']['career_quick_answers_block'] = $base['career_quick_answers_block'];
        self::assertFalse(CareerDisplayAssetComponentContract::hasExactPagesForVersion($translated, 'v4.3'));
        self::assertSame(
            'CURRENT_DISPLAY_SURFACE_V43_EN_QUICK_ANSWERS_INVALID',
            CareerDisplayAssetComponentContract::pageFailureCodeForVersion($translated, 'v4.3'),
        );
    }

    /** @return array{label:string,value:string,alternate_value:null,secondary_value:null} */
    private static function row(): array
    {
        return [
            'label' => 'label',
            'value' => 'value',
            'alternate_value' => null,
            'secondary_value' => null,
        ];
    }
}
