<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::create('topic_profile_revisions', function (Blueprint $table) use ($isSqlite): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('profile_id');
            $table->unsignedInteger('revision_no');
            if ($isSqlite) {
                $table->text('snapshot_json');
            } else {
                $table->json('snapshot_json');
            }
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by_admin_user_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['profile_id', 'revision_no'], 'uq_topic_revision');
            $table->index(['profile_id', 'created_at'], 'idx_topic_revision_created');
            $table->foreign('profile_id', 'fk_topic_revision_profile')
                ->references('id')
                ->on('topic_profiles')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
