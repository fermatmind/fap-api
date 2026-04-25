<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ops;

use App\Services\Ops\EnneagramRegistryOpsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
