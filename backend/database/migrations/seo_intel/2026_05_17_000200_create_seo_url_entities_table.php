<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        if (Schema::hasTable('seo_url_entities')) {
            return;
        }

        Schema::create('seo_url_entities', function (Blueprint $table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_id_or_slug', 255);
            $table->string('entity_source', 64);
            $table->string('authority_status', 64);
            $table->timestamp('source_updated_at')->nullable();
            $table->json('attributes_json')->nullable();
            $table->timestamps();

            $table->index(['canonical_url_hash', 'locale'], 'seo_url_entities_hash_locale_idx');
            $table->index(
                ['page_entity_type', 'entity_id_or_slug', 'locale'],
                'seo_url_entities_type_slug_locale_idx'
            );
            $table->index('authority_status', 'seo_url_entities_authority_status_idx');
            $table->index('entity_source', 'seo_url_entities_entity_source_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
