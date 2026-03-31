<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personality_profile_variant_clone_contents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('personality_profile_variant_id');
            $table->string('template_key', 64)->default('mbti_desktop_clone_v1');
            $table->string('status', 32)->default('draft');
            $table->string('schema_version', 32)->default('v1');
            $table->json('content_json');
            $table->json('asset_slots_json');
            $table->json('meta_json')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['personality_profile_variant_id', 'template_key'],
                'pp_variant_clone_contents_variant_template_unique'
            );
            $table->index(['status', 'published_at'], 'pp_variant_clone_contents_status_idx');
            $table->foreign('personality_profile_variant_id', 'pp_variant_clone_contents_variant_fk')
                ->references('id')
                ->on('personality_profile_variants')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
