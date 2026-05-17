<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageReleaseRootsAuditTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStoragePath;

    private string $originalStoragePath;

    private string $originalLocalRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        $this->originalLocalRoot = (string) config('filesystems.disks.local.root');
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-storage-release-roots-audit-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        Storage::forgetDisk('local');

        Schema::create('content_release_audit_refs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('storage_path', 1024)->nullable();
            $table->text('payload_json')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        $this->app->useStoragePath($this->originalStoragePath);
        config()->set('filesystems.disks.local.root', $this->originalLocalRoot);
        Storage::forgetDisk('local');

        if (is_dir($this->isolatedStoragePath)) {
            File::deleteDirectory($this->isolatedStoragePath);
        }

        parent::tearDown();
    }

    public function test_missing_root_exits_zero_without_creating_directory(): void
    {
        $root = storage_path('app/private/content_releases');
        $this->assertDirectoryDoesNotExist($root);

        $payload = $this->runAudit();

        $this->assertDirectoryDoesNotExist($root);
        $this->assertFalse((bool) data_get($payload, 'root.exists'));
        $this->assertSame('root_missing_no_action', data_get($payload, 'root.classification'));
        $this->assertSame(0, (int) data_get($payload, 'summary.root_count'));
        $this->assertSafetyNoWrite($payload);
    }

    public function test_empty_root_exits_zero(): void
    {
        File::ensureDirectoryExists(storage_path('app/private/content_releases'));

        $payload = $this->runAudit();

        $this->assertTrue((bool) data_get($payload, 'root.exists'));
        $this->assertTrue((bool) data_get($payload, 'root.empty'));
        $this->assertSame('root_empty_no_action', data_get($payload, 'root.classification'));
        $this->assertSame(0, (int) data_get($payload, 'summary.root_count'));
        $this->assertSafetyNoWrite($payload);
    }

    public function test_known_shapes_are_counted_and_classified(): void
    {
        Storage::disk('local')->put('content_releases/release-a/source_pack/compiled/questions.compiled.json', '{}');
        Storage::disk('local')->put('content_releases/backups/release-b/previous_pack/compiled/questions.compiled.json', '{}');
        Storage::disk('local')->put('content_releases/backups/release-c/current_pack/compiled/questions.compiled.json', '{}');

        $payload = $this->runAudit();

        $this->assertSame(3, (int) data_get($payload, 'summary.root_count'));
        $this->assertSame(1, (int) data_get($payload, 'summary.by_kind.source_pack'));
        $this->assertSame(1, (int) data_get($payload, 'summary.by_kind.previous_pack'));
        $this->assertSame(1, (int) data_get($payload, 'summary.by_kind.current_pack'));

        $roots = collect($payload['roots'])->keyBy('kind');
        $this->assertSame('unreferenced_source_pack_review_required', data_get($roots->get('source_pack'), 'classification'));
        $this->assertSame('unreferenced_previous_pack_review_required', data_get($roots->get('previous_pack'), 'classification'));
        $this->assertSame('unreferenced_current_pack_low_risk_candidate', data_get($roots->get('current_pack'), 'classification'));
        $this->assertSafetyNoWrite($payload);
    }

    public function test_db_referenced_source_pack_is_strong_keep(): void
    {
        Storage::disk('local')->put('content_releases/release-a/source_pack/compiled/questions.compiled.json', '{}');
        DB::table('content_release_audit_refs')->insert([
            'storage_path' => storage_path('app/private/content_releases/release-a/source_pack'),
            'payload_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->runAudit();
        $sourceRoot = collect($payload['roots'])->firstWhere('kind', 'source_pack');

        $this->assertSame('strong_keep', data_get($sourceRoot, 'classification'));
        $this->assertGreaterThanOrEqual(1, (int) data_get($payload, 'db_refs.content_release_related_refs'));
        $this->assertSafetyNoWrite($payload);
    }

    public function test_dangling_db_ref_is_reported_for_missing_release_root(): void
    {
        DB::table('content_release_audit_refs')->insert([
            'storage_path' => storage_path('app/private/content_releases/missing-release/source_pack'),
            'payload_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->runAudit();

        $this->assertSame(1, (int) data_get($payload, 'summary.dangling_ref_count'));
        $this->assertSame('dangling_ref_repair_required', data_get($payload, 'db_refs.dangling_refs.0.classification'));
        $this->assertSame('source_pack', data_get($payload, 'db_refs.dangling_refs.0.referenced_root_kind'));
        $this->assertSafetyNoWrite($payload);
    }

    public function test_audit_does_not_write_plans_change_files_or_mutate_db(): void
    {
        Storage::disk('local')->put('content_releases/release-a/source_pack/compiled/questions.compiled.json', '{}');
        DB::table('content_release_audit_refs')->insert([
            'storage_path' => storage_path('app/private/content_releases/release-a/source_pack'),
            'payload_json' => '{"path":"content_releases/release-a/source_pack"}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $beforeFiles = $this->contentReleaseFileList();
        $beforeRows = (int) DB::table('content_release_audit_refs')->count();
        $this->assertDirectoryDoesNotExist(storage_path('app/private/prune_plans'));

        $payload = $this->runAudit();

        $this->assertSame($beforeFiles, $this->contentReleaseFileList());
        $this->assertSame($beforeRows, (int) DB::table('content_release_audit_refs')->count());
        $this->assertDirectoryDoesNotExist(storage_path('app/private/prune_plans'));
        $this->assertSafetyNoWrite($payload);
    }

    /**
     * @return array<string,mixed>
     */
    private function runAudit(): array
    {
        $this->assertSame(0, Artisan::call('storage:release-roots:audit', [
            '--format' => 'json',
        ]));

        $payload = json_decode((string) Artisan::output(), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function contentReleaseFileList(): array
    {
        $root = storage_path('app/private/content_releases');
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertSafetyNoWrite(array $payload): void
    {
        $this->assertFalse((bool) data_get($payload, 'safety.cleanup_executed'));
        $this->assertFalse((bool) data_get($payload, 'safety.plan_file_written'));
        $this->assertFalse((bool) data_get($payload, 'safety.db_mutated'));
        $this->assertFalse((bool) data_get($payload, 'safety.prune_invoked'));
    }
}
