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
        Schema::table('events', function (Blueprint $table): void {
            if (!Schema::hasColumn('events', 'share_id')) {
                $table->uuid('share_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('events') || !Schema::hasColumn('events', 'share_id')) {
            return;
        }

        $tableName = 'events';
        $indexName = 'events_share_id_index';
        $driver = Schema::getConnection()->getDriverName();

        if (SchemaIndex::indexExists($tableName, $indexName)) {
            try {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropIndex('events_share_id_index');
                });
                SchemaIndex::logIndexAction('drop_index', $tableName, $indexName, $driver, ['phase' => 'down']);
            } catch (\Throwable $e) {
                if (SchemaIndex::isMissingIndexException($e, $indexName)) {
                    SchemaIndex::logIndexAction('drop_index_skip_missing', $tableName, $indexName, $driver, ['phase' => 'down']);
                } else {
                    throw $e;
                }
            }
        } else {
            SchemaIndex::logIndexAction('drop_index_skip_absent', $tableName, $indexName, $driver, ['phase' => 'down']);
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropColumn('share_id');
        });
    }
};
