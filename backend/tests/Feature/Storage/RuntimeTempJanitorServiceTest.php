<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\RuntimeTempJanitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RuntimeTempJanitorServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStoragePath;

    private string $originalStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-runtime-temp-janitor-service-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath);
        $this->app->useStoragePath($this->isolatedStoragePath);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStoragePath);

        if (is_dir($this->isolatedStoragePath)) {
            File::deleteDirectory($this->isolatedStoragePath);
        }

        parent::tearDown();
    }

    public function test_dry_run_enumerates_only_allowlisted_temp_files_and_preserves_no_touch_surfaces(): void
    {
        $fixture = $this->seedRuntimeTempFixture();
        $filesBefore = $this->storageFilesSnapshot();

        /** @var RuntimeTempJanitorService $service */
        $service = app(RuntimeTempJanitorService::class);
        $result = $service->run(false);

        $this->assertSame('planned', $result['status']);
        $this->assertSame(15, data_get($result, 'summary.scanned_file_count'));
        $this->assertSame(7, data_get($result, 'summary.candidate_delete_count'));
        $this->assertSame(0, data_get($result, 'summary.deleted_file_count'));
        $this->assertSame(8, data_get($result, 'summary.skipped_file_count'));

        $candidatePaths = array_column((array) $result['candidates'], 'path');
        sort($candidatePaths);
        $this->assertSame($fixture['candidate_paths'], $candidatePaths);

        $skippedPaths = array_column((array) $result['skipped'], 'path');
        sort($skippedPaths);
        $this->assertContains($fixture['session_file'], $skippedPaths);
        $this->assertContains($fixture['logs_gitkeep'], $skippedPaths);
        $this->assertContains($fixture['cache_non_allow'], $skippedPaths);
        $this->assertContains($fixture['testing_file'], $skippedPaths);
        $this->assertSame(4, data_get($result, 'skipped_reasons.GITKEEP_PRESERVED'));
        $this->assertSame(2, data_get($result, 'skipped_reasons.NON_ALLOWLIST_FILE'));
        $this->assertSame(2, data_get($result, 'skipped_reasons.RUNTIME_NO_TOUCH_SURFACE'));

        $noTouchPaths = (array) ($result['no_touch_paths'] ?? []);
        $this->assertContains(storage_path('logs'), $noTouchPaths);
        $this->assertContains(storage_path('framework/cache'), $noTouchPaths);
        $this->assertContains(storage_path('framework/cache/data'), $noTouchPaths);
        $this->assertContains(storage_path('framework/views'), $noTouchPaths);
        $this->assertContains(storage_path('framework/sessions'), $noTouchPaths);

        $this->assertSame($filesBefore, $this->storageFilesSnapshot());
        $this->assertDirectoryExists(storage_path('logs'));
        $this->assertDirectoryExists(storage_path('framework/cache'));
        $this->assertDirectoryExists(storage_path('framework/cache/data'));
        $this->assertDirectoryExists(storage_path('framework/views'));
        $this->assertDirectoryExists(storage_path('framework/sessions'));
        $this->assertFileExists($fixture['logs_gitkeep']);
        $this->assertFileExists($fixture['session_file']);
        $this->assertFileExists($fixture['testing_file']);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/runs'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'storage_janitor_runtime_temps',
            'target_id' => 'runtime_temps',
            'result' => 'success',
        ]);
    }

    public function test_execute_deletes_only_allowlisted_files_and_preserves_roots_gitkeep_and_sessions(): void
    {
        $fixture = $this->seedRuntimeTempFixture();

        /** @var RuntimeTempJanitorService $service */
        $service = app(RuntimeTempJanitorService::class);
        $result = $service->run(true);

        $this->assertSame('executed', $result['status']);
        $this->assertSame(7, data_get($result, 'summary.candidate_delete_count'));
        $this->assertSame(7, data_get($result, 'summary.deleted_file_count'));
        $this->assertSame(8, data_get($result, 'summary.skipped_file_count'));

        foreach ($fixture['candidate_paths'] as $path) {
            $this->assertFileDoesNotExist($path);
        }

        $this->assertFileExists($fixture['logs_gitkeep']);
        $this->assertFileExists($fixture['cache_gitkeep']);
        $this->assertFileExists($fixture['views_gitkeep']);
        $this->assertFileExists($fixture['session_file']);
        $this->assertFileExists($fixture['sessions_gitkeep']);
        $this->assertFileExists($fixture['testing_file']);
        $this->assertFileExists($fixture['logs_non_allow']);
        $this->assertFileExists($fixture['cache_non_allow']);
        $this->assertDirectoryExists(storage_path('logs'));
        $this->assertDirectoryExists(storage_path('framework/cache'));
        $this->assertDirectoryExists(storage_path('framework/cache/data'));
        $this->assertDirectoryExists(storage_path('framework/views'));
        $this->assertDirectoryExists(storage_path('framework/sessions'));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/plans'));

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_janitor_runtime_temps')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($audit);
        $meta = json_decode((string) $audit->meta_json, true);
        $this->assertIsArray($meta);
        $this->assertSame('execute', $meta['mode']);
        $this->assertSame(7, data_get($meta, 'summary.deleted_file_count'));
        $this->assertCount(7, (array) ($meta['deleted_paths'] ?? []));
        $this->assertSame(4, data_get($meta, 'skipped_reasons.GITKEEP_PRESERVED'));
        $this->assertSame(2, data_get($meta, 'skipped_reasons.NON_ALLOWLIST_FILE'));
        $this->assertSame(2, data_get($meta, 'skipped_reasons.RUNTIME_NO_TOUCH_SURFACE'));
    }

    /**
     * @return array<string,mixed>
     */
    private function seedRuntimeTempFixture(): array
    {
        $paths = [
            'logs_candidate_a' => $this->writeFile('logs/laravel.log', 'laravel'),
            'logs_candidate_b' => $this->writeFile('logs/worker.log', 'worker'),
            'logs_non_allow' => $this->writeFile('logs/readme.txt', 'readme'),
            'logs_gitkeep' => $this->writeFile('logs/.gitkeep', ''),
            'cache_facade' => $this->writeFile('framework/cache/facade-abc123.php', '<?php return [];'),
            'cache_non_allow' => $this->writeFile('framework/cache/not-allowed.php', '<?php return false;'),
            'cache_gitkeep' => $this->writeFile('framework/cache/.gitkeep', ''),
            'cache_data_a' => $this->writeFile('framework/cache/data/aa/bb/cache-one.bin', 'cache-one'),
            'cache_data_b' => $this->writeFile('framework/cache/data/root-cache.bin', 'cache-root'),
            'views_compiled' => $this->writeFile('framework/views/compiled.php', '<?php echo "compiled";'),
            'views_nested' => $this->writeFile('framework/views/nested/item.blade.php', 'blade'),
            'views_gitkeep' => $this->writeFile('framework/views/.gitkeep', ''),
            'session_file' => $this->writeFile('framework/sessions/session-active', 'session'),
            'sessions_gitkeep' => $this->writeFile('framework/sessions/.gitkeep', ''),
            'testing_file' => $this->writeFile('framework/testing/testing.tmp', 'testing'),
        ];

        $paths['candidate_paths'] = [
            $paths['cache_data_a'],
            $paths['cache_data_b'],
            $paths['cache_facade'],
            $paths['logs_candidate_a'],
            $paths['logs_candidate_b'],
            $paths['views_compiled'],
            $paths['views_nested'],
        ];
        sort($paths['candidate_paths']);

        return $paths;
    }

    private function writeFile(string $relativePath, string $contents): string
    {
        $path = storage_path($relativePath);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);

        return $path;
    }

    /**
     * @return list<string>
     */
    private function storageFilesSnapshot(): array
    {
        if (! is_dir($this->isolatedStoragePath)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->isolatedStoragePath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $files[] = str_replace($this->isolatedStoragePath.DIRECTORY_SEPARATOR, '', $file->getPathname());
        }

        sort($files);

        return $files;
    }
}
