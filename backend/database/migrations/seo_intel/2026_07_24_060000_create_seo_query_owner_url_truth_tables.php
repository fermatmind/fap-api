<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        if (! Schema::hasTable('seo_query_families')) {
            Schema::create('seo_query_families', function (Blueprint $table): void {
                $table->id();
                $table->string('family_key', 128);
                $table->string('locale', 16);
                $table->string('intent_type', 64);
                $table->string('source_authority', 64);
                $table->string('authority_reference', 255)->nullable();
                $table->string('state', 32)->default('active');
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->unique(['family_key', 'locale'], 'seo_query_families_key_locale_unique');
                $table->index(['intent_type', 'state'], 'seo_query_families_intent_state_idx');
                $table->index('source_authority', 'seo_query_families_authority_idx');
            });
        }

        if (! Schema::hasTable('seo_query_family_queries')) {
            Schema::create('seo_query_family_queries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('query_family_id')
                    ->constrained('seo_query_families')
                    ->cascadeOnDelete();
                $table->char('query_hash', 64);
                $table->string('source_engine', 64)->default('google');
                $table->string('source_authority', 64);
                $table->string('authority_status', 32)->default('active');
                $table->timestamps();

                $table->unique(
                    ['query_hash', 'source_engine'],
                    'seo_query_family_queries_hash_engine_unique'
                );
                $table->index(
                    ['query_family_id', 'authority_status'],
                    'seo_query_family_queries_family_status_idx'
                );
            });
        }

        if (! Schema::hasTable('seo_query_url_bindings')) {
            Schema::create('seo_query_url_bindings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('query_family_id')
                    ->constrained('seo_query_families')
                    ->cascadeOnDelete();
                $table->char('url_hash', 64);
                $table->text('url_path')->nullable();
                $table->string('url_role', 32);
                $table->char('target_owner_url_hash', 64)->nullable();
                $table->string('hreflang_locale', 16)->nullable();
                $table->string('source_authority', 64);
                $table->string('authority_status', 32)->default('active');
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->unique(
                    ['query_family_id', 'url_hash', 'url_role'],
                    'seo_query_url_bindings_family_hash_role_unique'
                );
                $table->index(
                    ['query_family_id', 'url_role', 'authority_status'],
                    'seo_query_url_bindings_family_role_status_idx'
                );
                $table->index('target_owner_url_hash', 'seo_query_url_bindings_owner_hash_idx');
            });
        }
    }

    public function down(): void
    {
        // Forward-only: production rollback requires a reviewed forward migration.
    }
};
