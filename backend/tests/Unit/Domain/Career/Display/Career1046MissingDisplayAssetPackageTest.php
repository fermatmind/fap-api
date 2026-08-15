<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\Career1046DisplayAssetReplacement;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class Career1046MissingDisplayAssetPackageTest extends TestCase
{
    private const EXPECTED_SLUGS = [
        'industrial-engineers',
        'logisticians',
        'mathematicians-and-statisticians',
        'mechanical-engineers',
        'network-and-computer-systems-administrators',
        'operations-research-analysts',
        'personal-financial-advisors',
        'public-relations-specialists',
        'sales-engineers',
        'technical-writers',
        'training-and-development-managers',
        'training-and-development-specialists',
    ];

    public function test_the_exact_twelve_assets_are_complete_bilingual_24_component_authority(): void
    {
        $root = dirname(__DIR__, 5).'/content_assets/career/missing-12-display-v1';
        $manifest = json_decode((string) file_get_contents($root.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $lines = file($root.'/assets.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        self::assertIsArray($lines);
        self::assertCount(12, $lines);
        self::assertSame('career.missing_12_display_asset_package.v1', $manifest['contract_version']);
        self::assertSame(12, $manifest['counts']['assets']);
        self::assertSame(24, $manifest['counts']['localized_pages']);
        self::assertFalse($manifest['normalization']['content_generation']);
        self::assertFalse($manifest['negative_guarantees']['discoverability_change']);
        self::assertSame(hash_file('sha256', $root.'/assets.jsonl'), $manifest['files'][0]['sha256']);

        $slugs = [];
        $payloadHashes = [];
        foreach ($lines as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $payload = $row['asset_payload'];
            $slugs[] = $row['slug'];
            $payloadHashes[] = $row['asset_payload_sha256'];

            self::assertSame(CareerDisplayAssetComponentContract::LEGACY_V4_2_ORDER, $payload['component_order_json']);
            self::assertSame($row['asset_payload_sha256'], $this->hashValue($payload));
            self::assertMatchesRegularExpression('/\A[0-9]{2}-[0-9]{4}\z/', $row['expected_soc']);
            self::assertMatchesRegularExpression('/\A[0-9]{2}-[0-9]{4}\.[0-9]{2}\z/', $row['expected_onet']);
            self::assertNotEmpty($payload['page_payload_json']['page']['en']['hero']['title']);
            foreach (['en', 'zh'] as $locale) {
                $page = $payload['page_payload_json']['page'][$locale];
                foreach (['hero', 'definition_block', 'responsibilities_block', 'market_signal_card', 'faq_block'] as $component) {
                    self::assertNotEmpty($page[$component], $row['slug'].' '.$locale.' '.$component);
                }
                self::assertSame('start_riasec_test', $page['primary_cta']['target_action']);
                self::assertSame('career_job_detail', $page['primary_cta']['source_page_type']);
                self::assertCount(3, $page['faq_block']['items']);
            }
        }

        sort($slugs, SORT_STRING);
        $expected = self::EXPECTED_SLUGS;
        sort($expected, SORT_STRING);
        self::assertSame($expected, $slugs);
        self::assertSame($manifest['sets']['slug_set_sha256'], $this->setHash($slugs));
        self::assertSame($manifest['sets']['asset_payload_set_sha256'], $this->setHash($payloadHashes));
    }

    public function test_an_authorized_missing_asset_merges_into_the_exact_26_component_shape(): void
    {
        $backendRoot = dirname(__DIR__, 5);
        $baseLine = file($backendRoot.'/content_assets/career/missing-12-display-v1/assets.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)[0];
        $baseRow = json_decode($baseLine, true, 512, JSON_THROW_ON_ERROR);
        $localizedRows = [];
        $handle = fopen($backendRoot.'/content_assets/career/workbuddy-1046-display-v1/assets.jsonl', 'rb');
        self::assertIsResource($handle);
        while (($line = fgets($handle)) !== false) {
            $row = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
            if ($row['slug'] === $baseRow['slug']) {
                $localizedRows[$row['locale']] = $row;
            }
        }
        fclose($handle);
        self::assertSame(['en', 'zh-CN'], array_keys($localizedRows));

        $occupation = new Occupation([
            'id' => '00000000-0000-4000-8000-000000000001',
            'canonical_slug' => $baseRow['slug'],
        ]);
        $service = (new ReflectionClass(Career1046DisplayAssetReplacement::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'insertAttributes');
        [$packageSummary, $missingSummary] = $this->packageSummaries($backendRoot);
        $attributes = $method->invoke(
            $service,
            $baseRow['slug'],
            $occupation,
            $baseRow,
            $localizedRows,
            $packageSummary,
            $missingSummary,
        );
        $asset = new CareerJobDisplayAsset($attributes);

        self::assertSame(CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER, $asset->component_order_json);
        self::assertSame($baseRow['asset_payload']['page_payload_json']['page']['en']['hero'], $asset->page_payload_json['page']['en']['hero']);
        self::assertSame($baseRow['asset_payload']['page_payload_json']['page']['zh']['definition_block'], $asset->page_payload_json['page']['zh']['definition_block']);
        self::assertSame(
            $localizedRows['en']['blocks']['career_ai_description_block']['heading'],
            $asset->page_payload_json['page']['en']['career_ai_description_block']['heading'],
        );
        self::assertSame(
            array_slice($localizedRows['en']['blocks']['career_ai_description_block']['body'], 0, 4),
            array_slice($asset->page_payload_json['page']['en']['career_ai_description_block']['body'], 0, 4),
        );
        self::assertSame($localizedRows['zh-CN']['blocks']['career_path_block'], $asset->page_payload_json['page']['zh']['career_path_block']);
        self::assertSame('4/10', $asset->page_payload_json['page']['en']['ai_impact_table']['score_normalized']);
        self::assertDoesNotMatchRegularExpression('/\d+(?:\.0)?\s*\/\s*10/u', implode("\n", $asset->page_payload_json['page']['en']['career_ai_description_block']['body']));
        self::assertDoesNotMatchRegularExpression('/\d+(?:\.0)?\s*\/\s*10/u', implode("\n", $asset->page_payload_json['page']['zh']['career_ai_description_block']['body']));
        $cacheBlocks = (new ReflectionMethod($service, 'localizedBlocksForCache'))->invoke(
            $service,
            $asset->page_payload_json,
        );
        self::assertSame(
            $asset->page_payload_json['page']['en']['career_ai_description_block'],
            $cacheBlocks['en']['career_ai_description_block'],
        );
        self::assertSame(
            $asset->page_payload_json['page']['zh']['career_ai_description_block'],
            $cacheBlocks['zh-CN']['career_ai_description_block'],
        );
        self::assertSame($baseRow['asset_payload']['seo_payload_json'], $asset->seo_payload_json);
        self::assertFalse($asset->metadata_json['content_generated']);
        self::assertFalse($asset->metadata_json['discoverability_changed']);
        self::assertSame('85549e2181112f58e6e174b35eb4bb5a672a3ed486c57739a7e835786627cc1b', $asset->metadata_json['workbook_sha256']);
        self::assertSame('第九版.xlsx', $asset->metadata_json['workbook_basename']);
        self::assertSame($baseRow['source_workbook_row_number'], $asset->metadata_json['row_number']);
        self::assertSame('career:import-selected-display-assets', $asset->metadata_json['command']);
        self::assertSame($packageSummary['package_sha256'], $asset->metadata_json['replacement_lineage']['package_sha256']);
    }

    public function test_an_authorized_insert_plans_exact_soc_and_onet_crosswalks_and_accepts_only_exact_applied_state(): void
    {
        $baseRow = json_decode((string) file(dirname(__DIR__, 5).'/content_assets/career/missing-12-display-v1/assets.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)[0], true, 512, JSON_THROW_ON_ERROR);
        $occupation = new Occupation([
            'id' => '00000000-0000-4000-8000-000000000001',
            'canonical_slug' => $baseRow['slug'],
        ]);
        $occupation->setRelation('crosswalks', collect());
        $service = (new ReflectionClass(Career1046DisplayAssetReplacement::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'occupationCrosswalkPlan');
        $missingPackageSha256 = (string) hash_file('sha256', dirname(__DIR__, 5).'/content_assets/career/missing-12-display-v1/assets.jsonl');

        $initial = $method->invoke($service, [$baseRow['slug'] => $occupation], [$baseRow['slug'] => $baseRow], true, $missingPackageSha256);
        self::assertSame([], $initial['before']);
        self::assertCount(2, $initial['expected']);
        $insertSnapshots = array_map(
            static fn (array $attributes): array => [
                'id' => $attributes['id'],
                'occupation_id' => $attributes['occupation_id'],
                'source_system' => $attributes['source_system'],
                'source_code' => $attributes['source_code'],
                'source_title' => $attributes['source_title'],
                'mapping_type' => $attributes['mapping_type'],
                'confidence_score' => $attributes['confidence_score'],
                'notes' => $attributes['notes'],
            ],
            $initial['inserts'],
        );
        usort($insertSnapshots, static fn (array $left, array $right): int => strcmp($left['source_system'], $right['source_system']));
        self::assertSame($initial['expected'], $insertSnapshots);
        self::assertSame(['us_soc', 'onet_soc_2019'], array_column($initial['inserts'], 'source_system'));
        self::assertSame([$baseRow['expected_soc'], $baseRow['expected_onet']], array_column($initial['inserts'], 'source_code'));
        self::assertSame([$baseRow['asset_payload']['page_payload_json']['page']['en']['hero']['title']], array_values(array_unique(array_column($initial['inserts'], 'source_title'))));
        self::assertStringContainsString('package_sha256='.$missingPackageSha256, $initial['inserts'][0]['notes']);
        self::assertStringContainsString('source_workbook_row_sha256='.$baseRow['source_workbook_row_sha256'], $initial['inserts'][0]['notes']);

        $occupation->setRelation('crosswalks', collect(array_map(
            static fn (array $attributes): OccupationCrosswalk => new OccupationCrosswalk($attributes),
            $initial['inserts'],
        )));
        $applied = $method->invoke($service, [$baseRow['slug'] => $occupation], [$baseRow['slug'] => $baseRow], false, $missingPackageSha256);
        self::assertSame([], $applied['inserts']);
        self::assertSame($initial['expected'], $applied['expected']);
    }

    public function test_partial_or_conflicting_crosswalk_state_fails_closed_before_writes(): void
    {
        $baseRow = json_decode((string) file(dirname(__DIR__, 5).'/content_assets/career/missing-12-display-v1/assets.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)[0], true, 512, JSON_THROW_ON_ERROR);
        $occupation = new Occupation([
            'id' => '00000000-0000-4000-8000-000000000001',
            'canonical_slug' => $baseRow['slug'],
        ]);
        $occupation->setRelation('crosswalks', collect([
            new OccupationCrosswalk([
                'id' => '00000000-0000-4000-8000-000000000002',
                'occupation_id' => $occupation->id,
                'source_system' => 'us_soc',
                'source_code' => $baseRow['expected_soc'],
                'source_title' => 'Conflicting title',
                'mapping_type' => 'direct_match',
                'confidence_score' => 1.0,
                'notes' => 'conflict',
            ]),
        ]));
        $service = (new ReflectionClass(Career1046DisplayAssetReplacement::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'occupationCrosswalkPlan');
        $missingPackageSha256 = (string) hash_file('sha256', dirname(__DIR__, 5).'/content_assets/career/missing-12-display-v1/assets.jsonl');

        $this->expectExceptionMessage('DISPLAY_INSERT_OCCUPATION_CROSSWALK_STATE_INVALID');
        $method->invoke($service, [$baseRow['slug'] => $occupation], [$baseRow['slug'] => $baseRow], true, $missingPackageSha256);
    }

    public function test_all_twelve_inserted_assets_have_one_numeric_ai_rating_authority(): void
    {
        $backendRoot = dirname(__DIR__, 5);
        $baseRows = [];
        foreach (file($backendRoot.'/content_assets/career/missing-12-display-v1/assets.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $baseRows[$row['slug']] = $row;
        }
        $localizedRows = [];
        $handle = fopen($backendRoot.'/content_assets/career/workbuddy-1046-display-v1/assets.jsonl', 'rb');
        self::assertIsResource($handle);
        while (($line = fgets($handle)) !== false) {
            $row = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
            if (isset($baseRows[$row['slug']])) {
                $localizedRows[$row['slug']][$row['locale']] = $row;
            }
        }
        fclose($handle);

        $service = (new ReflectionClass(Career1046DisplayAssetReplacement::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'insertAttributes');
        [$packageSummary, $missingSummary] = $this->packageSummaries($backendRoot);
        foreach ($baseRows as $slug => $baseRow) {
            $occupation = new Occupation([
                'id' => '00000000-0000-4000-8000-'.substr(hash('sha256', $slug), 0, 12),
                'canonical_slug' => $slug,
            ]);
            $asset = new CareerJobDisplayAsset($method->invoke(
                $service,
                $slug,
                $occupation,
                $baseRow,
                $localizedRows[$slug],
                $packageSummary,
                $missingSummary,
            ));
            foreach (['en', 'zh'] as $locale) {
                $body = implode("\n", $asset->page_payload_json['page'][$locale]['career_ai_description_block']['body']);
                preg_match_all('/(?<![0-9])(10|[0-9])(?:\.0)?\s*\/\s*10(?![0-9])/u', $body, $matches);
                self::assertSame([], $matches[1] ?? [], $slug.' '.$locale);
                self::assertNotEmpty($asset->page_payload_json['page'][$locale]['ai_impact_table']);
            }
        }
    }

    public function test_merge_preserves_historic_ai_impact_table_format_without_parsing_it(): void
    {
        $service = (new ReflectionClass(Career1046DisplayAssetReplacement::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'mergeLocalizedBlocks');
        $base = ['page' => [
            'en' => ['ai_impact_table' => ['score_normalized' => 'historic:82-of-100']],
            'zh' => ['ai_impact_table' => ['score_normalized' => ['legacy' => 82]]],
        ]];
        $localized = [
            'en' => ['blocks' => [
                'career_ai_description_block' => ['component' => 'CareerAiDescriptionBlock', 'heading' => 'AI', 'body' => ['Clean body.']],
                'career_path_block' => ['component' => 'CareerPathBlock', 'heading' => 'Path', 'rows' => [['A', 'B', 'C', 'D']]],
            ]],
            'zh-CN' => ['blocks' => [
                'career_ai_description_block' => ['component' => 'CareerAiDescriptionBlock', 'heading' => 'AI', 'body' => ['正文。']],
                'career_path_block' => ['component' => 'CareerPathBlock', 'heading' => '路径', 'rows' => [['甲', '乙', '丙', '丁']]],
            ]],
        ];
        $after = $method->invoke($service, $base, $localized);

        self::assertSame($base['page']['en']['ai_impact_table'], $after['page']['en']['ai_impact_table']);
        self::assertSame($base['page']['zh']['ai_impact_table'], $after['page']['zh']['ai_impact_table']);
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function packageSummaries(string $backendRoot): array
    {
        $packageRoot = $backendRoot.'/content_assets/career/workbuddy-1046-display-v1';
        $package = json_decode((string) file_get_contents($packageRoot.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $missing = json_decode((string) file_get_contents($backendRoot.'/content_assets/career/missing-12-display-v1/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        return [[
            'package_sha256' => $package['files'][0]['sha256'],
            'manifest_sha256' => hash_file('sha256', $packageRoot.'/manifest.json'),
            'delivery_report_sha256' => $package['source_delivery_report']['sha256'],
            'source_file_chain_sha256' => $package['sets']['source_file_chain_sha256'],
        ], [
            'source_workbook_sha256' => $missing['source']['workbook_sha256'],
            'source_workbook_basename' => $missing['source']['workbook_filename'],
            'mapper_version' => $missing['source']['mapper_version'],
        ]];
    }

    /** @param list<string> $values */
    private function setHash(array $values): string
    {
        $values = array_values(array_unique(array_map('strval', $values)));
        sort($values, SORT_STRING);

        return hash('sha256', implode("\n", $values)."\n");
    }

    private function hashValue(mixed $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
