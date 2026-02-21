<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PacksRollbackBig5Test extends TestCase
{
    use RefreshDatabase;

    private const DIR_ALIAS = 'BIG5-OCEAN-ROLLBACK-CI-TEST';

    protected function tearDown(): void
    {
        $target = base_path('../content_packages/default/CN_MAINLAND/zh-CN/'.self::DIR_ALIAS);
        if (File::isDirectory($target)) {
            File::deleteDirectory($target);
        }

        parent::tearDown();
    }

    public function test_packs_rollback_restores_previous_release(): void
    {
        $target = base_path('../content_packages/default/CN_MAINLAND/zh-CN/'.self::DIR_ALIAS);
        if (File::isDirectory($target)) {
            File::deleteDirectory($target);
        }

        $publishCommand = sprintf(
            'packs:publish --scale=BIG5_OCEAN --pack=BIG5_OCEAN --pack-version=v1 --region=CN_MAINLAND --locale=zh-CN --dir_alias=%s --probe=0',
            self::DIR_ALIAS
        );
        $this->artisan($publishCommand)->assertExitCode(0);
        $this->artisan($publishCommand)->assertExitCode(0);

        $this->artisan(sprintf(
            'packs:rollback --scale=BIG5_OCEAN --region=CN_MAINLAND --locale=zh-CN --dir_alias=%s --probe=0',
            self::DIR_ALIAS
        ))->assertExitCode(0);

        $release = DB::table('content_pack_releases')
            ->where('action', 'rollback')
            ->where('dir_alias', self::DIR_ALIAS)
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($release);
        $this->assertSame('success', (string) $release->status);
        $this->assertSame('BIG5_OCEAN', (string) $release->to_pack_id);

        $this->assertTrue(File::isDirectory($target.'/compiled'));
        $this->assertTrue(File::exists($target.'/compiled/manifest.json'));
    }
}
