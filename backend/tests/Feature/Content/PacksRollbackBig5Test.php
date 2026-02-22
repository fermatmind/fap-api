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

    public function test_packs_rollback_restores_previous_release_with_to_release_id(): void
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

        $targetReleaseId = '';
        $publishRows = DB::table('content_pack_releases')
            ->where('action', 'publish')
            ->where('status', 'success')
            ->where('dir_alias', self::DIR_ALIAS)
            ->orderByDesc('created_at')
            ->orderByDesc('updated_at')
            ->get();
        foreach ($publishRows as $row) {
            $backupPath = storage_path('app/private/content_releases/backups/' . (string) $row->id . '/previous_pack');
            if (!File::isDirectory($backupPath)) {
                continue;
            }
            $targetReleaseId = (string) $row->id;
            break;
        }
        $this->assertNotSame('', $targetReleaseId);

        $this->artisan(sprintf(
            'packs:rollback --scale=BIG5_OCEAN --region=CN_MAINLAND --locale=zh-CN --dir_alias=%s --to_release_id=%s --probe=0',
            self::DIR_ALIAS,
            $targetReleaseId
        ))->assertExitCode(0);

        $release = DB::table('content_pack_releases')
            ->where('action', 'rollback')
            ->where('dir_alias', self::DIR_ALIAS)
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($release);
        $this->assertSame('success', (string) $release->status);
        $this->assertSame('BIG5_OCEAN', (string) $release->to_pack_id);
        $this->assertNotEmpty((string) ($release->manifest_hash ?? ''));
        $this->assertNotEmpty((string) ($release->compiled_hash ?? ''));
        $this->assertNotEmpty((string) ($release->content_hash ?? ''));
        $this->assertNotEmpty((string) ($release->norms_version ?? ''));

        $this->assertTrue(File::isDirectory($target.'/compiled'));
        $this->assertTrue(File::exists($target.'/compiled/manifest.json'));

        $audit = DB::table('audit_logs')
            ->where('action', 'big5_pack_rollback')
            ->where('target_id', (string) $release->id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('success', (string) ($audit->result ?? ''));
        $this->assertSame('content_pack_release', (string) ($audit->target_type ?? ''));
        $auditMeta = json_decode((string) ($audit->meta_json ?? '{}'), true);
        $this->assertIsArray($auditMeta);
        $this->assertSame(self::DIR_ALIAS, (string) ($auditMeta['dir_alias'] ?? ''));
        $this->assertSame($targetReleaseId, (string) ($auditMeta['source_release_id'] ?? ''));
    }

    public function test_packs_rollback_fails_with_unknown_to_release_id(): void
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

        $unknownReleaseId = (string) \Illuminate\Support\Str::uuid();
        $rollbackCommand = sprintf(
            'packs:rollback --scale=BIG5_OCEAN --region=CN_MAINLAND --locale=zh-CN --dir_alias=%s --to_release_id=%s --probe=0',
            self::DIR_ALIAS,
            $unknownReleaseId
        );

        $this->artisan($rollbackCommand)
            ->expectsOutput('status=failed')
            ->expectsOutput('message=TARGET_RELEASE_NOT_FOUND')
            ->assertExitCode(1);

        $release = DB::table('content_pack_releases')
            ->where('action', 'rollback')
            ->where('dir_alias', self::DIR_ALIAS)
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($release);
        $this->assertSame('failed', (string) $release->status);
        $this->assertSame('TARGET_RELEASE_NOT_FOUND', (string) ($release->message ?? ''));

        $audit = DB::table('audit_logs')
            ->where('action', 'big5_pack_rollback')
            ->where('target_id', (string) $release->id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('failed', (string) ($audit->result ?? ''));
        $this->assertSame('TARGET_RELEASE_NOT_FOUND', (string) ($audit->reason ?? ''));
    }
}
