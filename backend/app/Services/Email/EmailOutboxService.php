<?php

namespace App\Services\Email;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EmailOutboxService
{
    /**
     * Queue a report-claim email task (outbox only, no send).
     *
     * @return array {ok:bool, claim_token?:string, claim_url?:string, expires_at?:string}
     */
    public function queueReportClaim(string $userId, string $email, string $attemptId): array
    {
        if (!Schema::hasTable('email_outbox')) {
            return ['ok' => false, 'error' => 'TABLE_MISSING'];
        }

        $userId = trim($userId);
        $email = trim($email);
        $attemptId = trim($attemptId);

        if ($userId === '' || $email === '' || $attemptId === '') {
            return ['ok' => false, 'error' => 'INVALID_INPUT'];
        }

        $token = 'claim_' . (string) Str::uuid();
        $tokenHash = hash('sha256', $token);
        $expiresAt = now()->addMinutes(15);

        $reportUrl = "/api/v0.2/attempts/{$attemptId}/report";
        $claimUrl = "/api/v0.2/claim/report?token={$token}";

        $payload = [
            'attempt_id' => $attemptId,
            'report_url' => $reportUrl,
            'claim_token' => $token,
            'claim_url' => $claimUrl,
            'claim_expires_at' => $expiresAt->toIso8601String(),
        ];

        $row = [
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'email' => $email,
            'template' => 'report_claim',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'claim_token_hash' => $tokenHash,
            'claim_expires_at' => $expiresAt,
            'status' => 'pending',
            'sent_at' => null,
            'consumed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('email_outbox')->insert($row);

        return [
            'ok' => true,
            'claim_token' => $token,
            'claim_url' => $claimUrl,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Claim report by token.
     *
     * @return array {ok:bool, attempt_id?:string, report_url?:string, status?:int, error?:string, message?:string}
     */
    public function claimReport(string $token): array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 128) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'INVALID_TOKEN',
                'message' => 'token invalid.',
            ];
        }

        if (!Schema::hasTable('email_outbox')) {
            return [
                'ok' => false,
                'status' => 410,
                'error' => 'TOKEN_GONE',
                'message' => 'claim token expired.',
            ];
        }

        $tokenHash = hash('sha256', $token);
        $row = DB::table('email_outbox')
            ->where('claim_token_hash', $tokenHash)
            ->first();

        if (!$row) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'INVALID_TOKEN',
                'message' => 'claim token not found.',
            ];
        }

        if (!empty($row->claim_expires_at)) {
            try {
                if (now()->greaterThan(\Illuminate\Support\Carbon::parse($row->claim_expires_at))) {
                    return [
                        'ok' => false,
                        'status' => 410,
                        'error' => 'TOKEN_EXPIRED',
                        'message' => 'claim token expired.',
                    ];
                }
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'status' => 410,
                    'error' => 'TOKEN_EXPIRED',
                    'message' => 'claim token expired.',
                ];
            }
        }

        if (!empty($row->status) && $row->status !== 'pending') {
            return [
                'ok' => false,
                'status' => 410,
                'error' => 'TOKEN_USED',
                'message' => 'claim token already used.',
            ];
        }

        $payload = $this->decodePayload($row->payload_json ?? null);
        $attemptId = (string) ($payload['attempt_id'] ?? '');
        if ($attemptId === '') {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'INVALID_PAYLOAD',
                'message' => 'attempt_id missing.',
            ];
        }

        $update = [
            'status' => 'consumed',
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('email_outbox', 'consumed_at')) {
            $update['consumed_at'] = now();
        }

        $updated = DB::table('email_outbox')
            ->where('id', $row->id)
            ->where('status', 'pending')
            ->update($update);

        if ($updated < 1) {
            return [
                'ok' => false,
                'status' => 410,
                'error' => 'TOKEN_USED',
                'message' => 'claim token already used.',
            ];
        }

        $reportUrl = $payload['report_url'] ?? "/api/v0.2/attempts/{$attemptId}/report";

        return [
            'ok' => true,
            'attempt_id' => $attemptId,
            'report_url' => $reportUrl,
        ];
    }

    private function decodePayload($raw): array
    {
        if (is_array($raw)) return $raw;
        if (!is_string($raw) || $raw === '') return [];

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
