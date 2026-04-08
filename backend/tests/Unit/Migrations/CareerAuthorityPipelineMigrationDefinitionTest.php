<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerAuthorityPipelineMigrationDefinitionTest extends TestCase
{
    #[Test]
    public function career_authority_pipeline_migrations_are_present_and_run_ledgers_use_uuid_primary_keys(): void
    {
        $paths = [
            base_path('database/migrations/2026_04_08_000200_create_career_import_runs_table.php'),
            base_path('database/migrations/2026_04_08_000300_create_career_compile_runs_table.php'),
            base_path('database/migrations/2026_04_08_000400_add_import_run_refs_to_career_authority_tables.php'),
            base_path('database/migrations/2026_04_08_000500_add_compile_run_refs_to_career_compiled_tables.php'),
        ];

        foreach ($paths as $path) {
            $this->assertFileExists($path);
        }

        foreach (array_slice($paths, 0, 2) as $path) {
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString("\$table->uuid('id')->primary();", $source);
        }
    }

    #[Test]
    public function new_run_ref_patches_stay_non_cascading_and_define_replay_safety_constraints(): void
    {
        $importPatch = (string) file_get_contents(base_path('database/migrations/2026_04_08_000400_add_import_run_refs_to_career_authority_tables.php'));
        $compilePatch = (string) file_get_contents(base_path('database/migrations/2026_04_08_000500_add_compile_run_refs_to_career_compiled_tables.php'));

        $this->assertStringNotContainsString('cascadeOnDelete()', $importPatch);
        $this->assertStringNotContainsString('cascadeOnDelete()', $compilePatch);
        $this->assertStringContainsString("unique(['import_run_id', \$config['fingerprint']]", $importPatch);
        $this->assertStringContainsString("'recommendation_snapshots_compile_projection_occ_unique'", $compilePatch);
    }
}
