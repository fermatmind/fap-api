<?php

declare(strict_types=1);

namespace App\Services\ReviewGovernance;

final class ReviewAttestationSchema
{
    public const VERSION = 'solo-owner-review-attestation.v1';

    public const REVIEW_MODE = 'solo_owner';

    public const REVIEW_SOURCE = 'owner_operator_attestation';

    public const STATEMENT_VERSION = 'solo-owner-attestation.v1';

    public const DECISIONS = [
        'approved_all',
        'approved_with_exceptions',
        'rejected',
    ];

    public const REQUIRED_FIELDS = [
        'schema_version',
        'review_mode',
        'review_source',
        'scope_type',
        'scope_identity',
        'decision',
        'target_count',
        'target_set_sha256',
        'package_sha256',
        'exceptions',
        'statement_version',
        'attested_by_admin_user_id',
        'attested_at',
        'evidence_sha256',
    ];
}
