<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerFoundationMigrationDefinitionTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function migrationFiles(): array
    {
        return [
            'occupation_families' => base_path('database/migrations/2026_04_07_000100_create_occupation_families_table.php'),
            'occupations' => base_path('database/migrations/2026_04_07_000200_create_occupations_table.php'),
            'occupation_aliases' => base_path('database/migrations/2026_04_07_000300_create_occupation_aliases_table.php'),
            'occupation_crosswalks' => base_path('database/migrations/2026_04_07_000400_create_occupation_crosswalks_table.php'),
            'source_traces' => base_path('database/migrations/2026_04_07_000500_create_source_traces_table.php'),
            'occupation_truth_metrics' => base_path('database/migrations/2026_04_07_000600_create_occupation_truth_metrics_table.php'),
            'occupation_skill_graphs' => base_path('database/migrations/2026_04_07_000700_create_occupation_skill_graphs_table.php'),
            'trust_manifests' => base_path('database/migrations/2026_04_07_000800_create_trust_manifests_table.php'),
            'editorial_patches' => base_path('database/migrations/2026_04_07_000900_create_editorial_patches_table.php'),
            'index_states' => base_path('database/migrations/2026_04_07_001000_create_index_states_table.php'),
            'context_snapshots' => base_path('database/migrations/2026_04_07_001100_create_context_snapshots_table.php'),
            'profile_projections' => base_path('database/migrations/2026_04_07_001200_create_profile_projections_table.php'),
            'projection_lineages' => base_path('database/migrations/2026_04_07_001300_create_projection_lineages_table.php'),
            'recommendation_snapshots' => base_path('database/migrations/2026_04_07_001400_create_recommendation_snapshots_table.php'),
            'transition_paths' => base_path('database/migrations/2026_04_07_001500_create_transition_paths_table.php'),
        ];
    }

    #[Test]
    public function career_foundation_create_migrations_are_present(): void
    {
        foreach ($this->migrationFiles() as $table => $path) {
            $this->assertFileExists($path, "Missing migration for {$table}");
        }
    }

    #[Test]
    public function every_career_foundation_table_uses_a_uuid_primary_key(): void
    {
        foreach ($this->migrationFiles() as $table => $path) {
            $source = (string) file_get_contents($path);

            $this->assertMatchesRegularExpression(
                "/\\\$table->uuid\\('id'\\)->primary\\(\\);/",
                $source,
                "Expected UUID primary key in {$table}"
            );
        }
    }

    #[Test]
    public function immutable_chain_migrations_use_uuid_foreign_keys_and_no_cascade_delete(): void
    {
        $immutableSources = [
            'context_snapshots' => (string) file_get_contents($this->migrationFiles()['context_snapshots']),
            'profile_projections' => (string) file_get_contents($this->migrationFiles()['profile_projections']),
            'projection_lineages' => (string) file_get_contents($this->migrationFiles()['projection_lineages']),
            'recommendation_snapshots' => (string) file_get_contents($this->migrationFiles()['recommendation_snapshots']),
            'transition_paths' => (string) file_get_contents($this->migrationFiles()['transition_paths']),
        ];

        $this->assertStringContainsString("foreignUuid('current_occupation_id')", $immutableSources['context_snapshots']);
        $this->assertStringContainsString("foreignUuid('context_snapshot_id')", $immutableSources['profile_projections']);
        $this->assertStringContainsString("foreignUuid('child_projection_id')", $immutableSources['projection_lineages']);
        $this->assertStringContainsString("foreignUuid('profile_projection_id')", $immutableSources['recommendation_snapshots']);
        $this->assertStringContainsString("foreignUuid('recommendation_snapshot_id')", $immutableSources['transition_paths']);

        foreach ($immutableSources as $table => $source) {
            $this->assertStringNotContainsString('cascadeOnDelete()', $source, "Immutable table {$table} must not cascade delete");
            $this->assertStringNotContainsString('$table->timestamps()', $source, "Immutable table {$table} must not define updated_at");
        }
    }

    #[Test]
    public function projection_lineages_enforces_one_direct_parent_per_child_in_schema_definition(): void
    {
        $source = (string) file_get_contents($this->migrationFiles()['projection_lineages']);

        $this->assertStringContainsString("\$table->unique('child_projection_id');", $source);
    }

    #[Test]
    public function immutable_snapshot_tables_do_not_define_forbidden_subject_unique_constraints(): void
    {
        $sources = [
            'context_snapshots' => (string) file_get_contents($this->migrationFiles()['context_snapshots']),
            'profile_projections' => (string) file_get_contents($this->migrationFiles()['profile_projections']),
            'recommendation_snapshots' => (string) file_get_contents($this->migrationFiles()['recommendation_snapshots']),
        ];

        foreach ($sources as $table => $source) {
            $this->assertDoesNotMatchRegularExpression('/\\$table->unique\\(\\[[^\\]]*(identity_id|visitor_id|context_snapshot_id|profile_projection_id)[^\\]]*\\]/', $source, "Immutable table {$table} must remain append-friendly");
            $this->assertStringNotContainsString("unique(['attempt_id']", $source, "Immutable table {$table} must not copy attempt-style overwrite semantics");
        }
    }
}
