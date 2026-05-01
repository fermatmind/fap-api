<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ops;

use App\Services\Ops\EnneagramRegistryOpsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class EnneagramRegistryOpsReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_creates_release_manifest_snapshot_and_activation(): void
    {
        $service = app(EnneagramRegistryOpsService::class);

        $preview = $service->publish('ops_release_test');

        $activeReleaseId = (string) DB::table('content_pack_activations')
            ->where('pack_id', 'ENNEAGRAM')
            ->where('pack_version', 'v2')
            ->value('release_id');

        $this->assertNotSame('', $activeReleaseId);
        $this->assertDatabaseHas('content_pack_releases', [
            'id' => $activeReleaseId,
            'to_pack_id' => 'ENNEAGRAM',
            'pack_version' => 'v2',
            'action' => 'enneagram_registry_publish',
        ]);
        $this->assertDatabaseHas('content_release_manifests', [
            'manifest_hash' => (string) $preview['registry_release_hash'],
            'pack_id' => 'ENNEAGRAM',
            'pack_version' => 'v2',
        ]);
        $this->assertDatabaseHas('content_release_snapshots', [
            'pack_id' => 'ENNEAGRAM',
            'pack_version' => 'v2',
            'reason' => 'enneagram_registry_publish',
        ]);
    }

    public function test_rollback_and_activate_update_active_release_binding(): void
    {
        $service = app(EnneagramRegistryOpsService::class);

        $service->publish('ops_release_test_first');
        $firstReleaseId = (string) DB::table('content_pack_activations')
            ->where('pack_id', 'ENNEAGRAM')
            ->where('pack_version', 'v2')
            ->value('release_id');

        DB::table('content_pack_activations')->delete();

        $service->publish('ops_release_test_second');
        $secondReleaseId = (string) DB::table('content_pack_activations')
            ->where('pack_id', 'ENNEAGRAM')
            ->where('pack_version', 'v2')
            ->value('release_id');

        $this->assertNotSame($firstReleaseId, $secondReleaseId);

        $service->rollback($firstReleaseId, 'ops_release_test_rollback');
        $this->assertSame($firstReleaseId, (string) DB::table('content_pack_activations')
            ->where('pack_id', 'ENNEAGRAM')
            ->where('pack_version', 'v2')
            ->value('release_id'));
        $this->assertDatabaseHas('content_pack_releases', [
            'to_pack_id' => 'ENNEAGRAM',
            'pack_version' => 'v2',
            'action' => 'enneagram_registry_rollback',
            'manifest_hash' => (string) DB::table('content_pack_releases')->where('id', $firstReleaseId)->value('manifest_hash'),
        ]);

        $service->activate($secondReleaseId, 'ops_release_test_activate');
        $this->assertSame($secondReleaseId, (string) DB::table('content_pack_activations')
            ->where('pack_id', 'ENNEAGRAM')
            ->where('pack_version', 'v2')
            ->value('release_id'));
        $this->assertDatabaseHas('content_release_snapshots', [
            'pack_id' => 'ENNEAGRAM',
            'pack_version' => 'v2',
            'reason' => 'enneagram_registry_activate',
            'activation_after_release_id' => $secondReleaseId,
        ]);
    }

    public function test_activate_rejects_non_registry_publish_release_rows(): void
    {
        $service = app(EnneagramRegistryOpsService::class);
        $service->publish('ops_release_test_source');

        $source = DB::table('content_pack_releases')
            ->where('to_pack_id', 'ENNEAGRAM')
            ->where('pack_version', 'v2')
            ->where('action', 'enneagram_registry_publish')
            ->first();

        $this->assertNotNull($source);

        $foreignReleaseId = (string) Str::uuid();
        DB::table('content_pack_releases')->insert([
            'id' => $foreignReleaseId,
            'action' => 'packs2_publish',
            'region' => 'GLOBAL',
            'locale' => 'global',
            'dir_alias' => 'v2',
            'from_version_id' => null,
            'to_version_id' => null,
            'from_pack_id' => null,
            'to_pack_id' => 'ENNEAGRAM',
            'status' => 'success',
            'message' => 'non-registry release row',
            'created_by' => 'ops_release_test',
            'manifest_hash' => (string) ($source->manifest_hash ?? ''),
            'compiled_hash' => (string) ($source->compiled_hash ?? ''),
            'content_hash' => (string) ($source->content_hash ?? ''),
            'pack_version' => 'v2',
            'manifest_json' => (string) ($source->manifest_json ?? '{}'),
            'storage_path' => (string) ($source->storage_path ?? ''),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ENNEAGRAM_REGISTRY_RELEASE_NOT_FOUND');

        $service->activate($foreignReleaseId, 'ops_release_test_activate');
    }

    public function test_activate_rejects_registry_release_without_matching_manifest_provenance(): void
    {
        $service = app(EnneagramRegistryOpsService::class);
        $service->publish('ops_release_test_source');

        $source = DB::table('content_pack_releases')
            ->where('to_pack_id', 'ENNEAGRAM')
            ->where('pack_version', 'v2')
            ->where('action', 'enneagram_registry_publish')
            ->first();

        $this->assertNotNull($source);

        $invalidReleaseId = (string) Str::uuid();
        DB::table('content_pack_releases')->insert([
            'id' => $invalidReleaseId,
            'action' => 'enneagram_registry_publish',
            'region' => 'GLOBAL',
            'locale' => 'global',
            'dir_alias' => 'v2',
            'from_version_id' => null,
            'to_version_id' => null,
            'from_pack_id' => null,
            'to_pack_id' => 'ENNEAGRAM',
            'status' => 'success',
            'message' => 'registry release row with mismatched manifest',
            'created_by' => 'ops_release_test',
            'manifest_hash' => (string) ($source->manifest_hash ?? ''),
            'compiled_hash' => (string) ($source->compiled_hash ?? ''),
            'content_hash' => (string) ($source->content_hash ?? ''),
            'pack_version' => 'v2',
            'manifest_json' => json_encode([
                'scale_code' => 'ENNEAGRAM',
                'registry_release_hash' => hash('sha256', 'mismatched'),
            ], JSON_THROW_ON_ERROR),
            'storage_path' => (string) ($source->storage_path ?? ''),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ENNEAGRAM_REGISTRY_RELEASE_INVALID');

        $service->activate($invalidReleaseId, 'ops_release_test_activate');
    }
}
