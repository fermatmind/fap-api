<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Support\Database\SchemaIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class StorageBlobLocationsIndexPortabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_storage_blob_locations_schema_preserves_full_unique_semantics_without_prefix_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('storage_blob_locations'));
        $this->assertTrue(Schema::hasColumn('storage_blob_locations', 'disk'));
        $this->assertTrue(Schema::hasColumn('storage_blob_locations', 'storage_path'));
        $this->assertTrue(SchemaIndex::indexExists('storage_blob_locations', 'sbl_disk_path_uq'));

        $createMigration = (string) file_get_contents(
            base_path('database/migrations/2026_03_20_060000_create_storage_blob_locations_table.php')
        );
        $normalizeMigration = (string) file_get_contents(
            base_path('database/migrations/2026_03_26_190000_normalize_storage_blob_locations_index_portability.php')
        );

        $this->assertStringContainsString("->string('disk', 32)", $createMigration);
        $this->assertStringContainsString("->string('storage_path', 1024)", $createMigration);
        $this->assertStringContainsString("charset('ascii')", $createMigration);
        $this->assertStringContainsString("collation('ascii_bin')", $createMigration);
        $this->assertStringContainsString(
            'ALTER TABLE `%s` MODIFY `disk` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
            $normalizeMigration
        );
        $this->assertStringContainsString(
            'ALTER TABLE `%s` MODIFY `storage_path` VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
            $normalizeMigration
        );
        $this->assertStringNotContainsString('storage_path(191)', $createMigration.$normalizeMigration);
        $this->assertStringNotContainsString('storage_path(255)', $createMigration.$normalizeMigration);

        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->addToAssertionCount(1);

            return;
        }

        $database = (string) DB::getDatabaseName();
        $columns = collect(DB::select(
            'SELECT column_name, character_set_name, collation_name
             FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ? AND column_name IN (?, ?)',
            [$database, 'storage_blob_locations', 'disk', 'storage_path']
        ))->keyBy(fn (object $row): string => (string) ($row->column_name ?? $row->COLUMN_NAME ?? ''));

        $this->assertSame('ascii', $this->normalizeColumnMeta($columns, 'disk', 'character_set_name'));
        $this->assertSame('ascii_bin', $this->normalizeColumnMeta($columns, 'disk', 'collation_name'));
        $this->assertSame('ascii', $this->normalizeColumnMeta($columns, 'storage_path', 'character_set_name'));
        $this->assertSame('ascii_bin', $this->normalizeColumnMeta($columns, 'storage_path', 'collation_name'));

        $indexRows = DB::select(
            'SELECT column_name, sub_part
             FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             ORDER BY seq_in_index',
            [$database, 'storage_blob_locations', 'sbl_disk_path_uq']
        );

        $this->assertCount(2, $indexRows);
        $this->assertSame('disk', (string) ($indexRows[0]->column_name ?? $indexRows[0]->COLUMN_NAME ?? ''));
        $this->assertSame('storage_path', (string) ($indexRows[1]->column_name ?? $indexRows[1]->COLUMN_NAME ?? ''));
        $this->assertNull($indexRows[0]->sub_part ?? $indexRows[0]->SUB_PART ?? null);
        $this->assertNull($indexRows[1]->sub_part ?? $indexRows[1]->SUB_PART ?? null);
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
