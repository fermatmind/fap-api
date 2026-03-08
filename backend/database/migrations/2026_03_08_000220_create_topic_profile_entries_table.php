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

        Schema::create('topic_profile_entries', function (Blueprint $table) use ($isSqlite): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('profile_id');
            $table->string('entry_type', 32);
            $table->string('group_key', 32);
            $table->string('target_key', 128);
            $table->string('target_locale', 16)->nullable();
            $table->string('title_override', 255)->nullable();
            $table->text('excerpt_override')->nullable();
            $table->string('badge_label', 64)->nullable();
            $table->string('cta_label', 64)->nullable();
            $table->text('target_url_override')->nullable();
            if ($isSqlite) {
                $table->text('payload_json')->nullable();
            } else {
                $table->json('payload_json')->nullable();
            }
            $table->integer('sort_order')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->index(['profile_id', 'group_key', 'sort_order'], 'idx_topic_entries_group');
            $table->index(['profile_id', 'entry_type', 'is_enabled'], 'idx_topic_entries_type');
            $table->index(['entry_type', 'target_key', 'target_locale'], 'idx_topic_entries_target');
            $table->foreign('profile_id', 'fk_topic_entry_profile')
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
