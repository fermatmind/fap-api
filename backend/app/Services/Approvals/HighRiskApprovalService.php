<?php

declare(strict_types=1);

namespace App\Services\Approvals;

use App\Models\AdminApproval;
use App\Models\AdminUser;
use App\Services\Auth\AdminTotpService;
use App\Services\ReviewGovernance\ReviewAttestationCanonicalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @review-surface admin_approval
 * @review-surface refund_approval
 * @review-surface manual_benefit_grant_approval
 * @review-surface benefit_revoke_approval
 * @review-surface payment_event_reprocess_approval
 * @review-surface rollback_release_approval
 * @review-surface data_lifecycle_approval
 */
final readonly class HighRiskApprovalService
{
    public const SCHEMA_VERSION = 'solo-owner-ops-approval.v1';

    public const METADATA_KEY = '_approval_governance';

    public const EXECUTION_METADATA_KEY = '_approval_execution_authorization';

    private const EXECUTION_SCHEMA_VERSION = 'solo-owner-ops-execution.v1';

    private const EXECUTION_AUTHORIZATION_TTL_MINUTES = 10;

    /** @var array<string, string> */
    private const TYPE_SURFACES = [
        AdminApproval::TYPE_MANUAL_GRANT => 'manual_benefit_grant_approval',
        AdminApproval::TYPE_REVOKE_BENEFIT => 'benefit_revoke_approval',
        AdminApproval::TYPE_REFUND => 'refund_approval',
        AdminApproval::TYPE_REPROCESS_EVENT => 'payment_event_reprocess_approval',
        AdminApproval::TYPE_ROLLBACK_RELEASE => 'rollback_release_approval',
        AdminApproval::TYPE_DATA_LIFECYCLE => 'data_lifecycle_approval',
    ];

    private const EVIDENCE_FIELDS = [
        'schema_version',
        'review_mode',
        'surface_id',
        'target_fingerprint_sha256',
        'reason_sha256',
        'correlation_id',
        'requested_by_admin_user_id',
        'approved_by_admin_user_id',
        'step_up_verified',
        'approved_at',
        'evidence_sha256',
    ];

    private const EXECUTION_EVIDENCE_FIELDS = [
        'schema_version',
        'actor_admin_user_id',
        'authorized_at',
        'execution_attempt',
        'approval_evidence_sha256',
        'authorization_sha256',
    ];

    public function __construct(
        private ReviewAttestationCanonicalizer $canonicalizer,
        private AdminTotpService $adminTotp,
    ) {}

    public function approve(
        string $approvalId,
        int $actorAdminUserId,
        string $freshStepUpCode,
    ): AdminApproval {
        return DB::transaction(function () use ($approvalId, $actorAdminUserId, $freshStepUpCode): AdminApproval {
            $approval = AdminApproval::query()->whereKey($approvalId)->lockForUpdate()->first();
            if (! $approval) {
                throw new HighRiskApprovalValidationException('Approval record was not found.');
            }

            $this->assertFreshStepUp($actorAdminUserId, $freshStepUpCode);
            $this->assertApprovalInputs($approval);
            $this->assertApproverPolicy($approval, $actorAdminUserId);

            if (strtoupper((string) $approval->status) === AdminApproval::STATUS_APPROVED) {
                if ((int) $approval->approved_by_admin_user_id !== $actorAdminUserId) {
                    throw new HighRiskApprovalValidationException('Approval actor does not match the existing approval evidence.');
                }
                if ($this->requiresLegacyGovernanceBinding($approval)) {
                    $this->bindLegacyGovernanceOnLockedApproval($approval, $actorAdminUserId);
                }

                $this->assertExecutable($approval);

                return $approval;
            }
            if (strtoupper((string) $approval->status) !== AdminApproval::STATUS_PENDING) {
                throw new HighRiskApprovalValidationException('Only pending approvals can be approved.');
            }

            $approvedAt = now()->startOfSecond();
            $metadata = $this->evidence($approval, $actorAdminUserId, $approvedAt);
            $payload = $this->businessPayload($approval);
            $payload[self::METADATA_KEY] = $metadata;

            $approval->status = AdminApproval::STATUS_APPROVED;
            $approval->approved_by_admin_user_id = $actorAdminUserId;
            $approval->approved_at = $approvedAt;
            $approval->payload_json = $payload;
            $approval->save();

            $this->writeApprovalAudit($approval, $metadata);
            $approval->refresh();
            $this->assertExecutable($approval);

            return $approval;
        }, 3);
    }

    public function bindLegacyGovernance(
        string $approvalId,
        int $actorAdminUserId,
        string $freshStepUpCode,
    ): AdminApproval {
        return DB::transaction(function () use ($approvalId, $actorAdminUserId, $freshStepUpCode): AdminApproval {
            $approval = AdminApproval::query()->whereKey($approvalId)->lockForUpdate()->first();
            if (! $approval) {
                throw new HighRiskApprovalValidationException('Approval record was not found.');
            }

            $this->assertFreshStepUp($actorAdminUserId, $freshStepUpCode);
            $this->assertApprovalInputs($approval);
            $this->assertApproverPolicy($approval, $actorAdminUserId);
            if (! $this->requiresLegacyGovernanceBinding($approval)) {
                throw new HighRiskApprovalValidationException('Approval does not require legacy governance binding.');
            }

            return $this->bindLegacyGovernanceOnLockedApproval($approval, $actorAdminUserId);
        }, 3);
    }

    public function assertExecutable(AdminApproval $approval): void
    {
        $this->assertApprovalInputs($approval);
        $payload = is_array($approval->payload_json) ? $approval->payload_json : [];
        $metadata = $payload[self::METADATA_KEY] ?? null;
        $actualFields = is_array($metadata) ? array_keys($metadata) : [];
        $expectedFields = self::EVIDENCE_FIELDS;
        sort($actualFields, SORT_STRING);
        sort($expectedFields, SORT_STRING);
        if (! is_array($metadata) || $actualFields !== $expectedFields) {
            throw new HighRiskApprovalValidationException('Approval governance evidence is missing or malformed.');
        }

        $mode = (string) config('review_governance.mode');
        $surfaceId = $this->surfaceIdFor((string) $approval->type);
        $approvedBy = (int) ($approval->approved_by_admin_user_id ?? 0);
        $requestedBy = (int) ($approval->requested_by_admin_user_id ?? 0);
        $approvedAt = $approval->approved_at;
        if (! $approvedAt instanceof Carbon) {
            throw new HighRiskApprovalValidationException('Approval timestamp is missing.');
        }

        if ($metadata['schema_version'] !== self::SCHEMA_VERSION
            || $metadata['review_mode'] !== $mode
            || $metadata['surface_id'] !== $surfaceId
            || $metadata['requested_by_admin_user_id'] !== $requestedBy
            || $metadata['approved_by_admin_user_id'] !== $approvedBy
            || $metadata['step_up_verified'] !== true
            || $metadata['approved_at'] !== $approvedAt->utc()->format('Y-m-d\TH:i:s\Z')
            || ! hash_equals((string) $approval->correlation_id, (string) $metadata['correlation_id'])
            || ! hash_equals(hash('sha256', trim((string) $approval->reason)), (string) $metadata['reason_sha256'])
            || ! hash_equals($this->targetFingerprint($approval), (string) $metadata['target_fingerprint_sha256'])) {
            throw new HighRiskApprovalValidationException('Approval governance evidence no longer matches the exact target.');
        }

        $expectedEvidenceSha = hash('sha256', $this->canonicalizer->encode(
            array_diff_key($metadata, ['evidence_sha256' => true]),
        ));
        if (! is_string($metadata['evidence_sha256'])
            || preg_match('/^[0-9a-f]{64}$/', $metadata['evidence_sha256']) !== 1
            || ! hash_equals($expectedEvidenceSha, $metadata['evidence_sha256'])) {
            throw new HighRiskApprovalValidationException('Approval evidence SHA-256 is invalid or drifted.');
        }

        $this->assertApproverPolicy($approval, $approvedBy);
    }

    public function authorizeExecution(
        string $approvalId,
        int $actorAdminUserId,
        string $freshStepUpCode,
        bool $retryFailed = false,
    ): AdminApproval {
        return DB::transaction(function () use ($approvalId, $actorAdminUserId, $freshStepUpCode, $retryFailed): AdminApproval {
            $approval = AdminApproval::query()->whereKey($approvalId)->lockForUpdate()->first();
            if (! $approval) {
                throw new HighRiskApprovalValidationException('Approval record was not found.');
            }

            $this->assertFreshStepUp($actorAdminUserId, $freshStepUpCode);
            $this->assertExecutable($approval);
            if (! $this->executionSupported($approval)) {
                throw new HighRiskApprovalValidationException('Approval execution adapter is not registered.');
            }

            $requiredStatus = $retryFailed ? AdminApproval::STATUS_FAILED : AdminApproval::STATUS_APPROVED;
            if (strtoupper((string) $approval->status) !== $requiredStatus) {
                throw new HighRiskApprovalValidationException(
                    $retryFailed
                        ? 'Only failed approvals can be authorized for retry.'
                        : 'Only approved approvals can be authorized for execution.',
                );
            }
            $this->assertExecutionActorPolicy($actorAdminUserId);

            if (! $retryFailed && $this->hasExecutionAuthorization($approval)) {
                try {
                    $existingActor = $this->executionActor($approval);
                } catch (HighRiskApprovalValidationException) {
                    // A fresh step-up may replace only transient execution authorization;
                    // immutable approval governance evidence was already revalidated above.
                    $existingActor = null;
                }
                if ($existingActor instanceof AdminUser) {
                    if ((int) $existingActor->id !== $actorAdminUserId) {
                        throw new HighRiskApprovalValidationException('Execution is already authorized by a different administrator.');
                    }

                    return $approval;
                }
            }

            $payload = is_array($approval->payload_json) ? $approval->payload_json : [];
            $governance = $payload[self::METADATA_KEY] ?? null;
            $approvalEvidenceSha = is_array($governance)
                ? (string) ($governance['evidence_sha256'] ?? '')
                : '';
            $authorizedAt = now()->startOfSecond();
            $metadata = [
                'schema_version' => self::EXECUTION_SCHEMA_VERSION,
                'actor_admin_user_id' => $actorAdminUserId,
                'authorized_at' => $authorizedAt->utc()->format('Y-m-d\TH:i:s\Z'),
                'execution_attempt' => (int) $approval->retry_count + 1,
                'approval_evidence_sha256' => $approvalEvidenceSha,
            ];
            $metadata['authorization_sha256'] = hash('sha256', $this->canonicalizer->encode($metadata));

            $payload[self::EXECUTION_METADATA_KEY] = $metadata;
            $approval->payload_json = $payload;
            if ($retryFailed) {
                $approval->status = AdminApproval::STATUS_APPROVED;
            }
            $approval->save();

            $this->writeExecutionAuthorizationAudit($approval, $actorAdminUserId, $metadata);
            $approval->refresh();
            $this->executionActor($approval);

            return $approval;
        }, 3);
    }

    public function executionActor(AdminApproval $approval): AdminUser
    {
        $this->assertExecutable($approval);
        $payload = is_array($approval->payload_json) ? $approval->payload_json : [];
        $metadata = $payload[self::EXECUTION_METADATA_KEY] ?? null;
        $actualFields = is_array($metadata) ? array_keys($metadata) : [];
        $expectedFields = self::EXECUTION_EVIDENCE_FIELDS;
        sort($actualFields, SORT_STRING);
        sort($expectedFields, SORT_STRING);
        if (! is_array($metadata) || $actualFields !== $expectedFields) {
            throw new HighRiskApprovalValidationException('Execution authorization evidence is missing or malformed.');
        }

        $governance = $payload[self::METADATA_KEY] ?? null;
        $approvalEvidenceSha = is_array($governance)
            ? (string) ($governance['evidence_sha256'] ?? '')
            : '';
        $actorAdminUserId = (int) ($metadata['actor_admin_user_id'] ?? 0);
        try {
            $authorizedAt = Carbon::createFromFormat(
                'Y-m-d\TH:i:s\Z',
                (string) ($metadata['authorized_at'] ?? ''),
                'UTC',
            );
        } catch (\Throwable) {
            throw new HighRiskApprovalValidationException('Execution authorization evidence is invalid, stale, or drifted.');
        }
        $expectedAuthorizationSha = hash('sha256', $this->canonicalizer->encode(
            array_diff_key($metadata, ['authorization_sha256' => true]),
        ));
        if ($metadata['schema_version'] !== self::EXECUTION_SCHEMA_VERSION
            || ! is_int($metadata['actor_admin_user_id'])
            || $actorAdminUserId <= 0
            || ! is_int($metadata['execution_attempt'])
            || (int) $metadata['execution_attempt'] !== (int) $approval->retry_count + 1
            || ! hash_equals($approvalEvidenceSha, (string) $metadata['approval_evidence_sha256'])
            || ! is_string($metadata['authorization_sha256'])
            || preg_match('/^[0-9a-f]{64}$/', $metadata['authorization_sha256']) !== 1
            || ! hash_equals($expectedAuthorizationSha, $metadata['authorization_sha256'])
            || ! $authorizedAt
            || $authorizedAt->isFuture()
            || ($approval->approved_at instanceof Carbon && $authorizedAt->lt($approval->approved_at->utc()))
            || $authorizedAt->lt(now()->utc()->subMinutes(self::EXECUTION_AUTHORIZATION_TTL_MINUTES))) {
            throw new HighRiskApprovalValidationException('Execution authorization evidence is invalid, stale, or drifted.');
        }

        $this->assertExecutionActorPolicy($actorAdminUserId);
        $actor = AdminUser::query()->find($actorAdminUserId);
        if (! $actor) {
            throw new HighRiskApprovalValidationException('Execution actor was not found.');
        }

        return $actor;
    }

    /** @return list<string> */
    public function supportedSurfaceIds(): array
    {
        return array_values(self::TYPE_SURFACES);
    }

    public function executionSupported(AdminApproval|string $approval): bool
    {
        $type = $approval instanceof AdminApproval ? (string) $approval->type : $approval;

        return strtoupper(trim($type)) !== AdminApproval::TYPE_DATA_LIFECYCLE;
    }

    public function hasValidGovernanceEvidence(AdminApproval $approval): bool
    {
        try {
            $this->assertExecutable($approval);

            return true;
        } catch (HighRiskApprovalValidationException) {
            return false;
        }
    }

    public function requiresLegacyGovernanceBinding(AdminApproval $approval): bool
    {
        $status = strtoupper((string) $approval->status);
        if (! in_array($status, [AdminApproval::STATUS_APPROVED, AdminApproval::STATUS_FAILED], true)) {
            return false;
        }

        $payload = is_array($approval->payload_json) ? $approval->payload_json : [];

        return ! array_key_exists(self::METADATA_KEY, $payload);
    }

    public function surfaceIdFor(string $type): string
    {
        $type = strtoupper(trim($type));
        $surfaceId = self::TYPE_SURFACES[$type] ?? null;
        if ($surfaceId === null) {
            throw new HighRiskApprovalValidationException('Approval type is not registered for high-risk governance.');
        }

        return $surfaceId;
    }

    public function targetFingerprint(AdminApproval $approval): string
    {
        return hash('sha256', $this->canonicalizer->encode([
            'approval_id' => (string) $approval->id,
            'correlation_id' => (string) $approval->correlation_id,
            'org_id' => (int) $approval->org_id,
            'payload' => $this->businessPayload($approval),
            'reason' => trim((string) $approval->reason),
            'requested_by_admin_user_id' => (int) ($approval->requested_by_admin_user_id ?? 0),
            'surface_id' => $this->surfaceIdFor((string) $approval->type),
            'type' => strtoupper(trim((string) $approval->type)),
        ]));
    }

    /** @return array<string, mixed> */
    private function evidence(AdminApproval $approval, int $actorAdminUserId, Carbon $approvedAt): array
    {
        $metadata = [
            'schema_version' => self::SCHEMA_VERSION,
            'review_mode' => (string) config('review_governance.mode'),
            'surface_id' => $this->surfaceIdFor((string) $approval->type),
            'target_fingerprint_sha256' => $this->targetFingerprint($approval),
            'reason_sha256' => hash('sha256', trim((string) $approval->reason)),
            'correlation_id' => (string) $approval->correlation_id,
            'requested_by_admin_user_id' => (int) $approval->requested_by_admin_user_id,
            'approved_by_admin_user_id' => $actorAdminUserId,
            'step_up_verified' => true,
            'approved_at' => $approvedAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
        $metadata['evidence_sha256'] = hash('sha256', $this->canonicalizer->encode($metadata));

        return $metadata;
    }

    public function assertFreshStepUp(int $actorAdminUserId, string $freshStepUpCode): void
    {
        if ($actorAdminUserId <= 0) {
            throw new HighRiskApprovalValidationException('Current MFA/TOTP step-up verification is required.');
        }

        $actor = AdminUser::query()->find($actorAdminUserId);
        if (! $actor) {
            throw new HighRiskApprovalValidationException('Approval actor was not found.');
        }
        if ($actor->totp_enabled_at === null || ! $this->adminTotp->verify($actor, $freshStepUpCode)) {
            throw new HighRiskApprovalValidationException('Current MFA/TOTP step-up verification is required.');
        }
    }

    private function assertApprovalInputs(AdminApproval $approval): void
    {
        $this->surfaceIdFor((string) $approval->type);
        if ((int) ($approval->requested_by_admin_user_id ?? 0) <= 0
            || trim((string) $approval->reason) === ''
            || ! Str::isUuid((string) $approval->correlation_id)) {
            throw new HighRiskApprovalValidationException('Approval requester, reason, or correlation ID is invalid.');
        }
        if (AdminApproval::reasonContainsCredential((string) $approval->reason)) {
            throw new HighRiskApprovalValidationException('Approval reason must not contain credentials or secret material.');
        }
    }

    private function assertApproverPolicy(AdminApproval $approval, int $actorAdminUserId): void
    {
        $mode = (string) config('review_governance.mode');
        $requester = (int) ($approval->requested_by_admin_user_id ?? 0);
        if ($mode === 'solo_owner') {
            $owner = (int) config('review_governance.solo_owner_admin_user_id');
            if ($owner <= 0 || $actorAdminUserId !== $owner) {
                throw new HighRiskApprovalValidationException('Approval actor is not the configured solo owner.');
            }

            return;
        }
        if ($mode === 'team_separated') {
            if ($actorAdminUserId <= 0 || $actorAdminUserId === $requester) {
                throw new HighRiskApprovalValidationException('Team-separated approval requires a distinct approver.');
            }

            return;
        }

        throw new HighRiskApprovalValidationException('Review governance mode is not supported for high-risk approval.');
    }

    private function assertExecutionActorPolicy(int $actorAdminUserId): void
    {
        $mode = (string) config('review_governance.mode');
        if ($mode === 'solo_owner') {
            $owner = (int) config('review_governance.solo_owner_admin_user_id');
            if ($owner <= 0 || $actorAdminUserId !== $owner) {
                throw new HighRiskApprovalValidationException('Execution actor is not the configured solo owner.');
            }

            return;
        }
        if ($mode === 'team_separated') {
            if ($actorAdminUserId <= 0) {
                throw new HighRiskApprovalValidationException('Team-separated execution requires a valid administrator.');
            }

            return;
        }

        throw new HighRiskApprovalValidationException('Review governance mode is not supported for high-risk execution.');
    }

    /** @return array<string, mixed> */
    private function businessPayload(AdminApproval $approval): array
    {
        $payload = is_array($approval->payload_json) ? $approval->payload_json : [];
        unset($payload[self::METADATA_KEY], $payload[self::EXECUTION_METADATA_KEY]);

        return $payload;
    }

    private function hasExecutionAuthorization(AdminApproval $approval): bool
    {
        $payload = is_array($approval->payload_json) ? $approval->payload_json : [];

        return array_key_exists(self::EXECUTION_METADATA_KEY, $payload);
    }

    private function bindLegacyGovernanceOnLockedApproval(AdminApproval $approval, int $actorAdminUserId): AdminApproval
    {
        if ((int) $approval->approved_by_admin_user_id !== $actorAdminUserId) {
            throw new HighRiskApprovalValidationException('Approval actor does not match the legacy approval record.');
        }
        $approvedAt = $approval->approved_at;
        if (! $approvedAt instanceof Carbon) {
            throw new HighRiskApprovalValidationException('Legacy approval timestamp is missing.');
        }

        $metadata = $this->evidence($approval, $actorAdminUserId, $approvedAt);
        $payload = $this->businessPayload($approval);
        $payload[self::METADATA_KEY] = $metadata;
        $approval->payload_json = $payload;
        $approval->save();

        $this->writeApprovalAudit($approval, $metadata, 'approval_governance_rebound');
        $approval->refresh();
        $this->assertExecutable($approval);

        return $approval;
    }

    /** @param array<string, mixed> $metadata */
    private function writeApprovalAudit(
        AdminApproval $approval,
        array $metadata,
        string $action = 'approval_approved',
    ): void {
        $request = request();
        DB::table('audit_logs')->insert([
            'org_id' => (int) $approval->org_id,
            'actor_admin_id' => (int) $approval->approved_by_admin_user_id,
            'action' => $action,
            'target_type' => 'AdminApproval',
            'target_id' => (string) $approval->id,
            'meta_json' => json_encode([
                'actor' => (int) $approval->approved_by_admin_user_id,
                'org_id' => (int) $approval->org_id,
                'surface_id' => (string) $metadata['surface_id'],
                'correlation_id' => (string) $approval->correlation_id,
                'target_fingerprint_sha256' => (string) $metadata['target_fingerprint_sha256'],
                'evidence_sha256' => (string) $metadata['evidence_sha256'],
                'review_mode' => (string) $metadata['review_mode'],
                'step_up_verified' => true,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'request_id' => (string) ($request->headers->get('X-Request-ID') ?: $request->attributes->get('request_id') ?: ''),
            'reason' => $action === 'approval_governance_rebound'
                ? 'legacy high-risk approval governance evidence rebound'
                : 'high-risk approval evidence recorded',
            'result' => 'approved',
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function writeExecutionAuthorizationAudit(
        AdminApproval $approval,
        int $actorAdminUserId,
        array $metadata,
    ): void {
        DB::table('audit_logs')->insert([
            'org_id' => (int) $approval->org_id,
            'actor_admin_id' => $actorAdminUserId,
            'action' => 'approval_execution_authorized',
            'target_type' => 'AdminApproval',
            'target_id' => (string) $approval->id,
            'meta_json' => json_encode([
                'actor' => $actorAdminUserId,
                'org_id' => (int) $approval->org_id,
                'correlation_id' => (string) $approval->correlation_id,
                'execution_attempt' => (int) $metadata['execution_attempt'],
                'approval_evidence_sha256' => (string) $metadata['approval_evidence_sha256'],
                'authorization_sha256' => (string) $metadata['authorization_sha256'],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
            'request_id' => (string) request()->headers->get('X-Request-Id', ''),
            'reason' => 'high-risk approval execution authorized after fresh step-up',
            'result' => 'authorized',
            'created_at' => now(),
        ]);
    }
}
