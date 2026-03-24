<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Jobs\ExecuteDsarRequestJob;
use App\Services\Auth\FmTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DsarRequestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_and_execute_user_dsar_request(): void
    {
        Queue::fake();

        $orgId = 701;
        $ownerUserId = 70101;
        $subjectUserId = 70102;

        $this->seedUser($ownerUserId, "owner_{$ownerUserId}@example.test", '+8613900007001');
        $this->seedUser($subjectUserId, "subject_{$subjectUserId}@example.test", '+8613900007002');
        $this->seedOrgWithOwnerMembership($orgId, $ownerUserId);

        $ownerToken = $this->issueToken($ownerUserId, $orgId, 'owner', 'anon_owner_dsar');
        $subjectToken = $this->issueToken($subjectUserId, $orgId, 'public', 'anon_subject_dsar');

        $attemptId = (string) Str::uuid();
        $this->seedAttempt($attemptId, $orgId, $subjectUserId, 'anon_subject_dsar');
        $this->seedEmailOutbox($subjectUserId, $attemptId);
        $orderNo = $this->seedFinancialRecords($orgId, $subjectUserId, 'anon_subject_dsar', $attemptId);

        $headers = [
            'Authorization' => 'Bearer '.$ownerToken,
            'X-Org-Id' => (string) $orgId,
        ];

        $create = $this->withHeaders($headers)->postJson('/api/v0.3/compliance/dsar/requests', [
            'subject_user_id' => $subjectUserId,
            'mode' => 'hybrid_anonymize',
            'reason' => 'subject requested erase',
        ]);

        $create->assertStatus(202)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'running');
        $requestId = (string) $create->json('request_id');
        $taskId = (string) $create->json('meta.execution.task_id');
        $referenceId = (string) $create->json('meta.execution.job_reference');
        $this->assertNotSame('', $requestId);
        $this->assertNotSame('', $taskId);
        $this->assertNotSame('', $referenceId);

        Queue::assertPushed(ExecuteDsarRequestJob::class, function (ExecuteDsarRequestJob $job) use (
            $requestId,
            $orgId,
            $ownerUserId,
            $taskId,
            $referenceId
        ): bool {
            return $job->requestId === $requestId
                && $job->orgId === $orgId
                && $job->actorUserId === $ownerUserId
                && $job->taskId === $taskId
                && $job->referenceId === $referenceId;
        });

        $createReplay = $this->withHeaders($headers)->postJson('/api/v0.3/compliance/dsar/requests', [
            'subject_user_id' => $subjectUserId,
            'mode' => 'hybrid_anonymize',
            'reason' => 'subject requested erase',
        ]);

        $createReplay->assertStatus(202)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('request_id', $requestId)
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('meta.execution.task_id', $taskId)
            ->assertJsonPath('meta.execution.job_reference', $referenceId);
        Queue::assertPushed(ExecuteDsarRequestJob::class, 1);

        $this->withHeaders($headers)
            ->getJson('/api/v0.3/compliance/dsar/requests/'.$requestId)
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('request.status', 'running');

        $execute = $this->withHeaders($headers)
            ->postJson('/api/v0.3/compliance/dsar/requests/'.$requestId.'/execute');

        $execute->assertStatus(202)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('request_id', $requestId)
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('job_reference', $referenceId)
            ->assertJsonPath('meta.execution.task_id', $taskId)
            ->assertJsonPath('meta.execution.job_reference', $referenceId);
        Queue::assertPushed(ExecuteDsarRequestJob::class, 1);

        $this->withHeaders($headers)
            ->getJson('/api/v0.3/compliance/dsar/requests/'.$requestId)
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('request.status', 'running');

        $executeReplay = $this->withHeaders($headers)
            ->postJson('/api/v0.3/compliance/dsar/requests/'.$requestId.'/execute');

        $executeReplay->assertStatus(202)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('request_id', $requestId)
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('job_reference', $referenceId)
            ->assertJsonPath('meta.execution.task_id', $taskId)
            ->assertJsonPath('meta.execution.job_reference', $referenceId);
        Queue::assertPushed(ExecuteDsarRequestJob::class, 1);

        $job = new ExecuteDsarRequestJob($requestId, $orgId, $ownerUserId, $taskId, $referenceId);
        $job->handle(app(\App\Services\Attempts\UserDataLifecycleService::class));

        $this->withHeaders($headers)
            ->getJson('/api/v0.3/compliance/dsar/requests/'.$requestId)
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('request.status', 'done');

        $executeAfterDone = $this->withHeaders($headers)
            ->postJson('/api/v0.3/compliance/dsar/requests/'.$requestId.'/execute');

        $executeAfterDone->assertStatus(202)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('request_id', $requestId)
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('job_reference', $referenceId)
            ->assertJsonPath('meta.execution.task_id', $taskId)
            ->assertJsonPath('meta.execution.job_reference', $referenceId);
        Queue::assertPushed(ExecuteDsarRequestJob::class, 1);

        $subjectTokenRow = DB::table('auth_tokens')
            ->where('token_hash', hash('sha256', $subjectToken))
            ->first();
        $this->assertNotNull($subjectTokenRow);
        $this->assertNotNull($subjectTokenRow->revoked_at);

        $attemptRow = DB::table('attempts')->where('id', $attemptId)->first();
        $this->assertNotNull($attemptRow);
        $this->assertNull($attemptRow->user_id);
        $this->assertStringStartsWith('dsar_', (string) $attemptRow->anon_id);

        $subjectUser = DB::table('users')->where('id', $subjectUserId)->first();
        $this->assertNotNull($subjectUser);
        $this->assertSame('deleted_user_'.$subjectUserId, (string) ($subjectUser->name ?? ''));
        $this->assertStringStartsWith('deleted_user_'.$subjectUserId.'_', (string) ($subjectUser->email ?? ''));
        $this->assertStringEndsWith('@fermat.invalid', (string) ($subjectUser->email ?? ''));
        $this->assertNull($subjectUser->phone_e164);

        $outboxRow = DB::table('email_outbox')
            ->where('user_id', (string) $subjectUserId)
            ->orderByDesc('created_at')
            ->first();
        $this->assertNotNull($outboxRow);
        $this->assertStringStartsWith('deleted_user_'.$subjectUserId.'_', (string) ($outboxRow->email ?? ''));
        $this->assertStringEndsWith('@fermat.invalid', (string) ($outboxRow->email ?? ''));
        $this->assertStringStartsWith('deleted_user_'.$subjectUserId.'_', (string) ($outboxRow->to_email ?? ''));
        $this->assertStringEndsWith('@fermat.invalid', (string) ($outboxRow->to_email ?? ''));

        $orderRow = DB::table('orders')->where('order_no', $orderNo)->first();
        $this->assertNotNull($orderRow);
        $this->assertNull($orderRow->user_id);
        $this->assertStringStartsWith('dsar_', (string) ($orderRow->anon_id ?? ''));

        $paymentEventRow = DB::table('payment_events')
            ->where('order_no', $orderNo)
            ->first();
        $this->assertNotNull($paymentEventRow);
        $payload = $paymentEventRow->payload_json;
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['retained'] ?? false));
        $this->assertTrue((bool) ($payload['redacted'] ?? false));

        $benefitGrantRow = DB::table('benefit_grants')
            ->where('org_id', $orgId)
            ->where('user_id', null)
            ->where('benefit_code', 'MBTI_REPORT_FULL')
            ->first();
        $this->assertNotNull($benefitGrantRow);

        $dsarRow = DB::table('dsar_requests')->where('id', $requestId)->first();
        $this->assertNotNull($dsarRow);
        $this->assertSame('done', (string) ($dsarRow->status ?? ''));

        $taskRows = DB::table('dsar_request_tasks')
            ->where('request_id', $requestId)
            ->whereIn('domain', ['orders', 'payment_events'])
            ->get();
        $this->assertCount(2, $taskRows);
        foreach ($taskRows as $taskRow) {
            $this->assertSame('done', (string) ($taskRow->status ?? ''));
        }

        $auditRow = DB::table('dsar_audit_logs')
            ->where('request_id', $requestId)
            ->where('event_type', 'dsar_completed')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($auditRow);

        $statusTransitions = DB::table('dsar_audit_logs')
            ->where('request_id', $requestId)
            ->where('event_type', 'dsar_status_transition')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $statusTransitions);

        $firstTransition = $statusTransitions->first();
        $firstContext = $this->decodeAuditContext($firstTransition?->context_json ?? null);
        $this->assertSame('pending', (string) ($firstContext['from'] ?? ''));
        $this->assertSame('running', (string) ($firstContext['to'] ?? ''));
        $this->assertSame($taskId, (string) ($firstContext['task_id'] ?? ''));
        $this->assertSame($referenceId, (string) ($firstContext['reference_id'] ?? ''));

        $lastTransition = $statusTransitions->last();
        $lastContext = $this->decodeAuditContext($lastTransition?->context_json ?? null);
        $this->assertSame('running', (string) ($lastContext['from'] ?? ''));
        $this->assertSame('done', (string) ($lastContext['to'] ?? ''));
        $this->assertSame($taskId, (string) ($lastContext['task_id'] ?? ''));
        $this->assertSame($referenceId, (string) ($lastContext['reference_id'] ?? ''));

        $lifecycleAudit = DB::table('data_lifecycle_requests')
            ->where('request_type', 'user_dsar')
            ->where('subject_ref', (string) $subjectUserId)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($lifecycleAudit);

        $this->assertNotEmpty($ownerToken);
    }

    public function test_execute_job_failed_marks_terminal_audit_dlq_state(): void
    {
        $orgId = 702;
        $ownerUserId = 70201;
        $subjectUserId = 70202;
        $requestId = (string) Str::uuid();
        $taskId = (string) Str::uuid();
        $referenceId = (string) Str::uuid();

        $this->seedUser($ownerUserId, "owner_{$ownerUserId}@example.test", '+8613900007101');
        $this->seedUser($subjectUserId, "subject_{$subjectUserId}@example.test", '+8613900007102');
        $this->seedOrgWithOwnerMembership($orgId, $ownerUserId);

        DB::table('dsar_requests')->insert([
            'id' => $requestId,
            'org_id' => $orgId,
            'subject_user_id' => $subjectUserId,
            'requested_by_user_id' => $ownerUserId,
            'executed_by_user_id' => $ownerUserId,
            'mode' => 'hybrid_anonymize',
            'status' => 'running',
            'reason' => 'retry exhaustion case',
            'payload_json' => json_encode([
                'execution' => [
                    'task_id' => $taskId,
                    'reference_id' => $referenceId,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'result_json' => null,
            'requested_at' => now()->subMinute(),
            'executed_at' => null,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        DB::table('dsar_request_tasks')->insert([
            'id' => $taskId,
            'request_id' => $requestId,
            'org_id' => $orgId,
            'subject_user_id' => $subjectUserId,
            'domain' => 'orchestration',
            'action' => 'execute',
            'status' => 'running',
            'error_code' => null,
            'stats_json' => null,
            'started_at' => now()->subMinute(),
            'finished_at' => null,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $job = new ExecuteDsarRequestJob($requestId, $orgId, $ownerUserId, $taskId, $referenceId);
        $job->failed(new \RuntimeException('simulated terminal failure'));

        $requestRow = DB::table('dsar_requests')->where('id', $requestId)->first();
        $this->assertNotNull($requestRow);
        $this->assertSame('failed', (string) ($requestRow->status ?? ''));
        $requestResult = $this->decodeAuditContext($requestRow->result_json ?? null);
        $this->assertSame('USER_DSAR_RETRY_EXHAUSTED', (string) ($requestResult['error_code'] ?? ''));

        $taskRow = DB::table('dsar_request_tasks')->where('id', $taskId)->first();
        $this->assertNotNull($taskRow);
        $this->assertSame('failed', (string) ($taskRow->status ?? ''));
        $this->assertSame('USER_DSAR_RETRY_EXHAUSTED', (string) ($taskRow->error_code ?? ''));
        $taskStats = $this->decodeAuditContext($taskRow->stats_json ?? null);
        $this->assertTrue((bool) ($taskStats['dlq_marked'] ?? false));
        $this->assertSame(3, (int) ($taskStats['max_tries'] ?? 0));
        $this->assertSame('compliance', (string) ($taskStats['queue'] ?? ''));
        $this->assertNotSame('', (string) ($taskStats['connection'] ?? ''));

        $statusTransitionAudit = DB::table('dsar_audit_logs')
            ->where('request_id', $requestId)
            ->where('event_type', 'dsar_status_transition')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($statusTransitionAudit);
        $statusTransitionContext = $this->decodeAuditContext($statusTransitionAudit->context_json ?? null);
        $this->assertSame('running', (string) ($statusTransitionContext['from'] ?? ''));
        $this->assertSame('failed', (string) ($statusTransitionContext['to'] ?? ''));

        $terminalAudit = DB::table('dsar_audit_logs')
            ->where('request_id', $requestId)
            ->where('event_type', 'dsar_job_failed_terminal')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($terminalAudit);
        $terminalContext = $this->decodeAuditContext($terminalAudit->context_json ?? null);
        $this->assertSame('USER_DSAR_RETRY_EXHAUSTED', (string) ($terminalContext['error_code'] ?? ''));
        $this->assertSame($taskId, (string) ($terminalContext['task_id'] ?? ''));
        $this->assertSame($referenceId, (string) ($terminalContext['reference_id'] ?? ''));
        $this->assertSame('RuntimeException', (string) ($terminalContext['exception_class'] ?? ''));
    }

    public function test_owner_cannot_create_dsar_for_user_outside_org_membership(): void
    {
        Queue::fake();

        $orgId = 703;
        $ownerUserId = 70301;
        $outsideUserId = 70302;

        $this->seedUser($ownerUserId, "owner_{$ownerUserId}@example.test", '+8613900007031');
        $this->seedUser($outsideUserId, "outside_{$outsideUserId}@example.test", '+8613900007032');
        $this->seedOrgWithOwnerMembership($orgId, $ownerUserId);

        $ownerToken = $this->issueToken($ownerUserId, $orgId, 'owner', 'anon_owner_dsar_703');

        $headers = [
            'Authorization' => 'Bearer '.$ownerToken,
            'X-Org-Id' => (string) $orgId,
        ];

        $response = $this->withHeaders($headers)->postJson('/api/v0.3/compliance/dsar/requests', [
            'subject_user_id' => $outsideUserId,
            'mode' => 'hybrid_anonymize',
            'reason' => 'cross tenant probe',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'INVALID_SUBJECT')
            ->assertJsonPath('message', 'subject user is not in current organization.');

        Queue::assertNothingPushed();
        $this->assertSame(0, DB::table('dsar_requests')->count());
    }

    private function seedUser(int $userId, string $email, string $phoneE164): void
    {
        DB::table('users')->insert([
            'id' => $userId,
            'name' => "user_{$userId}",
            'email' => $email,
            'phone_e164' => $phoneE164,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedOrgWithOwnerMembership(int $orgId, int $ownerUserId): void
    {
        DB::table('organizations')->insert([
            'id' => $orgId,
            'name' => 'dsar_org_'.$orgId,
            'status' => 'active',
            'domain' => null,
            'timezone' => 'UTC',
            'locale' => 'en-US',
            'owner_user_id' => $ownerUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organization_members')->insert([
            'org_id' => $orgId,
            'user_id' => $ownerUserId,
            'role' => 'owner',
            'is_active' => 1,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function issueToken(int $userId, int $orgId, string $role, string $anonId): string
    {
        /** @var FmTokenService $tokenService */
        $tokenService = app(FmTokenService::class);
        $issued = $tokenService->issueForUser((string) $userId, [
            'org_id' => $orgId,
            'role' => $role,
            'anon_id' => $anonId,
        ]);

        return (string) ($issued['token'] ?? '');
    }

    private function seedAttempt(string $attemptId, int $orgId, int $userId, string $anonId): void
    {
        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 8)),
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'user_id' => (string) $userId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'answers_summary_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now()->subMinutes(3),
            'submitted_at' => now()->subMinutes(2),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => (string) config('content_packs.default_dir_version'),
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
            'calculation_snapshot_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedEmailOutbox(int $subjectUserId, string $attemptId): void
    {
        DB::table('email_outbox')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => (string) $subjectUserId,
            'email' => "subject_{$subjectUserId}@example.test",
            'to_email' => "subject_{$subjectUserId}@example.test",
            'template' => 'report_claim',
            'template_key' => 'report_claim',
            'locale' => 'zh-CN',
            'subject' => 'Subject',
            'body_html' => null,
            'attempt_id' => $attemptId,
            'payload_json' => json_encode(['attempt_id' => $attemptId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'claim_token_hash' => hash('sha256', 'claim_'.$attemptId),
            'claim_expires_at' => now()->addMinutes(15),
            'status' => 'pending',
            'sent_at' => null,
            'consumed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedFinancialRecords(int $orgId, int $subjectUserId, string $anonId, string $attemptId): string
    {
        $orderNo = 'ORD-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 12));
        $orderId = (string) Str::uuid();

        DB::table('orders')->insert([
            'id' => $orderId,
            'order_no' => $orderNo,
            'org_id' => $orgId,
            'user_id' => (string) $subjectUserId,
            'anon_id' => $anonId,
            'item_sku' => 'MBTI_REPORT_FULL',
            'sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_total' => 9900,
            'amount_cents' => 9900,
            'currency' => 'USD',
            'status' => 'paid',
            'provider' => 'billing',
            'paid_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_events')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'provider' => 'billing',
            'provider_event_id' => 'evt_'.strtolower(substr(str_replace('-', '', (string) Str::uuid()), 0, 20)),
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'event_type' => 'order.paid',
            'signature_ok' => true,
            'payload_json' => json_encode([
                'buyer_email' => "subject_{$subjectUserId}@example.test",
                'buyer_phone' => '+8613900007002',
                'amount_cents' => 9900,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'received_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'user_id' => (string) $subjectUserId,
            'benefit_type' => 'report_unlock',
            'benefit_ref' => 'MBTI_REPORT_FULL',
            'source_order_id' => $orderId,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'attempt',
            'attempt_id' => $attemptId,
            'status' => 'active',
            'order_no' => $orderNo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderNo;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeAuditContext(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
