<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Services\Career\CareerCacheBackupRetention;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerCacheLifecycleTest extends TestCase
{
    public function test_twenty_identical_warms_reuse_versions_and_preserve_previous_lkg(): void
    {
        config(['cache.default' => 'array']);
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $old = $cache->publishDirectoryReadModel('en', ['items' => ['old']]);
        $active = $cache->publishDirectoryReadModel('en', ['items' => ['new']]);
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame($active, $cache->publishDirectoryReadModel('en', ['items' => ['new']]));
        }
        $this->assertSame($old, Cache::get('career:public-authority:directory-read-model:v2:en:lkg'));
        $this->assertNotSame($active, $cache->publishDirectoryReadModel('en', ['items' => ['changed']]));
        $this->assertSame($active, Cache::get('career:public-authority:directory-read-model:v2:en:lkg'));

        $payload = ['display_surface_v1' => ['page' => ['locale' => 'en', 'content' => ['hero' => ['h1' => 'Test']]], 'sources' => []]];
        $detail = $cache->publishJobDetailReadModel('test-role', 'en', $payload);
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame($detail, $cache->publishJobDetailReadModel('test-role', 'en', $payload));
        }
        $payload['source_version'] = 'next';
        $this->assertNotSame($detail, $cache->publishJobDetailReadModel('test-role', 'en', $payload));
    }

    public function test_backup_rotation_preserves_unfinished_and_recent_backups_and_enforces_capacity(): void
    {
        $root = sys_get_temp_dir().'/career-backups-'.bin2hex(random_bytes(6));
        try {
            foreach (['20260101-000000-aaaaaaaa', '20260101-000000-bbbbbbbb', '20260101-000000-cccccccc'] as $name) {
                File::ensureDirectoryExists($root.'/'.$name, 0700);
                file_put_contents($root.'/'.$name.'/removed.jsonl', 'backup');
            }
            file_put_contents($root.'/20260101-000000-aaaaaaaa/complete', 'done');
            touch($root.'/20260101-000000-aaaaaaaa/complete', time() - 8 * 86400);
            file_put_contents($root.'/20260101-000000-bbbbbbbb/complete', 'done');
            $retention = new CareerCacheBackupRetention;
            $this->assertSame(16, $retention->rotateAndMeasure($root, time()));
            $this->assertDirectoryDoesNotExist($root.'/20260101-000000-aaaaaaaa');
            $this->assertFileExists($root.'/20260101-000000-cccccccc/removed.jsonl');
            $this->expectException(\RuntimeException::class);
            $retention->assertHeadroom(CareerCacheBackupRetention::MAX_BYTES, 1);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
