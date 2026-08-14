<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\Career1046DisplayAssetReplacement;
use PHPUnit\Framework\TestCase;

final class Career1046WorkBuddyPackageTest extends TestCase
{
    public function test_the_frozen_package_contains_exactly_1046_bilingual_assets_and_4184_blocks(): void
    {
        $root = dirname(__DIR__, 5).'/content_assets/career/workbuddy-1046-display-v1';
        $manifest = json_decode((string) file_get_contents($root.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Career1046DisplayAssetReplacement::PACKAGE_CONTRACT_VERSION, $manifest['contract_version']);
        self::assertSame(1046, $manifest['counts']['careers']);
        self::assertSame(2092, $manifest['counts']['locale_rows']);
        self::assertSame(4184, $manifest['counts']['content_blocks']);
        self::assertSame(hash_file('sha256', $root.'/assets.jsonl'), $manifest['files'][0]['sha256']);

        $identities = [];
        $slugs = [];
        $handle = fopen($root.'/assets.jsonl', 'rb');
        self::assertIsResource($handle);
        while (($line = fgets($handle)) !== false) {
            $row = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
            $identity = $row['slug'].'|'.$row['locale'];
            self::assertArrayNotHasKey($identity, $identities);
            self::assertContains($row['locale'], ['en', 'zh-CN']);
            self::assertSame('CareerAiDescriptionBlock', $row['blocks']['career_ai_description_block']['component']);
            self::assertNotEmpty($row['blocks']['career_ai_description_block']['body']);
            self::assertSame('CareerPathBlock', $row['blocks']['career_path_block']['component']);
            self::assertCount(4, $row['blocks']['career_path_block']['rows']);
            self::assertNotEmpty($row['blocks']['career_path_block']['caveat']);
            $identities[$identity] = true;
            $slugs[$row['slug']][$row['locale']] = true;
        }
        fclose($handle);

        self::assertCount(2092, $identities);
        self::assertCount(1046, $slugs);
        foreach ($slugs as $locales) {
            self::assertSame(['en', 'zh-CN'], array_keys($locales));
        }
    }
}
