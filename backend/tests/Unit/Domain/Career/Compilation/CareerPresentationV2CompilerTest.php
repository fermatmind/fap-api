<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerPresentationV2Compiler;
use App\Domain\Career\Display\CareerContentV3Contract;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageLoader;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use App\Domain\Career\Display\CareerPresentationV2Contract;
use Tests\TestCase;

final class CareerPresentationV2CompilerTest extends TestCase
{
    public function test_current_package_uses_per_page_schema_for_each_locale_and_explicit_body_states(): void
    {
        $loader = app(CareerCurrentAuthorityPackageLoader::class);
        $package = $loader->indexForPublish(base_path());
        self::assertArrayNotHasKey('rows', $package);
        self::assertCount(1046, $package['entries']);
        $locales = CareerContentV3Contract::LOCALES;
        $expectedCount = count($package['entries']) * count($locales);
        self::assertCount($expectedCount, $package['manifest']['files']);
        $enhanced = $legacy = 0;
        foreach ($package['entries'] as $localized) {
            self::assertSame($locales, array_keys($localized));
            foreach ($localized as $entry) {
                $entry['body_qualification']['has_public_body'] ? $enhanced++ : $legacy++;
            }
        }

        // The release package scan verifies every file. This contract exercises
        // the canonical reader and compiler input in each supported locale.
        foreach ($locales as $locale) {
            $slug = 'accountants-and-auditors';
            $page = $loader->pageFromPublishIndex($package, $slug, $locale);
            CareerContentV3Contract::assert($page);
            self::assertSame($locale, $page['locale']);
            self::assertSame($slug, $page['subject']['canonical_slug']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $page['source_content_sha256']);
            self::assertSame('enhanced', $page['content_state']);
            self::assertSame([
                'quick-decision', 'profile', 'direction-comparison', 'ai-impact',
                'china-salary', 'us-salary', 'fit', 'risk', 'path',
                'market-signals', 'sources', 'navigation', 'source-register',
            ], array_column($page['blocks'], 'id'));
        }

        self::assertSame($package['manifest']['coverage']['enhanced_locale_pages'], $enhanced);
        self::assertSame($package['manifest']['coverage']['legacy_locale_pages'], $legacy);
        self::assertSame($expectedCount, $enhanced + $legacy);
    }

    public function test_projection_is_content_preserving_and_uses_language_neutral_contract_keys(): void
    {
        $page = [
            'hero' => ['h1' => 'Fixture occupation', 'quick_answer' => 'Fixture summary'],
            'primary_cta' => ['label' => 'Explore', 'href' => '/en/tests/holland-career-interest-test-riasec'],
        ];
        $order = CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS;
        $pageHash = CareerCurrentAuthorityPackage::hashValue($page);
        $orderHash = CareerCurrentAuthorityPackage::hashValue($order);
        $presentation = app(CareerPresentationV2Compiler::class)->project('fixture-occupation', 'en', $page, $order, null);
        CareerPresentationV2Contract::assert($presentation, $order);

        self::assertSame($pageHash, CareerCurrentAuthorityPackage::hashValue($page));
        self::assertSame($orderHash, CareerCurrentAuthorityPackage::hashValue($order));
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
