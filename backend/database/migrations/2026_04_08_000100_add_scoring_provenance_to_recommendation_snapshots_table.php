<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'recommendation_snapshots';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'compiler_version')) {
                $table->string('compiler_version', 64)->nullable()->after('snapshot_payload');
            }
            if (! Schema::hasColumn(self::TABLE, 'trust_manifest_id')) {
                $table->uuid('trust_manifest_id')->nullable()->after('compiler_version');
            }
            if (! Schema::hasColumn(self::TABLE, 'index_state_id')) {
                $table->uuid('index_state_id')->nullable()->after('trust_manifest_id');
            }
            if (! Schema::hasColumn(self::TABLE, 'truth_metric_id')) {
                $table->uuid('truth_metric_id')->nullable()->after('index_state_id');
            }
            if (! Schema::hasColumn(self::TABLE, 'compiled_at')) {
                $table->timestamp('compiled_at')->nullable()->after('truth_metric_id');
            }
        });

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index(['compiler_version'], 'recommendation_snapshots_compiler_version_idx');
            $table->index(['trust_manifest_id'], 'recommendation_snapshots_trust_manifest_idx');
            $table->index(['index_state_id'], 'recommendation_snapshots_index_state_idx');
            $table->index(['truth_metric_id'], 'recommendation_snapshots_truth_metric_idx');
            $table->index(['compiled_at'], 'recommendation_snapshots_compiled_at_idx');

            $table->foreign('trust_manifest_id', 'recommendation_snapshots_trust_manifest_fk')
                ->references('id')
                ->on('trust_manifests')
                ->nullOnDelete();
            $table->foreign('index_state_id', 'recommendation_snapshots_index_state_fk')
                ->references('id')
                ->on('index_states')
                ->nullOnDelete();
            $table->foreign('truth_metric_id', 'recommendation_snapshots_truth_metric_fk')
                ->references('id')
                ->on('occupation_truth_metrics')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
