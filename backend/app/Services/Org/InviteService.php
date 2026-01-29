<?php

namespace App\Services\Org;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InviteService
{
    public function __construct(private MembershipService $memberships)
    {
    }

    public function createInvite(int $orgId, string $email, \DateTimeInterface $expiresAt): array
    {
        $email = trim(strtolower($email));
        $token = 'inv_' . Str::uuid()->toString() . Str::random(16);

        $now = now();
        DB::table('organization_invites')->insert([
            'org_id' => $orgId,
            'email' => $email,
            'token' => $token,
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'org_id' => $orgId,
            'email' => $email,
            'token' => $token,
            'expires_at' => $expiresAt instanceof \Illuminate\Support\Carbon
                ? $expiresAt->toIso8601String()
                : $expiresAt->format(\DateTimeInterface::ATOM),
        ];
    }

    public function acceptInvite(string $token, int $userId): array
    {
        $token = trim($token);
        if ($token === '') {
            return [
                'ok' => false,
                'error' => 'INVITE_TOKEN_REQUIRED',
                'message' => 'invite token required.',
            ];
        }

        $invite = DB::table('organization_invites')->where('token', $token)->first();
        if (!$invite) {
            return [
                'ok' => false,
                'error' => 'INVITE_NOT_FOUND',
                'message' => 'invite not found.',
            ];
        }

        if (!empty($invite->accepted_at)) {
            return [
                'ok' => false,
                'error' => 'INVITE_ALREADY_ACCEPTED',
                'message' => 'invite already accepted.',
            ];
        }

        if (!empty($invite->expires_at)) {
            try {
                if (now()->greaterThan(\Illuminate\Support\Carbon::parse($invite->expires_at))) {
                    return [
                        'ok' => false,
                        'error' => 'INVITE_EXPIRED',
                        'message' => 'invite expired.',
                    ];
                }
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'error' => 'INVITE_INVALID',
                    'message' => 'invite invalid.',
                ];
            }
        }

        $orgId = (int) ($invite->org_id ?? 0);

        DB::transaction(function () use ($orgId, $userId, $token) {
            $this->memberships->addMember($orgId, $userId, 'member');
            DB::table('organization_invites')
                ->where('token', $token)
                ->update([
                    'accepted_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        return [
            'ok' => true,
            'org_id' => $orgId,
            'email' => (string) ($invite->email ?? ''),
        ];
    }
}
