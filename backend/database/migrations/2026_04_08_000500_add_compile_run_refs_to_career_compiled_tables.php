<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->patchContextSnapshots();
        $this->patchProfileProjections();
        $this->patchRecommendationSnapshots();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function patchContextSnapshots(): void
    {
        if (! Schema::hasTable('context_snapshots')) {
            return;
        }

        Schema::table('context_snapshots', function (Blueprint $table): void {
            if (! Schema::hasColumn('context_snapshots', 'compile_run_id')) {
                $table->uuid('compile_run_id')->nullable()->after('visitor_id');
            }
        });

        Schema::table('context_snapshots', function (Blueprint $table): void {
            $table->index(['compile_run_id', 'captured_at'], 'context_snapshots_compile_run_idx');
            $table->foreign('compile_run_id', 'context_snapshots_compile_run_fk')
                ->references('id')
                ->on('career_compile_runs')
                ->nullOnDelete();
        });
    }

    private function patchProfileProjections(): void
    {
        if (! Schema::hasTable('profile_projections')) {
            return;
        }

        Schema::table('profile_projections', function (Blueprint $table): void {
            if (! Schema::hasColumn('profile_projections', 'compile_run_id')) {
                $table->uuid('compile_run_id')->nullable()->after('visitor_id');
            }
        });

        Schema::table('profile_projections', function (Blueprint $table): void {
            $table->index(['compile_run_id', 'created_at'], 'profile_projections_compile_run_idx');
            $table->foreign('compile_run_id', 'profile_projections_compile_run_fk')
                ->references('id')
                ->on('career_compile_runs')
                ->nullOnDelete();
        });
    }

    private function patchRecommendationSnapshots(): void
    {
        if (! Schema::hasTable('recommendation_snapshots')) {
            return;
        }

        Schema::table('recommendation_snapshots', function (Blueprint $table): void {
            if (! Schema::hasColumn('recommendation_snapshots', 'compile_run_id')) {
                $table->uuid('compile_run_id')->nullable()->after('occupation_id');
            }
        });

        Schema::table('recommendation_snapshots', function (Blueprint $table): void {
            $table->index(['compile_run_id', 'compiled_at'], 'recommendation_snapshots_compile_run_idx');
            $table->unique(
                ['compile_run_id', 'profile_projection_id', 'occupation_id'],
                'recommendation_snapshots_compile_projection_occ_unique'
            );
            $table->foreign('compile_run_id', 'recommendation_snapshots_compile_run_fk')
                ->references('id')
                ->on('career_compile_runs')
                ->nullOnDelete();
        });
    }
};
