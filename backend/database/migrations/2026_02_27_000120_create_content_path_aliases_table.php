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
        if (! Schema::hasTable('content_path_aliases')) {
            Schema::create('content_path_aliases', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('scope', 64);
                $table->string('old_path', 255);
                $table->string('new_path', 255);
                $table->char('scale_uid', 36)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['scope', 'old_path'], 'content_path_aliases_scope_old_unique');
                $table->index(['scope', 'is_active'], 'content_path_aliases_scope_active_idx');
                $table->index('scale_uid', 'content_path_aliases_scale_uid_idx');
            });
        }

        $now = now();
        $rows = [
            [
                'scope' => 'backend_content_packs',
                'old_path' => 'content_packs/BIG5_OCEAN',
                'new_path' => 'content_packs/BIG_FIVE_OCEAN_MODEL',
                'scale_uid' => '22222222-2222-4222-8222-222222222222',
            ],
            [
                'scope' => 'backend_content_packs',
                'old_path' => 'content_packs/CLINICAL_COMBO_68',
                'new_path' => 'content_packs/CLINICAL_DEPRESSION_ANXIETY_PRO',
                'scale_uid' => '33333333-3333-4333-8333-333333333333',
            ],
            [
                'scope' => 'backend_content_packs',
                'old_path' => 'content_packs/SDS_20',
                'new_path' => 'content_packs/DEPRESSION_SCREENING_STANDARD',
                'scale_uid' => '44444444-4444-4444-8444-444444444444',
            ],
            [
                'scope' => 'backend_content_packs',
                'old_path' => 'content_packs/EQ_60',
                'new_path' => 'content_packs/EQ_EMOTIONAL_INTELLIGENCE',
                'scale_uid' => '66666666-6666-4666-8666-666666666666',
            ],
            [
                'scope' => 'backend_content_packs',
                'old_path' => 'content_packs/ENNEAGRAM',
                'new_path' => 'content_packs/ENNEAGRAM_PERSONALITY_TEST',
                'scale_uid' => '77777777-7777-4777-8777-777777777777',
            ],
            [
                'scope' => 'content_packages',
                'old_path' => 'default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3',
                'new_path' => 'default/CN_MAINLAND/zh-CN/MBTI_PERSONALITY_TEST_16_TYPES-CN-v0.3',
                'scale_uid' => '11111111-1111-4111-8111-111111111111',
            ],
            [
                'scope' => 'content_packages',
                'old_path' => 'default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO',
                'new_path' => 'default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO',
                'scale_uid' => '55555555-5555-4555-8555-555555555555',
            ],
            [
                'scope' => 'content_packages',
                'old_path' => 'default/CN_MAINLAND/zh-CN/BIG5-CN-v0.1.0-TEST',
                'new_path' => 'default/CN_MAINLAND/zh-CN/BIG_FIVE_OCEAN_MODEL-CN-v0.1.0-TEST',
                'scale_uid' => '22222222-2222-4222-8222-222222222222',
            ],
        ];

        foreach ($rows as $row) {
            DB::table('content_path_aliases')->updateOrInsert(
                ['scope' => $row['scope'], 'old_path' => $row['old_path']],
                [
                    'new_path' => $row['new_path'],
                    'scale_uid' => $row['scale_uid'],
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled by design.
    }
};
