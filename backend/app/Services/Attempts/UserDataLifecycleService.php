<?php

declare(strict_types=1);

namespace App\Services\Attempts;

use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;

final class UserDataLifecycleService
{
    public function __construct(
        private readonly AttemptDataLifecycleService $attemptLifecycleService,
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
        ];

        $attemptFailures = [];

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

            $this->recordDataLifecycleRequest($orgId, $subjectUserIdStr, $mode, $context, $counts, $attemptFailures);
        } catch (\Throwable $e) {
            $this->recordDataLifecycleRequest(
                $orgId,
                $subjectUserIdStr,
                $mode,
                $context,
                $counts + ['exception' => $e::class],
                $attemptFailures,
                'failed',
                'failed'
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
     */
    private function recordDataLifecycleRequest(
        int $orgId,
        string $subjectRef,
        string $mode,
        array $context,
        array $counts,
        array $attemptFailures,
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
