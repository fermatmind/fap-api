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

        Schema::create('method_page_seo_meta', function (Blueprint $table) use ($isSqlite): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('method_page_id');
            $table->string('seo_title', 255)->nullable();
            $table->text('seo_description')->nullable();
            $table->text('canonical_url')->nullable();
            $table->string('og_title', 255)->nullable();
            $table->text('og_description')->nullable();
            $table->text('og_image_url')->nullable();
            $table->string('twitter_title', 255)->nullable();
            $table->text('twitter_description')->nullable();
            $table->text('twitter_image_url')->nullable();
            $table->string('robots', 64)->nullable();
            if ($isSqlite) {
                $table->text('jsonld_overrides_json')->nullable();
            } else {
                $table->json('jsonld_overrides_json')->nullable();
            }
            $table->timestamps();

            $table->unique(['method_page_id'], 'uq_method_page_seo');
            $table->foreign('method_page_id', 'fk_method_page_seo_page')
                ->references('id')
                ->on('method_pages')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
