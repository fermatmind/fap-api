<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureIndex('events', 'events_scale_event_name_idx', ['scale_code', 'event_name']);
        $this->ensureIndex('recommendation_snapshots', 'recommendation_snapshots_occ_compiled_created_idx', [
            'occupation_id',
            'compiled_at',
            'created_at',
        ]);
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to preserve production authority performance.
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureIndex(string $tableName, string $indexName, array $columns): void
    {
        if (! Schema::hasTable($tableName) || SchemaIndex::indexExists($tableName, $indexName)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($tableName, $column)) {
                return;
            }
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }
};
