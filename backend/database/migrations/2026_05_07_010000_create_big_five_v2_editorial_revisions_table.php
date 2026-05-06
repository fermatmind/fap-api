<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'big_five_v2_editorial_revisions';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('asset_key', 191);
            $table->string('asset_type', 64);
            $table->string('asset_path', 512);
            $table->char('asset_sha256', 64);
            $table->unsignedInteger('version_no');
            $table->uuid('supersedes_revision_id')->nullable();
            $table->string('workflow_state', 32)->default('draft');
            $table->string('release_snapshot_id', 128)->nullable();
            $table->char('release_snapshot_hash', 64)->nullable();
            $table->char('draft_payload_hash', 64)->nullable();
            $table->unsignedBigInteger('created_by_admin_user_id')->nullable();
            $table->unsignedBigInteger('submitted_by_admin_user_id')->nullable();
            $table->unsignedBigInteger('reviewed_by_admin_user_id')->nullable();
            $table->unsignedBigInteger('archived_by_admin_user_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('decision_note', 512)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['asset_key', 'version_no'], 'big5_v2_editorial_asset_version_unique');
            $table->index(['workflow_state', 'updated_at'], 'big5_v2_editorial_state_updated_idx');
            $table->index('release_snapshot_id', 'big5_v2_editorial_release_snapshot_idx');
            $table->index('supersedes_revision_id', 'big5_v2_editorial_supersedes_idx');
        });
    }

    public function down(): void
    {
        // Forward-only: editorial revision history must not be dropped by rollback.
    }
};
