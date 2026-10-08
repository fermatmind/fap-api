<?php

namespace Tests\Unit;

use App\Http\Controllers\HealthzController;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class HealthzCacheDirsTest extends TestCase
{
    private string $root;

    private Container $previousContainer;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->root = sys_get_temp_dir().'/healthz-cache-'.bin2hex(random_bytes(8));
        foreach (['bootstrap/cache', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views'] as $path) {
            mkdir($this->root.'/'.$path, 0775, true);
        }
        file_put_contents($this->root.'/bootstrap/cache/config.php', '<?php return [];');
        $this->application = new class($this->root) extends Application
        {
            public function getCachedConfigPath()
            {
                return $this->basePath('bootstrap/cache/config.php');
            }
        };
        $this->application->useStoragePath($this->root.'/storage');
        $this->application->instance('config_loaded_from_cache', true);
        Container::setInstance($this->application);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        $this->removeFixture($this->root);
        parent::tearDown();
    }

    public function test_readable_compiled_configuration_does_not_require_bootstrap_write_access(): void
    {
        chmod($this->root.'/bootstrap/cache/config.php', 0444);
        chmod($this->root.'/bootstrap/cache', 0555);
        clearstatcache();
        $this->assertFalse(is_writable($this->root.'/bootstrap/cache'), 'Run permission regression as an unprivileged user.');

        $result = $this->checkCacheDirs();

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['items']['bootstrap_cache']['ok']);
        $this->assertFileDoesNotExist($this->root.'/bootstrap/cache/.__healthz_probe');
    }

    public function test_missing_compiled_configuration_fails_closed(): void
    {
        unlink($this->root.'/bootstrap/cache/config.php');

        $this->assertFalse($this->checkCacheDirs()['ok']);
    }

    public function test_unreadable_compiled_configuration_fails_closed(): void
    {
        chmod($this->root.'/bootstrap/cache/config.php', 0000);
        clearstatcache();
        $this->assertFalse(is_readable($this->root.'/bootstrap/cache/config.php'));

        $this->assertFalse($this->checkCacheDirs()['ok']);
    }

    public function test_missing_compiled_directory_fails_without_creating_it(): void
    {
        unlink($this->root.'/bootstrap/cache/config.php');
        rmdir($this->root.'/bootstrap/cache');

        $this->assertFalse($this->checkCacheDirs()['ok']);
        $this->assertDirectoryDoesNotExist($this->root.'/bootstrap/cache');
    }

    public function test_readonly_runtime_storage_still_fails(): void
    {
        chmod($this->root.'/storage/framework/sessions', 0555);
        clearstatcache();
        $this->assertFalse(is_writable($this->root.'/storage/framework/sessions'));

        $result = $this->checkCacheDirs();

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['items']['storage_framework_sessions']['ok']);
    }

    public function test_uncached_bootstrap_still_requires_write_access(): void
    {
        $this->application->instance('config_loaded_from_cache', false);
        unlink($this->root.'/bootstrap/cache/config.php');
        $this->assertTrue($this->checkCacheDirs()['ok']);
        chmod($this->root.'/bootstrap/cache', 0555);
        clearstatcache();

        $this->assertFalse($this->checkCacheDirs()['ok']);
    }

    private function checkCacheDirs(): array
    {
        return (new ReflectionMethod(HealthzController::class, 'checkCacheDirs'))
            ->invoke(new HealthzController);
    }

    private function removeFixture(string $path): void
    {
        if (! is_dir($path)) {
            chmod($path, 0600);
            unlink($path);

            return;
        }
        chmod($path, 0700);
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeFixture($path.'/'.$name);
            }
        }
        rmdir($path);
    }
}
