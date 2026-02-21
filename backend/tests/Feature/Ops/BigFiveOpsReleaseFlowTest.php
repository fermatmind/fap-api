<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class BigFiveOpsReleaseFlowTest extends TestCase
{
    use RefreshDatabase;

    private const DIR_ALIAS = 'BIG5-OCEAN-OPS-FLOW-CI-TEST';

    protected function tearDown(): void
    {
        $target = base_path('../content_packages/default/CN_MAINLAND/zh-CN/' . self::DIR_ALIAS);
        if (File::isDirectory($target)) {
            File::deleteDirectory($target);
        }

        parent::tearDown();
    }

    public function test_ops_release_flow_writes_publish_and_rollback_audits(): void
    {
        $target = base_path('../content_packages/default/CN_MAINLAND/zh-CN/' . self::DIR_ALIAS);
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

        $publishRelease = DB::table('content_pack_releases')
            ->where('id', $targetReleaseId)
            ->first();
        $this->assertNotNull($publishRelease);
        $this->assertSame('success', (string) $publishRelease->status);
        $this->assertNotSame('', (string) ($publishRelease->manifest_hash ?? ''));

        $rollbackRelease = DB::table('content_pack_releases')
            ->where('action', 'rollback')
            ->where('status', 'success')
            ->where('dir_alias', self::DIR_ALIAS)
            ->orderByDesc('created_at')
            ->first();
        $this->assertNotNull($rollbackRelease);
        $this->assertNotSame('', (string) ($rollbackRelease->manifest_hash ?? ''));
        $this->assertNotSame('', (string) ($rollbackRelease->compiled_hash ?? ''));
        $this->assertNotSame('', (string) ($rollbackRelease->content_hash ?? ''));

        $publishAudit = DB::table('audit_logs')
            ->where('action', 'big5_pack_publish')
            ->where('target_id', $targetReleaseId)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($publishAudit);
        $this->assertSame('success', (string) ($publishAudit->result ?? ''));

        $rollbackAudit = DB::table('audit_logs')
            ->where('action', 'big5_pack_rollback')
            ->where('target_id', (string) $rollbackRelease->id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($rollbackAudit);
        $this->assertSame('success', (string) ($rollbackAudit->result ?? ''));

        $rollbackMeta = json_decode((string) ($rollbackAudit->meta_json ?? '{}'), true);
        $this->assertIsArray($rollbackMeta);
        $this->assertSame($targetReleaseId, (string) ($rollbackMeta['source_release_id'] ?? ''));
        $this->assertSame(self::DIR_ALIAS, (string) ($rollbackMeta['dir_alias'] ?? ''));
    }
}

