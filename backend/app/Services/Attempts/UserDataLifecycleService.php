<?php

declare(strict_types=1);

namespace App\Services\Attempts;

use App\Support\SensitiveDataRedactor;
use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UserDataLifecycleService
{
    public function __construct(
        private readonly AttemptDataLifecycleService $attemptLifecycleService,
        private readonly ?SensitiveDataRedactor $sensitiveDataRedactor = null,
    ) {}

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function process(int $orgId, int $subjectUserId, string $mode = 'hybrid_anonymize', array $context = []): array
    {
        if ($orgId < 0 || $subjectUserId <= 0) {
            return [
                'ok' => false,
                'error' => 'INVALID_SUBJECT',
            ];
        }

        $mode = $this->normalizeMode($mode);
        $subjectUserIdStr = (string) $subjectUserId;
        $now = now();
        $redactedAnonId = $this->redactedAnonId($orgId, $subjectUserId);
        $requestId = trim((string) ($context['request_id'] ?? ''));
        $subjectAnonIds = $this->collectSubjectAnonIds($orgId, $subjectUserId, $subjectUserIdStr);

        $counts = [
            'attempts_purged' => 0,
            'attempts_failed' => 0,
            'attempts_user_detached' => 0,
            'auth_tokens_revoked' => 0,
            'legacy_tokens_revoked' => 0,
            'email_outbox_redacted' => 0,
            'identities_deleted' => 0,
            'sessions_deleted' => 0,
            'users_anonymized' => 0,
            'users_deleted' => 0,
            'events_pseudonymized' => 0,
            'orders_pseudonymized' => 0,
            'payment_events_pseudonymized' => 0,
            'benefit_grants_pseudonymized' => 0,
        ];

        $attemptFailures = [];
        $artifactResidualAudits = [];
        $retentionSummary = [
            'financial_records' => [
                'strategy' => 'pseudonymize_retain',
                'orders_retained' => 0,
                'payment_events_retained' => 0,
                'benefit_grants_retained' => 0,
            ],
        ];

        $this->appendAuditLog(
            $requestId,
            $orgId,
            $subjectUserId,
            'dsar_started',
            'user dsar started',
            [
                'mode' => $mode,
                'subject_anon_ids' => $subjectAnonIds,
            ]
        );

        try {
            if (SchemaBaseline::hasTable('attempts')) {
                $attemptRows = DB::table('attempts')
                    ->select(['id', 'scale_code'])
                    ->where('org_id', $orgId)
                    ->where('user_id', $subjectUserIdStr)
                    ->orderBy('id')
                    ->get();

                foreach ($attemptRows as $attemptRow) {
                    $attemptId = trim((string) ($attemptRow->id ?? ''));
                    if ($attemptId === '') {
                        continue;
                    }

                    $purge = $this->attemptLifecycleService->purgeAttempt($attemptId, $orgId, [
                        'reason' => 'user_dsar_request',
                        'scale_code' => (string) ($attemptRow->scale_code ?? ''),
                        'mode' => $mode,
                    ]);

                    if (($purge['ok'] ?? false) === true) {
                        $counts['attempts_purged']++;
                        $artifactResidualAudits[$attemptId] = is_array($purge['artifact_residual_audit'] ?? null)
                            ? $purge['artifact_residual_audit']
                            : [];
                        continue;
                    }

                    $counts['attempts_failed']++;
                    $attemptFailures[$attemptId] = (string) ($purge['error'] ?? 'ATTEMPT_PURGE_FAILED');
                }

                if (SchemaBaseline::hasColumn('attempts', 'user_id')) {
                    $updates = [
                        'user_id' => null,
                        'updated_at' => $now,
                    ];
                    if (SchemaBaseline::hasColumn('attempts', 'anon_id')) {
                        $updates['anon_id'] = $redactedAnonId;
                    }

                    $counts['attempts_user_detached'] = DB::table('attempts')
                        ->where('org_id', $orgId)
                        ->where('user_id', $subjectUserIdStr)
                        ->update($updates);
                }
            }

            if (SchemaBaseline::hasTable('auth_tokens')) {
                $counts['auth_tokens_revoked'] = DB::table('auth_tokens')
                    ->where('org_id', $orgId)
                    ->where('user_id', $subjectUserId)
                    ->whereNull('revoked_at')
                    ->update([
                        'revoked_at' => $now,
                        'updated_at' => $now,
                        'anon_id' => $redactedAnonId,
                    ]);
            }

            if (SchemaBaseline::hasTable('fm_tokens')) {
                $legacyQuery = DB::table('fm_tokens')
                    ->where('user_id', $subjectUserId)
                    ->whereNull('revoked_at');
                if (SchemaBaseline::hasColumn('fm_tokens', 'org_id')) {
                    $legacyQuery->where('org_id', $orgId);
                }

                $legacyUpdates = [
                    'revoked_at' => $now,
                    'updated_at' => $now,
                ];
                if (SchemaBaseline::hasColumn('fm_tokens', 'anon_id')) {
                    $legacyUpdates['anon_id'] = $redactedAnonId;
                }

                $counts['legacy_tokens_revoked'] = $legacyQuery->update($legacyUpdates);
            }

            if (SchemaBaseline::hasTable('email_outbox') && SchemaBaseline::hasColumn('email_outbox', 'user_id')) {
                $redactedEmail = $this->redactedEmail($orgId, $subjectUserId);
                $outboxUpdates = [
                    'updated_at' => $now,
                ];
                if (SchemaBaseline::hasColumn('email_outbox', 'email')) {
                    $outboxUpdates['email'] = $redactedEmail;
                }
                if (SchemaBaseline::hasColumn('email_outbox', 'email_enc')) {
                    $outboxUpdates['email_enc'] = null;
                }
                if (SchemaBaseline::hasColumn('email_outbox', 'email_hash')) {
                    $outboxUpdates['email_hash'] = null;
                }
                if (SchemaBaseline::hasColumn('email_outbox', 'to_email')) {
                    $outboxUpdates['to_email'] = $redactedEmail;
                }
                if (SchemaBaseline::hasColumn('email_outbox', 'to_email_enc')) {
                    $outboxUpdates['to_email_enc'] = null;
                }
                if (SchemaBaseline::hasColumn('email_outbox', 'to_email_hash')) {
                    $outboxUpdates['to_email_hash'] = null;
                }
                if (SchemaBaseline::hasColumn('email_outbox', 'payload_json')) {
                    $outboxUpdates['payload_json'] = json_encode([
                        'redacted' => true,
                        'reason' => 'user_dsar_request',
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if (SchemaBaseline::hasColumn('email_outbox', 'payload_enc')) {
                    $outboxUpdates['payload_enc'] = null;
                }
                if (SchemaBaseline::hasColumn('email_outbox', 'payload_schema_version')) {
                    $outboxUpdates['payload_schema_version'] = 'redacted_v1';
                }

                $counts['email_outbox_redacted'] = DB::table('email_outbox')
                    ->where('user_id', $subjectUserIdStr)
                    ->update($outboxUpdates);
            }

            if (SchemaBaseline::hasTable('identities') && SchemaBaseline::hasColumn('identities', 'user_id')) {
                $counts['identities_deleted'] = DB::table('identities')
                    ->where('user_id', $subjectUserIdStr)
                    ->delete();
            }

            if (SchemaBaseline::hasTable('sessions') && SchemaBaseline::hasColumn('sessions', 'user_id')) {
                $counts['sessions_deleted'] = DB::table('sessions')
                    ->where('user_id', $subjectUserId)
                    ->delete();
            }

            if (SchemaBaseline::hasTable('users')) {
                if ($mode === 'delete') {
                    $counts['users_deleted'] = DB::table('users')
                        ->where('id', $subjectUserId)
                        ->delete();
                } else {
                    $userUpdates = [
                        'updated_at' => $now,
                    ];
                    if (SchemaBaseline::hasColumn('users', 'name')) {
                        $userUpdates['name'] = 'deleted_user_'.$subjectUserId;
                    }
                    if (SchemaBaseline::hasColumn('users', 'email')) {
                        $userUpdates['email'] = $this->redactedEmail($orgId, $subjectUserId);
                    }
                    if (SchemaBaseline::hasColumn('users', 'email_verified_at')) {
                        $userUpdates['email_verified_at'] = null;
                    }
                    if (SchemaBaseline::hasColumn('users', 'email_enc')) {
                        $userUpdates['email_enc'] = null;
                    }
                    if (SchemaBaseline::hasColumn('users', 'email_hash')) {
                        $userUpdates['email_hash'] = null;
                    }
                    if (SchemaBaseline::hasColumn('users', 'phone_e164')) {
                        $userUpdates['phone_e164'] = null;
                    }
                    if (SchemaBaseline::hasColumn('users', 'phone_verified_at')) {
                        $userUpdates['phone_verified_at'] = null;
                    }
                    if (SchemaBaseline::hasColumn('users', 'phone_e164_enc')) {
                        $userUpdates['phone_e164_enc'] = null;
                    }
                    if (SchemaBaseline::hasColumn('users', 'phone_e164_hash')) {
                        $userUpdates['phone_e164_hash'] = null;
                    }
                    if (SchemaBaseline::hasColumn('users', 'remember_token')) {
                        $userUpdates['remember_token'] = null;
                    }

                    $counts['users_anonymized'] = DB::table('users')
                        ->where('id', $subjectUserId)
                        ->update($userUpdates);
                }
            }

            $counts['events_pseudonymized'] = $this->pseudonymizeEvents(
                $orgId,
                $subjectUserId,
                $redactedAnonId,
                $subjectAnonIds
            );

            [$counts['orders_pseudonymized'], $orderNos] = $this->pseudonymizeOrders(
                $orgId,
                $subjectUserIdStr,
                $redactedAnonId,
                $subjectAnonIds
            );

            $counts['payment_events_pseudonymized'] = $this->pseudonymizePaymentEvents(
                $orgId,
                $orderNos
            );

            $counts['benefit_grants_pseudonymized'] = $this->pseudonymizeBenefitGrants(
                $orgId,
                $subjectUserIdStr,
                $orderNos
            );

            $retentionSummary['financial_records']['orders_retained'] = $counts['orders_pseudonymized'];
            $retentionSummary['financial_records']['payment_events_retained'] = $counts['payment_events_pseudonymized'];
            $retentionSummary['financial_records']['benefit_grants_retained'] = $counts['benefit_grants_pseudonymized'];

            $this->recordDataLifecycleRequest(
                $orgId,
                $subjectUserIdStr,
                $mode,
                $context,
                $counts,
                $attemptFailures,
                $artifactResidualAudits
            );
            $this->recordExecutionTasks($requestId, $orgId, $subjectUserId, $counts, $attemptFailures);
            $this->appendAuditLog(
                $requestId,
                $orgId,
                $subjectUserId,
                'dsar_completed',
                'user dsar completed',
                [
                    'counts' => $counts,
                    'retention' => $retentionSummary,
                    'artifact_residual_audits' => $artifactResidualAudits,
                ]
            );
        } catch (\Throwable $e) {
            $this->recordExecutionTasks($requestId, $orgId, $subjectUserId, $counts, $attemptFailures, $e::class);
            $this->recordDataLifecycleRequest(
                $orgId,
                $subjectUserIdStr,
                $mode,
                $context,
                $counts + ['exception' => $e::class],
                $attemptFailures,
                $artifactResidualAudits,
                'failed',
                'failed'
            );
            $this->appendAuditLog(
                $requestId,
                $orgId,
                $subjectUserId,
                'dsar_failed',
                'user dsar failed',
                [
                    'exception' => $e::class,
                    'counts' => $counts,
                    'artifact_residual_audits' => $artifactResidualAudits,
                ],
                'error'
            );

            return [
                'ok' => false,
                'error' => 'USER_DSAR_FAILED',
                'counts' => $counts,
            ];
        }

        return [
            'ok' => true,
            'org_id' => $orgId,
            'subject_user_id' => $subjectUserId,
            'mode' => $mode,
            'counts' => $counts,
            'attempt_failures' => $attemptFailures,
            'artifact_residual_audits' => $artifactResidualAudits,
            'retention' => $retentionSummary,
        ];
    }

    private function normalizeMode(string $mode): string
    {
        $mode = trim(strtolower($mode));

        return match ($mode) {
            'delete' => 'delete',
            default => 'hybrid_anonymize',
        };
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $counts
     * @param  array<string,string>  $attemptFailures
     * @param  array<string,array<string,mixed>>  $artifactResidualAudits
     */
    private function recordDataLifecycleRequest(
        int $orgId,
        string $subjectRef,
        string $mode,
        array $context,
        array $counts,
        array $attemptFailures,
        array $artifactResidualAudits,
        string $status = 'done',
        string $result = 'success'
    ): void {
        if (! SchemaBaseline::hasTable('data_lifecycle_requests')) {
            return;
        }

        DB::table('data_lifecycle_requests')->insert([
            'org_id' => $orgId,
            'request_type' => 'user_dsar',
            'status' => $status,
            'requested_by_admin_user_id' => $this->nullablePositiveInt($context['actor_user_id'] ?? null),
            'approved_by_admin_user_id' => $this->nullablePositiveInt($context['actor_user_id'] ?? null),
            'subject_ref' => $subjectRef,
            'reason' => (string) ($context['reason'] ?? 'user_dsar_request'),
            'result' => $result,
            'payload_json' => json_encode([
                'mode' => $mode,
                'request_id' => (string) ($context['request_id'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'result_json' => json_encode([
                'counts' => $counts,
                'attempt_failures' => $attemptFailures,
                'artifact_residual_audits' => $artifactResidualAudits,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'approved_at' => now(),
            'executed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function redactedAnonId(int $orgId, int $subjectUserId): string
    {
        return 'dsar_'.substr(hash('sha256', "{$orgId}|{$subjectUserId}"), 0, 32);
    }

    private function redactedEmail(int $orgId, int $subjectUserId): string
    {
        $suffix = substr(hash('sha256', "{$orgId}|{$subjectUserId}"), 0, 16);

        return "deleted_user_{$subjectUserId}_{$suffix}@fermat.invalid";
    }

    /**
     * @return list<string>
     */
    private function collectSubjectAnonIds(int $orgId, int $subjectUserId, string $subjectUserIdStr): array
    {
        $set = [];

        if (SchemaBaseline::hasTable('attempts') && SchemaBaseline::hasColumn('attempts', 'anon_id') && SchemaBaseline::hasColumn('attempts', 'user_id')) {
            $rows = DB::table('attempts')
                ->where('org_id', $orgId)
                ->where('user_id', $subjectUserIdStr)
                ->pluck('anon_id');
            foreach ($rows as $row) {
                $value = trim((string) $row);
                if ($value !== '') {
                    $set[$value] = true;
                }
            }
        }

        if (SchemaBaseline::hasTable('auth_tokens') && SchemaBaseline::hasColumn('auth_tokens', 'anon_id')) {
            $rows = DB::table('auth_tokens')
                ->where('org_id', $orgId)
                ->where('user_id', $subjectUserId)
                ->pluck('anon_id');
            foreach ($rows as $row) {
                $value = trim((string) $row);
                if ($value !== '') {
                    $set[$value] = true;
                }
            }
        }

        if (SchemaBaseline::hasTable('fm_tokens') && SchemaBaseline::hasColumn('fm_tokens', 'anon_id')) {
            $query = DB::table('fm_tokens')->where('user_id', $subjectUserId);
            if (SchemaBaseline::hasColumn('fm_tokens', 'org_id')) {
                $query->where('org_id', $orgId);
            }
            $rows = $query->pluck('anon_id');
            foreach ($rows as $row) {
                $value = trim((string) $row);
                if ($value !== '') {
                    $set[$value] = true;
                }
            }
        }

        if (SchemaBaseline::hasTable('orders') && SchemaBaseline::hasColumn('orders', 'anon_id') && SchemaBaseline::hasColumn('orders', 'user_id')) {
            $rows = DB::table('orders')
                ->where('org_id', $orgId)
                ->where('user_id', $subjectUserIdStr)
                ->pluck('anon_id');
            foreach ($rows as $row) {
                $value = trim((string) $row);
                if ($value !== '') {
                    $set[$value] = true;
                }
            }
        }

        return array_values(array_keys($set));
    }

    private function pseudonymizeEvents(int $orgId, int $subjectUserId, string $redactedAnonId, array $subjectAnonIds): int
    {
        if (! SchemaBaseline::hasTable('events')) {
            return 0;
        }

        $hasUserId = SchemaBaseline::hasColumn('events', 'user_id');
        $hasAnonId = SchemaBaseline::hasColumn('events', 'anon_id');
        $canFilterByUser = $hasUserId;
        $canFilterByAnon = $hasAnonId && $subjectAnonIds !== [];
        if (! $canFilterByUser && ! $canFilterByAnon) {
            return 0;
        }

        $query = DB::table('events')->where('org_id', $orgId);
        $query->where(function ($inner) use ($canFilterByUser, $canFilterByAnon, $subjectUserId, $subjectAnonIds): void {
            if ($canFilterByUser) {
                $inner->where('user_id', $subjectUserId);
            }
            if ($canFilterByAnon) {
                $inner->orWhereIn('anon_id', $subjectAnonIds);
            }
        });

        $updates = [];
        if (SchemaBaseline::hasColumn('events', 'updated_at')) {
            $updates['updated_at'] = now();
        }
        if ($hasUserId) {
            $updates['user_id'] = null;
        }
        if ($hasAnonId) {
            $updates['anon_id'] = $redactedAnonId;
        }
        if (SchemaBaseline::hasColumn('events', 'meta_json')) {
            $updates['meta_json'] = json_encode([
                'redacted' => true,
                'reason' => 'user_dsar_request',
                'retained' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $query->update($updates);
    }

    /**
     * @param  list<string>  $subjectAnonIds
     * @return array{0:int,1:list<string>}
     */
    private function pseudonymizeOrders(int $orgId, string $subjectUserIdStr, string $redactedAnonId, array $subjectAnonIds): array
    {
        if (! SchemaBaseline::hasTable('orders') || ! SchemaBaseline::hasColumn('orders', 'org_id')) {
            return [0, []];
        }

        $hasUserId = SchemaBaseline::hasColumn('orders', 'user_id');
        $hasAnonId = SchemaBaseline::hasColumn('orders', 'anon_id');
        $canFilterByUser = $hasUserId;
        $canFilterByAnon = $hasAnonId && $subjectAnonIds !== [];
        if (! $canFilterByUser && ! $canFilterByAnon) {
            return [0, []];
        }

        $scope = DB::table('orders')->where('org_id', $orgId);
        $scope->where(function ($inner) use ($canFilterByUser, $canFilterByAnon, $subjectUserIdStr, $subjectAnonIds): void {
            if ($canFilterByUser) {
                $inner->where('user_id', $subjectUserIdStr);
            }
            if ($canFilterByAnon) {
                $inner->orWhereIn('anon_id', $subjectAnonIds);
            }
        });

        $orderNos = [];
        if (SchemaBaseline::hasColumn('orders', 'order_no')) {
            $orderNos = array_values(array_unique(array_filter(
                array_map(
                    static fn (mixed $v): string => trim((string) $v),
                    (clone $scope)->pluck('order_no')->all()
                ),
                static fn (string $v): bool => $v !== ''
            )));
        }

        $updates = [];
        if (SchemaBaseline::hasColumn('orders', 'updated_at')) {
            $updates['updated_at'] = now();
        }
        if ($hasUserId) {
            $updates['user_id'] = null;
        }
        if ($hasAnonId) {
            $updates['anon_id'] = $redactedAnonId;
        }
        if (SchemaBaseline::hasColumn('orders', 'contact_email_hash')) {
            $updates['contact_email_hash'] = null;
        }
        if (SchemaBaseline::hasColumn('orders', 'meta_json')) {
            $updates['meta_json'] = $this->redactedJson([
                'retained' => true,
                'reason' => 'user_dsar_request',
                'domain' => 'orders',
            ]);
        }

        $updated = $scope->update($updates);

        return [$updated, $orderNos];
    }

    /**
     * @param  list<string>  $orderNos
     */
    private function pseudonymizePaymentEvents(int $orgId, array $orderNos): int
    {
        if (! SchemaBaseline::hasTable('payment_events') || $orderNos === []) {
            return 0;
        }
        if (! SchemaBaseline::hasColumn('payment_events', 'order_no')) {
            return 0;
        }

        $query = DB::table('payment_events');
        if (SchemaBaseline::hasColumn('payment_events', 'org_id')) {
            $query->where('org_id', $orgId);
        }
        $query->whereIn('order_no', $orderNos);

        $updates = [];
        if (SchemaBaseline::hasColumn('payment_events', 'updated_at')) {
            $updates['updated_at'] = now();
        }
        if (SchemaBaseline::hasColumn('payment_events', 'payload_json')) {
            $updates['payload_json'] = $this->redactedJson([
                'redacted' => true,
                'retained' => true,
                'reason' => 'user_dsar_request',
                'domain' => 'payment_events',
            ]);
        }
        if (SchemaBaseline::hasColumn('payment_events', 'payload_excerpt')) {
            $updates['payload_excerpt'] = '[REDACTED]';
        }

        return $query->update($updates);
    }

    /**
     * @param  list<string>  $orderNos
     */
    private function pseudonymizeBenefitGrants(int $orgId, string $subjectUserIdStr, array $orderNos): int
    {
        if (! SchemaBaseline::hasTable('benefit_grants') || ! SchemaBaseline::hasColumn('benefit_grants', 'org_id')) {
            return 0;
        }

        $hasUserId = SchemaBaseline::hasColumn('benefit_grants', 'user_id');
        $hasOrderNo = SchemaBaseline::hasColumn('benefit_grants', 'order_no');
        $canFilterByUser = $hasUserId;
        $canFilterByOrderNo = $hasOrderNo && $orderNos !== [];
        if (! $canFilterByUser && ! $canFilterByOrderNo) {
            return 0;
        }

        $query = DB::table('benefit_grants')->where('org_id', $orgId);
        $query->where(function ($inner) use ($canFilterByUser, $canFilterByOrderNo, $subjectUserIdStr, $orderNos): void {
            if ($canFilterByUser) {
                $inner->where('user_id', $subjectUserIdStr);
            }
            if ($canFilterByOrderNo) {
                $inner->orWhereIn('order_no', $orderNos);
            }
        });

        $updates = [];
        if (SchemaBaseline::hasColumn('benefit_grants', 'updated_at')) {
            $updates['updated_at'] = now();
        }
        if ($hasUserId) {
            $updates['user_id'] = null;
        }
        if (SchemaBaseline::hasColumn('benefit_grants', 'meta_json')) {
            $updates['meta_json'] = $this->redactedJson([
                'retained' => true,
                'reason' => 'user_dsar_request',
                'domain' => 'benefit_grants',
            ]);
        }

        return $query->update($updates);
    }

    /**
     * @param  array<string,mixed>  $counts
     * @param  array<string,string>  $attemptFailures
     */
    private function recordExecutionTasks(
        string $requestId,
        int $orgId,
        int $subjectUserId,
        array $counts,
        array $attemptFailures,
        ?string $errorCode = null
    ): void {
        if ($requestId === '' || ! SchemaBaseline::hasTable('dsar_request_tasks')) {
            return;
        }

        $tasks = [
            ['domain' => 'attempts', 'action' => 'purge', 'count_key' => 'attempts_purged', 'failure_key' => 'attempts_failed'],
            ['domain' => 'auth_tokens', 'action' => 'revoke', 'count_key' => 'auth_tokens_revoked'],
            ['domain' => 'legacy_tokens', 'action' => 'revoke', 'count_key' => 'legacy_tokens_revoked'],
            ['domain' => 'email_outbox', 'action' => 'redact', 'count_key' => 'email_outbox_redacted'],
            ['domain' => 'identities', 'action' => 'delete', 'count_key' => 'identities_deleted'],
            ['domain' => 'sessions', 'action' => 'delete', 'count_key' => 'sessions_deleted'],
            ['domain' => 'users', 'action' => 'anonymize', 'count_key' => 'users_anonymized'],
            ['domain' => 'events', 'action' => 'pseudonymize', 'count_key' => 'events_pseudonymized'],
            ['domain' => 'orders', 'action' => 'pseudonymize_retain', 'count_key' => 'orders_pseudonymized'],
            ['domain' => 'payment_events', 'action' => 'pseudonymize_retain', 'count_key' => 'payment_events_pseudonymized'],
            ['domain' => 'benefit_grants', 'action' => 'pseudonymize_retain', 'count_key' => 'benefit_grants_pseudonymized'],
        ];

        $now = now();
        foreach ($tasks as $task) {
            $count = (int) ($counts[$task['count_key']] ?? 0);
            $failureCount = isset($task['failure_key']) ? (int) ($counts[$task['failure_key']] ?? 0) : 0;
            $status = ($failureCount > 0 || $errorCode !== null) ? 'failed' : 'done';
            $taskError = $failureCount > 0 ? 'PARTIAL_FAILURE' : $errorCode;

            DB::table('dsar_request_tasks')->insert([
                'id' => (string) Str::uuid(),
                'request_id' => $requestId,
                'org_id' => $orgId,
                'subject_user_id' => $subjectUserId,
                'domain' => (string) $task['domain'],
                'action' => (string) $task['action'],
                'status' => $status,
                'error_code' => $taskError,
                'stats_json' => json_encode([
                    'affected_rows' => $count,
                    'attempt_failures' => $task['domain'] === 'attempts' ? $attemptFailures : null,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'started_at' => $now,
                'finished_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function appendAuditLog(
        string $requestId,
        int $orgId,
        int $subjectUserId,
        string $eventType,
        string $message,
        array $context = [],
        string $level = 'info'
    ): void {
        if ($requestId === '' || ! SchemaBaseline::hasTable('dsar_audit_logs')) {
            return;
        }

        $now = now();
        DB::table('dsar_audit_logs')->insert([
            'request_id' => $requestId,
            'org_id' => $orgId,
            'subject_user_id' => $subjectUserId,
            'event_type' => $eventType,
            'level' => $level,
            'message' => $message,
            'context_json' => $this->redactedJson($context),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function redactedJson(array $payload): string
    {
        $redactor = $this->sensitiveDataRedactor ?? new SensitiveDataRedactor;
        $redacted = $redactor->redact($payload);

        return json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }
}
