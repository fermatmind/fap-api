<?php

use App\Services\Report\ReportAccess;

return [
    'schema_version' => 'freemium_locale_policy.v1',
    'policy_source' => 'backend/config/freemium_locale_policy.php',
    'enabled' => (bool) env('FAP_FREEMIUM_LOCALE_POLICY_ENABLED', true),

    'frontend_contract' => [
        'payload_key' => 'locale_freemium_policy',
        'authority' => 'backend',
        'frontend_rule' => 'consume_policy_only',
        'mismatch_behavior' => 'stop_before_checkout_or_order_creation',
    ],

    'scales' => [
        'MBTI' => [
            'enabled' => true,
            'mismatch_stop_conditions' => [
                'locale_missing_for_policy_scale',
                'requested_locale_conflicts_with_attempt_locale',
                'unsupported_locale_for_policy_scale',
                'english_paid_offer_before_free_until',
                'sku_not_allowed_for_locale',
                'currency_not_allowed_for_locale',
                'price_not_allowed_for_locale',
            ],
            'locales' => [
                'en' => [
                    'locale_family' => 'en',
                    'policy' => 'free_until',
                    'free_until' => '2026-12-31',
                    'report_access_level' => ReportAccess::REPORT_ACCESS_FULL,
                    'free_modules' => [
                        ReportAccess::MODULE_CORE_FREE,
                        ReportAccess::MODULE_CORE_FULL,
                        ReportAccess::MODULE_CAREER,
                        ReportAccess::MODULE_RELATIONSHIPS,
                    ],
                    'paid_modules' => [],
                    'sku' => null,
                    'upgrade_sku' => null,
                    'price_cents' => null,
                    'currency' => null,
                    'paywall_allowed' => false,
                    'order_creation_allowed' => false,
                ],
                'zh-CN' => [
                    'locale_family' => 'zh',
                    'policy' => 'cny_199_unlock',
                    'free_until' => null,
                    'report_access_level' => ReportAccess::REPORT_ACCESS_FREE,
                    'free_modules' => [
                        ReportAccess::MODULE_CORE_FREE,
                    ],
                    'paid_modules' => [
                        ReportAccess::MODULE_CORE_FULL,
                        ReportAccess::MODULE_CAREER,
                        ReportAccess::MODULE_RELATIONSHIPS,
                    ],
                    'sku' => 'MBTI_REPORT_FULL_199',
                    'upgrade_sku' => 'MBTI_REPORT_FULL',
                    'price_cents' => 199,
                    'currency' => 'CNY',
                    'paywall_allowed' => true,
                    'order_creation_allowed' => true,
                ],
            ],
        ],
    ],
];
