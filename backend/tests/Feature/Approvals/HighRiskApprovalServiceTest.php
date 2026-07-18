<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Filament\Ops\Resources\AdminApprovalResource;
use App\Models\AdminApproval;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Approvals\ApprovalExecutor;
use App\Services\Approvals\HighRiskApprovalService;
use App\Services\Approvals\HighRiskApprovalValidationException;
use App\Services\Auth\AdminTotpService;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

final class HighRiskApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_solo_owner_can_self_approve_with_step_up_without_executing(): void
    {
        Queue::fake();
        $owner = $this->admin(
            totpEnabled: true,
            permissions: [
                PermissionNames::ADMIN_FINANCE_WRITE,
                PermissionNames::ADMIN_OPS_WRITE,
            ],
        );
        $this->soloOwner($owner);
        $approval = $this->approval(AdminApproval::TYPE_REFUND, $owner, [
            'order_no' => 'ord-safe-target',
        ]);

        $service = app(HighRiskApprovalService::class);
        $freshStepUpCode = $this->freshStepUpCode($owner);
        $request = Request::create('/', 'POST');
        $request->headers->set('User-Agent', str_repeat('a', 400));
        $this->app->instance('request', $request);
        $this->app->instance(Request::class, $request);
        $approved = $service->approve((string) $approval->id, (int) $owner->id, $freshStepUpCode);

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
        $approvalAudit = DB::table('audit_logs')
            ->where('target_id', (string) $approval->id)
            ->where('action', 'approval_approved')
            ->first();
        $this->assertNotNull($approvalAudit);
        $this->assertSame(255, mb_strlen((string) $approvalAudit->user_agent));

        $unauthorizedExecution = app(ApprovalExecutor::class)->execute((string) $approval->id);
        $this->assertFalse($unauthorizedExecution->ok);
        $this->assertSame('APPROVAL_EXECUTION_AUTHORIZATION_INVALID', $unauthorizedExecution->code);
        $this->assertSame(AdminApproval::STATUS_APPROVED, (string) $approval->fresh()->status);

        $executionAuthorized = $service->authorizeExecution(
            (string) $approval->id,
            (int) $owner->id,
            $freshStepUpCode,
        );
        $this->assertSame((int) $owner->id, (int) $service->executionActor($executionAuthorized)->id);
        $executionMetadata = (array) data_get(
            $executionAuthorized->payload_json,
            HighRiskApprovalService::EXECUTION_METADATA_KEY,
            [],
        );
        $this->assertSame(1, $executionMetadata['execution_attempt'] ?? null);
        $this->assertArrayNotHasKey('fresh_step_up_code', $executionMetadata);
        $this->assertDatabaseHas('audit_logs', [
            'target_id' => (string) $approval->id,
            'actor_admin_id' => (int) $owner->id,
            'action' => 'approval_execution_authorized',
        ]);

        $payload = (array) $executionAuthorized->payload_json;
        $reorderedMetadata = (array) $payload[HighRiskApprovalService::METADATA_KEY];
        ksort($reorderedMetadata, SORT_STRING);
        $payload[HighRiskApprovalService::METADATA_KEY] = $reorderedMetadata;
        $approved->forceFill(['payload_json' => $payload])->save();

        $again = $service->approve((string) $approval->id, (int) $owner->id, $freshStepUpCode);
        $this->assertSame((string) $approved->id, (string) $again->id);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('target_type', 'AdminApproval')
            ->where('target_id', (string) $approval->id)
            ->where('action', 'approval_approved')
            ->count());
    }

    public function test_configured_solo_owner_can_reach_review_ui_without_review_permission(): void
    {
        $owner = $this->admin(totpEnabled: true);
        $domainOperator = $this->admin(
            totpEnabled: true,
            permissions: [PermissionNames::ADMIN_OPS_WRITE],
        );
        $this->soloOwner($owner);
        $approval = $this->approval(AdminApproval::TYPE_REPROCESS_EVENT, $owner, [
            'payment_event_id' => (string) Str::uuid(),
            'order_no' => 'ord-solo-owner-review-ui',
        ]);
        app(HighRiskApprovalService::class)->approve(
            (string) $approval->id,
            (int) $owner->id,
            $this->freshStepUpCode($owner),
        );
        $this->actingAs($owner, (string) config('admin.guard', 'admin'));

        $canReview = new ReflectionMethod(AdminApprovalResource::class, 'canReview');
        $canReview->setAccessible(true);
        $executableTypes = new ReflectionMethod(AdminApprovalResource::class, 'executableTypesForCurrentAdmin');
        $executableTypes->setAccessible(true);

        $this->assertTrue($canReview->invoke(null));
        $this->assertTrue(AdminApprovalResource::canViewAny());

        $this->actingAs($domainOperator, (string) config('admin.guard', 'admin'));
        $this->assertFalse($canReview->invoke(null));
        $this->assertFalse(AdminApprovalResource::canViewAny());
        $this->assertSame([], $executableTypes->invoke(null));

        config()->set('review_governance.mode', 'team_separated');
        $this->assertTrue(AdminApprovalResource::canViewAny());
        $this->assertSame(
            [AdminApproval::TYPE_MANUAL_GRANT, AdminApproval::TYPE_REVOKE_BENEFIT, AdminApproval::TYPE_REPROCESS_EVENT],
            $executableTypes->invoke(null),
        );

        $this->actingAs($owner, (string) config('admin.guard', 'admin'));
        $this->assertFalse($canReview->invoke(null));
        $this->assertFalse(AdminApprovalResource::canViewAny());
    }

    public function test_team_separated_mode_rejects_self_approval_but_accepts_distinct_step_up_actor(): void
    {
        config()->set('review_governance.mode', 'team_separated');
        $requester = $this->admin(totpEnabled: true);
        $reviewer = $this->admin(
            totpEnabled: true,
            permissions: [PermissionNames::ADMIN_APPROVAL_REVIEW],
        );
        $approval = $this->approval(AdminApproval::TYPE_MANUAL_GRANT, $requester, [
            'order_no' => 'ord-team-separated',
            'attempt_id' => (string) Str::uuid(),
        ]);

        $service = app(HighRiskApprovalService::class);
        try {
            $service->approve((string) $approval->id, (int) $requester->id, $this->freshStepUpCode($requester));
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

        $approved = $service->approve((string) $approval->id, (int) $reviewer->id, $this->freshStepUpCode($reviewer));
        $this->assertSame((int) $requester->id, (int) $approved->requested_by_admin_user_id);
        $this->assertSame((int) $reviewer->id, (int) $approved->approved_by_admin_user_id);
        $service->assertExecutable($approved);
    }

    public function test_team_separated_service_boundary_rejects_distinct_admin_without_review_permission(): void
    {
        config()->set('review_governance.mode', 'team_separated');
        $requester = $this->admin(totpEnabled: true);
        $nonReviewer = $this->admin(totpEnabled: true);
        $service = app(HighRiskApprovalService::class);

        $approve = $this->approval(AdminApproval::TYPE_MANUAL_GRANT, $requester, [
            'order_no' => 'ord-non-reviewer-approve',
        ]);
        try {
            $service->approve((string) $approve->id, (int) $nonReviewer->id, $this->freshStepUpCode($nonReviewer));
            $this->fail('Expected a distinct non-review admin to be rejected during approval.');
        } catch (HighRiskApprovalValidationException $exception) {
            $this->assertSame('Team-separated approver lacks approval review permission.', $exception->getMessage());
        }
        $this->assertSame(AdminApproval::STATUS_PENDING, (string) $approve->fresh()->status);

        $reject = $this->approval(AdminApproval::TYPE_REFUND, $requester, [
            'order_no' => 'ord-non-reviewer-reject',
        ]);
        try {
            $service->reject(
                (string) $reject->id,
                (int) $nonReviewer->id,
                $this->freshStepUpCode($nonReviewer),
                'not authorized to review',
            );
            $this->fail('Expected a distinct non-review admin to be rejected during rejection.');
        } catch (HighRiskApprovalValidationException $exception) {
            $this->assertSame('Team-separated approver lacks approval review permission.', $exception->getMessage());
        }
        $this->assertSame(AdminApproval::STATUS_PENDING, (string) $reject->fresh()->status);

        $legacy = $this->approval(AdminApproval::TYPE_REPROCESS_EVENT, $requester, [
            'payment_event_id' => (string) Str::uuid(),
        ]);
        DB::table('admin_approvals')->where('id', (string) $legacy->id)->update([
            'status' => AdminApproval::STATUS_APPROVED,
            'approved_by_admin_user_id' => (int) $nonReviewer->id,
            'approved_at' => now()->subMinute()->startOfSecond(),
        ]);
        try {
            $service->bindLegacyGovernance(
                (string) $legacy->id,
                (int) $nonReviewer->id,
                $this->freshStepUpCode($nonReviewer),
            );
            $this->fail('Expected a distinct non-review admin to be rejected during legacy binding.');
        } catch (HighRiskApprovalValidationException $exception) {
            $this->assertSame('Team-separated approver lacks approval review permission.', $exception->getMessage());
        }
        $this->assertTrue($service->requiresLegacyGovernanceBinding($legacy->fresh()));
        $this->assertDatabaseMissing('audit_logs', [
            'target_id' => (string) $legacy->id,
            'action' => 'approval_governance_rebound',
        ]);
    }

    public function test_team_separated_domain_executor_can_reach_execute_action_without_review_permission(): void
    {
        config()->set('review_governance.mode', 'team_separated');
        $requester = $this->admin(totpEnabled: true);
        $reviewer = $this->admin(
            totpEnabled: true,
            permissions: [PermissionNames::ADMIN_APPROVAL_REVIEW],
        );
        $executor = $this->admin(
            totpEnabled: true,
            permissions: [
                PermissionNames::ADMIN_FINANCE_WRITE,
                PermissionNames::ADMIN_OPS_WRITE,
            ],
        );
        $approval = $this->approval(AdminApproval::TYPE_REFUND, $requester, [
            'order_no' => 'ord-separated-executor-ui',
        ]);
        $service = app(HighRiskApprovalService::class);
        $approved = $service->approve(
            (string) $approval->id,
            (int) $reviewer->id,
            $this->freshStepUpCode($reviewer),
        );

        $canExecute = new ReflectionMethod(AdminApprovalResource::class, 'canExecute');
        $canExecute->setAccessible(true);

        $this->actingAs($reviewer, (string) config('admin.guard', 'admin'));
        $this->assertFalse($canExecute->invoke(null, $approved));

        $this->actingAs($executor, (string) config('admin.guard', 'admin'));
        $this->assertTrue(AdminApprovalResource::canViewAny());
        $this->assertTrue($canExecute->invoke(null, $approved));
        $this->assertTrue($service->canAuthorizeExecution($approved, (int) $executor->id));

        $this->actingAs($requester, (string) config('admin.guard', 'admin'));
        $this->assertFalse(AdminApprovalResource::canViewAny());
        $this->assertFalse($canExecute->invoke(null, $approved));
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
        session(['ops_admin_totp_verified_user_id' => (int) $owner->id]);

        config()->set('admin.totp.enabled', false);
        try {
            $service->approve((string) $approval->id, (int) $owner->id, '');
            $this->fail('Expected the global TOTP switch not to bypass R3 step-up.');
        } catch (HighRiskApprovalValidationException) {
            $this->assertSame(AdminApproval::STATUS_PENDING, (string) $approval->fresh()->status);
        } finally {
            config()->set('admin.totp.enabled', true);
        }

        foreach ([
            [(int) $owner->id, ''],
            [(int) $other->id, $this->freshStepUpCode($other)],
        ] as [$actorId, $freshStepUpCode]) {
            try {
                $service->approve((string) $approval->id, $actorId, $freshStepUpCode);
                $this->fail('Expected high-risk approval validation to fail.');
            } catch (HighRiskApprovalValidationException $exception) {
                $this->assertStringNotContainsString((string) $approval->correlation_id, $exception->getMessage());
            }
            $this->assertSame(AdminApproval::STATUS_PENDING, (string) $approval->fresh()->status);
        }

        $privateCredential = Str::random(40);
        $safeReason = (string) $approval->reason;
        $credentialReasons = array_map(
            static fn (string $prefix): string => $prefix.$privateCredential,
            ['token=', 'access_token=', 'cookie=', 'accessToken=', 'clientSecret=', 'apiKey=', 'Bearer '],
        );
        $credentialReasons[] = '{"access_token":"'.$privateCredential.'"}';
        $credentialReasons[] = "{'clientSecret':'".$privateCredential."'}";

        foreach ($credentialReasons as $credentialReason) {
            try {
                $approval->forceFill(['reason' => $credentialReason])->save();
                $this->fail('Expected request creation boundary to reject credential-bearing reason.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringNotContainsString($privateCredential, $exception->getMessage());
            }
            $approval->refresh();
            $this->assertSame($safeReason, (string) $approval->reason);

            DB::table('admin_approvals')->where('id', (string) $approval->id)->update([
                'reason' => $credentialReason,
            ]);
            $approval->refresh();
            try {
                $service->approve((string) $approval->id, (int) $owner->id, $this->freshStepUpCode($owner));
                $this->fail('Expected credential-bearing reason to fail.');
            } catch (HighRiskApprovalValidationException $exception) {
                $this->assertStringNotContainsString($privateCredential, $exception->getMessage());
            }
            $this->assertSame(AdminApproval::STATUS_PENDING, (string) $approval->fresh()->status);
            DB::table('admin_approvals')->where('id', (string) $approval->id)->update(['reason' => $safeReason]);
            $approval->refresh();
        }

        $service->approve((string) $approval->id, (int) $owner->id, $this->freshStepUpCode($owner));
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
                $service->bindLegacyGovernance((string) $approval->id, (int) $owner->id, '');
                $this->fail('Expected current step-up to be required for legacy binding.');
            } catch (HighRiskApprovalValidationException) {
                $this->assertDatabaseMissing('audit_logs', [
                    'target_id' => (string) $approval->id,
                    'action' => 'approval_governance_rebound',
                ]);
            }

            $bound = $status === AdminApproval::STATUS_APPROVED
                ? $service->approve((string) $approval->id, (int) $owner->id, $this->freshStepUpCode($owner))
                : $service->bindLegacyGovernance((string) $approval->id, (int) $owner->id, $this->freshStepUpCode($owner));

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
                $this->freshStepUpCode($owner),
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
            $approved = $service->approve((string) $approval->id, (int) $owner->id, $this->freshStepUpCode($owner));
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
        $bearerToken = Str::random(48);
        $quotedAccessToken = Str::random(48);
        $quotedClientSecret = Str::random(48);
        $singleQuotedApiKey = Str::random(48);
        $basicAuthorization = base64_encode(Str::random(48));
        $sanitize = new ReflectionMethod($executor, 'sanitizeErrorMessage');
        $sanitize->setAccessible(true);
        $message = (string) $sanitize->invoke(
            $executor,
            'provider failed token='.$privateValue.' access_token='.$accessToken.' cookie='.$cookie.' accessToken='.$accessTokenCamel.' clientSecret='.$clientSecret.' apiKey='.$apiKey.' bare Bearer '.$bearerToken.' json {"access_token":"'.$quotedAccessToken.'","clientSecret":"'.$quotedClientSecret.'"} json-ish {\'apiKey\':\''.$singleQuotedApiKey.'\'} Authorization: Basic '.$basicAuthorization,
        );
        $this->assertStringNotContainsString($privateValue, $message);
        $this->assertStringNotContainsString($accessToken, $message);
        $this->assertStringNotContainsString($cookie, $message);
        $this->assertStringNotContainsString($accessTokenCamel, $message);
        $this->assertStringNotContainsString($clientSecret, $message);
        $this->assertStringNotContainsString($apiKey, $message);
        $this->assertStringNotContainsString($bearerToken, $message);
        $this->assertStringNotContainsString($quotedAccessToken, $message);
        $this->assertStringNotContainsString($quotedClientSecret, $message);
        $this->assertStringNotContainsString($singleQuotedApiKey, $message);
        $this->assertStringNotContainsString($basicAuthorization, $message);
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
                    'authorization_error' => 'Authorization: Basic '.$basicAuthorization,
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
        $this->assertStringNotContainsString($basicAuthorization, (string) $audit->meta_json);
        $this->assertStringNotContainsString($privateValue, (string) $audit->reason);
        $this->assertStringNotContainsString($clientSecret, (string) $audit->reason);
        $this->assertStringContainsString('[REDACTED]', (string) $audit->meta_json);
    }

    public function test_reject_requires_governance_actor_and_audit_does_not_capture_step_up_code(): void
    {
        $owner = $this->admin(totpEnabled: true);
        $other = $this->admin(totpEnabled: true);
        $this->soloOwner($owner);
        $approval = $this->approval(AdminApproval::TYPE_REFUND, $owner, [
            'order_no' => 'ord-reject-policy',
        ]);
        $service = app(HighRiskApprovalService::class);

        try {
            $service->reject(
                (string) $approval->id,
                (int) $other->id,
                $this->freshStepUpCode($other),
                'unauthorized rejection',
            );
            $this->fail('Expected solo-owner rejection policy to reject a non-owner actor.');
        } catch (HighRiskApprovalValidationException $exception) {
            $this->assertSame('Approval actor is not the configured solo owner.', $exception->getMessage());
        }
        $this->assertSame(AdminApproval::STATUS_PENDING, (string) $approval->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'target_id' => (string) $approval->id,
            'action' => 'approval_rejected',
        ]);

        $privateStepUpCode = $this->freshStepUpCode($owner);
        $request = Request::create('/', 'POST', [
            'fresh_step_up_code' => $privateStepUpCode,
            'reason_append' => 'safe rejection note',
        ]);
        $this->app->instance('request', $request);
        $this->app->instance(Request::class, $request);

        $rejected = $service->reject(
            (string) $approval->id,
            (int) $owner->id,
            $privateStepUpCode,
            'safe rejection note',
        );
        $this->assertSame(AdminApproval::STATUS_REJECTED, (string) $rejected->status);
        $this->assertSame((int) $owner->id, (int) $rejected->approved_by_admin_user_id);

        $audit = DB::table('audit_logs')
            ->where('target_id', (string) $approval->id)
            ->where('action', 'approval_rejected')
            ->first();

        $this->assertNotNull($audit);
        $this->assertStringNotContainsString($privateStepUpCode, (string) $audit->meta_json);
        $this->assertStringNotContainsString('fresh_step_up_code', (string) $audit->meta_json);
        $this->assertStringContainsString('safe rejection note', (string) $audit->meta_json);
    }

    public function test_recovery_code_remains_consumed_when_approval_policy_rejects_actor(): void
    {
        $owner = $this->admin(totpEnabled: true);
        $other = $this->admin();
        $this->soloOwner($owner);
        $recoveryCode = strtoupper(Str::random(10));
        app(AdminTotpService::class)->enableForUser(
            $other,
            'JBSWY3DPEHPK3PXP',
            [$recoveryCode],
        );
        $approval = $this->approval(AdminApproval::TYPE_REFUND, $owner, [
            'order_no' => 'ord-recovery-consumption',
        ]);
        $service = app(HighRiskApprovalService::class);
        $request = Request::create('/', 'POST', [
            'fresh_step_up_code' => $recoveryCode,
            'reason_append' => 'safe recovery step-up',
        ]);
        $this->app->instance('request', $request);
        $this->app->instance(Request::class, $request);

        try {
            $service->approve((string) $approval->id, (int) $other->id, $recoveryCode);
            $this->fail('Expected solo-owner approval policy to reject a non-owner actor.');
        } catch (HighRiskApprovalValidationException $exception) {
            $this->assertSame('Approval actor is not the configured solo owner.', $exception->getMessage());
        }

        $this->assertSame(AdminApproval::STATUS_PENDING, (string) $approval->fresh()->status);
        $this->assertNotNull(DB::table('admin_user_totp_recovery_codes')
            ->where('admin_user_id', (int) $other->id)
            ->where('code_hash', hash('sha256', $recoveryCode))
            ->value('used_at'));
        $this->assertDatabaseHas('audit_logs', [
            'target_type' => 'AdminUser',
            'target_id' => (string) $other->id,
            'action' => 'admin_totp_recovery_code_used',
        ]);
        $recoveryAuditMeta = (string) DB::table('audit_logs')
            ->where('target_type', 'AdminUser')
            ->where('target_id', (string) $other->id)
            ->where('action', 'admin_totp_recovery_code_used')
            ->value('meta_json');
        $this->assertStringNotContainsString($recoveryCode, $recoveryAuditMeta);
        $this->assertStringNotContainsString('fresh_step_up_code', $recoveryAuditMeta);
        $this->assertStringNotContainsString('reason_append', $recoveryAuditMeta);

        try {
            $service->approve((string) $approval->id, (int) $other->id, $recoveryCode);
            $this->fail('Expected the consumed recovery code not to be reusable.');
        } catch (HighRiskApprovalValidationException $exception) {
            $this->assertSame('Current MFA/TOTP step-up verification is required.', $exception->getMessage());
        }
    }

    public function test_refund_execution_requires_finance_and_order_write_before_authorization(): void
    {
        $owner = $this->admin(
            totpEnabled: true,
            permissions: [PermissionNames::ADMIN_FINANCE_WRITE],
        );
        $this->soloOwner($owner);
        $approval = $this->approval(AdminApproval::TYPE_REFUND, $owner, [
            'order_no' => 'ord-finance-only',
        ]);
        $service = app(HighRiskApprovalService::class);
        $service->approve(
            (string) $approval->id,
            (int) $owner->id,
            $this->freshStepUpCode($owner),
        );

        try {
            $service->authorizeExecution(
                (string) $approval->id,
                (int) $owner->id,
                $this->freshStepUpCode($owner),
            );
            $this->fail('Expected finance-only refund execution authorization to fail before dispatch.');
        } catch (HighRiskApprovalValidationException $exception) {
            $this->assertSame('Execution actor lacks the required domain permission.', $exception->getMessage());
        }

        $approval->refresh();
        $this->assertSame(AdminApproval::STATUS_APPROVED, (string) $approval->status);
        $this->assertSame(0, (int) $approval->retry_count);
        $this->assertNull(data_get(
            $approval->payload_json,
            HighRiskApprovalService::EXECUTION_METADATA_KEY,
        ));
        $this->assertDatabaseMissing('audit_logs', [
            'target_id' => (string) $approval->id,
            'action' => 'approval_execution_authorized',
        ]);
    }

    public function test_fresh_step_up_failures_are_rate_limited_per_actor_and_action(): void
    {
        $owner = $this->admin(totpEnabled: true);
        $this->soloOwner($owner);
        $approval = $this->approval(AdminApproval::TYPE_REFUND, $owner, [
            'order_no' => 'ord-step-up-rate-limit',
        ]);
        $service = app(HighRiskApprovalService::class);
        $rateLimitKey = 'high-risk-approval-step-up:approve:'.$owner->id;
        RateLimiter::clear($rateLimitKey);

        try {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                try {
                    $service->approve((string) $approval->id, (int) $owner->id, 'not-a-code');
                    $this->fail('Expected invalid step-up to fail.');
                } catch (HighRiskApprovalValidationException $exception) {
                    $this->assertSame('Current MFA/TOTP step-up verification is required.', $exception->getMessage());
                }
            }

            $this->assertSame(5, RateLimiter::attempts($rateLimitKey));
            try {
                $service->approve(
                    (string) $approval->id,
                    (int) $owner->id,
                    $this->freshStepUpCode($owner),
                );
                $this->fail('Expected the rate-limited valid step-up not to be verified.');
            } catch (HighRiskApprovalValidationException $exception) {
                $this->assertSame('Current MFA/TOTP step-up verification is required.', $exception->getMessage());
            }

            $this->assertSame(AdminApproval::STATUS_PENDING, (string) $approval->fresh()->status);
            $this->assertSame(0, RateLimiter::attempts('high-risk-approval-step-up:reject:'.$owner->id));
        } finally {
            RateLimiter::clear($rateLimitKey);
        }

        $service->approve(
            (string) $approval->id,
            (int) $owner->id,
            $this->freshStepUpCode($owner),
        );
        $this->assertSame(0, RateLimiter::attempts($rateLimitKey));
        $this->assertSame(AdminApproval::STATUS_APPROVED, (string) $approval->fresh()->status);
    }

    public function test_same_execution_actor_fresh_step_up_refreshes_authorization_ttl(): void
    {
        $owner = $this->admin(
            totpEnabled: true,
            permissions: [
                PermissionNames::ADMIN_FINANCE_WRITE,
                PermissionNames::ADMIN_OPS_WRITE,
            ],
        );
        $this->soloOwner($owner);
        $approval = $this->approval(AdminApproval::TYPE_REFUND, $owner, [
            'order_no' => 'ord-refresh-execution-authorization',
        ]);
        $service = app(HighRiskApprovalService::class);
        $service->approve(
            (string) $approval->id,
            (int) $owner->id,
            $this->freshStepUpCode($owner),
        );
        $first = $service->authorizeExecution(
            (string) $approval->id,
            (int) $owner->id,
            $this->freshStepUpCode($owner),
        );
        $firstAuthorization = (array) data_get(
            $first->payload_json,
            HighRiskApprovalService::EXECUTION_METADATA_KEY,
            [],
        );

        $this->travel(9)->minutes();
        $refreshed = $service->authorizeExecution(
            (string) $approval->id,
            (int) $owner->id,
            $this->freshStepUpCode($owner),
        );
        $refreshedAuthorization = (array) data_get(
            $refreshed->payload_json,
            HighRiskApprovalService::EXECUTION_METADATA_KEY,
            [],
        );

        $this->assertNotSame(
            $firstAuthorization['authorized_at'] ?? null,
            $refreshedAuthorization['authorized_at'] ?? null,
        );
        $this->assertNotSame(
            $firstAuthorization['authorization_sha256'] ?? null,
            $refreshedAuthorization['authorization_sha256'] ?? null,
        );
        $this->assertSame(2, DB::table('audit_logs')
            ->where('target_id', (string) $approval->id)
            ->where('action', 'approval_execution_authorized')
            ->count());

        $this->travel(2)->minutes();
        $this->assertSame((int) $owner->id, (int) $service->executionActor($refreshed->fresh())->id);
    }

    private function soloOwner(AdminUser $owner): void
    {
        config()->set('admin.totp.enabled', true);
        config()->set('review_governance.mode', 'solo_owner');
        config()->set('review_governance.solo_owner_admin_user_id', (int) $owner->id);
    }

    /** @param list<string> $permissions */
    private function admin(bool $totpEnabled = false, array $permissions = []): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'approval-'.Str::random(8),
            'email' => 'approval-'.Str::random(10).'@example.test',
            'password' => 'not-used-by-this-test',
            'is_active' => 1,
            'totp_enabled_at' => $totpEnabled ? now() : null,
            'totp_secret' => $totpEnabled ? 'JBSWY3DPEHPK3PXP' : null,
        ]);

        if ($permissions === []) {
            return $admin;
        }

        $role = Role::query()->create([
            'name' => 'approval-role-'.Str::random(8),
            'description' => 'approval test role',
        ]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => $permissionName],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }

    private function freshStepUpCode(AdminUser $admin): string
    {
        $totpAt = new ReflectionMethod(AdminTotpService::class, 'totpAt');
        $totpAt->setAccessible(true);

        return (string) $totpAt->invoke(
            app(AdminTotpService::class),
            (string) $admin->totp_secret,
            (int) floor(time() / 30),
        );
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
