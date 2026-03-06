<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('name', 255);
            $table->string('slug', 127);
            $table->text('description')->nullable();
            $table->string('seo_title', 255)->nullable();
            $table->string('seo_description', 255)->nullable();
            $table->timestamps();

            $table->unique(['slug'], 'topics_slug_unique');
            $table->index(['org_id', 'updated_at'], 'topics_org_updated_idx');
        });

        DB::table('topics')->insert([
            'org_id' => 0,
            'name' => 'MBTI Topic Cluster',
            'slug' => 'mbti',
            'description' => 'A topic hub that connects MBTI personality guides, related careers, and editorial articles.',
            'seo_title' => 'MBTI Topic Cluster | FermatMind',
            'seo_description' => 'Explore MBTI personality content, matching careers, and related articles in one topic cluster.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
