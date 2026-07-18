<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Models\AdminApproval;
use App\Models\AdminUser;
use App\Services\Approvals\ApprovalExecutor;
use App\Services\Approvals\HighRiskApprovalService;
use App\Services\Approvals\HighRiskApprovalValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

final class HighRiskApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_solo_owner_can_self_approve_with_step_up_without_executing(): void
    {
        Queue::fake();
        $owner = $this->admin(totpEnabled: true);
        $this->soloOwner($owner);
        $approval = $this->approval(AdminApproval::TYPE_REFUND, $owner, [
            'order_no' => 'ord-safe-target',
        ]);

        $service = app(HighRiskApprovalService::class);
        $approved = $service->approve((string) $approval->id, (int) $owner->id, (int) $owner->id);

        $this->assertSame(AdminApproval::STATUS_APPROVED, (string) $approved->status);
        $this->assertSame((int) $owner->id, (int) $approved->approved_by_admin_user_id);
        $metadata = (array) data_get($approved->payload_json, HighRiskApprovalService::METADATA_KEY, []);
        $this->assertSame(HighRiskApprovalService::SCHEMA_VERSION, $metadata['schema_version'] ?? null);
        $this->assertSame('refund_approval', $metadata['surface_id'] ?? null);
        $this->assertTrue((bool) ($metadata['step_up_verified'] ?? false));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) ($metadata['target_fingerprint_sha256'] ?? ''));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) ($metadata['evidence_sha256'] ?? ''));
        $this->assertNull($approved->executed_at);
        $this->assertSame(0, (int) $approved->retry_count);
        Queue::assertNothingPushed();

        $payload = (array) $approved->payload_json;
        $reorderedMetadata = (array) $payload[HighRiskApprovalService::METADATA_KEY];
        ksort($reorderedMetadata, SORT_STRING);
        $payload[HighRiskApprovalService::METADATA_KEY] = $reorderedMetadata;
        $approved->forceFill(['payload_json' => $payload])->save();

        $again = $service->approve((string) $approval->id, (int) $owner->id, (int) $owner->id);
        $this->assertSame((string) $approved->id, (string) $again->id);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('target_type', 'AdminApproval')
            ->where('target_id', (string) $approval->id)
            ->where('action', 'approval_approved')
            ->count());
    }

    public function test_team_separated_mode_rejects_self_approval_but_accepts_distinct_step_up_actor(): void
    {
        config()->set('admin.totp.enabled', false);
        config()->set('review_governance.mode', 'team_separated');
        $requester = $this->admin();
        $reviewer = $this->admin();
        $approval = $this->approval(AdminApproval::TYPE_MANUAL_GRANT, $requester, [
            'order_no' => 'ord-team-separated',
            'attempt_id' => (string) Str::uuid(),
        ]);

        $service = app(HighRiskApprovalService::class);
        try {
            $service->approve((string) $approval->id, (int) $requester->id, (int) $requester->id);
            $this->fail('Expected team-separated self approval to fail.');
        } catch (HighRiskApprovalValidationException $exception) {
            $this->assertSame('Team-separated approval requires a distinct approver.', $exception->getMessage());
        }

        $this->assertSame(AdminApproval::STATUS_PENDING, (string) $approval->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'target_type' => 'AdminApproval',
            'target_id' => (string) $approval->id,
            'action' => 'approval_approved',
        ]);

        $approved = $service->approve((string) $approval->id, (int) $reviewer->id, (int) $reviewer->id);
        $this->assertSame((int) $requester->id, (int) $approved->requested_by_admin_user_id);
        $this->assertSame((int) $reviewer->id, (int) $approved->approved_by_admin_user_id);
        $service->assertExecutable($approved);
    }

    public function test_missing_step_up_non_owner_and_target_drift_fail_closed_without_action(): void
    {
        Queue::fake();
        $owner = $this->admin(totpEnabled: true);
        $other = $this->admin(totpEnabled: true);
        $this->soloOwner($owner);
        $approval = $this->approval(AdminApproval::TYPE_REPROCESS_EVENT, $owner, [
            'payment_event_id' => (string) Str::uuid(),
            'order_no' => 'ord-fingerprint',
        ]);
        $service = app(HighRiskApprovalService::class);

        foreach ([
            [(int) $owner->id, 0],
            [(int) $other->id, (int) $other->id],
        ] as [$actorId, $stepUpId]) {
            try {
                $service->approve((string) $approval->id, $actorId, $stepUpId);
                $this->fail('Expected high-risk approval validation to fail.');
            } catch (HighRiskApprovalValidationException $exception) {
                $this->assertStringNotContainsString((string) $approval->correlation_id, $exception->getMessage());
            }
            $this->assertSame(AdminApproval::STATUS_PENDING, (string) $approval->fresh()->status);
        }

        $privateCredential = Str::random(40);
        $safeReason = (string) $approval->reason;
        foreach (['token=', 'access_token=', 'cookie=', 'accessToken=', 'clientSecret=', 'apiKey='] as $credentialPrefix) {
            try {
                $approval->forceFill(['reason' => $credentialPrefix.$privateCredential])->save();
                $this->fail('Expected request creation boundary to reject credential-bearing reason.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringNotContainsString($privateCredential, $exception->getMessage());
            }
            $approval->refresh();
            $this->assertSame($safeReason, (string) $approval->reason);

            DB::table('admin_approvals')->where('id', (string) $approval->id)->update([
                'reason' => $credentialPrefix.$privateCredential,
            ]);
            $approval->refresh();
            try {
                $service->approve((string) $approval->id, (int) $owner->id, (int) $owner->id);
                $this->fail('Expected credential-bearing reason to fail.');
            } catch (HighRiskApprovalValidationException $exception) {
                $this->assertStringNotContainsString($privateCredential, $exception->getMessage());
            }
            $this->assertSame(AdminApproval::STATUS_PENDING, (string) $approval->fresh()->status);
            DB::table('admin_approvals')->where('id', (string) $approval->id)->update(['reason' => $safeReason]);
            $approval->refresh();
        }

        $service->approve((string) $approval->id, (int) $owner->id, (int) $owner->id);
        $payload = (array) $approval->fresh()->payload_json;
        $payload['payment_event_id'] = (string) Str::uuid();
        $approval->forceFill(['payload_json' => $payload])->save();

        $result = app(ApprovalExecutor::class)->execute((string) $approval->id);
        $this->assertFalse($result->ok);
        $this->assertSame('APPROVAL_GOVERNANCE_INVALID', $result->code);
        $this->assertSame('approval governance validation failed.', $result->message);
        $this->assertSame(AdminApproval::STATUS_APPROVED, (string) $approval->fresh()->status);
        $this->assertSame(0, (int) $approval->fresh()->retry_count);
        Queue::assertNothingPushed();
    }

    public function test_legacy_approved_and_failed_rows_can_bind_missing_governance_with_step_up_only(): void
    {
        Queue::fake();
        $owner = $this->admin(totpEnabled: true);
        $this->soloOwner($owner);
        $service = app(HighRiskApprovalService::class);

        foreach ([AdminApproval::STATUS_APPROVED, AdminApproval::STATUS_FAILED] as $status) {
            $approval = $this->approval(AdminApproval::TYPE_REFUND, $owner, [
                'order_no' => 'legacy-'.strtolower($status),
            ]);
            DB::table('admin_approvals')->where('id', (string) $approval->id)->update([
                'status' => $status,
                'approved_by_admin_user_id' => (int) $owner->id,
                'approved_at' => now()->subMinute()->startOfSecond(),
            ]);
            $approval->refresh();

            $this->assertTrue($service->requiresLegacyGovernanceBinding($approval));
            $this->assertFalse($service->hasValidGovernanceEvidence($approval));

            try {
                $service->bindLegacyGovernance((string) $approval->id, (int) $owner->id, 0);
                $this->fail('Expected current step-up to be required for legacy binding.');
            } catch (HighRiskApprovalValidationException) {
                $this->assertDatabaseMissing('audit_logs', [
                    'target_id' => (string) $approval->id,
                    'action' => 'approval_governance_rebound',
                ]);
            }

            $bound = $status === AdminApproval::STATUS_APPROVED
                ? $service->approve((string) $approval->id, (int) $owner->id, (int) $owner->id)
                : $service->bindLegacyGovernance((string) $approval->id, (int) $owner->id, (int) $owner->id);

            $this->assertSame($status, (string) $bound->status);
            $this->assertFalse($service->requiresLegacyGovernanceBinding($bound));
            $this->assertTrue($service->hasValidGovernanceEvidence($bound));
            $this->assertDatabaseHas('audit_logs', [
                'target_id' => (string) $approval->id,
                'action' => 'approval_governance_rebound',
                'reason' => 'legacy high-risk approval governance evidence rebound',
            ]);
        }

        $malformed = $this->approval(AdminApproval::TYPE_REFUND, $owner, ['order_no' => 'legacy-malformed']);
        DB::table('admin_approvals')->where('id', (string) $malformed->id)->update([
            'status' => AdminApproval::STATUS_APPROVED,
            'approved_by_admin_user_id' => (int) $owner->id,
            'approved_at' => now()->subMinute()->startOfSecond(),
            'payload_json' => json_encode([
                'order_no' => 'legacy-malformed',
                HighRiskApprovalService::METADATA_KEY => null,
            ], JSON_THROW_ON_ERROR),
        ]);
        $malformed->refresh();
        $this->assertFalse($service->requiresLegacyGovernanceBinding($malformed));

        try {
            $service->bindLegacyGovernance(
                (string) $malformed->id,
                (int) $owner->id,
                (int) $owner->id,
            );
            $this->fail('Expected malformed existing governance evidence to remain fail closed.');
        } catch (HighRiskApprovalValidationException) {
            $this->assertFalse($service->hasValidGovernanceEvidence($malformed->fresh()));
        }

        Queue::assertNothingPushed();
    }

    public function test_all_registered_r3_operation_types_bind_private_hash_only_evidence(): void
    {
        Queue::fake();
        $owner = $this->admin(totpEnabled: true);
        $this->soloOwner($owner);
        $service = app(HighRiskApprovalService::class);
        $types = [
            AdminApproval::TYPE_MANUAL_GRANT,
            AdminApproval::TYPE_REVOKE_BENEFIT,
            AdminApproval::TYPE_REFUND,
            AdminApproval::TYPE_REPROCESS_EVENT,
            AdminApproval::TYPE_ROLLBACK_RELEASE,
            AdminApproval::TYPE_DATA_LIFECYCLE,
        ];

        foreach ($types as $type) {
            $privateValue = Str::random(48);
            $approval = $this->approval($type, $owner, [
                'target_id' => (string) Str::uuid(),
                'operator_private_value' => $privateValue,
            ]);
            $approved = $service->approve((string) $approval->id, (int) $owner->id, (int) $owner->id);
            $service->assertExecutable($approved);

            $audit = DB::table('audit_logs')
                ->where('target_type', 'AdminApproval')
                ->where('target_id', (string) $approval->id)
                ->where('action', 'approval_approved')
                ->first();
            $this->assertNotNull($audit);
            $this->assertStringNotContainsString($privateValue, (string) $audit->meta_json);
            $this->assertStringNotContainsString($privateValue, (string) $audit->reason);
        }

        $this->assertSame([
            'manual_benefit_grant_approval',
            'benefit_revoke_approval',
            'refund_approval',
            'payment_event_reprocess_approval',
            'rollback_release_approval',
            'data_lifecycle_approval',
        ], $service->supportedSurfaceIds());

        $dataLifecycle = AdminApproval::query()
            ->where('type', AdminApproval::TYPE_DATA_LIFECYCLE)
            ->firstOrFail();
        $this->assertFalse($service->executionSupported($dataLifecycle));
        $execution = app(ApprovalExecutor::class)->execute((string) $dataLifecycle->id);
        $this->assertFalse($execution->ok);
        $this->assertSame('APPROVAL_EXECUTION_ADAPTER_MISSING', $execution->code);
        $this->assertSame(AdminApproval::STATUS_APPROVED, (string) $dataLifecycle->fresh()->status);
        $this->assertSame(0, (int) $dataLifecycle->fresh()->retry_count);
        Queue::assertNothingPushed();
    }

    public function test_executor_redacts_credentials_from_errors_and_audit_output(): void
    {
        $executor = app(ApprovalExecutor::class);
        $privateValue = Str::random(48);
        $accessToken = Str::random(48);
        $cookie = Str::random(48);
        $accessTokenCamel = Str::random(48);
        $clientSecret = Str::random(48);
        $apiKey = Str::random(48);
        $sanitize = new ReflectionMethod($executor, 'sanitizeErrorMessage');
        $sanitize->setAccessible(true);
        $message = (string) $sanitize->invoke(
            $executor,
            'provider failed token='.$privateValue.' access_token='.$accessToken.' cookie='.$cookie.' accessToken='.$accessTokenCamel.' clientSecret='.$clientSecret.' apiKey='.$apiKey.' Authorization: Bearer '.$privateValue,
        );
        $this->assertStringNotContainsString($privateValue, $message);
        $this->assertStringNotContainsString($accessToken, $message);
        $this->assertStringNotContainsString($cookie, $message);
        $this->assertStringNotContainsString($accessTokenCamel, $message);
        $this->assertStringNotContainsString($clientSecret, $message);
        $this->assertStringNotContainsString($apiKey, $message);
        $this->assertStringContainsString('[REDACTED]', $message);

        $writeAudit = new ReflectionMethod($executor, 'writeAudit');
        $writeAudit->setAccessible(true);
        $writeAudit->invoke(
            $executor,
            0,
            null,
            'approval_executed_failed',
            (string) Str::uuid(),
            'clientSecret='.$clientSecret,
            (string) Str::uuid(),
            [
                'totp' => $privateValue,
                'nested' => [
                    'api_key' => $privateValue,
                    'access_token' => $accessToken,
                    'cookie' => $cookie,
                    'provider_error' => 'apiKey='.$apiKey,
                ],
            ],
        );

        $audit = DB::table('audit_logs')->where('action', 'approval_executed_failed')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString($privateValue, (string) $audit->meta_json);
        $this->assertStringNotContainsString($accessToken, (string) $audit->meta_json);
        $this->assertStringNotContainsString($cookie, (string) $audit->meta_json);
        $this->assertStringNotContainsString($apiKey, (string) $audit->meta_json);
        $this->assertStringNotContainsString($clientSecret, (string) $audit->meta_json);
        $this->assertStringNotContainsString($privateValue, (string) $audit->reason);
        $this->assertStringNotContainsString($clientSecret, (string) $audit->reason);
        $this->assertStringContainsString('[REDACTED]', (string) $audit->meta_json);
    }

    private function soloOwner(AdminUser $owner): void
    {
        config()->set('admin.totp.enabled', true);
        config()->set('review_governance.mode', 'solo_owner');
        config()->set('review_governance.solo_owner_admin_user_id', (int) $owner->id);
    }

    private function admin(bool $totpEnabled = false): AdminUser
    {
        return AdminUser::query()->create([
            'name' => 'approval-'.Str::random(8),
            'email' => 'approval-'.Str::random(10).'@example.test',
            'password' => 'not-used-by-this-test',
            'is_active' => 1,
            'totp_enabled_at' => $totpEnabled ? now() : null,
            'totp_secret' => $totpEnabled ? Str::random(32) : null,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function approval(string $type, AdminUser $requester, array $payload): AdminApproval
    {
        return AdminApproval::query()->create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'type' => $type,
            'status' => AdminApproval::STATUS_PENDING,
            'requested_by_admin_user_id' => (int) $requester->id,
            'reason' => 'operator confirmed exact high-risk target',
            'payload_json' => $payload,
            'correlation_id' => (string) Str::uuid(),
        ]);
    }
}
