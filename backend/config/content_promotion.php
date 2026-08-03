<?php

declare(strict_types=1);

return [
    'contract_version' => 'fermatmind.content_promotion.v2',
    'workflow_identity_key' => env('CONTENT_PROMOTION_AUTOMATION_KEY'),
    'execution' => [
        'source_commit' => env('CONTENT_PROMOTION_SOURCE_COMMIT'),
        'workflow_run_id' => env('CONTENT_PROMOTION_WORKFLOW_RUN_ID'),
        'workflow_run_attempt' => env('CONTENT_PROMOTION_WORKFLOW_RUN_ATTEMPT'),
        'workflow_signature' => env('CONTENT_PROMOTION_WORKFLOW_SIGNATURE'),
        'expected_row_count' => env('CONTENT_PROMOTION_EXPECTED_ROW_COUNT'),
        'executor_release_sha256' => env('CONTENT_PROMOTION_EXECUTOR_RELEASE_SHA256'),
        'release_policy_sha256' => env('CONTENT_PROMOTION_RELEASE_POLICY_SHA256'),
        'previous_receipt' => env('CONTENT_PROMOTION_PREVIOUS_RECEIPT'),
    ],
    'authority_roots' => [
        'content_assets/en-content-parity',
        'content_packs',
        'content_baselines',
        'database/seeders/data',
    ],
    // Independent W9 evidence is intentionally outside the frozen producer
    // package. A package may name an exact report here, but cannot self-approve
    // by adding a payload file beside its own candidate assets.
    'w9_authority_root' => 'content_assets/en-content-parity/W9',
    'release_policy' => json_decode(
        (string) file_get_contents(__DIR__.'/content_promotion_release_policy.v2.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    ),
    'adapter_capabilities' => [
        'W1' => ['mbti-comparisons' => 'audit_compatible', 'mbti-results' => 'audit_compatible'],
        'W2' => ['big-five' => 'audit_compatible'],
        'W3' => ['W3-ARTICLES' => 'audit_compatible', 'W3-CAREER-GUIDES' => 'audit_compatible'],
        'W4' => ['riasec' => 'audit_compatible'],
        'W5' => ['enneagram' => 'audit_compatible', 'enneagram-results' => 'audit_compatible'],
        'W6' => ['iq' => 'fail_closed_legacy_audit'],
        'W7' => ['eq' => 'audit_compatible'],
        'W8' => ['career-jobs' => 'audit_compatible'],
    ],
];
