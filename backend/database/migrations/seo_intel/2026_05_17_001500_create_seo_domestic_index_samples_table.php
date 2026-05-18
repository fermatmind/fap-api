<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        if (Schema::hasTable('seo_domestic_index_samples')) {
            return;
        }

        Schema::create('seo_domestic_index_samples', function (Blueprint $table): void {
            $table->id();
            $table->string('engine', 64);
            $table->char('canonical_url_hash', 64);
            $table->text('canonical_url')->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('sample_type', 64)->default('manual_or_serp_sample');
            $table->string('index_status', 64)->default('unknown');
            $table->char('title_hash', 64)->nullable();
            $table->char('snippet_hash', 64)->nullable();
            $table->timestamp('sampled_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index('engine', 'seo_domestic_index_sample_engine_idx');
            $table->index('canonical_url_hash', 'seo_domestic_index_sample_url_hash_idx');
            $table->index('index_status', 'seo_domestic_index_sample_status_idx');
            $table->index('sampled_at', 'seo_domestic_index_sample_sampled_at_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
