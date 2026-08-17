<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Services\Content\Eq60PackLoader;
use App\Services\Content\Publisher\ContentPackV2Publisher;
use App\Services\Report\ReportGatekeeper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class Eq60PrivateResultAuthorityRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_bilingual_pack_is_published_activated_and_read_back_by_exact_hash(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('content_packs/EQ_60/v1/compiled/manifest.json')), true);

        $this->artisan('packs2:publish', [
            '--pack' => Eq60PackLoader::PACK_ID,
            '--pack-version' => Eq60PackLoader::PACK_VERSION,
            '--activate' => 1,
            '--source_commit' => str_repeat('a', 40),
        ])->assertSuccessful();

        $authorityZh = app(Eq60PackLoader::class)->authority('v1', 'zh-CN');
        $authorityEn = app(Eq60PackLoader::class)->authority('v1', 'en');
        $release = DB::table('content_pack_releases')->where('id', $authorityZh['release_id'])->first();

        $this->assertNotNull($release);
        $this->assertSame($manifest['source_hash'], $release->content_hash);
        $this->assertSame($manifest['compiled_hash'], $release->compiled_hash);
        $this->assertSame(str_repeat('a', 40), $release->source_commit);
        $this->assertSame(['zh-CN', 'en'], $authorityZh['locales']);
        $this->assertSame($authorityZh['source_hash'], $authorityEn['source_hash']);
        $this->assertSame($authorityZh['compiled_hash'], $authorityEn['compiled_hash']);
        $this->assertCount(8, glob(storage_path('app/'.$release->storage_path.'/compiled/*')) ?: []);
    }

    public function test_activation_uses_expected_previous_compare_and_swap_and_preserves_lkg_on_mismatch(): void
    {
        $publisher = app(ContentPackV2Publisher::class);
        $first = $publisher->publishCompiled(Eq60PackLoader::PACK_ID, Eq60PackLoader::PACK_VERSION, [
            'source_commit' => str_repeat('b', 40),
        ]);
        $publisher->activateRelease((string) $first['id'], null, true);
        $second = $publisher->publishCompiled(Eq60PackLoader::PACK_ID, Eq60PackLoader::PACK_VERSION, [
            'source_commit' => str_repeat('c', 40),
        ]);

        try {
            $publisher->activateRelease((string) $second['id'], (string) \Illuminate\Support\Str::uuid(), true);
            $this->fail('Activation accepted a stale expected previous release.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ACTIVE_RELEASE_COMPARE_AND_SWAP_MISMATCH', $exception->getMessage());
        }
        $this->assertSame($first['id'], DB::table('content_pack_activations')->value('release_id'));

        $publisher->activateRelease((string) $second['id'], (string) $first['id'], true);
        $this->assertSame($second['id'], DB::table('content_pack_activations')->value('release_id'));
        $publisher->rollbackToRelease(Eq60PackLoader::PACK_ID, Eq60PackLoader::PACK_VERSION, (string) $first['id']);
        $this->assertSame($first['id'], DB::table('content_pack_activations')->value('release_id'));
    }

    public function test_production_runtime_fails_closed_without_active_release_or_raw_fallback(): void
    {
        $previousEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'production');

        try {
            app(Eq60PackLoader::class)->loadReportAssets('v1');
            $this->fail('Production loader accepted repository or raw content without an active release.');
        } catch (RuntimeException $exception) {
            $this->assertSame('EQ_60_ACTIVE_RELEASE_MISSING', $exception->getMessage());
        } finally {
            app()->detectEnvironment(static fn (): string => $previousEnvironment);
        }
    }

    public function test_invalid_candidate_is_rejected_before_release_evidence_is_written(): void
    {
        $candidateDir = storage_path('framework/testing/eq60-invalid-candidate');
        File::deleteDirectory($candidateDir);
        File::copyDirectory(base_path('content_packs/EQ_60/v1/compiled'), $candidateDir);
        File::append($candidateDir.'/report.compiled.json', "\n");

        try {
            app(ContentPackV2Publisher::class)->publishCompiled(
                Eq60PackLoader::PACK_ID,
                Eq60PackLoader::PACK_VERSION,
                [
                    'source_commit' => str_repeat('d', 40),
                    'source_compiled_dir' => $candidateDir,
                ],
            );
            $this->fail('Publisher accepted a candidate whose bytes do not match the manifest.');
        } catch (RuntimeException $exception) {
            $this->assertSame('EQ_60_PRIVATE_RESULT_RELEASE_FILE_HASH_MISMATCH', $exception->getMessage());
        } finally {
            File::deleteDirectory($candidateDir);
        }

        $this->assertSame(0, DB::table('content_pack_releases')->count());
    }

    public function test_eq60_publication_requires_an_exact_source_commit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EQ_60_SOURCE_COMMIT_INVALID');

        app(ContentPackV2Publisher::class)->publishCompiled(
            Eq60PackLoader::PACK_ID,
            Eq60PackLoader::PACK_VERSION,
            ['source_commit' => 'main'],
        );
    }

    public function test_legacy_snapshot_body_is_preserved_and_marked_immutable(): void
    {
        $body = ['schema_version' => 'eq_60.report.v1', 'sections' => [['key' => 'legacy', 'body' => 'original body']]];
        $method = new \ReflectionMethod(ReportGatekeeper::class, 'markLegacyPrivateResultSnapshot');
        $marked = $method->invoke(app(ReportGatekeeper::class), $body, (object) ['scale_code' => 'EQ_60']);

        $this->assertSame($body['sections'], $marked['sections']);
        $this->assertSame('immutable_legacy_snapshot', data_get($marked, '_meta.eq60_private_result_authority.mode'));
        $this->assertSame('', data_get($marked, '_meta.eq60_private_result_authority.source_hash'));
        $this->assertSame('', data_get($marked, '_meta.eq60_private_result_authority.compiled_hash'));
    }
}
