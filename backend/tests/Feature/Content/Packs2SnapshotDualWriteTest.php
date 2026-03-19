<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\Publisher\ContentPackV2Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class Packs2SnapshotDualWriteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $publishedStoragePaths = [];

    protected function tearDown(): void
    {
        foreach (array_values(array_unique($this->publishedStoragePaths)) as $storagePath) {
            if ($storagePath !== '') {
                File::deleteDirectory(storage_path('app/'.$storagePath));
            }
        }

        parent::tearDown();
    }

    public function test_flags_disabled_activate_and_rollback_do_not_write_snapshot_metadata(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);

        /** @var ContentPackV2Publisher $publisher */
        $publisher = app(ContentPackV2Publisher::class);

        $firstRelease = $publisher->publishCompiled('BIG5_OCEAN', 'v1', ['created_by' => 'test']);
        $firstReleaseId = (string) ($firstRelease['id'] ?? '');
        $this->rememberPublishedPaths('BIG5_OCEAN', 'v1', $firstReleaseId);

        $publisher->activateRelease($firstReleaseId);
        $this->assertDatabaseCount('content_release_snapshots', 0);

        $secondRelease = $publisher->publishCompiled('BIG5_OCEAN', 'v1', ['created_by' => 'test']);
        $secondReleaseId = (string) ($secondRelease['id'] ?? '');
        $this->rememberPublishedPaths('BIG5_OCEAN', 'v1', $secondReleaseId);

        $publisher->activateRelease($secondReleaseId);
        $publisher->rollbackToRelease('BIG5_OCEAN', 'v1', $firstReleaseId);

        $this->assertDatabaseCount('content_release_snapshots', 0);
        $this->assertSame(
            $firstReleaseId,
            (string) DB::table('content_pack_activations')
                ->where('pack_id', 'BIG5_OCEAN')
                ->where('pack_version', 'v1')
                ->value('release_id')
        );
    }

    public function test_flags_enabled_activate_and_rollback_write_snapshot_rows_with_accurate_before_after_ids(): void
    {
        config()->set('storage_rollout.content_pack_v2_dual_write_enabled', true);
        config()->set('storage_rollout.snapshot_catalog_enabled', true);

        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);

        /** @var ContentPackV2Publisher $publisher */
        $publisher = app(ContentPackV2Publisher::class);

        $firstRelease = $publisher->publishCompiled('BIG5_OCEAN', 'v1', ['created_by' => 'test']);
        $firstReleaseId = (string) ($firstRelease['id'] ?? '');
        $this->rememberPublishedPaths('BIG5_OCEAN', 'v1', $firstReleaseId);

        $publisher->activateRelease($firstReleaseId);

        $firstSnapshot = DB::table('content_release_snapshots')->orderBy('id')->first();
        $this->assertNotNull($firstSnapshot);
        $this->assertSame('BIG5_OCEAN', (string) ($firstSnapshot->pack_id ?? ''));
        $this->assertSame('v1', (string) ($firstSnapshot->pack_version ?? ''));
        $this->assertNull($firstSnapshot->from_content_pack_release_id);
        $this->assertSame($firstReleaseId, (string) ($firstSnapshot->to_content_pack_release_id ?? ''));
        $this->assertNull($firstSnapshot->activation_before_release_id);
        $this->assertSame($firstReleaseId, (string) ($firstSnapshot->activation_after_release_id ?? ''));
        $this->assertSame('packs2_activate', (string) ($firstSnapshot->reason ?? ''));

        $secondRelease = $publisher->publishCompiled('BIG5_OCEAN', 'v1', ['created_by' => 'test']);
        $secondReleaseId = (string) ($secondRelease['id'] ?? '');
        $this->rememberPublishedPaths('BIG5_OCEAN', 'v1', $secondReleaseId);

        $publisher->activateRelease($secondReleaseId);

        $secondSnapshot = DB::table('content_release_snapshots')->orderByDesc('id')->first();
        $this->assertNotNull($secondSnapshot);
        $this->assertSame($firstReleaseId, (string) ($secondSnapshot->from_content_pack_release_id ?? ''));
        $this->assertSame($secondReleaseId, (string) ($secondSnapshot->to_content_pack_release_id ?? ''));
        $this->assertSame($firstReleaseId, (string) ($secondSnapshot->activation_before_release_id ?? ''));
        $this->assertSame($secondReleaseId, (string) ($secondSnapshot->activation_after_release_id ?? ''));
        $this->assertSame('packs2_activate', (string) ($secondSnapshot->reason ?? ''));

        $publisher->rollbackToRelease('BIG5_OCEAN', 'v1', $firstReleaseId);

        $rollbackSnapshot = DB::table('content_release_snapshots')->orderByDesc('id')->first();
        $rollbackHistoryRow = DB::table('content_pack_releases')
            ->where('action', 'packs2_rollback')
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($rollbackSnapshot);
        $this->assertNotNull($rollbackHistoryRow);
        $this->assertSame($secondReleaseId, (string) ($rollbackSnapshot->from_content_pack_release_id ?? ''));
        $this->assertSame($firstReleaseId, (string) ($rollbackSnapshot->to_content_pack_release_id ?? ''));
        $this->assertSame($secondReleaseId, (string) ($rollbackSnapshot->activation_before_release_id ?? ''));
        $this->assertSame($firstReleaseId, (string) ($rollbackSnapshot->activation_after_release_id ?? ''));
        $this->assertSame('packs2_rollback', (string) ($rollbackSnapshot->reason ?? ''));
        $this->assertNotSame((string) ($rollbackHistoryRow->id ?? ''), (string) ($rollbackSnapshot->to_content_pack_release_id ?? ''));
        $this->assertSame(
            $firstReleaseId,
            (string) DB::table('content_pack_activations')
                ->where('pack_id', 'BIG5_OCEAN')
                ->where('pack_version', 'v1')
                ->value('release_id')
        );
    }

    private function rememberPublishedPaths(string $packId, string $packVersion, string $releaseId): void
    {
        if ($releaseId === '') {
            return;
        }

        $this->publishedStoragePaths[] = 'private/packs_v2/'.$packId.'/'.$packVersion.'/'.$releaseId;
        $this->publishedStoragePaths[] = 'content_packs_v2/'.$packId.'/'.$packVersion.'/'.$releaseId;
    }
}
