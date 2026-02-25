<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ContentPathMirrorCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $suffix;

    private string $backendLegacyRel;

    private string $backendMappedRel;

    private string $contentLegacyRel;

    private string $contentMappedRel;

    private string $backendLegacyAbs;

    private string $backendMappedAbs;

    private string $contentLegacyAbs;

    private string $contentMappedAbs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->suffix = 'test_mirror_' . strtolower(bin2hex(random_bytes(4)));
        $this->backendLegacyRel = 'content_packs/TEST_MIRROR_SRC_' . $this->suffix;
        $this->backendMappedRel = 'content_packs/TEST_MIRROR_DST_' . $this->suffix;
        $this->contentLegacyRel = 'default/CN_MAINLAND/zh-CN/TEST_MIRROR_SRC_' . $this->suffix;
        $this->contentMappedRel = 'default/CN_MAINLAND/zh-CN/TEST_MIRROR_DST_' . $this->suffix;

        $this->backendLegacyAbs = base_path($this->backendLegacyRel);
        $this->backendMappedAbs = base_path($this->backendMappedRel);

        $contentRoot = rtrim((string) config('content_packs.root', base_path('../content_packages')), DIRECTORY_SEPARATOR);
        $this->contentLegacyAbs = $contentRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->contentLegacyRel);
        $this->contentMappedAbs = $contentRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->contentMappedRel);

        File::deleteDirectory($this->backendLegacyAbs);
        File::deleteDirectory($this->backendMappedAbs);
        File::deleteDirectory($this->contentLegacyAbs);
        File::deleteDirectory($this->contentMappedAbs);

        $this->seedSourceTrees();
        $this->seedAliasRows();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backendLegacyAbs);
        File::deleteDirectory($this->backendMappedAbs);
        File::deleteDirectory($this->contentLegacyAbs);
        File::deleteDirectory($this->contentMappedAbs);
        parent::tearDown();
    }

    public function test_sync_and_verify_hash_mirror_backend_and_content_packages_aliases(): void
    {
        $this->artisan('ops:content-path-mirror', [
            '--sync' => true,
            '--verify-hash' => true,
            '--old-path' => [$this->backendLegacyRel, $this->contentLegacyRel],
        ])
            ->expectsOutputToContain('content_path_mirror')
            ->assertExitCode(0);

        $this->assertTrue(is_file($this->backendMappedAbs . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'compiled' . DIRECTORY_SEPARATOR . 'manifest.json'));
        $this->assertTrue(is_file($this->contentMappedAbs . DIRECTORY_SEPARATOR . 'compiled' . DIRECTORY_SEPARATOR . 'questions.compiled.json'));

        $this->assertSame(
            hash_file('sha256', $this->backendLegacyAbs . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'compiled' . DIRECTORY_SEPARATOR . 'questions.compiled.json'),
            hash_file('sha256', $this->backendMappedAbs . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'compiled' . DIRECTORY_SEPARATOR . 'questions.compiled.json')
        );
        $this->assertSame(
            hash_file('sha256', $this->contentLegacyAbs . DIRECTORY_SEPARATOR . 'manifest.json'),
            hash_file('sha256', $this->contentMappedAbs . DIRECTORY_SEPARATOR . 'manifest.json')
        );
    }

    public function test_dry_run_does_not_create_mapped_directories(): void
    {
        File::deleteDirectory($this->backendMappedAbs);
        File::deleteDirectory($this->contentMappedAbs);

        $this->artisan('ops:content-path-mirror', [
            '--sync' => true,
            '--dry-run' => true,
            '--old-path' => [$this->backendLegacyRel, $this->contentLegacyRel],
        ])
            ->expectsOutputToContain('content_path_mirror')
            ->assertExitCode(0);

        $this->assertFalse(is_dir($this->backendMappedAbs));
        $this->assertFalse(is_dir($this->contentMappedAbs));
    }

    private function seedAliasRows(): void
    {
        DB::table('content_path_aliases')->updateOrInsert(
            ['scope' => 'backend_content_packs', 'old_path' => $this->backendLegacyRel],
            [
                'new_path' => $this->backendMappedRel,
                'scale_uid' => null,
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('content_path_aliases')->updateOrInsert(
            ['scope' => 'content_packages', 'old_path' => $this->contentLegacyRel],
            [
                'new_path' => $this->contentMappedRel,
                'scale_uid' => null,
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function seedSourceTrees(): void
    {
        File::ensureDirectoryExists($this->backendLegacyAbs . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'compiled');
        File::put($this->backendLegacyAbs . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'manifest.json', json_encode(['pack' => 'backend', 'kind' => 'manifest'], JSON_UNESCAPED_UNICODE));
        File::put($this->backendLegacyAbs . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'questions.json', json_encode([['id' => 'B1']], JSON_UNESCAPED_UNICODE));
        File::put($this->backendLegacyAbs . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'compiled' . DIRECTORY_SEPARATOR . 'manifest.json', json_encode(['compiled' => true], JSON_UNESCAPED_UNICODE));
        File::put($this->backendLegacyAbs . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'compiled' . DIRECTORY_SEPARATOR . 'questions.compiled.json', json_encode(['question_index' => [['id' => 'B1']]], JSON_UNESCAPED_UNICODE));

        File::ensureDirectoryExists($this->contentLegacyAbs . DIRECTORY_SEPARATOR . 'compiled');
        File::put($this->contentLegacyAbs . DIRECTORY_SEPARATOR . 'manifest.json', json_encode(['pack' => 'content', 'kind' => 'manifest'], JSON_UNESCAPED_UNICODE));
        File::put($this->contentLegacyAbs . DIRECTORY_SEPARATOR . 'questions.json', json_encode([['id' => 'C1']], JSON_UNESCAPED_UNICODE));
        File::put($this->contentLegacyAbs . DIRECTORY_SEPARATOR . 'compiled' . DIRECTORY_SEPARATOR . 'manifest.json', json_encode(['compiled' => true], JSON_UNESCAPED_UNICODE));
        File::put($this->contentLegacyAbs . DIRECTORY_SEPARATOR . 'compiled' . DIRECTORY_SEPARATOR . 'questions.compiled.json', json_encode(['question_index' => [['id' => 'C1']]], JSON_UNESCAPED_UNICODE));
    }
}
