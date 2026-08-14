<?php

declare(strict_types=1);

return [
    'benefit_code' => 'FERMAT_MEMBER',
    'report_credit_skus' => [
        'MBTI_REPORT_FULL_199',
        'SKU_BIG5_FULL_REPORT_199',
        'WEAPP_LOCAL_REPORT_FULL_199',
    ],
    'report_credit_unit_cents' => 199,
    'automatic_annual_report_count' => 5,
    'plans' => [
        'annual' => [
            'title' => '年卡',
            'list_price_cents' => 999,
            'full_sku' => 'WEAPP_MEMBERSHIP_ANNUAL_999',
            'duration_days' => 365,
        ],
        'lifetime' => [
            'title' => '永久卡',
            'list_price_cents' => 1999,
            'full_sku' => 'WEAPP_MEMBERSHIP_LIFETIME_1999',
            'upgrade_sku' => 'WEAPP_MEMBERSHIP_LIFETIME_UPGRADE_999',
            'upgrade_price_cents' => 999,
            'duration_days' => 0,
            'inventory_limit' => 10000,
        ],
    ],
];
