<?php

declare(strict_types=1);

namespace App\Services\ReviewGovernance;

use App\DTO\ReviewGovernance\ReviewTargetSet;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ReviewAttestationFactory
{
    public function __construct(
        private ReviewAttestationCanonicalizer $canonicalizer,
        private ReviewAttestationFingerprintBuilder $fingerprintBuilder,
    ) {}

    /**
     * @param  list<array<string, mixed>|\App\DTO\ReviewGovernance\ReviewTarget>  $targets
     * @param  list<array{target_identity: string, reason: string}>  $exceptions
     * @return array<string, mixed>
     */
    public function make(
        string $scopeType,
        string $scopeIdentity,
        string $decision,
        array $targets,
        ?string $packageSha256 = null,
        array $exceptions = [],
        ?CarbonImmutable $attestedAt = null,
        ?int $adminUserId = null,
    ): array {
        $targetSet = ReviewTargetSet::fromArray($targets, $this->canonicalizer);
        $configuredAdminUserId = (int) config('review_governance.solo_owner_admin_user_id', 1);
        $adminUserId ??= $configuredAdminUserId;
        if ($adminUserId <= 0) {
            throw new InvalidArgumentException('Solo-owner admin user ID must be a positive integer.');
        }

        $packageSha256 = $packageSha256 === null ? null : strtolower(trim($packageSha256));
        if ($packageSha256 !== null && preg_match('/^[0-9a-f]{64}$/', $packageSha256) !== 1) {
            throw new InvalidArgumentException('Package SHA-256 must be null or an exact lowercase hash.');
        }

        $payload = [
            'schema_version' => (string) config('review_governance.attestation.schema_version'),
            'review_mode' => ReviewAttestationSchema::REVIEW_MODE,
            'review_source' => (string) config('review_governance.attestation.review_source'),
            'scope_type' => $scopeType,
            'scope_identity' => $scopeIdentity,
            'decision' => $decision,
            'target_count' => $targetSet->count(),
            'target_set_sha256' => $targetSet->sha256,
            'package_sha256' => $packageSha256,
            'exceptions' => $exceptions,
            'statement_version' => (string) config('review_governance.attestation.statement_version'),
            'attested_by_admin_user_id' => $adminUserId,
            'attested_at' => ($attestedAt ?? CarbonImmutable::now('UTC'))->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
        $payload['evidence_sha256'] = $this->fingerprintBuilder->evidenceSha256($payload);

        return $payload;
    }
}
