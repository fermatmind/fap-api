<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('content_release_snapshots')) {
            return;
        }

        Schema::create('content_release_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('pack_id', 64);
            $table->string('pack_version', 64)->nullable();
            $table->uuid('from_content_pack_release_id')->nullable();
            $table->uuid('to_content_pack_release_id')->nullable();
            $table->uuid('activation_before_release_id')->nullable();
            $table->uuid('activation_after_release_id')->nullable();
            $table->string('reason', 64)->nullable();
            $table->string('created_by', 128)->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->index(['pack_id', 'created_at'], 'crs_pack_time_idx');
            $table->index(['from_content_pack_release_id'], 'crs_from_rel_idx');
            $table->index(['to_content_pack_release_id'], 'crs_to_rel_idx');
            $table->index(['activation_before_release_id'], 'crs_ab_rel_idx');
            $table->index(['activation_after_release_id'], 'crs_aa_rel_idx');

            $table->foreign('from_content_pack_release_id', 'crs_from_rel_fk')
                ->references('id')
                ->on('content_pack_releases')
                ->nullOnDelete();
            $table->foreign('to_content_pack_release_id', 'crs_to_rel_fk')
                ->references('id')
                ->on('content_pack_releases')
                ->nullOnDelete();
            $table->foreign('activation_before_release_id', 'crs_ab_rel_fk')
                ->references('id')
                ->on('content_pack_releases')
                ->nullOnDelete();
            $table->foreign('activation_after_release_id', 'crs_aa_rel_fk')
                ->references('id')
                ->on('content_pack_releases')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
