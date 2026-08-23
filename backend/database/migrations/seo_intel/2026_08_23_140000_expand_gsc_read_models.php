<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        // Read-model expansion only: no sitemap, canonical, or indexing authority is changed.
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_gsc_daily')) {
            $schema->table('seo_gsc_daily', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('seo_gsc_daily', 'url_truth_id')) {
                    $table->unsignedBigInteger('url_truth_id')->nullable()->after('canonical_url');
                    $table->index('url_truth_id', 'seo_gsc_daily_url_truth_id_idx');
                }
                if (! $schema->hasColumn('seo_gsc_daily', 'mapping_state')) {
                    $table->string('mapping_state', 32)->default('unmapped')->after('url_truth_id');
                    $table->index('mapping_state', 'seo_gsc_daily_mapping_state_idx');
                }
                if (! $schema->hasColumn('seo_gsc_daily', 'sync_run_uid')) {
                    $table->uuid('sync_run_uid')->nullable()->after('mapping_state');
                    $table->index('sync_run_uid', 'seo_gsc_daily_sync_run_uid_idx');
                }
            });
        }

        if (! $schema->hasTable('seo_gsc_sync_runs')) {
            $schema->create('seo_gsc_sync_runs', function (Blueprint $table): void {
                $table->uuid('sync_run_uid')->primary();
                $table->unsignedSmallInteger('window_days');
                $table->date('start_date');
                $table->date('end_date');
                $table->json('search_types_json');
                $table->string('status', 32);
                $table->unsignedInteger('pages_fetched')->default(0);
                $table->unsignedInteger('rows_seen')->default(0);
                $table->unsignedInteger('rows_upserted')->default(0);
                $table->unsignedInteger('unmapped_rows')->default(0);
                $table->string('failure_code', 128)->nullable();
                $table->json('quality_gate_json')->nullable();
                $table->timestamp('started_at');
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'finished_at'], 'seo_gsc_sync_runs_status_finished_idx');
            });
        }

        if (! $schema->hasTable('seo_gsc_data_quality_queue')) {
            $schema->create('seo_gsc_data_quality_queue', function (Blueprint $table): void {
                $table->id();
                $table->uuid('sync_run_uid');
                $table->date('report_date');
                $table->char('canonical_url_hash', 64);
                $table->string('issue_code', 128);
                $table->string('status', 32)->default('open');
                $table->json('details_json')->nullable();
                $table->timestamps();

                $table->unique(
                    ['report_date', 'canonical_url_hash', 'issue_code'],
                    'seo_gsc_quality_queue_date_url_issue_unique'
                );
                $table->index(['status', 'created_at'], 'seo_gsc_quality_queue_status_created_idx');
            });
        }
    }

    public function down(): void
    {
        // Expand-only: old application releases ignore the new tables and nullable columns.
        // rollback compatibility requires preserving imported GSC rows and quality evidence.
    }
};
