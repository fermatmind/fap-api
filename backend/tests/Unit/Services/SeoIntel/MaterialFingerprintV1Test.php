<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SeoIntel;

use App\Services\SeoIntel\MaterialFingerprint\MaterialChangeContract;
use App\Services\SeoIntel\MaterialFingerprint\MaterialFingerprintV1;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MaterialFingerprintV1Test extends TestCase
{
    private MaterialFingerprintV1 $fingerprint;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fingerprint = new MaterialFingerprintV1;
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function goldenVectors(): iterable
    {
        $path = dirname(__DIR__, 3).'/Fixtures/SeoIntel/material-fingerprint-v1-golden-vectors.json';
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        foreach ($fixture['vectors'] as $vector) {
            yield $vector['id'] => [
                $vector['input'],
                $vector['expected_canonical_json'],
                $vector['expected_fingerprint'],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws JsonException
     */
    #[DataProvider('goldenVectors')]
    public function test_it_matches_golden_vectors(
        array $input,
        string $expectedCanonicalJson,
        string $expectedFingerprint,
    ): void {
        self::assertSame($expectedCanonicalJson, $this->fingerprint->canonicalJson($input));
        self::assertSame($expectedFingerprint, $this->fingerprint->fingerprint($input));
    }

    public function test_semantically_equivalent_object_field_order_is_stable(): void
    {
        $input = $this->baselineInput();
        $reordered = [
            'public_structure' => ['modules' => ['summary', 'sources']],
            'locale_linkage' => ['counterparts' => ['en']],
            'search_surface' => ['indexable' => true, 'canonical' => '/zh-CN/articles/example'],
            'claims_and_sources' => [['source' => 'authority', 'claim' => 'bounded']],
            'visible_content' => ['title' => '示例', 'summary' => '稳定内容'],
            'authority_revision_kind' => 'article_translation_revision',
            'public_identity' => 'article:example',
            'locale' => 'zh-CN',
            'family' => 'article',
        ];

        self::assertSame(
            $this->fingerprint->canonicalJson($input),
            $this->fingerprint->canonicalJson($reordered),
        );
        self::assertSame(
            $this->fingerprint->fingerprint($input),
            $this->fingerprint->fingerprint($reordered),
        );
    }

    public function test_non_material_context_never_changes_the_fingerprint(): void
    {
        $input = $this->baselineInput();
        $withRuntimeNoise = $input + [
            'non_material_context' => [
                'cache_warmed_at' => '2099-01-01T00:00:00Z',
                'compiled_at' => '2099-01-02T00:00:00Z',
                'deploy_sha' => str_repeat('f', 40),
                'format_revision' => 99,
                'view_count' => 123456,
            ],
        ];

        self::assertSame(
            $this->fingerprint->fingerprint($input),
            $this->fingerprint->fingerprint($withRuntimeNoise),
        );
    }

    public function test_locale_and_business_array_order_are_material_boundaries(): void
    {
        $input = $this->baselineInput();
        $otherLocale = $input;
        $otherLocale['locale'] = 'en';
        $otherModuleOrder = $input;
        $otherModuleOrder['public_structure']['modules'] = ['sources', 'summary'];

        self::assertNotSame(
            $this->fingerprint->fingerprint($input),
            $this->fingerprint->fingerprint($otherLocale),
        );
        self::assertNotSame(
            $this->fingerprint->fingerprint($input),
            $this->fingerprint->fingerprint($otherModuleOrder),
        );
    }

    public function test_material_and_non_material_classes_are_explicit_and_unknown_holds(): void
    {
        foreach (MaterialChangeContract::MATERIAL_CLASSES as $changeClass) {
            self::assertSame(MaterialChangeContract::MATERIAL, MaterialChangeContract::classify($changeClass));
        }

        foreach (MaterialChangeContract::NON_MATERIAL_CLASSES as $changeClass) {
            self::assertSame(MaterialChangeContract::NON_MATERIAL, MaterialChangeContract::classify($changeClass));
        }

        self::assertSame(MaterialChangeContract::UNKNOWN, MaterialChangeContract::classify('future_unclassified_change'));
    }

    public function test_missing_invalid_or_unknown_contract_fields_fail_closed(): void
    {
        $missing = $this->baselineInput();
        unset($missing['search_surface']);

        try {
            $this->fingerprint->fingerprint($missing);
            self::fail('Missing material field must fail closed.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Missing material fingerprint field', $exception->getMessage());
        }

        $unknown = $this->baselineInput() + ['compiled_at' => '2099-01-01T00:00:00Z'];
        $this->expectException(InvalidArgumentException::class);
        $this->fingerprint->fingerprint($unknown);
    }

    /** @return array<string, mixed> */
    private function baselineInput(): array
    {
        return [
            'family' => 'article',
            'locale' => 'zh-CN',
            'public_identity' => 'article:example',
            'authority_revision_kind' => 'article_translation_revision',
            'visible_content' => ['summary' => '稳定内容', 'title' => '示例'],
            'claims_and_sources' => [['claim' => 'bounded', 'source' => 'authority']],
            'search_surface' => ['canonical' => '/zh-CN/articles/example', 'indexable' => true],
            'locale_linkage' => ['counterparts' => ['en']],
            'public_structure' => ['modules' => ['summary', 'sources']],
        ];
    }
}
