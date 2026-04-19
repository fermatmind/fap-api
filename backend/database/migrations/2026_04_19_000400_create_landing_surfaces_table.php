<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('landing_surfaces')) {
            return;
        }

        Schema::create('landing_surfaces', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('surface_key', 128);
            $table->string('locale', 16);
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('schema_version', 32)->default('v1');
            $table->json('payload_json')->nullable();
            $table->string('status', 32)->default('published');
            $table->boolean('is_public')->default(true);
            $table->boolean('is_indexable')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'surface_key', 'locale'], 'uq_landing_surfaces_key_locale');
            $table->index(['org_id', 'locale', 'status', 'is_public'], 'idx_landing_surfaces_visibility');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent content loss in production.
    }
};
