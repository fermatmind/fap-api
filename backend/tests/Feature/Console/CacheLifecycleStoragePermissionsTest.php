<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class CacheLifecycleStoragePermissionsTest extends TestCase
{
    public function test_preparation_repairs_only_owned_maintenance_directories_and_refuses_symlinks(): void
    {
        $root = realpath(sys_get_temp_dir()).'/cache-permissions-'.bin2hex(random_bytes(6));
        $storage = $root.'/shared/backend/storage';
        try {
            File::ensureDirectoryExists($storage.'/app/private/career-cache-retention/20260908-000000-aaaaaaaa', 0700);
            File::ensureDirectoryExists($storage.'/app/ops', 0770);
            file_put_contents($storage.'/app/private/career-cache-retention/20260908-000000-aaaaaaaa/removed.jsonl', 'backup');
            $command = ['python3', base_path('scripts/deploy/prepare_cache_lifecycle_storage.py'), '--storage-root', $storage];
            $process = new Process($command);
            $process->mustRun();
            clearstatcache();
            $this->assertSame(02770, fileperms($storage.'/app/private/career-cache-retention') & 07777);
            $this->assertSame(0660, fileperms($storage.'/app/private/career-cache-retention/20260908-000000-aaaaaaaa/removed.jsonl') & 0777);
            file_put_contents($root.'/unrelated', 'untouched');
            symlink($root.'/unrelated', $storage.'/app/ops/cache-lifecycle/unsafe.json');
            $process = new Process($command);
            $process->run();
            $this->assertFalse($process->isSuccessful());
            $this->assertSame('untouched', file_get_contents($root.'/unrelated'));
        } finally {
            File::deleteDirectory($root);
        }
    }
}
