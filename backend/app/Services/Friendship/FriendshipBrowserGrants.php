<?php

declare(strict_types=1);

namespace App\Services\Friendship;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class FriendshipBrowserGrants
{
    public function key(Request $request, string $id, string $role): string
    {
        $bearer = $request->bearerToken();
        if ($bearer !== null) {
            abort_unless($this->isKey($bearer), 401, 'KEY_REQUIRED');

            return $bearer;
        }

        $record = $this->query($request, $id, $role)->where('expires_at', '>', now())->first();
        abort_if($record === null, 401, 'KEY_REQUIRED');

        return Crypt::decryptString($record->encrypted_key);
    }

    public function grant(Request $request, string $id, string $role, ?string $legacy, bool $draft): void
    {
        $identity = $this->identity($request, $id, $role);
        DB::transaction(function () use ($identity, $id, $role, $legacy, $draft): void {
            $challenge = DB::table('friendship_challenges')->where('id', $id)->lockForUpdate()->first();
            $query = DB::table('friendship_browser_grants')->where($identity);
            $record = $query->lockForUpdate()->first();
            $existing = $record && now()->lessThan($record->expires_at) ? Crypt::decryptString($record->encrypted_key) : null;
            $key = $legacy ?? $existing ?? bin2hex(random_bytes(32));
            $hash = hash('sha256', $key);
            abort_if($challenge && now()->greaterThanOrEqualTo($challenge->expires_at), 410, 'QUIZ_EXPIRED');
            if ($role === 'owner') {
                abort_unless($challenge ? hash_equals($challenge->owner_hash, $hash) : $draft, 404, 'QUIZ_NOT_FOUND');
            } else {
                abort_unless($challenge && ! hash_equals($challenge->owner_hash, $hash), 404, 'QUIZ_NOT_FOUND');
            }
            abort_if($existing !== null && ! hash_equals($existing, $key), 409, 'GRANT_CONFLICT');
            $values = ['encrypted_key' => Crypt::encryptString($key), 'expires_at' => $challenge?->expires_at ?? now()->addDays(90)];
            if ($record) {
                $query->update($values);
            } else {
                DB::table('friendship_browser_grants')->insertOrIgnore($identity + $values);
                $winner = $query->first();
                abort_unless($winner && hash_equals(Crypt::decryptString($winner->encrypted_key), $key), 409, 'GRANT_CONFLICT');
            }
        }, 3);
    }

    private function query(Request $request, string $id, string $role): \Illuminate\Database\Query\Builder
    {
        return DB::table('friendship_browser_grants')->where($this->identity($request, $id, $role));
    }

    private function identity(Request $request, string $id, string $role): array
    {
        $session = $request->header('X-Friendship-Browser-Session', '');
        abort_unless($this->isKey($session), 401, 'KEY_REQUIRED');

        return ['session_hash' => hash('sha256', $session), 'challenge_id' => strtolower($id), 'role' => $role];
    }

    private function isKey(string $key): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $key) === 1;
    }
}
