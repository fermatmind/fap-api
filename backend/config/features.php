<?php

return [
    'selfcheck_v2' => (bool) env('FEATURE_SELFCHECK_V2', false),
    'legacy_mbti_report_payload_v2' => (bool) env('FEATURE_LEGACY_MBTI_REPORT_PAYLOAD_V2', false),
    'payment_webhook_v2' => (bool) env('FEATURE_PAYMENT_WEBHOOK_V2', false),
    'payment_webhook_v2_shadow' => (bool) env('FEATURE_PAYMENT_WEBHOOK_V2_SHADOW', false),
    'content_store_v2' => (bool) env('FEATURE_CONTENT_STORE_V2', false),
];
