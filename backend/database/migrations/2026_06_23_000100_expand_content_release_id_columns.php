<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RELEASE_ID_LENGTH = 128;

    /**
     * @var array<string,string>
     */
    private const FOREIGN_KEYS = [
        'content_release_manifests.content_pack_release_id' => 'crm_rel_fk',
        'content_release_exact_manifests.content_pack_release_id' => 'crem_rel_fk',
        'content_release_snapshots.from_content_pack_release_id' => 'crs_from_rel_fk',
        'content_release_snapshots.to_content_pack_release_id' => 'crs_to_rel_fk',
        'content_release_snapshots.activation_before_release_id' => 'crs_ab_rel_fk',
        'content_release_snapshots.activation_after_release_id' => 'crs_aa_rel_fk',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->dropReleaseForeignKeys();

        $this->changeColumn('content_pack_releases', 'id', nullable: false);
        $this->changeColumn('content_release_manifests', 'content_pack_release_id', nullable: true);
        $this->changeColumn('content_release_exact_manifests', 'content_pack_release_id', nullable: true);
        $this->changeColumn('content_pack_activations', 'release_id', nullable: true);

        foreach ([
            'from_content_pack_release_id',
            'to_content_pack_release_id',
            'activation_before_release_id',
            'activation_after_release_id',
        ] as $column) {
            $this->changeColumn('content_release_snapshots', $column, nullable: true);
        }

        $this->restoreReleaseForeignKeys();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent truncating long release ids.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function changeColumn(string $table, string $column, bool $nullable): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` MODIFY COLUMN `%s` VARCHAR(%d) %s',
            str_replace('`', '``', $table),
            str_replace('`', '``', $column),
            self::RELEASE_ID_LENGTH,
            $nullable ? 'NULL' : 'NOT NULL',
        ));
    }

    private function dropReleaseForeignKeys(): void
    {
        foreach (self::FOREIGN_KEYS as $tableColumn => $foreignKey) {
            [$table, $column] = explode('.', $tableColumn, 2);
            if (! $this->foreignKeyExists($table, $foreignKey)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($foreignKey): void {
                $blueprint->dropForeign($foreignKey);
            });
        }
    }

    private function restoreReleaseForeignKeys(): void
    {
        foreach (self::FOREIGN_KEYS as $tableColumn => $foreignKey) {
            [$table, $column] = explode('.', $tableColumn, 2);
            if (! Schema::hasTable($table)
                || ! Schema::hasColumn($table, $column)
                || $this->foreignKeyExists($table, $foreignKey)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column, $foreignKey): void {
                $blueprint->foreign($column, $foreignKey)
                    ->references('id')
                    ->on('content_pack_releases')
                    ->nullOnDelete();
            });
        }
    }

    private function foreignKeyExists(string $table, string $foreignKey): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return false;
        }

        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $foreignKey)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
