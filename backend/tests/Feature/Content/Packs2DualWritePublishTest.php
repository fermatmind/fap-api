<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\Publisher\ContentPackV2Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class Packs2DualWritePublishTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_compiled_dual_writes_to_private_and_mirror_paths(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);

        /** @var ContentPackV2Publisher $publisher */
        $publisher = app(ContentPackV2Publisher::class);
        $release = $publisher->publishCompiled('BIG5_OCEAN', 'v1', [
            'created_by' => 'test',
        ]);

        $releaseId = (string) ($release['id'] ?? '');
        $this->assertNotSame('', $releaseId);

        $primaryPath = 'private/packs_v2/BIG5_OCEAN/v1/'.$releaseId;
        $mirrorPath = 'content_packs_v2/BIG5_OCEAN/v1/'.$releaseId;

        $this->assertSame($primaryPath, (string) ($release['storage_path'] ?? ''));
        $this->assertFileExists(storage_path('app/'.$primaryPath.'/compiled/manifest.json'));
        $this->assertFileExists(storage_path('app/'.$mirrorPath.'/compiled/manifest.json'));

        $storedPath = (string) DB::table('content_pack_releases')
            ->where('id', $releaseId)
            ->value('storage_path');
        $this->assertSame($primaryPath, $storedPath);

        File::deleteDirectory(storage_path('app/'.$primaryPath));
        File::deleteDirectory(storage_path('app/'.$mirrorPath));
    }
}
