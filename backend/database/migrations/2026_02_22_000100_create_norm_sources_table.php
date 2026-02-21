<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        if (!Schema::hasTable('norm_sources')) {
            Schema::create('norm_sources', function (Blueprint $table) use ($isSqlite) {
                $table->string('source_id', 128)->primary();
                $table->string('title', 255);
                $table->text('citation')->nullable();
                $table->text('homepage_url')->nullable();
                $table->text('license')->nullable();
                if ($isSqlite) {
                    $table->text('notes_json')->nullable();
                } else {
                    $table->json('notes_json')->nullable();
                }
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        Schema::table('norm_sources', function (Blueprint $table) use ($isSqlite) {
            if (!Schema::hasColumn('norm_sources', 'source_id')) {
                $table->string('source_id', 128)->nullable();
            }
            if (!Schema::hasColumn('norm_sources', 'title')) {
                $table->string('title', 255)->nullable();
            }
            if (!Schema::hasColumn('norm_sources', 'citation')) {
                $table->text('citation')->nullable();
            }
            if (!Schema::hasColumn('norm_sources', 'homepage_url')) {
                $table->text('homepage_url')->nullable();
            }
            if (!Schema::hasColumn('norm_sources', 'license')) {
                $table->text('license')->nullable();
            }
            if (!Schema::hasColumn('norm_sources', 'notes_json')) {
                if ($isSqlite) {
                    $table->text('notes_json')->nullable();
                } else {
                    $table->json('notes_json')->nullable();
                }
            }
            if (!Schema::hasColumn('norm_sources', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('norm_sources', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
