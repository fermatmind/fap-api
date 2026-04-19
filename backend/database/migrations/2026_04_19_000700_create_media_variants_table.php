<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_variants')) {
            return;
        }

        Schema::create('media_variants', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('media_asset_id');
            $table->string('variant_key', 64);
            $table->string('path', 512)->nullable();
            $table->string('url', 1024)->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->foreign('media_asset_id')
                ->references('id')
                ->on('media_assets')
                ->cascadeOnDelete();
            $table->unique(['media_asset_id', 'variant_key'], 'uq_media_variants_asset_key');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent content loss in production.
    }
};
