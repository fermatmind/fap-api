<?php

namespace Tests\Feature\V0_3;

use App\Services\Experiments\ExperimentAssigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExperimentAssignerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_keeps_latest_active_registry_version_for_duplicate_keys(): void
    {
        $now = now();
        DB::table('experiments_registry')->insert([
            [
                'org_id' => 0,
                'experiment_key' => 'PR23_STICKY_BUCKET',
                'stage' => 'prod',
                'version' => '2026-02-26',
                'variants_json' => json_encode(['B' => 100], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_active' => true,
                'active_from' => $now->copy()->subMinutes(10),
                'active_to' => $now->copy()->addMinutes(10),
                'created_by' => 9001,
                'updated_by' => 9001,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'org_id' => 0,
                'experiment_key' => 'PR23_STICKY_BUCKET',
                'stage' => 'prod',
                'version' => '2026-02-27',
                'variants_json' => json_encode(['A' => 100], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_active' => true,
                'active_from' => $now->copy()->subMinute(),
                'active_to' => $now->copy()->addMinutes(10),
                'created_by' => 9001,
                'updated_by' => 9001,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $assignments = app(ExperimentAssigner::class)->assignActive(0, 'fer2_assigner_latest_anon', null);

        $this->assertSame('A', $assignments['PR23_STICKY_BUCKET'] ?? null);
    }

    public function test_it_attaches_user_id_to_existing_anon_assignment(): void
    {
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

        $anonId = 'fer2_assigner_attach_user_anon';
        $initial = app(ExperimentAssigner::class)->assignActive(0, $anonId, null);
        $attached = app(ExperimentAssigner::class)->assignActive(0, $anonId, 12345);

        $this->assertSame('B', $initial['PR23_STICKY_BUCKET'] ?? null);
        $this->assertSame('B', $attached['PR23_STICKY_BUCKET'] ?? null);
        $this->assertDatabaseHas('experiment_assignments', [
            'org_id' => 0,
            'anon_id' => $anonId,
            'experiment_key' => 'PR23_STICKY_BUCKET',
            'variant' => 'B',
            'user_id' => 12345,
        ]);
    }
}
