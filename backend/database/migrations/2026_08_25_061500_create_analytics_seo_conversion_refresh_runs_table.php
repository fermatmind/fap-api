<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('analytics_seo_conversion_refresh_runs')) {
            $schema = Schema::getFacadeRoot();
            Schema::table('analytics_seo_conversion_refresh_runs', function (Blueprint $table) use ($schema): void {
                $columns = [
                    'trigger_mode' => static fn () => $table->string('trigger_mode', 32)->default('manual'),
                    'status' => static fn () => $table->string('status', 32)->default('blocked'),
                    'from_date' => static fn () => $table->date('from_date')->nullable(),
                    'to_date' => static fn () => $table->date('to_date')->nullable(),
                    'org_scope_count' => static fn () => $table->unsignedInteger('org_scope_count')->default(0),
                    'attempted_rows' => static fn () => $table->unsignedInteger('attempted_rows')->default(0),
                    'skipped_rows' => static fn () => $table->unsignedInteger('skipped_rows')->default(0),
                    'deleted_rows' => static fn () => $table->unsignedInteger('deleted_rows')->default(0),
                    'upserted_rows' => static fn () => $table->unsignedInteger('upserted_rows')->default(0),
                    'receipt_json' => static fn () => $table->json('receipt_json')->nullable(),
                    'started_at' => static fn () => $table->dateTime('started_at')->nullable(),
                    'completed_at' => static fn () => $table->dateTime('completed_at')->nullable(),
                    'created_at' => static fn () => $table->timestamp('created_at')->nullable(),
                    'updated_at' => static fn () => $table->timestamp('updated_at')->nullable(),
                ];
                foreach ($columns as $column => $add) {
                    if (! $schema->hasColumn('analytics_seo_conversion_refresh_runs', $column)) {
                        $add();
                    }
                }
            });

            return;
        }

        Schema::create('analytics_seo_conversion_refresh_runs', function (Blueprint $table): void {
            $table->uuid('run_uid')->primary();
            $table->string('trigger_mode', 32)->index();
            $table->string('status', 32)->index();
            $table->date('from_date');
            $table->date('to_date');
            $table->unsignedInteger('org_scope_count')->default(0);
            $table->unsignedInteger('attempted_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->unsignedInteger('deleted_rows')->default(0);
            $table->unsignedInteger('upserted_rows')->default(0);
            $table->json('receipt_json');
            $table->dateTime('started_at');
            $table->dateTime('completed_at');
            $table->timestamps();

            $table->index(
                ['trigger_mode', 'status', 'completed_at'],
                'analytics_seo_conversion_refresh_runs_lookup_idx',
            );
        });
    }

    public function down(): void
    {
        // Expand-only: refresh evidence is retained for the rolling SLO.
    }
};
