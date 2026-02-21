<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('norm_sources')) {
            return;
        }

        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

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

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
