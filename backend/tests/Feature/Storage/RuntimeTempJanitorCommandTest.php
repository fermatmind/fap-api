<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Console\Commands\StorageJanitorRuntimeTemps;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RuntimeTempJanitorCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStoragePath;

    private string $originalStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-runtime-temp-janitor-command-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath);
        $this->app->useStoragePath($this->isolatedStoragePath);

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(StorageJanitorRuntimeTemps::class)
        );
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStoragePath);

        if (is_dir($this->isolatedStoragePath)) {
            File::deleteDirectory($this->isolatedStoragePath);
        }

        parent::tearDown();
    }

    public function test_command_dry_run_outputs_summary_without_deleting_files(): void
    {
        $fixture = $this->seedRuntimeTempFixture();

        $this->assertSame(0, Artisan::call('storage:janitor-runtime-temps', [
            '--dry-run' => true,
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('status=planned', $output);
        $this->assertStringContainsString('mode=dry_run', $output);
        $this->assertStringContainsString('schema=storage_janitor_runtime_temps.v1', $output);
        $this->assertStringContainsString('scanned_file_count=7', $output);
        $this->assertStringContainsString('candidate_delete_count=4', $output);
        $this->assertStringContainsString('deleted_file_count=0', $output);
        $this->assertStringContainsString('skipped_file_count=3', $output);

        $this->assertFileExists($fixture['logs_candidate']);
        $this->assertFileExists($fixture['session_file']);
        $this->assertFileExists($fixture['gitkeep']);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/runs'));
    }

    public function test_command_execute_deletes_only_allowlisted_files(): void
    {
        $fixture = $this->seedRuntimeTempFixture();

        $this->assertSame(0, Artisan::call('storage:janitor-runtime-temps', [
            '--execute' => true,
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('status=executed', $output);
        $this->assertStringContainsString('mode=execute', $output);
        $this->assertStringContainsString('deleted_file_count=4', $output);

        $this->assertFileDoesNotExist($fixture['logs_candidate']);
        $this->assertFileDoesNotExist($fixture['cache_candidate']);
        $this->assertFileDoesNotExist($fixture['cache_data_candidate']);
        $this->assertFileDoesNotExist($fixture['views_candidate']);
        $this->assertFileExists($fixture['session_file']);
        $this->assertFileExists($fixture['gitkeep']);
        $this->assertDirectoryExists(storage_path('logs'));
        $this->assertDirectoryExists(storage_path('framework/sessions'));
    }

    public function test_command_requires_exactly_one_mode(): void
    {
        $this->assertSame(1, Artisan::call('storage:janitor-runtime-temps'));
        $this->assertStringContainsString('exactly one of --dry-run or --execute is required.', Artisan::output());

        $this->assertSame(1, Artisan::call('storage:janitor-runtime-temps', [
            '--dry-run' => true,
            '--execute' => true,
        ]));
        $this->assertStringContainsString('exactly one of --dry-run or --execute is required.', Artisan::output());
    }

    public function test_command_json_outputs_full_payload_without_extra_artifacts(): void
    {
        $fixture = $this->seedRuntimeTempFixture();

        $this->assertSame(0, Artisan::call('storage:janitor-runtime-temps', [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('storage_janitor_runtime_temps.v1', $payload['schema']);
        $this->assertSame('planned', $payload['status']);
        $this->assertSame(4, data_get($payload, 'summary.candidate_delete_count'));
        $this->assertSame(4, count((array) ($payload['candidates'] ?? [])));
        $this->assertContains($fixture['session_file'], array_column((array) ($payload['skipped'] ?? []), 'path'));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/plans'));
    }

    /**
     * @return array<string,string>
     */
    private function seedRuntimeTempFixture(): array
    {
        return [
            'logs_candidate' => $this->writeFile('logs/laravel.log', 'laravel'),
            'cache_candidate' => $this->writeFile('framework/cache/facade-runtime.php', '<?php return [];'),
            'cache_data_candidate' => $this->writeFile('framework/cache/data/ab/cd/cache.bin', 'cache'),
            'views_candidate' => $this->writeFile('framework/views/compiled.php', '<?php echo "view";'),
            'session_file' => $this->writeFile('framework/sessions/session-live', 'session'),
            'gitkeep' => $this->writeFile('framework/views/.gitkeep', ''),
            'non_allow' => $this->writeFile('logs/readme.txt', 'readme'),
        ];
    }

    private function writeFile(string $relativePath, string $contents): string
    {
        $path = storage_path($relativePath);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);

        return $path;
    }
}
