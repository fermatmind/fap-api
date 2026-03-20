<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PacksPublishBig5Test extends TestCase
{
    use RefreshDatabase;

    private const DIR_ALIAS = 'BIG5-OCEAN-CI-TEST';

    protected function tearDown(): void
    {
        $target = base_path('../content_packages/default/CN_MAINLAND/zh-CN/'.self::DIR_ALIAS);
        if (File::isDirectory($target)) {
            File::deleteDirectory($target);
        }

        parent::tearDown();
    }

    public function test_packs_publish_creates_release_and_target_pack_dir(): void
    {
        $target = base_path('../content_packages/default/CN_MAINLAND/zh-CN/'.self::DIR_ALIAS);
        if (File::isDirectory($target)) {
            File::deleteDirectory($target);
        }

        $this->artisan(sprintf(
            'packs:publish --scale=BIG5_OCEAN --pack=BIG5_OCEAN --pack-version=v1 --region=CN_MAINLAND --locale=zh-CN --dir_alias=%s --probe=0',
            self::DIR_ALIAS
        ))
            ->expectsOutputToContain('release_id=')
            ->expectsOutput('status=success')
            ->expectsOutput('to_pack_id=BIG5_OCEAN')
            ->assertExitCode(0);

        $release = DB::table('content_pack_releases')
            ->where('action', 'publish')
            ->where('dir_alias', self::DIR_ALIAS)
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($release);
        $this->assertSame('success', (string) $release->status);
        $this->assertSame('BIG5_OCEAN', (string) $release->to_pack_id);
        $this->assertNotEmpty((string) $release->to_version_id);
        $this->assertNotEmpty((string) ($release->manifest_hash ?? ''));
        $this->assertNotEmpty((string) ($release->compiled_hash ?? ''));
        $this->assertNotEmpty((string) ($release->content_hash ?? ''));
        $this->assertNotEmpty((string) ($release->norms_version ?? ''));
        $expectedSourcePath = storage_path('app/private/content_releases/'.(string) $release->to_version_id.'/source_pack');
        $this->assertSame('v1', (string) ($release->pack_version ?? ''));
        $this->assertSame($expectedSourcePath, (string) ($release->storage_path ?? ''));
        $this->assertSame((string) ($release->git_sha ?? ''), (string) ($release->source_commit ?? ''));
        $this->assertDatabaseMissing('content_pack_activations', [
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
        ]);

        $releaseManifest = json_decode((string) ($release->manifest_json ?? '{}'), true);
        $this->assertIsArray($releaseManifest);
        $this->assertSame('BIG5_OCEAN', (string) ($releaseManifest['pack_id'] ?? ''));
        $this->assertSame('v1', (string) ($releaseManifest['content_package_version'] ?? ''));

        $version = DB::table('content_pack_versions')->where('id', (string) $release->to_version_id)->first();
        $this->assertNotNull($version);
        $this->assertSame('BIG5_OCEAN', (string) $version->pack_id);
        $this->assertSame('v1', (string) $version->content_package_version);
        $this->assertSame('local_repo_legacy', (string) $version->source_type);

        $audit = DB::table('audit_logs')
            ->where('action', 'big5_pack_publish')
            ->where('target_id', (string) $release->id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('success', (string) ($audit->result ?? ''));
        $this->assertSame('content_pack_release', (string) ($audit->target_type ?? ''));
        $auditMeta = json_decode((string) ($audit->meta_json ?? '{}'), true);
        $this->assertIsArray($auditMeta);
        $this->assertSame(self::DIR_ALIAS, (string) ($auditMeta['dir_alias'] ?? ''));
        $this->assertSame('BIG5_OCEAN', (string) ($auditMeta['scale_code'] ?? ''));
        $this->assertSame('legacy', (string) ($auditMeta['content_publish_mode'] ?? ''));
        $this->assertSame('local_repo_legacy', (string) ($auditMeta['staged_source_type'] ?? ''));
        $this->assertNotSame('', trim((string) ($auditMeta['staged_source_ref'] ?? '')));

        $this->assertTrue(File::isDirectory($target.'/compiled'));
        $this->assertTrue(File::exists($target.'/compiled/manifest.json'));
    }
}
