<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personality_public_content_asset_revision_reviews')) {
            return;
        }

        Schema::create('personality_public_content_asset_revision_reviews', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('revision_id');
            $table->unsignedBigInteger('asset_id');
            $table->string('authority_asset_key', 191);
            $table->string('source_package', 191);
            $table->char('asset_sha256', 64);
            $table->char('authority_package_sha256', 64);
            $table->char('review_register_sha256', 64);
            $table->string('reviewer_name', 255);
            $table->timestamp('reviewed_at');
            $table->string('decision', 32);
            $table->string('review_source', 64);
            $table->char('evidence_sha256', 64);
            $table->unsignedBigInteger('bound_by_admin_user_id')->nullable();
            $table->timestamps();

            $table->unique('revision_id', 'personality_asset_revision_reviews_revision_unique');
            $table->unique(
                ['authority_package_sha256', 'authority_asset_key'],
                'personality_asset_revision_reviews_package_asset_unique'
            );
            $table->index(['asset_id', 'decision'], 'personality_asset_revision_reviews_asset_decision_idx');
            $table->foreign('revision_id', 'personality_asset_revision_reviews_revision_fk')
                ->references('id')
                ->on('personality_public_content_asset_revisions')
                ->cascadeOnDelete();
            $table->foreign('asset_id', 'personality_asset_revision_reviews_asset_fk')
                ->references('id')
                ->on('personality_public_content_assets')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Forward-only private review evidence. Removal requires a separately reviewed migration.
    }
};
