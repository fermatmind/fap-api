<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\Career1046DisplayAssetReplacement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class Career1046WorkBuddyPackageTest extends TestCase
{
    public function test_runtime_revalidates_the_complete_frozen_package(): void
    {
        $backendRoot = dirname(__DIR__, 5);
        $assets = $backendRoot.'/content_assets/career/workbuddy-1046-display-v1/assets.jsonl';
        $service = (new ReflectionClass(Career1046DisplayAssetReplacement::class))->newInstanceWithoutConstructor();
        $package = (new ReflectionMethod($service, 'loadPackage'))->invoke(
            $service,
            $backendRoot,
            hash_file('sha256', $assets),
        );

        self::assertCount(1046, $package['rows']);
        self::assertCount(1046, $package['slugs']);
        self::assertSame(2092, $package['summary']['locale_row_count']);
        self::assertSame(4184, $package['summary']['content_block_count']);
        self::assertSame(0, $package['summary']['numeric_rating_statement_residue_count']);
    }

    public function test_the_frozen_package_contains_exactly_1046_bilingual_assets_and_4184_blocks(): void
    {
        $root = dirname(__DIR__, 5).'/content_assets/career/workbuddy-1046-display-v1';
        $manifest = json_decode((string) file_get_contents($root.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Career1046DisplayAssetReplacement::PACKAGE_CONTRACT_VERSION, $manifest['contract_version']);
        self::assertSame(1046, $manifest['counts']['careers']);
        self::assertSame(2092, $manifest['counts']['locale_rows']);
        self::assertSame(4184, $manifest['counts']['content_blocks']);
        self::assertSame(hash_file('sha256', $root.'/assets.jsonl'), $manifest['files'][0]['sha256']);
        self::assertFileExists($root.'/w12_s3_delivery_report.json');
        self::assertSame(hash_file('sha256', $root.'/w12_s3_delivery_report.json'), $manifest['files'][1]['sha256']);
        self::assertSame($manifest['files'][1]['sha256'], $manifest['source_delivery_report']['sha256']);
        self::assertSame(0, $manifest['mapping']['numeric_rating_statement_residue_count']);

        $identities = [];
        $slugs = [];
        $sources = [];
        $aiHashes = [];
        $pathHashes = [];
        $handle = fopen($root.'/assets.jsonl', 'rb');
        self::assertIsResource($handle);
        while (($line = fgets($handle)) !== false) {
            $row = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
            $identity = $row['slug'].'|'.$row['locale'];
            self::assertArrayNotHasKey($identity, $identities);
            self::assertContains($row['locale'], ['en', 'zh-CN']);
            self::assertSame('CareerAiDescriptionBlock', $row['blocks']['career_ai_description_block']['component']);
            self::assertNotEmpty($row['blocks']['career_ai_description_block']['body']);
            self::assertDoesNotMatchRegularExpression(
                '/(?<![0-9])(10|[0-9])(?:\.0)?\s*\/\s*10(?![0-9])/u',
                json_encode($row['blocks']['career_ai_description_block'], JSON_THROW_ON_ERROR),
            );
            self::assertSame('CareerPathBlock', $row['blocks']['career_path_block']['component']);
            self::assertCount(4, $row['blocks']['career_path_block']['rows']);
            foreach ($row['blocks']['career_path_block']['rows'] as $pathRow) {
                self::assertCount(4, $pathRow);
                self::assertNotEmpty($pathRow[3]);
            }
            self::assertNotEmpty($row['blocks']['career_path_block']['caveat']);
            self::assertSame(
                ['career_ai_description_block', 'career_path_block'],
                array_keys($row['sources']),
            );
            foreach ($row['sources'] as $source) {
                self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $source['sha256']);
                self::assertArrayNotHasKey($source['relative_path'], $sources);
                $sources[$source['relative_path']] = $source['relative_path'].'|'.$source['sha256'];
            }
            $aiHashes[] = $identity.'|'.self::hashValue($row['blocks']['career_ai_description_block']);
            $pathHashes[] = $identity.'|'.self::hashValue($row['blocks']['career_path_block']);
            $identities[$identity] = true;
            $slugs[$row['slug']][$row['locale']] = true;
        }
        fclose($handle);

        self::assertCount(2092, $identities);
        self::assertCount(1046, $slugs);
        self::assertCount(4184, $sources);
        foreach ($slugs as $locales) {
            self::assertSame(['en', 'zh-CN'], array_keys($locales));
        }
        self::assertSame(self::setHash(array_values($sources)), $manifest['sets']['source_file_chain_sha256']);
        self::assertSame(self::setHash($aiHashes), $manifest['sets']['career_ai_description_block_sha256']);
        self::assertSame(self::setHash($pathHashes), $manifest['sets']['career_path_block_sha256']);
        self::assertSame(self::setHash([...$aiHashes, ...$pathHashes]), $manifest['sets']['display_block_aggregate_sha256']);
    }

    /** @param list<string> $values */
    private static function setHash(array $values): string
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return hash('sha256', implode("\n", $values)."\n");
    }

    private static function hashValue(mixed $value): string
    {
        return hash('sha256', json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
