<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerPresentationSourceRegistry;
use App\Domain\Career\Compilation\CareerTenBlockCompileFailure;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerShardedCurrentAuthorityPackage;
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
        $package = self::package();
        $registry = app(CareerPresentationSourceRegistry::class)->load(base_path(), $package['manifest'], $package['rows']);

        self::assertCount(2, $registry['onet']);
        self::assertCount(5, $registry['bls']);
        self::assertFileDoesNotExist(base_path(CareerPresentationSourceRegistry::RELATIVE_PATH));
        self::assertFileDoesNotExist(base_path(CareerCurrentAuthorityPackage::RELATIVE_PATH.'/structured-component-source-registry.json'));
        self::assertStringNotContainsString('17-3012.00', CareerCurrentAuthorityPackage::encodeCanonical($registry['document']));
    }

    public function test_multiple_occupation_records_keep_the_single_onet_slot_null_and_publish_both_children(): void
    {
        $package = self::package();
        $registry = app(CareerPresentationSourceRegistry::class)->load(base_path(), $package['manifest'], $package['rows']);
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
        $package = self::package();
        $registry = app(CareerPresentationSourceRegistry::class)->load(base_path(), $package['manifest'], $package['rows']);
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

    public function test_missing_rows_and_inconsistent_metric_source_bindings_fail_closed(): void
    {
        $package = self::package();
        foreach ([[], array_replace_recursive($package['rows'], [
            'customs-brokers' => ['metadata_json' => ['presentation_v1' => ['zh' => ['hero' => [
                'stats' => [1 => ['source_keys' => ['bls.unrelated']]],
            ]]]]],
        ])] as $rows) {
            try {
                app(CareerPresentationSourceRegistry::class)->load(base_path(), $package['manifest'], $rows);
                self::fail('Invalid legacy source bindings were accepted.');
            } catch (CareerTenBlockCompileFailure $exception) {
                self::assertSame('PRESENTATION_V1_SHARDED_SOURCE_BINDINGS_INVALID', $exception->getMessage());
            }
        }
    }

    /** @return array<string,mixed> */
    private static function package(): array
    {
        // These retired projection inputs are synthetic and never read Current body assets.
        $rows = [];
        foreach ([
            'electrical-and-electronics-engineers' => ['17-2070', ['17-2071.00', '17-2072.00']],
            'mathematicians-and-statisticians' => ['15-2020', ['15-2021.00', '15-2041.00']],
        ] as $slug => [$soc, $children]) {
            $rows[$slug] = [
                'metadata_json' => ['presentation_v1' => ['zh' => ['hero' => ['soc_code' => $soc, 'onet_code' => null]]]],
                'sources_json' => ['references' => array_map(static fn (string $code): array => [
                    'url' => 'https://www.onetonline.org/link/details/'.$code,
                    'label' => 'O*NET OnLine: Fixture occupation '.$code,
                ], $children)],
            ];
        }
        foreach (self::BLS_EXPECTED as $slug => [$scope, $values]) {
            $label = 'BLS 2024 · '.match ($scope) {
                'parent_occupation_proxy' => '上级职业代理：Compliance Officers',
                'combined_official' => '官方组合口径',
                default => '精确职业',
            };
            $rows[$slug] = [
                'metadata_json' => ['presentation_v1' => ['zh' => ['hero' => ['stats' => array_map(
                    static fn (string $value): array => ['value' => $value, 'source_keys' => ['bls.'.$slug], 'source_label' => $label],
                    $values,
                )]]]],
                'sources_json' => ['references' => []],
            ];
        }
        for ($index = count($rows); $index < CareerCurrentAuthorityPackage::EXPECTED_CAREERS; $index++) {
            $rows['fixture-career-'.$index] = ['metadata_json' => ['presentation_v1' => ['zh' => ['hero' => []]]]];
        }

        return ['manifest' => ['contract_version' => CareerShardedCurrentAuthorityPackage::CONTRACT_VERSION], 'rows' => $rows];
    }
}
