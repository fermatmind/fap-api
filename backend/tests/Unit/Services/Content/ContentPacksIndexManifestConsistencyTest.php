<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\ContentPacksIndex;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ContentPacksIndexManifestConsistencyTest extends TestCase
{
    private string $packsRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packsRoot = sys_get_temp_dir() . '/pr53_content_packs_' . uniqid('', true);
        File::ensureDirectoryExists($this->packsRoot);

        config()->set('content_packs.driver', 'local');
        config()->set('content_packs.root', $this->packsRoot);
        config()->set('content_packs.default_pack_id', 'PACK_A');
        config()->set('content_packs.default_dir_version', 'MBTI-CN-v-test');
        config()->set('content_packs.default_region', 'CN_MAINLAND');
        config()->set('content_packs.default_locale', 'zh-CN');

        Cache::forget(CacheKeys::packsIndex());
    }

    protected function tearDown(): void
    {
        Cache::forget(CacheKeys::packsIndex());
        File::deleteDirectory($this->packsRoot);
        parent::tearDown();
    }

    public function test_find_refreshes_when_cached_manifest_becomes_stale(): void
    {
        $dirVersion = 'MBTI-CN-v-test';
        $packDir = $this->makePackDir($dirVersion);

        $this->writePack($packDir, 'PACK_A', $dirVersion, 'v-test');

        /** @var ContentPacksIndex $index */
        $index = app(ContentPacksIndex::class);

        $first = $index->find('PACK_A', $dirVersion);
        $this->assertTrue((bool) ($first['ok'] ?? false));
        $this->assertSame('PACK_A', (string) ($first['item']['pack_id'] ?? ''));

        // mutate manifest/version to a new pack_id in the same dir_version
        $this->writePack($packDir, 'PACK_B', $dirVersion, 'v-test-2');

        $stale = $index->find('PACK_A', $dirVersion);
        $this->assertFalse((bool) ($stale['ok'] ?? false));

        $fresh = $index->find('PACK_B', $dirVersion);
        $this->assertTrue((bool) ($fresh['ok'] ?? false));
        $this->assertSame('PACK_B', (string) ($fresh['item']['pack_id'] ?? ''));
        $this->assertSame('v-test-2', (string) ($fresh['item']['content_package_version'] ?? ''));
    }

    public function test_invalid_manifest_version_pair_is_excluded_from_index(): void
    {
        $dirVersion = 'MBTI-CN-v-invalid';
        $packDir = $this->makePackDir($dirVersion);

        $this->writePack($packDir, 'PACK_BAD', $dirVersion, 'v-manifest', 'v-version-mismatch');

        /** @var ContentPacksIndex $index */
        $index = app(ContentPacksIndex::class);

        $found = $index->find('PACK_BAD', $dirVersion);
        $this->assertFalse((bool) ($found['ok'] ?? false));

        $snapshot = $index->getIndex(true);
        $this->assertTrue((bool) ($snapshot['ok'] ?? false));
        $this->assertIsArray($snapshot['items'] ?? null);
        $this->assertCount(0, (array) ($snapshot['items'] ?? []));
    }

    private function makePackDir(string $dirVersion): string
    {
        $packDir = $this->packsRoot . DIRECTORY_SEPARATOR . 'default'
            . DIRECTORY_SEPARATOR . 'CN_MAINLAND'
            . DIRECTORY_SEPARATOR . 'zh-CN'
            . DIRECTORY_SEPARATOR . $dirVersion;

        File::ensureDirectoryExists($packDir);

        return $packDir;
    }

    private function writePack(
        string $packDir,
        string $packId,
        string $dirVersion,
        string $manifestContentVersion,
        ?string $versionContentVersion = null
    ): void {
        $versionContentVersion = $versionContentVersion ?? $manifestContentVersion;

        file_put_contents(
            $packDir . DIRECTORY_SEPARATOR . 'manifest.json',
            json_encode([
                'schema_version' => 'pack-manifest@v1',
                'pack_id' => $packId,
                'scale_code' => 'MBTI',
                'region' => 'CN_MAINLAND',
                'locale' => 'zh-CN',
                'content_package_version' => $manifestContentVersion,
                'assets' => [
                    'questions' => ['questions.json'],
                ],
            ], JSON_UNESCAPED_UNICODE)
        );

        file_put_contents(
            $packDir . DIRECTORY_SEPARATOR . 'version.json',
            json_encode([
                'pack_id' => $packId,
                'content_package_version' => $versionContentVersion,
                'dir_version' => $dirVersion,
            ], JSON_UNESCAPED_UNICODE)
        );

        file_put_contents(
            $packDir . DIRECTORY_SEPARATOR . 'questions.json',
            json_encode([
                [
                    'question_id' => 'q1',
                    'text' => 'Q1',
                    'options' => [
                        ['code' => 'A', 'text' => 'Option A'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE)
        );
    }
}
