<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_assets')) {
            return;
        }

        Schema::create('media_assets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('asset_key', 160);
            $table->string('disk', 64)->default('public_static');
            $table->string('path', 512)->nullable();
            $table->string('url', 1024)->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('alt', 255)->nullable();
            $table->text('caption')->nullable();
            $table->string('credit', 255)->nullable();
            $table->string('status', 32)->default('published');
            $table->boolean('is_public')->default(true);
            $table->unsignedBigInteger('uploaded_by_admin_user_id')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'asset_key'], 'uq_media_assets_key');
            $table->index(['org_id', 'status', 'is_public'], 'idx_media_assets_visibility');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent content loss in production.
    }
};
