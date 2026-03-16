<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('personality_profile_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('personality_profile_id')
                ->constrained('personality_profiles')
                ->cascadeOnDelete();
            $table->string('canonical_type_code', 4);
            $table->char('variant_code', 1);
            $table->string('runtime_type_code', 8);
            $table->string('type_name', 120)->nullable();
            $table->string('nickname', 160)->nullable();
            $table->string('rarity_text', 64)->nullable();
            $table->json('keywords_json')->nullable();
            $table->text('hero_summary_md')->nullable();
            $table->longText('hero_summary_html')->nullable();
            $table->string('schema_version', 32)->default('v2');
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['personality_profile_id', 'variant_code'],
                'personality_profile_variants_profile_variant_unique'
            );
            $table->unique(
                ['personality_profile_id', 'runtime_type_code'],
                'personality_profile_variants_profile_runtime_unique'
            );
            $table->index('canonical_type_code', 'personality_profile_variants_canonical_idx');
        });

        Schema::create('personality_profile_variant_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('personality_profile_variant_id')
                ->constrained('personality_profile_variants')
                ->cascadeOnDelete();
            $table->string('section_key', 100);
            $table->string('render_variant', 100);
            $table->longText('body_md')->nullable();
            $table->longText('body_html')->nullable();
            $table->json('payload_json')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(
                ['personality_profile_variant_id', 'section_key'],
                'personality_profile_variant_sections_variant_section_unique'
            );
        });

        Schema::create('personality_profile_variant_seo_meta', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('personality_profile_variant_id')
                ->constrained('personality_profile_variants')
                ->cascadeOnDelete();
            $table->string('seo_title', 255)->nullable();
            $table->text('seo_description')->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->string('og_title', 255)->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image_url', 2048)->nullable();
            $table->string('twitter_title', 255)->nullable();
            $table->text('twitter_description')->nullable();
            $table->string('twitter_image_url', 2048)->nullable();
            $table->string('robots', 64)->nullable();
            $table->json('jsonld_overrides_json')->nullable();
            $table->timestamps();

            $table->unique(
                ['personality_profile_variant_id'],
                'personality_profile_variant_seo_meta_variant_unique'
            );
        });

        Schema::create('personality_profile_variant_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('personality_profile_variant_id')
                ->constrained('personality_profile_variants')
                ->cascadeOnDelete();
            $table->unsignedInteger('revision_no');
            $table->json('snapshot_json');
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by_admin_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally non-destructive: rollback does not remove authority tables.
        return;
    }
};
