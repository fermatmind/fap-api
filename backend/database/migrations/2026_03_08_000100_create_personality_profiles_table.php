<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personality_profiles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('scale_code', 32)->default('MBTI');
            $table->string('type_code', 8);
            $table->string('slug', 64);
            $table->string('locale', 16);
            $table->string('title', 255);
            $table->string('subtitle', 255)->nullable();
            $table->text('excerpt')->nullable();
            $table->string('hero_kicker', 128)->nullable();
            $table->text('hero_quote')->nullable();
            $table->text('hero_image_url')->nullable();
            $table->string('status', 32)->default('draft');
            $table->boolean('is_public')->default(true);
            $table->boolean('is_indexable')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('schema_version', 32)->default('v1');
            $table->unsignedBigInteger('created_by_admin_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_admin_user_id')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'scale_code', 'type_code', 'locale'], 'uq_personality_profile');
            $table->unique(['org_id', 'scale_code', 'slug', 'locale'], 'uq_personality_slug');
            $table->index(['status', 'is_public', 'published_at'], 'idx_personality_status');
            $table->index(['locale'], 'idx_personality_locale');
            $table->index(['type_code'], 'idx_personality_type');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
