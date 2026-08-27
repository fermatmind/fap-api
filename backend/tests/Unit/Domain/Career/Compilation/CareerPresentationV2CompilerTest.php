<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerPresentationV2Compiler;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerPresentationV2Contract;
use Tests\TestCase;

final class CareerPresentationV2CompilerTest extends TestCase
{
    public function test_current_package_publishes_bilingual_v2_for_every_locale_page(): void
    {
        ini_set('memory_limit', '2048M');
        $package = app(CareerCurrentAuthorityPackage::class)->load(base_path());
        $localePages = 0;
        $enhanced = 0;
        $legacy = 0;
        foreach ($package['rows'] as $slug => $row) {
            foreach (['en', 'zh-CN'] as $locale) {
                $projection = app(CareerCurrentAuthorityPackage::class)->publicProjection($row, $locale);
                $presentation = $projection['presentation_v2'] ?? null;
                self::assertIsArray($presentation);
                CareerPresentationV2Contract::assert($presentation, $row['component_order_json']);
                self::assertSame($locale, $presentation['locale']);
                self::assertSame($row['component_order_json'], array_merge(...array_column($presentation['groups'], 'component_ids')));
                foreach ($presentation['groups'] as $group) {
                    if ($slug === 'accountants-and-auditors') {
                        self::assertSame('enhanced', $group['content_state']);
                        self::assertArrayNotHasKey('pending_enrichment', $group);
                        $enhanced++;
                    } else {
                        self::assertSame('legacy', $group['content_state']);
                        self::assertSame('display_placeholder', $group['pending_enrichment']);
                        $legacy++;
                    }
                }
                $localePages++;
            }
        }

        self::assertSame(2092, $localePages);
        self::assertGreaterThan(0, $enhanced);
        self::assertGreaterThan(0, $legacy);
    }

    public function test_projection_is_content_preserving_and_uses_language_neutral_contract_keys(): void
    {
        $authorityPackage = app(CareerCurrentAuthorityPackage::class);
        $package = $authorityPackage->load(base_path());
        $row = $package['rows']['actors'];
        $projection = $authorityPackage->publicProjection($row, 'en');
        $page = $projection['page']['content'];
        $pageHash = CareerCurrentAuthorityPackage::hashValue($page);
        $orderHash = CareerCurrentAuthorityPackage::hashValue($row['component_order_json']);

        $presentation = app(CareerPresentationV2Compiler::class)->project(
            'actors',
            'en',
            $page,
            $row['component_order_json'],
            $row['metadata_json']['presentation_v1']['zh'],
        );

        self::assertSame($pageHash, CareerCurrentAuthorityPackage::hashValue($page));
        self::assertSame($orderHash, CareerCurrentAuthorityPackage::hashValue($row['component_order_json']));
        $keys = array_keys($presentation);
        sort($keys, SORT_STRING);
        self::assertSame(
            ['contract_version', 'design_authority', 'groups', 'hero', 'locale', 'template_id'],
            $keys,
        );
        self::assertSame('Career overview', $presentation['groups'][0]['label']);
        self::assertDoesNotMatchRegularExpression(
            '/会计|审计/u',
            CareerCurrentAuthorityPackage::encodeCanonical(array_keys($presentation['hero'])),
        );
    }
}
