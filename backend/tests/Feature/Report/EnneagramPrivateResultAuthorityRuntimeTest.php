<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Services\Content\EnneagramPrivateResultCompileService;
use App\Services\Content\Publisher\ContentPackV2Publisher;
use App\Services\Report\ReportGatekeeper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EnneagramPrivateResultAuthorityRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_compile_publish_and_activation_are_hash_bound_and_idempotent(): void
    {
        $compiled = app(EnneagramPrivateResultCompileService::class)->compile();
        $this->artisan('packs2:publish', [
            '--pack' => EnneagramPrivateResultCompileService::PACK_ID,
            '--pack-version' => EnneagramPrivateResultCompileService::PACK_VERSION,
            '--activate' => 1,
            '--source_commit' => str_repeat('b', 40),
        ])->assertSuccessful();

        $releaseId = DB::table('content_pack_activations')
            ->where('pack_id', EnneagramPrivateResultCompileService::PACK_ID)
            ->where('pack_version', EnneagramPrivateResultCompileService::PACK_VERSION)
            ->value('release_id');
        $release = DB::table('content_pack_releases')->where('id', $releaseId)->first();
        $this->assertNotNull($release);
        $this->assertSame($compiled['source_hash'], $release->content_hash);
        $this->assertSame($compiled['compiled_hash'], $release->compiled_hash);
        $this->assertSame(str_repeat('b', 40), $release->source_commit);

        $releaseCount = DB::table('content_pack_releases')
            ->where('to_pack_id', EnneagramPrivateResultCompileService::PACK_ID)
            ->count();
        $this->artisan('packs2:publish', [
            '--pack' => EnneagramPrivateResultCompileService::PACK_ID,
            '--pack-version' => EnneagramPrivateResultCompileService::PACK_VERSION,
            '--activate' => 1,
        ])->assertSuccessful();
        $this->assertSame($releaseCount, DB::table('content_pack_releases')
            ->where('to_pack_id', EnneagramPrivateResultCompileService::PACK_ID)
            ->count());
    }

    public function test_legacy_snapshot_body_is_preserved_and_marked_immutable(): void
    {
        $body = ['schema_version' => 'enneagram.report.v1', 'sections' => [['key' => 'legacy', 'body' => 'original body']]];
        $method = new \ReflectionMethod(ReportGatekeeper::class, 'markLegacyPrivateResultSnapshot');
        $marked = $method->invoke(app(ReportGatekeeper::class), $body, (object) ['scale_code' => 'ENNEAGRAM']);

        $this->assertSame($body['sections'], $marked['sections']);
        $this->assertSame('immutable_legacy_snapshot', data_get($marked, '_meta.enneagram_private_result_authority.mode'));
        $this->assertSame('', data_get($marked, '_meta.enneagram_private_result_authority.source_hash'));
        $this->assertSame('', data_get($marked, '_meta.enneagram_private_result_authority.compiled_hash'));
    }

    public function test_activation_can_atomically_restore_the_previous_immutable_release(): void
    {
        $publisher = app(ContentPackV2Publisher::class);
        $first = $publisher->publishCompiled(
            EnneagramPrivateResultCompileService::PACK_ID,
            EnneagramPrivateResultCompileService::PACK_VERSION,
            ['source_commit' => str_repeat('c', 40)]
        );
        $publisher->activateRelease((string) $first['id']);
        $second = $publisher->publishCompiled(
            EnneagramPrivateResultCompileService::PACK_ID,
            EnneagramPrivateResultCompileService::PACK_VERSION,
            ['source_commit' => str_repeat('d', 40)]
        );
        $publisher->activateRelease((string) $second['id']);

        $this->assertSame($second['id'], DB::table('content_pack_activations')->value('release_id'));
        $publisher->rollbackToRelease(
            EnneagramPrivateResultCompileService::PACK_ID,
            EnneagramPrivateResultCompileService::PACK_VERSION,
            (string) $first['id']
        );
        $this->assertSame($first['id'], DB::table('content_pack_activations')->value('release_id'));
        $this->assertDatabaseHas('content_pack_releases', ['id' => $second['id'], 'status' => 'success']);
    }
}
