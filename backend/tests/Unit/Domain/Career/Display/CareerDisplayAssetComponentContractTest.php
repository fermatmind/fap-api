<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use PHPUnit\Framework\TestCase;

final class CareerDisplayAssetComponentContractTest extends TestCase
{
    public function test_it_accepts_any_non_empty_unique_supported_component_order(): void
    {
        self::assertTrue(CareerDisplayAssetComponentContract::supports(
            CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS,
        ));
        self::assertTrue(CareerDisplayAssetComponentContract::supports(['definition_block']));
        self::assertFalse(CareerDisplayAssetComponentContract::supports([]));

        $unknown = CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS;
        $unknown[5] = 'unknown_component';
        self::assertFalse(CareerDisplayAssetComponentContract::supports($unknown));

        $duplicate = CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS;
        $duplicate[11] = $duplicate[10];
        self::assertFalse(CareerDisplayAssetComponentContract::supports($duplicate));

        $reordered = CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS;
        [$reordered[10], $reordered[11]] = [$reordered[11], $reordered[10]];
        self::assertTrue(CareerDisplayAssetComponentContract::supports($reordered));
    }

    public function test_current_pages_require_published_zh_and_accept_unavailable_or_published_en_components(): void
    {
        $base = array_fill_keys(CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS, ['value' => 'verified']);
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
        $componentOrder = CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS;

        self::assertTrue(CareerDisplayAssetComponentContract::hasDeclaredPages($payload, $componentOrder));
        self::assertNull(CareerDisplayAssetComponentContract::pageFailureCode($payload, $componentOrder));

        $databaseOrdered = $payload;
        $databaseOrdered['page']['en']['career_quick_answers_block'] = array_reverse($unavailable, true);
        $databaseOrdered['page']['en']['onet_structured_fields_block'] = array_reverse($unavailable, true);
        self::assertTrue(CareerDisplayAssetComponentContract::hasDeclaredPages($databaseOrdered, $componentOrder));
        self::assertNull(CareerDisplayAssetComponentContract::pageFailureCode($databaseOrdered, $componentOrder));

        $malformed = $payload;
        unset($malformed['page']['zh']['career_quick_answers_block']['items'][0]['table']['rows'][0]['alternate_value']);
        self::assertFalse(CareerDisplayAssetComponentContract::hasDeclaredPages($malformed, $componentOrder));
        self::assertSame(
            'CURRENT_DISPLAY_SURFACE_ZH_QUICK_ANSWER_TABLE_INVALID',
            CareerDisplayAssetComponentContract::pageFailureCode($malformed, $componentOrder),
        );

        $published = $payload;
        $published['page']['en']['career_quick_answers_block'] = $base['career_quick_answers_block'];
        $published['page']['en']['career_quick_answers_block']['heading'] = 'Career quick answers';
        $published['page']['en']['onet_structured_fields_block'] = $base['onet_structured_fields_block'];
        $published['page']['en']['onet_structured_fields_block']['heading'] = 'O*NET structured fields';
        self::assertTrue(CareerDisplayAssetComponentContract::hasDeclaredPages($published, $componentOrder));
        self::assertNull(CareerDisplayAssetComponentContract::pageFailureCode($published, $componentOrder));

        $mixed = $published;
        $mixed['page']['en']['onet_structured_fields_block'] = $unavailable;
        self::assertTrue(CareerDisplayAssetComponentContract::hasDeclaredPages($mixed, $componentOrder));

        $subset = ['definition_block'];
        self::assertTrue(CareerDisplayAssetComponentContract::hasDeclaredPages($payload, $subset));

        $reorderedSubset = ['final_cta', 'definition_block'];
        self::assertTrue(CareerDisplayAssetComponentContract::hasDeclaredPages($payload, $reorderedSubset));

        $unknownField = $payload;
        $unknownField['page']['en']['future_component'] = ['value' => 'verified'];
        self::assertFalse(CareerDisplayAssetComponentContract::hasDeclaredPages($unknownField, $subset));
        self::assertSame(
            'CURRENT_DISPLAY_SURFACE_COMPONENT_UNEXPECTED',
            CareerDisplayAssetComponentContract::pageFailureCode($unknownField, $subset),
        );

        $missingLocale = $payload;
        unset($missingLocale['page']['en']);
        self::assertFalse(CareerDisplayAssetComponentContract::hasDeclaredPages($missingLocale, $subset));
        self::assertSame(
            'CURRENT_DISPLAY_SURFACE_LOCALE_PAGE_MISSING',
            CareerDisplayAssetComponentContract::pageFailureCode($missingLocale, $subset),
        );

        $placeholder = $payload;
        $placeholder['page']['en']['definition_block'] = ['module_state' => 'pending_content'];
        self::assertFalse(CareerDisplayAssetComponentContract::hasDeclaredPages($placeholder, $subset));
        self::assertSame(
            'CURRENT_DISPLAY_SURFACE_PLACEHOLDER_PRESENT',
            CareerDisplayAssetComponentContract::pageFailureCode($placeholder, $subset),
        );

        unset($payload['page']['en']['definition_block']);
        self::assertFalse(CareerDisplayAssetComponentContract::hasDeclaredPages($payload, $subset));
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
