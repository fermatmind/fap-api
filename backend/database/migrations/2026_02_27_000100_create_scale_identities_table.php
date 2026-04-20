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
        if (! Schema::hasTable('scale_identities')) {
            Schema::create('scale_identities', function (Blueprint $table): void {
                $table->char('scale_uid', 36);
                $table->string('scale_code_v1', 64);
                $table->string('scale_code_v2', 64);
                $table->string('pack_id_v1', 128)->nullable();
                $table->string('pack_id_v2', 128)->nullable();
                $table->string('dir_version_v1', 128)->nullable();
                $table->string('dir_version_v2', 128)->nullable();
                $table->string('status', 16)->default('active');
                $table->timestamps();

                $table->primary('scale_uid', 'scale_identities_scale_uid_pk');
                $table->unique('scale_code_v1', 'scale_identities_scale_code_v1_unique');
                $table->unique('scale_code_v2', 'scale_identities_scale_code_v2_unique');
                $table->index('status', 'scale_identities_status_idx');
            });
        }

        $now = now();
        DB::table('scale_identities')->upsert([
            [
                'scale_uid' => '11111111-1111-4111-8111-111111111111',
                'scale_code_v1' => 'MBTI',
                'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES',
                'pack_id_v1' => 'MBTI.cn-mainland.zh-CN.v0.3',
                'pack_id_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES.cn-mainland.zh-CN.v0.3',
                'dir_version_v1' => 'MBTI-CN-v0.3',
                'dir_version_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES-CN-v0.3',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'scale_uid' => '22222222-2222-4222-8222-222222222222',
                'scale_code_v1' => 'BIG5_OCEAN',
                'scale_code_v2' => 'BIG_FIVE_OCEAN_MODEL',
                'pack_id_v1' => 'BIG5_OCEAN',
                'pack_id_v2' => 'BIG_FIVE_OCEAN_MODEL',
                'dir_version_v1' => 'v1',
                'dir_version_v2' => 'v1',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'scale_uid' => '33333333-3333-4333-8333-333333333333',
                'scale_code_v1' => 'CLINICAL_COMBO_68',
                'scale_code_v2' => 'CLINICAL_DEPRESSION_ANXIETY_PRO',
                'pack_id_v1' => 'CLINICAL_COMBO_68',
                'pack_id_v2' => 'CLINICAL_DEPRESSION_ANXIETY_PRO',
                'dir_version_v1' => 'v1',
                'dir_version_v2' => 'v1',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'scale_uid' => '44444444-4444-4444-8444-444444444444',
                'scale_code_v1' => 'SDS_20',
                'scale_code_v2' => 'DEPRESSION_SCREENING_STANDARD',
                'pack_id_v1' => 'SDS_20',
                'pack_id_v2' => 'DEPRESSION_SCREENING_STANDARD',
                'dir_version_v1' => 'v1',
                'dir_version_v2' => 'v1',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'scale_uid' => '55555555-5555-4555-8555-555555555555',
                'scale_code_v1' => 'IQ_RAVEN',
                'scale_code_v2' => 'IQ_INTELLIGENCE_QUOTIENT',
                'pack_id_v1' => 'default',
                'pack_id_v2' => 'default',
                'dir_version_v1' => 'IQ-RAVEN-CN-v0.3.0-DEMO',
                'dir_version_v2' => 'IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'scale_uid' => '66666666-6666-4666-8666-666666666666',
                'scale_code_v1' => 'EQ_60',
                'scale_code_v2' => 'EQ_EMOTIONAL_INTELLIGENCE',
                'pack_id_v1' => 'EQ_60',
                'pack_id_v2' => 'EQ_EMOTIONAL_INTELLIGENCE',
                'dir_version_v1' => 'v1',
                'dir_version_v2' => 'v1',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'scale_uid' => '77777777-7777-4777-8777-777777777777',
                'scale_code_v1' => 'ENNEAGRAM',
                'scale_code_v2' => 'ENNEAGRAM_PERSONALITY_TEST',
                'pack_id_v1' => 'ENNEAGRAM',
                'pack_id_v2' => 'ENNEAGRAM_PERSONALITY_TEST',
                'dir_version_v1' => 'v1-likert-105',
                'dir_version_v2' => 'v1-likert-105',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['scale_code_v1'], [
            'scale_uid',
            'scale_code_v2',
            'pack_id_v1',
            'pack_id_v2',
            'dir_version_v1',
            'dir_version_v2',
            'status',
            'updated_at',
        ]);
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled by design.
    }
};
