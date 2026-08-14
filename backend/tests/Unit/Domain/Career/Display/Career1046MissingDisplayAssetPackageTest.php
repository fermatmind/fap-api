<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\Career1046DisplayAssetReplacement;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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
        self::assertSame($localizedRows['en']['blocks']['career_ai_description_block'], $asset->page_payload_json['page']['en']['career_ai_description_block']);
        self::assertSame($localizedRows['zh-CN']['blocks']['career_path_block'], $asset->page_payload_json['page']['zh']['career_path_block']);
        self::assertSame($baseRow['asset_payload']['seo_payload_json'], $asset->seo_payload_json);
        self::assertFalse($asset->metadata_json['content_generated']);
        self::assertFalse($asset->metadata_json['discoverability_changed']);
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
