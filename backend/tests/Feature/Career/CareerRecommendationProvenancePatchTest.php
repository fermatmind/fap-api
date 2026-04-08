<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CareerRecommendationProvenancePatchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string>  $deleteRules
     */
    private function assertRestrictiveDeleteRule(array $deleteRules, string $column): void
    {
        $this->assertContains(
            $deleteRules[$column] ?? null,
            ['NO ACTION', 'RESTRICT'],
            "Expected {$column} to keep restrictive delete semantics",
        );
    }

    public function test_new_recommendation_snapshot_provenance_foreign_keys_do_not_cascade_delete(): void
    {
        $foreignKeys = DB::select("PRAGMA foreign_key_list('recommendation_snapshots')");

        $deleteRules = [];
        foreach ($foreignKeys as $foreignKey) {
            $deleteRules[(string) $foreignKey->from] = strtoupper((string) $foreignKey->on_delete);
        }

        $this->assertRestrictiveDeleteRule($deleteRules, 'profile_projection_id');
        $this->assertRestrictiveDeleteRule($deleteRules, 'context_snapshot_id');
        $this->assertRestrictiveDeleteRule($deleteRules, 'occupation_id');
        $this->assertSame('SET NULL', $deleteRules['trust_manifest_id'] ?? null);
        $this->assertSame('SET NULL', $deleteRules['index_state_id'] ?? null);
        $this->assertSame('SET NULL', $deleteRules['truth_metric_id'] ?? null);
    }
}
