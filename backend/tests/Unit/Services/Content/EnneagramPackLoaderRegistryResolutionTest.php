<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\EnneagramPackLoader;
use App\Services\Content\EnneagramPrivateResultCompileService;
use App\Services\Content\EnneagramPrivateResultPackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class EnneagramPackLoaderRegistryResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_runtime_compiles_the_canonical_registry_without_creating_a_release(): void
    {
        $pack = app(EnneagramPackLoader::class)->loadRegistryPack(null, 'zh-CN');

        $this->assertNull($pack['root']);
        $this->assertNull(data_get($pack, 'authority.release_id'));
        $this->assertSame(EnneagramPrivateResultCompileService::AUTHORITY_ID, data_get($pack, 'authority.authority_id'));
        $this->assertSame('zh-CN', data_get($pack, 'authority.locale'));
        $this->assertMatchesRegularExpression('/\Asha256:[0-9a-f]{64}\z/', (string) $pack['release_hash']);
        $this->assertCount(36, data_get($pack, 'pair_registry.entries'));
        $this->assertSame(0, DB::table('content_pack_releases')->count());
    }

    public function test_active_immutable_release_is_the_only_runtime_selector_for_both_locales(): void
    {
        $this->artisan('packs2:publish', [
            '--pack' => EnneagramPrivateResultCompileService::PACK_ID,
            '--pack-version' => EnneagramPrivateResultCompileService::PACK_VERSION,
            '--activate' => 1,
            '--source_commit' => str_repeat('a', 40),
        ])->assertSuccessful();

        $zh = app(EnneagramPrivateResultPackLoader::class)->load('zh-CN');
        $en = app(EnneagramPrivateResultPackLoader::class)->load('en');
        $releaseId = DB::table('content_pack_activations')
            ->where('pack_id', EnneagramPrivateResultCompileService::PACK_ID)
            ->where('pack_version', EnneagramPrivateResultCompileService::PACK_VERSION)
            ->value('release_id');

        $this->assertNotEmpty($releaseId);
        $this->assertSame($releaseId, data_get($zh, 'authority.release_id'));
        $this->assertSame($releaseId, data_get($en, 'authority.release_id'));
        $this->assertSame(data_get($zh, 'authority.source_hash'), data_get($en, 'authority.source_hash'));
        $this->assertSame(data_get($zh, 'authority.compiled_hash'), data_get($en, 'authority.compiled_hash'));
        $this->assertSame('zh-CN', data_get($zh, 'authority.locale'));
        $this->assertSame('en', data_get($en, 'authority.locale'));
    }

    public function test_production_runtime_fails_closed_without_an_active_release(): void
    {
        $previousEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'production');

        try {
            app(EnneagramPrivateResultPackLoader::class)->load('zh-CN');
            $this->fail('Production loader accepted a missing active release.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ENNEAGRAM_PRIVATE_RESULT_ACTIVE_RELEASE_MISSING', $exception->getMessage());
        } finally {
            app()->detectEnvironment(static fn (): string => $previousEnvironment);
        }
    }

    public function test_active_release_hash_mismatch_fails_closed(): void
    {
        $this->artisan('packs2:publish', [
            '--pack' => EnneagramPrivateResultCompileService::PACK_ID,
            '--pack-version' => EnneagramPrivateResultCompileService::PACK_VERSION,
            '--activate' => 1,
        ])->assertSuccessful();
        $releaseId = DB::table('content_pack_activations')
            ->where('pack_id', EnneagramPrivateResultCompileService::PACK_ID)
            ->where('pack_version', EnneagramPrivateResultCompileService::PACK_VERSION)
            ->value('release_id');
        DB::table('content_pack_releases')->where('id', $releaseId)->update(['compiled_hash' => str_repeat('0', 64)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ENNEAGRAM_PRIVATE_RESULT_ACTIVE_RELEASE_BINDING_INVALID');
        app(EnneagramPrivateResultPackLoader::class)->load('zh-CN');
    }
}
