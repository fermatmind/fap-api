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

        Schema::create('career_jobs', function (Blueprint $table) use ($isSqlite): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('job_code', 96);
            $table->string('slug', 128);
            $table->string('locale', 16);
            $table->string('title', 255);
            $table->string('subtitle', 255)->nullable();
            $table->text('excerpt')->nullable();
            $table->string('hero_kicker', 128)->nullable();
            $table->text('hero_quote')->nullable();
            $table->text('cover_image_url')->nullable();
            $table->string('industry_slug', 128)->nullable();
            $table->string('industry_label', 255)->nullable();
            $table->longText('body_md')->nullable();
            $table->longText('body_html')->nullable();

            if ($isSqlite) {
                $table->text('salary_json')->nullable();
                $table->text('outlook_json')->nullable();
                $table->text('skills_json')->nullable();
                $table->text('work_contents_json')->nullable();
                $table->text('growth_path_json')->nullable();
                $table->text('fit_personality_codes_json')->nullable();
                $table->text('mbti_primary_codes_json')->nullable();
                $table->text('mbti_secondary_codes_json')->nullable();
                $table->text('riasec_profile_json')->nullable();
                $table->text('big5_targets_json')->nullable();
                $table->text('iq_eq_notes_json')->nullable();
                $table->text('market_demand_json')->nullable();
            } else {
                $table->json('salary_json')->nullable();
                $table->json('outlook_json')->nullable();
                $table->json('skills_json')->nullable();
                $table->json('work_contents_json')->nullable();
                $table->json('growth_path_json')->nullable();
                $table->json('fit_personality_codes_json')->nullable();
                $table->json('mbti_primary_codes_json')->nullable();
                $table->json('mbti_secondary_codes_json')->nullable();
                $table->json('riasec_profile_json')->nullable();
                $table->json('big5_targets_json')->nullable();
                $table->json('iq_eq_notes_json')->nullable();
                $table->json('market_demand_json')->nullable();
            }

            $table->string('status', 32)->default('draft');
            $table->boolean('is_public')->default(true);
            $table->boolean('is_indexable')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('schema_version', 32)->default('v1');
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by_admin_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_admin_user_id')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'job_code', 'locale'], 'uq_career_job');
            $table->unique(['org_id', 'slug', 'locale'], 'uq_career_job_slug');
            $table->index(['status', 'is_public', 'published_at'], 'idx_career_job_status');
            $table->index(['locale'], 'idx_career_job_locale');
            $table->index(['locale', 'sort_order'], 'idx_career_job_sort');
            $table->index(['locale', 'industry_slug'], 'idx_career_job_industry');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
