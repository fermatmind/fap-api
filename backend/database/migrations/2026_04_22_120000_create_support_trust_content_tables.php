<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_articles')) {
            Schema::create('support_articles', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('slug', 128);
                $table->string('title', 255);
                $table->text('summary')->nullable();
                $table->longText('body_md')->nullable();
                $table->longText('body_html')->nullable();
                $table->string('support_category', 64);
                $table->string('support_intent', 96);
                $table->string('locale', 16)->default('en');
                $table->string('status', 32)->default('draft');
                $table->string('review_state', 64)->default('draft');
                $table->string('primary_cta_label', 128)->nullable();
                $table->string('primary_cta_url', 255)->nullable();
                $table->json('related_support_article_ids')->nullable();
                $table->json('related_content_page_ids')->nullable();
                $table->dateTime('last_reviewed_at')->nullable();
                $table->dateTime('published_at')->nullable();
                $table->string('seo_title', 255)->nullable();
                $table->text('seo_description')->nullable();
                $table->string('canonical_path', 255)->nullable();
                $table->timestamps();

                $table->unique(['org_id', 'slug', 'locale'], 'uq_support_articles_slug_locale');
                $table->index(['org_id', 'locale', 'status'], 'idx_support_articles_public_read');
                $table->index(['org_id', 'support_category', 'support_intent'], 'idx_support_articles_scope');
                $table->index(['org_id', 'review_state'], 'idx_support_articles_review');
            });
        }

        if (! Schema::hasTable('interpretation_guides')) {
            Schema::create('interpretation_guides', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('slug', 128);
                $table->string('title', 255);
                $table->text('summary')->nullable();
                $table->longText('body_md')->nullable();
                $table->longText('body_html')->nullable();
                $table->string('test_family', 64)->default('general');
                $table->string('result_context', 96);
                $table->string('audience', 96)->default('general');
                $table->string('locale', 16)->default('en');
                $table->string('status', 32)->default('draft');
                $table->string('review_state', 64)->default('draft');
                $table->json('related_guide_ids')->nullable();
                $table->json('related_methodology_page_ids')->nullable();
                $table->dateTime('last_reviewed_at')->nullable();
                $table->dateTime('published_at')->nullable();
                $table->string('seo_title', 255)->nullable();
                $table->text('seo_description')->nullable();
                $table->string('canonical_path', 255)->nullable();
                $table->timestamps();

                $table->unique(['org_id', 'slug', 'locale'], 'uq_interpretation_guides_slug_locale');
                $table->index(['org_id', 'locale', 'status'], 'idx_interpretation_guides_public_read');
                $table->index(['org_id', 'test_family', 'result_context'], 'idx_interpretation_guides_scope');
                $table->index(['org_id', 'review_state'], 'idx_interpretation_guides_review');
            });
        }

        if (Schema::hasTable('content_pages')) {
            Schema::table('content_pages', function (Blueprint $table): void {
                if (! Schema::hasColumn('content_pages', 'page_type')) {
                    $table->string('page_type', 64)->default('company')->after('kind');
                }
                if (! Schema::hasColumn('content_pages', 'review_state')) {
                    $table->string('review_state', 64)->default('draft')->after('status');
                }
                if (! Schema::hasColumn('content_pages', 'owner')) {
                    $table->string('owner', 128)->nullable()->after('review_state');
                }
                if (! Schema::hasColumn('content_pages', 'legal_review_required')) {
                    $table->boolean('legal_review_required')->default(false)->after('owner');
                }
                if (! Schema::hasColumn('content_pages', 'science_review_required')) {
                    $table->boolean('science_review_required')->default(false)->after('legal_review_required');
                }
                if (! Schema::hasColumn('content_pages', 'last_reviewed_at')) {
                    $table->dateTime('last_reviewed_at')->nullable()->after('science_review_required');
                }
                if (! Schema::hasColumn('content_pages', 'seo_description')) {
                    $table->text('seo_description')->nullable()->after('meta_description');
                }
                if (! Schema::hasColumn('content_pages', 'canonical_path')) {
                    $table->string('canonical_path', 255)->nullable()->after('seo_description');
                }
            });
        }
    }

    public function down(): void
    {
        // Forward-only migration: destructive rollback is intentionally disabled
        // by repository migration safety policy.
    }
};
