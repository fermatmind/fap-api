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
        if (! Schema::hasTable('scale_code_aliases')) {
            Schema::create('scale_code_aliases', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->char('scale_uid', 36);
                $table->string('alias_code', 64);
                $table->string('alias_type', 16);
                $table->boolean('is_primary')->default(false);
                $table->timestamps();

                $table->unique('alias_code', 'scale_code_aliases_alias_code_unique');
                $table->index('scale_uid', 'scale_code_aliases_scale_uid_idx');
                $table->index(['scale_uid', 'alias_type'], 'scale_code_aliases_uid_type_idx');
                $table->index('is_primary', 'scale_code_aliases_is_primary_idx');
            });
        }

        $now = now();
        $aliases = [
            ['scale_uid' => '11111111-1111-4111-8111-111111111111', 'alias_code' => 'MBTI', 'alias_type' => 'v1', 'is_primary' => true],
            ['scale_uid' => '11111111-1111-4111-8111-111111111111', 'alias_code' => 'MBTI_PERSONALITY_TEST_16_TYPES', 'alias_type' => 'v2', 'is_primary' => false],
            ['scale_uid' => '22222222-2222-4222-8222-222222222222', 'alias_code' => 'BIG5_OCEAN', 'alias_type' => 'v1', 'is_primary' => true],
            ['scale_uid' => '22222222-2222-4222-8222-222222222222', 'alias_code' => 'BIG_FIVE_OCEAN_MODEL', 'alias_type' => 'v2', 'is_primary' => false],
            ['scale_uid' => '33333333-3333-4333-8333-333333333333', 'alias_code' => 'CLINICAL_COMBO_68', 'alias_type' => 'v1', 'is_primary' => true],
            ['scale_uid' => '33333333-3333-4333-8333-333333333333', 'alias_code' => 'CLINICAL_DEPRESSION_ANXIETY_PRO', 'alias_type' => 'v2', 'is_primary' => false],
            ['scale_uid' => '44444444-4444-4444-8444-444444444444', 'alias_code' => 'SDS_20', 'alias_type' => 'v1', 'is_primary' => true],
            ['scale_uid' => '44444444-4444-4444-8444-444444444444', 'alias_code' => 'DEPRESSION_SCREENING_STANDARD', 'alias_type' => 'v2', 'is_primary' => false],
            ['scale_uid' => '55555555-5555-4555-8555-555555555555', 'alias_code' => 'IQ_RAVEN', 'alias_type' => 'v1', 'is_primary' => true],
            ['scale_uid' => '55555555-5555-4555-8555-555555555555', 'alias_code' => 'IQ_INTELLIGENCE_QUOTIENT', 'alias_type' => 'v2', 'is_primary' => false],
            ['scale_uid' => '66666666-6666-4666-8666-666666666666', 'alias_code' => 'EQ_60', 'alias_type' => 'v1', 'is_primary' => true],
            ['scale_uid' => '66666666-6666-4666-8666-666666666666', 'alias_code' => 'EQ_EMOTIONAL_INTELLIGENCE', 'alias_type' => 'v2', 'is_primary' => false],
        ];

        foreach ($aliases as $alias) {
            DB::table('scale_code_aliases')->updateOrInsert(
                ['alias_code' => $alias['alias_code']],
                [
                    'scale_uid' => $alias['scale_uid'],
                    'alias_type' => $alias['alias_type'],
                    'is_primary' => $alias['is_primary'],
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
