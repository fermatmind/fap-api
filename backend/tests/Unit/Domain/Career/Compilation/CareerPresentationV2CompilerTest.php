<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerPresentationV2Compiler;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageLoader;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use App\Domain\Career\Display\CareerPresentationV2Contract;
use Tests\TestCase;

final class CareerPresentationV2CompilerTest extends TestCase
{
    public function test_accountants_current_body_has_the_expected_block_order_in_all_three_locales(): void
    {
        $loader = app(CareerCurrentAuthorityPackageLoader::class);
        $package = $loader->indexForPublish(base_path());
        self::assertArrayNotHasKey('rows', $package);
        self::assertSame(['en', 'zh-CN', 'zh-TW'], $package['manifest']['locales']);

        foreach (['en', 'zh-CN', 'zh-TW'] as $locale) {
            $page = $loader->pageFromPublishIndex($package, 'accountants-and-auditors', $locale);
            self::assertSame('enhanced', $page['content_state']);
            self::assertSame([
                'quick-decision', 'profile', 'direction-comparison', 'ai-impact',
                'china-salary', 'us-salary', 'fit', 'risk', 'path',
                'market-signals', 'sources', 'navigation', 'source-register',
            ], array_column($page['blocks'], 'id'));
        }
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
