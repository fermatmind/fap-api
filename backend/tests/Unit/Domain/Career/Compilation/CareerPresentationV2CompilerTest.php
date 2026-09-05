<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerPresentationV2Compiler;
use App\Domain\Career\Display\CareerContentV3Contract;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use App\Domain\Career\Display\CareerPresentationV2Contract;
use Tests\TestCase;

final class CareerPresentationV2CompilerTest extends TestCase
{
    public function test_current_package_uses_per_page_schema_bilingual_identities_and_explicit_body_states(): void
    {
        $package = app(CareerCurrentAuthorityPackage::class)->load(base_path());
        self::assertArrayNotHasKey('rows', $package);
        self::assertCount(1046, $package['pages']);
        self::assertCount(2092, $package['manifest']['files']);
        $enhanced = $legacy = 0;
        foreach ($package['pages'] as $slug => $localized) {
            self::assertSame(['en', 'zh-CN'], array_keys($localized));
            foreach ($localized as $locale => $page) {
                CareerContentV3Contract::assert($page);
                self::assertSame($locale, $page['locale']);
                self::assertSame($slug, $page['subject']['canonical_slug']);
                self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $page['source_content_sha256']);
                if ($slug === 'accountants-and-auditors') {
                    self::assertSame('enhanced', $page['content_state']);
                    self::assertSame([
                        'quick-decision', 'profile', 'direction-comparison', 'ai-impact',
                        'china-salary', 'us-salary', 'fit', 'risk', 'path',
                        'market-signals', 'sources', 'navigation', 'source-register',
                    ], array_column($page['blocks'], 'id'));
                    $enhanced++;
                } else {
                    self::assertSame('legacy', $page['content_state']);
                    self::assertSame([], $page['blocks']);
                    self::assertNull($page['subject']['summary']);
                    $legacy++;
                }
            }
        }
        self::assertSame(2, $enhanced);
        self::assertSame(2090, $legacy);
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
