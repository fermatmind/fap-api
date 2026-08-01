<?php

declare(strict_types=1);

return [
    'contract_version' => 'fermatmind.content_promotion.v2',
    'workflow_identity_key' => env('CONTENT_PROMOTION_AUTOMATION_KEY'),
    'authority_roots' => [
        'content_assets/en-content-parity',
        'content_packs',
        'content_baselines',
        'database/seeders/data',
    ],
    'release_policy' => json_decode(
        (string) file_get_contents(__DIR__.'/content_promotion_release_policy.v2.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    ),
    'adapter_capabilities' => [
        'W1' => ['mbti-comparisons' => 'audit_compatible', 'mbti-results' => 'fail_closed_legacy_audit'],
        'W2' => ['big-five' => 'fail_closed_legacy_audit'],
        'W3' => ['articles' => 'fail_closed_legacy_audit', 'career-guides' => 'fail_closed_legacy_audit'],
        'W4' => ['riasec' => 'fail_closed_legacy_audit'],
        'W5' => ['enneagram' => 'fail_closed_legacy_audit'],
        'W6' => ['iq' => 'fail_closed_legacy_audit'],
        'W7' => ['eq' => 'fail_closed_legacy_audit'],
        'W8' => ['career-jobs' => 'fail_closed_legacy_audit'],
    ],
];
