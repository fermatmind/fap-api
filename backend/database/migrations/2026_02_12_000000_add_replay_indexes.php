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
        $this->addReplayIndex('sleep_samples', 'sleep_samples_ingest_id_idx');
        $this->addReplayIndex('screen_time_samples', 'screen_time_samples_ingest_id_idx');
        $this->addReplayIndex('health_samples', 'health_samples_ingest_id_idx');
    }

    public function down(): void
    {
        // forward-only: do not drop replay indexes on rollback.
    }

    private function addReplayIndex(string $table, string $indexName): void
    {
        if (!Schema::hasTable($table)
            || !Schema::hasColumn($table, 'ingest_batch_id')
            || !Schema::hasColumn($table, 'id')
            || SchemaIndex::indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
            $blueprint->index(['ingest_batch_id', 'id'], $indexName);
        });
    }
};
