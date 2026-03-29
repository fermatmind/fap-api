<?php

namespace Tests\Feature\V0_3;

use App\Services\Experiments\ExperimentAssigner;
use App\Support\StableBucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExperimentRegistryActiveWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_active_prefers_registry_within_active_window(): void
    {
        $this->assertTrue(Schema::hasColumns('experiments_registry', [
            'active_from',
            'active_to',
            'created_by',
            'updated_by',
        ]));

        $now = now();
        DB::table('experiments_registry')->insert([
            'org_id' => 0,
            'experiment_key' => 'PR23_STICKY_BUCKET',
            'stage' => 'prod',
            'version' => '2026-02-27',
            'variants_json' => json_encode(['B' => 100], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_active' => true,
            'active_from' => $now->copy()->subMinute(),
            'active_to' => $now->copy()->addMinutes(10),
            'created_by' => 9001,
            'updated_by' => 9001,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $anonId = 'fer2_registry_window_anon';
        $assignments = app(ExperimentAssigner::class)->assignActive(0, $anonId, null);

        $this->assertSame('B', $assignments['PR23_STICKY_BUCKET'] ?? null);
        $this->assertDatabaseHas('experiment_assignments', [
            'org_id' => 0,
            'anon_id' => $anonId,
            'experiment_key' => 'PR23_STICKY_BUCKET',
            'variant' => 'B',
        ]);
    }

    public function test_assign_active_falls_back_to_config_when_registry_row_is_outside_window(): void
    {
        $now = now();
        DB::table('experiments_registry')->insert([
            'org_id' => 0,
            'experiment_key' => 'PR23_STICKY_BUCKET',
            'stage' => 'prod',
            'version' => '2026-02-26',
            'variants_json' => json_encode(['B' => 100], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_active' => true,
            'active_from' => $now->copy()->subMinutes(20),
            'active_to' => $now->copy()->subMinute(),
            'created_by' => 9001,
            'updated_by' => 9001,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $anonId = 'fer2_registry_fallback_anon';
        $assignments = app(ExperimentAssigner::class)->assignActive(0, $anonId, null);

        $this->assertArrayHasKey('PR23_STICKY_BUCKET', $assignments);
        $this->assertSame(
            $this->expectedConfigVariant($anonId, 0),
            $assignments['PR23_STICKY_BUCKET']
        );
    }

    private function expectedConfigVariant(string $anonId, int $orgId): string
    {
        $salt = (string) config('fap_experiments.salt', '');
        $subjectKey = 'anon:'.$anonId;
        $bucket = StableBucket::bucket($subjectKey.'|'.$orgId.'|PR23_STICKY_BUCKET|'.$salt, 100);

        return $bucket < 50 ? 'A' : 'B';
    }
}
