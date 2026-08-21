<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerPresentationSourceRegistry;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CareerPresentationSourceRegistryTest extends TestCase
{
    private const BLS_EXPECTED = [
        'appraisers-of-personal-and-business-property' => [
            'combined_official', ['$65,420', '4%', '77,300 人', '6,300 个'],
        ],
        'customs-brokers' => [
            'parent_occupation_proxy', ['$78,420', '3%', '418,000 人', '33,300 个'],
        ],
        'first-line-supervisors-of-helpers-laborers-and-material-movers-hand' => [
            'combined_official', ['$61,890', '4%', '609,600 人', '61,300 个'],
        ],
        'forest-fire-inspectors-and-prevention-specialists' => [
            'exact', ['$52,380', '15%', '2,900 人', '300 个'],
        ],
        'forging-machine-setters-operators-and-tenders-metal-and-plastic' => [
            'exact', ['$49,240', '-19%', '8,800 人', '600 个'],
        ],
    ];

    public function test_registry_is_manifest_bound_and_contains_only_the_approved_source_scopes(): void
    {
        $package = app(CareerCurrentAuthorityPackage::class)->load(base_path());
        $registry = app(CareerPresentationSourceRegistry::class)->load(base_path(), $package['manifest']);

        self::assertCount(2, $registry['onet']);
        self::assertCount(5, $registry['bls']);
        self::assertSame(2, data_get($package, 'manifest.presentation_v1.source_registry.onet_multiple_occupation_count'));
        self::assertSame(5, data_get($package, 'manifest.presentation_v1.source_registry.bls_projection_count'));
        self::assertSame(
            hash_file('sha256', base_path(CareerPresentationSourceRegistry::RELATIVE_PATH)),
            data_get($package, 'manifest.presentation_v1.source_registry.sha256'),
        );
        self::assertStringNotContainsString('17-3012.00', CareerCurrentAuthorityPackage::encodeCanonical($registry['document']));
    }

    public function test_multiple_occupation_records_keep_the_single_onet_slot_null_and_publish_both_children(): void
    {
        $package = app(CareerCurrentAuthorityPackage::class)->load(base_path());
        $registry = app(CareerPresentationSourceRegistry::class)->load(base_path(), $package['manifest']);
        $expected = [
            'electrical-and-electronics-engineers' => ['17-2070', ['17-2071.00', '17-2072.00']],
            'mathematicians-and-statisticians' => ['15-2020', ['15-2021.00', '15-2041.00']],
        ];

        foreach ($expected as $slug => [$soc, $children]) {
            $presentation = $package['rows'][$slug]['metadata_json']['presentation_v1']['zh'];
            self::assertSame($soc, data_get($presentation, 'hero.soc_code'));
            self::assertNull(data_get($presentation, 'hero.onet_code'));
            self::assertSame($children, array_column($registry['onet'][$slug]['child_occupations'], 'code'));
            $sources = CareerCurrentAuthorityPackage::encodeCanonical($package['rows'][$slug]['sources_json']);
            foreach ($children as $child) {
                self::assertStringContainsString($child, $sources);
            }
        }
        self::assertStringNotContainsString(
            '17-3012',
            CareerCurrentAuthorityPackage::encodeCanonical($package['rows']['electrical-and-electronics-engineers']['sources_json']),
        );
    }

    #[DataProvider('blsProvider')]
    public function test_approved_label_value_salary_schema_projects_four_registry_bound_metrics(
        string $slug,
        string $scope,
        array $expectedValues,
    ): void {
        $package = app(CareerCurrentAuthorityPackage::class)->load(base_path());
        $registry = app(CareerPresentationSourceRegistry::class)->load(base_path(), $package['manifest']);
        $presentation = $package['rows'][$slug]['metadata_json']['presentation_v1']['zh'];
        $stats = array_slice(data_get($presentation, 'hero.stats'), 0, 4);

        self::assertSame($expectedValues, array_column($stats, 'value'));
        self::assertSame($scope, $registry['bls'][$slug]['source_scope']);
        self::assertSame(
            array_fill(0, 4, $registry['bls'][$slug]['source_key']),
            array_map(static fn (array $stat): string => $stat['source_keys'][0], $stats),
        );
        if ($scope === 'parent_occupation_proxy') {
            self::assertStringContainsString('上级职业代理', (string) $stats[0]['source_label']);
            self::assertStringContainsString('Compliance Officers', (string) $stats[0]['source_label']);
        } elseif ($scope === 'combined_official') {
            self::assertStringContainsString('官方组合口径', (string) $stats[0]['source_label']);
        } else {
            self::assertStringContainsString('精确职业', (string) $stats[0]['source_label']);
        }
    }

    /** @return iterable<string,array{string,string,list<string>}> */
    public static function blsProvider(): iterable
    {
        foreach (self::BLS_EXPECTED as $slug => [$scope, $values]) {
            yield $slug => [$slug, $scope, $values];
        }
    }
}
