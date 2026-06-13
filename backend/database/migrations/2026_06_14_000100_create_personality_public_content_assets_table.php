<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personality_public_content_assets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('framework', 32);
            $table->string('entity_type', 32);
            $table->string('entity_key', 128);
            $table->string('slug', 160);
            $table->string('locale', 16);
            $table->string('title', 255);
            $table->text('summary')->nullable();
            $table->json('content_sections_json')->nullable();
            $table->json('seo_json')->nullable();
            $table->json('canonical_json')->nullable();
            $table->json('hreflang_json')->nullable();
            $table->json('faq_json')->nullable();
            $table->json('media_json')->nullable();
            $table->json('schema_json')->nullable();
            $table->json('method_boundary_json')->nullable();
            $table->json('evidence_notes_json')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('index_eligible')->default(false);
            $table->boolean('sitemap_eligible')->default(false);
            $table->boolean('llms_eligible')->default(false);
            $table->string('launch_state', 32)->default('draft');
            $table->string('review_state', 32)->default('draft');
            $table->string('contract_version', 64)->default('personality_public_asset.v1');
            $table->string('source_package', 160)->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->unsignedBigInteger('created_by_admin_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_admin_user_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['org_id', 'framework', 'entity_type', 'entity_key', 'locale'],
                'uq_personality_public_asset_entity'
            );
            $table->unique(
                ['org_id', 'framework', 'slug', 'locale'],
                'uq_personality_public_asset_slug'
            );
            $table->index(
                ['framework', 'entity_type', 'locale', 'launch_state'],
                'idx_personality_public_asset_lookup'
            );
            $table->index(
                ['is_public', 'index_eligible', 'sitemap_eligible', 'llms_eligible'],
                'idx_personality_public_asset_discovery'
            );
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent content authority loss.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
