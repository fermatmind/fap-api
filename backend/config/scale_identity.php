<?php

declare(strict_types=1);

return [
    // Write path mode for scale identity columns.
    // legacy: write legacy fields only
    // dual: write both legacy and v2 fields
    // v2: write v2 fields only
    'write_mode' => env('FAP_SCALE_IDENTITY_WRITE_MODE', 'legacy'),

    // Read path mode for scale identity resolution.
    // legacy|dual_prefer_old|dual_prefer_new|v2
    'read_mode' => env('FAP_SCALE_IDENTITY_READ_MODE', 'legacy'),

    // Whether old scale codes are still accepted as request input.
    'accept_legacy_scale_code' => (bool) env('FAP_ACCEPT_LEGACY_SCALE_CODE', true),

    // API response scale code mode.
    // legacy|dual|v2
    'api_response_scale_code_mode' => env('FAP_API_RESPONSE_SCALE_CODE_MODE', 'legacy'),

    // Content path read mode and publish mode.
    // content_path_mode: legacy|dual_prefer_old|dual_prefer_new|v2
    // content_publish_mode: legacy|dual|v2
    // Note: for MBTI, current canonical dir is still defined by
    // content_packs.canonical_dir_versions.MBTI. The v2 maps below are compat identity/path aliases.
    'content_path_mode' => env('FAP_CONTENT_PATH_MODE', 'legacy'),
    'content_publish_mode' => env('FAP_CONTENT_PUBLISH_MODE', 'legacy'),

    // Mode semantic guardrails (enforced by ops:scale-identity-mode-audit):
    // 1) read_mode=v2 requires write_mode in {dual,v2}
    // 2) read_mode=v2 requires accept_legacy_scale_code=false
    // 3) read_mode=v2 requires api_response_scale_code_mode=v2
    // 4) read_mode=v2 requires allow_demo_scales=false
    // 5) Other combinations are allowed but may emit warnings in audit output.

    // Demo scale switch for offboarding.
    'allow_demo_scales' => (bool) env('FAP_ALLOW_DEMO_SCALES', true),

    'code_map_v1_to_v2' => [
        'MBTI' => 'MBTI_PERSONALITY_TEST_16_TYPES',
        'BIG5_OCEAN' => 'BIG_FIVE_OCEAN_MODEL',
        'CLINICAL_COMBO_68' => 'CLINICAL_DEPRESSION_ANXIETY_PRO',
        'SDS_20' => 'DEPRESSION_SCREENING_STANDARD',
        'IQ_RAVEN' => 'IQ_INTELLIGENCE_QUOTIENT',
        'EQ_60' => 'EQ_EMOTIONAL_INTELLIGENCE',
        'ENNEAGRAM' => 'ENNEAGRAM_PERSONALITY_TEST',
    ],

    'code_map_v2_to_v1' => [
        'MBTI_PERSONALITY_TEST_16_TYPES' => 'MBTI',
        'BIG_FIVE_OCEAN_MODEL' => 'BIG5_OCEAN',
        'CLINICAL_DEPRESSION_ANXIETY_PRO' => 'CLINICAL_COMBO_68',
        'DEPRESSION_SCREENING_STANDARD' => 'SDS_20',
        'IQ_INTELLIGENCE_QUOTIENT' => 'IQ_RAVEN',
        'EQ_EMOTIONAL_INTELLIGENCE' => 'EQ_60',
        'ENNEAGRAM_PERSONALITY_TEST' => 'ENNEAGRAM',
    ],

    'scale_uid_map' => [
        'MBTI' => '11111111-1111-4111-8111-111111111111',
        'BIG5_OCEAN' => '22222222-2222-4222-8222-222222222222',
        'CLINICAL_COMBO_68' => '33333333-3333-4333-8333-333333333333',
        'SDS_20' => '44444444-4444-4444-8444-444444444444',
        'IQ_RAVEN' => '55555555-5555-4555-8555-555555555555',
        'EQ_60' => '66666666-6666-4666-8666-666666666666',
        'ENNEAGRAM' => '77777777-7777-4777-8777-777777777777',
    ],

    'pack_id_map_v1' => [
        'MBTI' => 'MBTI.cn-mainland.zh-CN.v0.3',
        'BIG5_OCEAN' => 'BIG5_OCEAN',
        'CLINICAL_COMBO_68' => 'CLINICAL_COMBO_68',
        'SDS_20' => 'SDS_20',
        'IQ_RAVEN' => 'default',
        'EQ_60' => 'EQ_60',
        'ENNEAGRAM' => 'ENNEAGRAM',
    ],

    // v2 pack/dir maps are compat aliases for dual-code/read-path migration.
    // They do not redefine the current runtime canonical MBTI content source.
    'pack_id_map_v2' => [
        'MBTI' => 'MBTI_PERSONALITY_TEST_16_TYPES.cn-mainland.zh-CN.v0.3',
        'BIG5_OCEAN' => 'BIG_FIVE_OCEAN_MODEL',
        'CLINICAL_COMBO_68' => 'CLINICAL_DEPRESSION_ANXIETY_PRO',
        'SDS_20' => 'DEPRESSION_SCREENING_STANDARD',
        'IQ_RAVEN' => 'default',
        'EQ_60' => 'EQ_EMOTIONAL_INTELLIGENCE',
        'ENNEAGRAM' => 'ENNEAGRAM_PERSONALITY_TEST',
    ],

    'dir_version_map_v1' => [
        'MBTI' => 'MBTI-CN-v0.3',
        'BIG5_OCEAN' => 'v1',
        'CLINICAL_COMBO_68' => 'v1',
        'SDS_20' => 'v1',
        'IQ_RAVEN' => 'IQ-RAVEN-CN-v0.3.0-DEMO',
        'EQ_60' => 'v1',
        'ENNEAGRAM' => 'v1-likert-105',
    ],

    'dir_version_map_v2' => [
        'MBTI' => 'MBTI_PERSONALITY_TEST_16_TYPES-CN-v0.3',
        'BIG5_OCEAN' => 'v1',
        'CLINICAL_COMBO_68' => 'v1',
        'SDS_20' => 'v1',
        'IQ_RAVEN' => 'IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO',
        'EQ_60' => 'v1',
        'ENNEAGRAM' => 'v1-likert-105',
    ],

    'demo_replacement_map' => [
        'DEMO_ANSWERS' => 'IQ_RAVEN',
        'SIMPLE_SCORE_DEMO' => 'SDS_20',
    ],
];
