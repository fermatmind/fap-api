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

        Schema::create('personality_profile_sections', function (Blueprint $table) use ($isSqlite): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('profile_id');
            $table->string('section_key', 64);
            $table->string('title', 255)->nullable();
            $table->string('render_variant', 32)->default('rich_text');
            $table->longText('body_md')->nullable();
            $table->longText('body_html')->nullable();
            if ($isSqlite) {
                $table->text('payload_json')->nullable();
            } else {
                $table->json('payload_json')->nullable();
            }
            $table->integer('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['profile_id', 'section_key'], 'uq_profile_section');
            $table->index(['profile_id', 'sort_order'], 'idx_profile_section_sort');
            $table->foreign('profile_id', 'fk_profile_section_profile')
                ->references('id')
                ->on('personality_profiles')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
