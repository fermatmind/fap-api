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
        Schema::create('topic_personality_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('topic_id');
            $table->string('personality_type', 16);
            $table->timestamps();

            $table->unique(['topic_id', 'personality_type'], 'topic_personality_map_topic_type_unique');
            $table->index(['topic_id', 'personality_type'], 'topic_personality_map_topic_type_idx');
        });

        $topicId = DB::table('topics')->where('slug', 'mbti')->value('id');
        if (! is_int($topicId)) {
            return;
        }

        foreach (['INTP', 'ENTJ', 'INFJ', 'ENFP'] as $type) {
            DB::table('topic_personality_map')->insert([
                'topic_id' => $topicId,
                'personality_type' => $type,
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
