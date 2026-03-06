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
        Schema::create('topic_article_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('topic_id');
            $table->unsignedBigInteger('article_id');
            $table->timestamps();

            $table->unique(['topic_id', 'article_id'], 'topic_article_map_topic_article_unique');
            $table->index(['topic_id', 'article_id'], 'topic_article_map_topic_article_idx');
        });

        $topicId = DB::table('topics')->where('slug', 'mbti')->value('id');
        if (! is_int($topicId)) {
            return;
        }

        $articleIds = DB::table('articles')
            ->where('org_id', 0)
            ->where('status', 'published')
            ->where('is_public', 1)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(3)
            ->pluck('id')
            ->all();

        foreach ($articleIds as $articleId) {
            DB::table('topic_article_map')->insert([
                'topic_id' => $topicId,
                'article_id' => (int) $articleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
