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

    public function test_linux_default_acl_preserves_writes_for_deployer_and_runtime_users(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Production POSIX ACL requires Linux.');
        }
        $sudo = new Process(['sudo', '-n', 'true']);
        $sudo->run();
        if (! $sudo->isSuccessful()) {
            $this->markTestSkipped('Cross-user permission exercise requires isolated runner sudo.');
        }
        $root = realpath(sys_get_temp_dir()).'/cache-actors-'.bin2hex(random_bytes(6));
        $storage = $root.'/shared/backend/storage';
        try {
            foreach (['', '/shared', '/shared/backend', '/shared/backend/storage', '/shared/backend/storage/app', '/shared/backend/storage/app/private', '/shared/backend/storage/app/ops'] as $part) {
                File::ensureDirectoryExists($root.$part, 0711);
            }
            // Give the second actor a disposable readable runtime; runner checkout parents
            // may be private and must not have their permissions broadened by this test.
            $runtime = $root.'/runtime';
            File::ensureDirectoryExists($runtime, 0755);
            (new Process(['cp', '-R', base_path('vendor'), $runtime.'/vendor']))->mustRun();
            foreach (['Services/Ops/CacheLifecycleAlerts.php', 'Support/PublicProjectionCache.php'] as $source) {
                File::ensureDirectoryExists(dirname($runtime.'/app/'.$source), 0755);
                File::copy(app_path($source), $runtime.'/app/'.$source);
            }
            (new Process(['chmod', '-R', 'a+rX', $runtime]))->mustRun();
            $nobody = posix_getpwnam('nobody');
            (new Process(['sudo', '-n', 'chown', posix_getuid().':'.$nobody['gid'], $root.'/shared']))->mustRun();
            (new Process(['sudo', '-n', 'python3', base_path('scripts/deploy/prepare_cache_lifecycle_storage.py'), '--storage-root', $storage]))->mustRun();
            $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = new Illuminate\Foundation\Application($argv[1]);
$app->useStoragePath($argv[2]);
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$app->instance('files', new Illuminate\Filesystem\Filesystem());
$app->instance('config', new Illuminate\Config\Repository(['public_projection_cache'=>['phase'=>'prepare']]));
set_error_handler(static function($number, $message) { throw new ErrorException($message, 0, $number); });
(new App\Services\Ops\CacheLifecycleAlerts)->observe('career_retention', true);
App\Support\PublicProjectionCache::mutation(static function() {
    App\Support\PublicProjectionCache::writeState(['version'=>1,'mode'=>'legacy']);
}, true);
PHP;
            foreach ([[], ['sudo', '-n', '-u', 'nobody', '--'], []] as $prefix) {
                (new Process([...$prefix, PHP_BINARY, '-r', $code, $runtime, $storage]))->mustRun();
            }
            $state = json_decode(file_get_contents($storage.'/app/ops/cache-lifecycle/career_retention.json'), true);
            $this->assertTrue($state['healthy']);
            $this->assertSame(0660, fileperms($storage.'/app/ops/cache-lifecycle/public_projection.lock') & 0777);
        } finally {
            if (is_dir($root)) {
                // Both test actors own files; only this unique disposable tree is removed.
                (new Process(['sudo', '-n', 'rm', '-rf', '--', $root]))->mustRun();
            }
        }
    }
}
