<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Services\Content\ContentPackV2Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ContentPackV2ResolverDatabaseBackedInactiveMbtiResultTest extends TestCase
{
    use RefreshDatabase;

    private const RELEASE_ID = '2b6deff4-0fdf-5d7c-a86f-e3d4aa61c488';

    private const MANIFEST_HASH = '649a61633a05728618477b97036718c582673c96a82c24d142287991b3d2d0e1';

    private const PACKAGE_SHA256 = '9325013b870fd2496efc0882656240f91ce28ff4faaf1da42fb3dde3577b0ed3';

    private const PACK_ROOT = 'default/GLOBAL/en/MBTI-GLOBAL-en-v0.3';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('content_packs.root', base_path('../content_packages'));
        config()->set('storage_rollout.resolver_materialization_enabled', false);
        config()->set('storage_rollout.packs_v2_remote_rehydrate_enabled', false);
    }

    public function test_it_rejects_the_historical_inactive_receipt_after_the_physical_pack_became_active_without_writes(): void
    {
        $this->seedExactInactiveTarget();
        $packRoot = base_path('../content_packages/'.self::PACK_ROOT);
        $manifestPath = $packRoot.'/manifest.json';
        $draftPath = $packRoot.'/drafts/en-parity-w1-mbti-result-content-v1.json';
        $before = [
            'releases' => DB::table('content_pack_releases')->count(),
            'manifests' => DB::table('content_release_manifests')->count(),
            'activations' => DB::table('content_pack_activations')->count(),
            'manifest_hash' => hash_file('sha256', $manifestPath),
            'draft_hash' => hash_file('sha256', $draftPath),
        ];

        $resolved = app(ContentPackV2Resolver::class)->resolveCompiledPathByManifestHash(
            'MBTI.global.en.default',
            'v0.3',
            self::MANIFEST_HASH,
        );

        self::assertNull($resolved);
        self::assertSame($before['releases'], DB::table('content_pack_releases')->count());
        self::assertSame($before['manifests'], DB::table('content_release_manifests')->count());
        self::assertSame($before['activations'], DB::table('content_pack_activations')->count());
        self::assertSame($before['manifest_hash'], hash_file('sha256', $manifestPath));
        self::assertSame($before['draft_hash'], hash_file('sha256', $draftPath));
        self::assertFalse((bool) config('storage_rollout.resolver_materialization_enabled'));
        self::assertFalse((bool) config('storage_rollout.packs_v2_remote_rehydrate_enabled'));
    }

    public function test_it_rejects_any_identity_drift_or_active_pointer(): void
    {
        $this->seedExactInactiveTarget();
        $resolver = app(ContentPackV2Resolver::class);

        self::assertNull($resolver->resolveCompiledPathByManifestHash('MBTI.global.en.default', 'v0.2', self::MANIFEST_HASH));
        self::assertNull($resolver->resolveCompiledPathByManifestHash('MBTI.global.en.default', 'v0.3', str_repeat('0', 64)));
        self::assertNull($resolver->resolveCompiledPathByManifestHash('MBTI.cn-mainland.zh-CN.v0.3', 'v0.3', self::MANIFEST_HASH));

        DB::table('content_pack_activations')->insert([
            'pack_id' => 'MBTI.global.en.default',
            'pack_version' => 'v0.3',
            'release_id' => self::RELEASE_ID,
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertNull($resolver->resolveCompiledPathByManifestHash('MBTI.global.en.default', 'v0.3', self::MANIFEST_HASH));
    }

    public function test_it_rejects_non_english_or_unregistered_database_releases(): void
    {
        $payload = $this->exactPayload();
        $payload['authority']['locale'] = 'zh-CN';
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->insertRelease('11111111-1111-5111-a111-111111111111', 'zh-CN', $payloadJson);

        self::assertNull(app(ContentPackV2Resolver::class)->resolveCompiledPathByManifestHash(
            'MBTI.global.en.default',
            'v0.3',
            self::MANIFEST_HASH,
        ));
        self::assertNull(app(ContentPackV2Resolver::class)->resolveCompiledPathByManifestHash(
            'MBTI.global.en.default',
            'v0.3',
            str_repeat('f', 64),
        ));
    }

    public function test_it_rejects_release_storage_or_source_package_drift(): void
    {
        $this->seedExactInactiveTarget();
        $resolver = app(ContentPackV2Resolver::class);

        DB::table('content_pack_releases')
            ->where('id', self::RELEASE_ID)
            ->update(['storage_path' => 'database/content_pack_releases/not-the-target']);

        self::assertNull($resolver->resolveCompiledPathByManifestHash(
            'MBTI.global.en.default',
            'v0.3',
            self::MANIFEST_HASH,
        ));

        DB::table('content_pack_releases')
            ->where('id', self::RELEASE_ID)
            ->update(['storage_path' => 'database/content_pack_releases/'.self::RELEASE_ID]);
        $payload = $this->exactPayload();
        $payload['source']['package_sha256'] = str_repeat('0', 64);
        $driftedPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        DB::table('content_release_manifests')
            ->where('content_pack_release_id', self::RELEASE_ID)
            ->update(['payload_json' => $driftedPayload]);

        self::assertNull(app(ContentPackV2Resolver::class)->resolveCompiledPathByManifestHash(
            'MBTI.global.en.default',
            'v0.3',
            self::MANIFEST_HASH,
        ));
    }

    private function seedExactInactiveTarget(): void
    {
        $payloadJson = (string) File::get(base_path('../content_packages/'.self::PACK_ROOT.'/drafts/en-parity-w1-mbti-result-content-v1.json'));
        $this->insertRelease(self::RELEASE_ID, 'en', $payloadJson);
    }

    private function insertRelease(string $releaseId, string $locale, string $payloadJson): void
    {
        DB::table('content_pack_releases')->insert([
            'id' => $releaseId,
            'action' => 'mbti_target_authority_draft_receipt',
            'region' => 'GLOBAL',
            'locale' => $locale,
            'dir_alias' => 'MBTI-GLOBAL-en-v0.3',
            'to_pack_id' => 'MBTI.global.en.default',
            'status' => 'success',
            'manifest_hash' => self::MANIFEST_HASH,
            'compiled_hash' => self::PACKAGE_SHA256,
            'content_hash' => self::MANIFEST_HASH,
            'pack_version' => 'v0.3',
            'manifest_json' => $payloadJson,
            'storage_path' => 'database/content_pack_releases/'.$releaseId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('content_release_manifests')->insert([
            'content_pack_release_id' => $releaseId,
            'manifest_hash' => self::MANIFEST_HASH,
            'schema_version' => 'mbti.en_result_draft.v1',
            'storage_disk' => 'database',
            'storage_path' => 'content_pack_releases/'.$releaseId,
            'pack_id' => 'MBTI.global.en.default',
            'pack_version' => 'v0.3',
            'compiled_hash' => self::PACKAGE_SHA256,
            'content_hash' => self::MANIFEST_HASH,
            'payload_json' => $payloadJson,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function exactPayload(): array
    {
        return json_decode(
            (string) File::get(base_path('../content_packages/'.self::PACK_ROOT.'/drafts/en-parity-w1-mbti-result-content-v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
