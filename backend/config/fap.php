<?php

return [
    // ✅ 统一使用 .env 里的 MBTI_CONTENT_PACKAGE
    'content_package_version' => env('MBTI_CONTENT_PACKAGE', 'MBTI-CN-v0.2.1-TEST'),

    // ✅ 同理：你可以在 .env 里加 MBTI_PROFILE_VERSION
    'profile_version'         => env('MBTI_PROFILE_VERSION', 'mbti32-v2.5'),
];