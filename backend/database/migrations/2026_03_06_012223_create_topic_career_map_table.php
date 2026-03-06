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
        Schema::create('topic_career_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('topic_id');
            $table->unsignedBigInteger('career_id');
            $table->timestamps();

            $table->unique(['topic_id', 'career_id'], 'topic_career_map_topic_career_unique');
            $table->index(['topic_id', 'career_id'], 'topic_career_map_topic_career_idx');
        });

        $topicId = DB::table('topics')->where('slug', 'mbti')->value('id');
        if (! is_int($topicId)) {
            return;
        }

        foreach ([1, 2, 3] as $careerId) {
            DB::table('topic_career_map')->insert([
                'topic_id' => $topicId,
                'career_id' => $careerId,
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
