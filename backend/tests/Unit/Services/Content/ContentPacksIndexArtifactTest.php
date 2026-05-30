<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\ContentPacksIndex;
use App\Services\Content\ContentPacksIndexArtifactStore;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ContentPacksIndexArtifactTest extends TestCase
{
    private string $packsRoot;

    private string $artifactPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packsRoot = sys_get_temp_dir().'/content_pack_artifact_'.uniqid('', true);
        $this->artifactPath = sys_get_temp_dir().'/content_pack_artifact_index_'.uniqid('', true).'.json';

        File::ensureDirectoryExists($this->packsRoot);

        config()->set('content_packs.driver', 'local');
        config()->set('content_packs.root', $this->packsRoot);
        config()->set('content_packs.default_pack_id', 'PACK_A');
        config()->set('content_packs.default_dir_version', 'MBTI-CN-v-test');
        config()->set('content_packs.default_region', 'CN_MAINLAND');
        config()->set('content_packs.default_locale', 'zh-CN');
        config()->set('content_packs.index_artifact_enabled', false);
        config()->set('content_packs.index_artifact_path', $this->artifactPath);

        $this->forgetIndexCache();
    }

    protected function tearDown(): void
    {
        $this->forgetIndexCache();
        File::deleteDirectory($this->packsRoot);
        if (File::exists($this->artifactPath)) {
            File::delete($this->artifactPath);
        }

        parent::tearDown();
    }

    public function test_artifact_round_trip_matches_scanned_index_contract(): void
    {
        $dirVersion = 'MBTI-CN-v-test';
        $this->writePack($this->makePackDir($dirVersion), 'PACK_A', $dirVersion, 'v-test');

        $index = app(ContentPacksIndex::class);
        $scanned = $index->getIndex(true);

        $store = app(ContentPacksIndexArtifactStore::class);
        $store->write($scanned, $this->artifactPath);

        $payload = json_decode((string) File::get($this->artifactPath), true);
        $this->assertSame(ContentPacksIndexArtifactStore::SCHEMA_VERSION, (string) ($payload['schema_version'] ?? ''));
        $this->assertSame(1, (int) ($payload['summary']['item_count'] ?? 0));
        $this->assertSame(
            'default/CN_MAINLAND/zh-CN/MBTI-CN-v-test/manifest.json',
            (string) ($payload['items'][0]['manifest_path'] ?? '')
        );

        $this->forgetIndexCache();
        config()->set('content_packs.index_artifact_enabled', true);

        $fromArtifact = $index->getIndex(false);
        $this->assertTrue((bool) ($fromArtifact['ok'] ?? false));
        $this->assertSame($scanned['by_pack_id'], $fromArtifact['by_pack_id']);
        $this->assertSame($scanned['items'], $fromArtifact['items']);
    }

    public function test_stale_artifact_falls_back_to_streaming_scan(): void
    {
        $dirVersion = 'MBTI-CN-v-test';
        $packDir = $this->makePackDir($dirVersion);
        $this->writePack($packDir, 'PACK_A', $dirVersion, 'v-test');

        $index = app(ContentPacksIndex::class);
        app(ContentPacksIndexArtifactStore::class)->write($index->getIndex(true), $this->artifactPath);

        $this->writePack($packDir, 'PACK_B', $dirVersion, 'v-test-2');
        $this->forgetIndexCache();
        config()->set('content_packs.index_artifact_enabled', true);
        config()->set('content_packs.default_pack_id', 'PACK_B');

        $fresh = $index->getIndex(false);

        $this->assertTrue((bool) ($fresh['ok'] ?? false));
        $this->assertCount(1, (array) ($fresh['items'] ?? []));
        $this->assertSame('PACK_B', (string) ($fresh['items'][0]['pack_id'] ?? ''));
        $this->assertSame('v-test-2', (string) ($fresh['items'][0]['content_package_version'] ?? ''));
    }

    public function test_build_command_writes_artifact_without_enabling_runtime_contract_change(): void
    {
        $dirVersion = 'MBTI-CN-v-test';
        $this->writePack($this->makePackDir($dirVersion), 'PACK_A', $dirVersion, 'v-test');

        $this->artisan('content-packs:index:build', [
            '--output' => $this->artifactPath,
            '--json' => true,
        ])->assertExitCode(0);

        $payload = json_decode((string) File::get($this->artifactPath), true);
        $this->assertSame(ContentPacksIndexArtifactStore::SCHEMA_VERSION, (string) ($payload['schema_version'] ?? ''));
        $this->assertSame(1, (int) ($payload['summary']['item_count'] ?? 0));
        $this->assertFalse((bool) config('content_packs.index_artifact_enabled'));
    }

    public function test_artifact_hit_does_not_invoke_fallback_scanner(): void
    {
        $dirVersion = 'MBTI-CN-v-test';
        $this->writePack($this->makePackDir($dirVersion), 'PACK_A', $dirVersion, 'v-test');

        $store = app(ContentPacksIndexArtifactStore::class);
        $store->write(app(ContentPacksIndex::class)->getIndex(true), $this->artifactPath);

        $this->forgetIndexCache();
        config()->set('content_packs.index_artifact_enabled', true);

        $index = new ContentPacksIndex($store, new class extends \App\Services\Content\ContentPacksIndexFallbackScanner
        {
            public function scan(string $packsRootFs, string $driver, array $defaults): array
            {
                throw new \RuntimeException('fallback scanner should not run on artifact hit');
            }
        });

        $fromArtifact = $index->getIndex(false);

        $this->assertTrue((bool) ($fromArtifact['ok'] ?? false));
        $this->assertSame('PACK_A', (string) ($fromArtifact['items'][0]['pack_id'] ?? ''));
    }

    private function makePackDir(string $dirVersion): string
    {
        $packDir = $this->packsRoot.DIRECTORY_SEPARATOR.'default'
            .DIRECTORY_SEPARATOR.'CN_MAINLAND'
            .DIRECTORY_SEPARATOR.'zh-CN'
            .DIRECTORY_SEPARATOR.$dirVersion;

        File::ensureDirectoryExists($packDir);

        return $packDir;
    }

    private function forgetIndexCache(): void
    {
        foreach (['hot_redis', null] as $store) {
            try {
                $store === null
                    ? Cache::forget(CacheKeys::packsIndex())
                    : Cache::store($store)->forget(CacheKeys::packsIndex());
            } catch (\Throwable $e) {
                // Test environments may not define every production cache store.
            }
        }
    }

    private function writePack(
        string $packDir,
        string $packId,
        string $dirVersion,
        string $contentVersion
    ): void {
        file_put_contents(
            $packDir.DIRECTORY_SEPARATOR.'manifest.json',
            json_encode([
                'schema_version' => 'pack-manifest@v1',
                'pack_id' => $packId,
                'scale_code' => 'MBTI',
                'region' => 'CN_MAINLAND',
                'locale' => 'zh-CN',
                'content_package_version' => $contentVersion,
                'assets' => [
                    'questions' => ['questions.json'],
                ],
            ], JSON_UNESCAPED_UNICODE)
        );

        file_put_contents(
            $packDir.DIRECTORY_SEPARATOR.'version.json',
            json_encode([
                'pack_id' => $packId,
                'content_package_version' => $contentVersion,
                'dir_version' => $dirVersion,
            ], JSON_UNESCAPED_UNICODE)
        );

        file_put_contents(
            $packDir.DIRECTORY_SEPARATOR.'questions.json',
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
