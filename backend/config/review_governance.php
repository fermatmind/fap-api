<?php

declare(strict_types=1);

return [
    'mode' => env('FM_REVIEW_GOVERNANCE_MODE', 'solo_owner'),
    'solo_owner_admin_user_id' => (int) env('FM_REVIEW_SOLO_OWNER_ADMIN_USER_ID', 1),
    'attestation' => [
        'schema_version' => App\Services\ReviewGovernance\ReviewAttestationSchema::VERSION,
        'review_source' => App\Services\ReviewGovernance\ReviewAttestationSchema::REVIEW_SOURCE,
        'statement_version' => App\Services\ReviewGovernance\ReviewAttestationSchema::STATEMENT_VERSION,
    ],
];
