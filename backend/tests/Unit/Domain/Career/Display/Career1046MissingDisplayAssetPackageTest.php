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
        $attributes = $method->invoke($service, $baseRow['slug'], $occupation, $baseRow, $localizedRows);
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
        self::assertStringNotContainsString('7/10', implode("\n", $asset->page_payload_json['page']['en']['career_ai_description_block']['body']));
        self::assertStringNotContainsString('7/10', implode("\n", $asset->page_payload_json['page']['zh']['career_ai_description_block']['body']));
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
    }

    public function test_an_authorized_insert_requires_exact_soc_and_onet_crosswalks(): void
    {
        $occupation = new Occupation(['canonical_slug' => 'industrial-engineers']);
        $occupation->setRelation('crosswalks', collect([
            new OccupationCrosswalk(['source_system' => 'us_soc', 'source_code' => '17-2112']),
            new OccupationCrosswalk(['source_system' => 'onet_soc_2019', 'source_code' => '17-2112.00']),
        ]));
        $service = (new ReflectionClass(Career1046DisplayAssetReplacement::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'assertOccupationCrosswalks');

        $method->invoke($service, $occupation, ['expected_soc' => '17-2112', 'expected_onet' => '17-2112.00']);
        self::assertTrue(true);

        $this->expectExceptionMessage('DISPLAY_INSERT_OCCUPATION_CROSSWALK_MISMATCH');
        $method->invoke($service, $occupation, ['expected_soc' => '17-2112', 'expected_onet' => '17-2112.99']);
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
            ));
            foreach (['en', 'zh'] as $locale) {
                $expected = (int) $asset->page_payload_json['page'][$locale]['ai_impact_table']['score_normalized'];
                $body = implode("\n", $asset->page_payload_json['page'][$locale]['career_ai_description_block']['body']);
                preg_match_all('/(?<![0-9])(10|[0-9])(?:\.0)?\s*\/\s*10(?![0-9])/u', $body, $matches);
                foreach (array_map('intval', $matches[1] ?? []) as $actual) {
                    self::assertSame($expected, $actual, $slug.' '.$locale);
                }
            }
        }
    }

    public function test_legacy_zero_to_one_hundred_ai_scores_remain_the_numeric_authority(): void
    {
        $service = (new ReflectionClass(Career1046DisplayAssetReplacement::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'reconcileAiExposureRating');

        $matching = ['body' => ['FermatMind rates this career 8/10. Reviewed explanation.']];
        self::assertSame(
            $matching,
            $method->invoke($service, $matching, ['score_normalized' => '82'], 'en'),
        );

        $conflicting = ['body' => ['FermatMind rates this career 7/10. Reviewed explanation.']];
        self::assertSame(
            ['body' => ['Reviewed explanation.']],
            $method->invoke($service, $conflicting, ['score_normalized' => '82'], 'en'),
        );
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
