<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, array{fingerprint: string, after: string, unique: string}>
     */
    private array $tables = [
        'occupation_aliases' => [
            'fingerprint' => 'row_fingerprint',
            'after' => 'function_hint',
            'unique' => 'occupation_aliases_run_fingerprint_unique',
        ],
        'occupation_crosswalks' => [
            'fingerprint' => 'row_fingerprint',
            'after' => 'notes',
            'unique' => 'occupation_crosswalks_run_fingerprint_unique',
        ],
        'source_traces' => [
            'fingerprint' => 'row_fingerprint',
            'after' => 'evidence_strength',
            'unique' => 'source_traces_run_fingerprint_unique',
        ],
        'occupation_truth_metrics' => [
            'fingerprint' => 'row_fingerprint',
            'after' => 'reviewed_at',
            'unique' => 'occupation_truth_metrics_run_fingerprint_unique',
        ],
        'occupation_skill_graphs' => [
            'fingerprint' => 'row_fingerprint',
            'after' => 'tool_overlap_graph',
            'unique' => 'occupation_skill_graphs_run_fingerprint_unique',
        ],
        'trust_manifests' => [
            'fingerprint' => 'row_fingerprint',
            'after' => 'next_review_due_at',
            'unique' => 'trust_manifests_run_fingerprint_unique',
        ],
        'editorial_patches' => [
            'fingerprint' => 'row_fingerprint',
            'after' => 'notes',
            'unique' => 'editorial_patches_run_fingerprint_unique',
        ],
        'index_states' => [
            'fingerprint' => 'row_fingerprint',
            'after' => 'changed_at',
            'unique' => 'index_states_run_fingerprint_unique',
        ],
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName => $config) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $config): void {
                if (! Schema::hasColumn($tableName, 'import_run_id')) {
                    $table->uuid('import_run_id')->nullable()->after($config['after']);
                }
                if (! Schema::hasColumn($tableName, $config['fingerprint'])) {
                    $table->string($config['fingerprint'], 64)->nullable()->after('import_run_id');
                }
            });

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $config): void {
                $table->index(['import_run_id'], "{$tableName}_import_run_idx");
                $table->unique(['import_run_id', $config['fingerprint']], $config['unique']);
                $table->foreign('import_run_id', "{$tableName}_import_run_fk")
                    ->references('id')
                    ->on('career_import_runs')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
