<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Support\Database\SchemaIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ContentReleaseExactManifestFilesIndexPortabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_manifest_files_schema_preserves_full_unique_semantics_without_prefix_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('content_release_exact_manifest_files'));
        $this->assertTrue(Schema::hasColumn('content_release_exact_manifest_files', 'content_release_exact_manifest_id'));
        $this->assertTrue(Schema::hasColumn('content_release_exact_manifest_files', 'logical_path'));
        $this->assertTrue(SchemaIndex::indexExists('content_release_exact_manifest_files', 'cremf_manifest_path_uq'));

        $createMigration = (string) file_get_contents(
            base_path('database/migrations/2026_03_20_070100_create_content_release_exact_manifest_files_table.php')
        );
        $normalizeMigration = (string) file_get_contents(
            base_path('database/migrations/2026_03_26_200000_normalize_content_release_exact_manifest_files_index_portability.php')
        );

        $this->assertStringContainsString("->string('logical_path', 1024)", $createMigration);
        $this->assertStringContainsString("charset('ascii')", $createMigration);
        $this->assertStringContainsString("collation('ascii_bin')", $createMigration);
        $this->assertStringContainsString(
            'ALTER TABLE `%s` MODIFY `logical_path` VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
            $normalizeMigration
        );
        $this->assertStringContainsString(
            "\$table->index(['content_release_exact_manifest_id'], self::TEMP_FOREIGN_KEY_SUPPORT_INDEX);",
            $normalizeMigration
        );
        $this->assertStringContainsString(
            '$table->dropIndex(self::TEMP_FOREIGN_KEY_SUPPORT_INDEX);',
            $normalizeMigration
        );
        $this->assertStringNotContainsString('logical_path(191)', $createMigration.$normalizeMigration);
        $this->assertStringNotContainsString('logical_path(255)', $createMigration.$normalizeMigration);

        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->addToAssertionCount(1);

            return;
        }

        $database = (string) DB::getDatabaseName();
        $columns = collect(DB::select(
            'SELECT column_name, character_set_name, collation_name
             FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ? AND column_name IN (?)',
            [$database, 'content_release_exact_manifest_files', 'logical_path']
        ))->keyBy(fn (object $row): string => (string) ($row->column_name ?? $row->COLUMN_NAME ?? ''));

        $this->assertSame('ascii', $this->normalizeColumnMeta($columns, 'logical_path', 'character_set_name'));
        $this->assertSame('ascii_bin', $this->normalizeColumnMeta($columns, 'logical_path', 'collation_name'));

        $indexRows = DB::select(
            'SELECT column_name, sub_part
             FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             ORDER BY seq_in_index',
            [$database, 'content_release_exact_manifest_files', 'cremf_manifest_path_uq']
        );

        $this->assertCount(2, $indexRows);
        $this->assertSame(
            'content_release_exact_manifest_id',
            (string) ($indexRows[0]->column_name ?? $indexRows[0]->COLUMN_NAME ?? '')
        );
        $this->assertSame('logical_path', (string) ($indexRows[1]->column_name ?? $indexRows[1]->COLUMN_NAME ?? ''));
        $this->assertNull($indexRows[0]->sub_part ?? $indexRows[0]->SUB_PART ?? null);
        $this->assertNull($indexRows[1]->sub_part ?? $indexRows[1]->SUB_PART ?? null);

        $this->assertFalse(
            SchemaIndex::indexExists('content_release_exact_manifest_files', 'cremf_manifest_fk_idx'),
            'temporary foreign-key support index should be removed after normalize migration'
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<string, object>  $columns
     */
    private function normalizeColumnMeta($columns, string $column, string $field): ?string
    {
        $row = $columns->get($column);
        if (! is_object($row)) {
            return null;
        }

        return (string) ($row->{$field} ?? $row->{strtoupper($field)} ?? null);
    }
}
