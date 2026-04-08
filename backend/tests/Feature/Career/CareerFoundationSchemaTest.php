<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\ContextSnapshot;
use App\Models\EditorialPatch;
use App\Models\ProfileProjection;
use App\Models\ProjectionLineage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerFoundationSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function career_foundation_tables_exist_and_immutable_tables_have_no_updated_at_column(): void
    {
        $tables = [
            'occupation_families',
            'occupations',
            'occupation_aliases',
            'occupation_crosswalks',
            'occupation_truth_metrics',
            'occupation_skill_graphs',
            'source_traces',
            'trust_manifests',
            'editorial_patches',
            'index_states',
            'context_snapshots',
            'profile_projections',
            'projection_lineages',
            'recommendation_snapshots',
            'transition_paths',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected table {$table} to exist");
        }

        foreach (['context_snapshots', 'profile_projections', 'projection_lineages', 'recommendation_snapshots', 'transition_paths', 'trust_manifests'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'updated_at'), "Immutable table {$table} must not have updated_at");
        }
    }

    #[Test]
    public function immutable_chain_foreign_keys_do_not_use_cascade_delete(): void
    {
        foreach (['context_snapshots', 'profile_projections', 'projection_lineages', 'recommendation_snapshots', 'transition_paths'] as $table) {
            $foreignKeys = DB::select("PRAGMA foreign_key_list('{$table}')");

            foreach ($foreignKeys as $foreignKey) {
                $this->assertNotSame('CASCADE', strtoupper((string) $foreignKey->on_delete), "Immutable table {$table} must not cascade delete");
            }
        }
    }

    #[Test]
    public function append_friendly_snapshots_allow_multiple_rows_for_the_same_identity(): void
    {
        $chain = CareerFoundationFixture::seedMinimalChain();

        $secondContext = ContextSnapshot::query()->create([
            'identity_id' => 'identity-1',
            'visitor_id' => 'visitor-1',
            'captured_at' => now(),
            'current_occupation_id' => $chain['occupation']->id,
            'employment_status' => 'employed',
            'context_payload' => ['trigger' => 'manual_refresh'],
        ]);

        $secondProjection = ProfileProjection::query()->create([
            'identity_id' => 'identity-1',
            'visitor_id' => 'visitor-1',
            'context_snapshot_id' => $chain['contextSnapshot']->id,
            'projection_version' => 'career_projection_v1',
            'projection_payload' => ['fit_axes' => ['autonomy' => 0.77]],
        ]);

        $this->assertSame(2, ContextSnapshot::query()->where('identity_id', 'identity-1')->count());
        $this->assertNotNull($secondContext->created_at);
        $this->assertSame(3, ProfileProjection::query()->where('identity_id', 'identity-1')->count());
        $this->assertNotNull($secondProjection->created_at);
    }

    #[Test]
    public function projection_lineage_allows_one_direct_parent_per_child(): void
    {
        $chain = CareerFoundationFixture::seedMinimalChain();

        $this->expectException(QueryException::class);

        ProjectionLineage::query()->create([
            'parent_projection_id' => $chain['parentProjection']->id,
            'child_projection_id' => $chain['childProjection']->id,
            'lineage_reason' => 'manual_recompute',
            'diff_summary' => ['note' => 'duplicate direct parent should fail'],
        ]);
    }

    #[Test]
    public function immutable_history_rows_survive_editorial_changes(): void
    {
        $chain = CareerFoundationFixture::seedMinimalChain();

        $chain['occupation']->update([
            'canonical_title_en' => 'Backend Systems Architect',
        ]);

        $patch = EditorialPatch::query()->findOrFail($chain['editorialPatch']->id);
        $patch->update([
            'required' => true,
            'status' => 'queued',
        ]);

        $this->assertDatabaseHas('context_snapshots', ['id' => $chain['contextSnapshot']->id]);
        $this->assertDatabaseHas('profile_projections', ['id' => $chain['parentProjection']->id]);
        $this->assertDatabaseHas('profile_projections', ['id' => $chain['childProjection']->id]);
        $this->assertDatabaseHas('projection_lineages', ['id' => $chain['lineage']->id]);
        $this->assertDatabaseHas('recommendation_snapshots', ['id' => $chain['recommendationSnapshot']->id]);
        $this->assertDatabaseHas('transition_paths', ['id' => $chain['transitionPath']->id]);
    }
}
