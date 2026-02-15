<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminTotpService
{
    public function generateSecret(int $length = 32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';

        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $secret;
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 10): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(Str::random(10));
        }

        return $codes;
    }

    /**
     * @param  list<string>  $recoveryCodes
     */
    public function enableForUser(AdminUser $user, string $secret, array $recoveryCodes): void
    {
        DB::transaction(function () use ($user, $secret, $recoveryCodes): void {
            $user->forceFill([
                'totp_secret' => $secret,
                'totp_enabled_at' => now(),
            ])->save();

            DB::table('admin_user_totp_recovery_codes')
                ->where('admin_user_id', (int) $user->id)
                ->delete();

            foreach ($recoveryCodes as $code) {
                DB::table('admin_user_totp_recovery_codes')->insert([
                    'admin_user_id' => (int) $user->id,
                    'code_hash' => hash('sha256', strtoupper(trim($code))),
                    'used_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function verify(AdminUser $user, string $code): bool
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') {
            return false;
        }

        if ($this->verifyTotpCode($user, $normalized)) {
            return true;
        }

        return $this->verifyRecoveryCode($user, $normalized);
    }

    private function verifyTotpCode(AdminUser $user, string $code): bool
    {
        $secret = trim((string) ($user->totp_secret ?? ''));
        if ($secret === '' || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $timeSlice = (int) floor(time() / 30);
        for ($offset = -1; $offset <= 1; $offset++) {
            if ($this->totpAt($secret, $timeSlice + $offset) === $code) {
                return true;
            }
        }

        return false;
    }

    private function verifyRecoveryCode(AdminUser $user, string $code): bool
    {
        if (!\App\Support\SchemaBaseline::hasTable('admin_user_totp_recovery_codes')) {
            return false;
        }

        $hash = hash('sha256', $code);

        return (bool) DB::transaction(function () use ($user, $hash): bool {
            $row = DB::table('admin_user_totp_recovery_codes')
                ->where('admin_user_id', (int) $user->id)
                ->where('code_hash', $hash)
                ->whereNull('used_at')
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return false;
            }

            DB::table('admin_user_totp_recovery_codes')
                ->where('id', (int) $row->id)
                ->update([
                    'used_at' => now(),
                    'updated_at' => now(),
                ]);

            return true;
        });
    }

    private function totpAt(string $secret, int $timeSlice): string
    {
        $secretKey = $this->base32Decode($secret);
        if ($secretKey === '') {
            return '';
        }

        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $binary % 1000000;

        return str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $clean = strtoupper(str_replace('=', '', trim($secret)));
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        $chars = str_split($clean);
        foreach ($chars as $char) {
            $value = strpos($alphabet, $char);
            if ($value === false) {
                return '';
            }

            $buffer = ($buffer << 5) | $value;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
