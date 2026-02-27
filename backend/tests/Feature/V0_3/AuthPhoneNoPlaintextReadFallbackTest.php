<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Support\PiiCipher;
use App\Support\PiiReadFallbackMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AuthPhoneNoPlaintextReadFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_verify_does_not_read_plaintext_phone_when_encrypted_value_missing(): void
    {
        $phone = '+86138' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $pii = app(PiiCipher::class);

        $insert = [
            'name' => 'legacy-phone-user',
            'email' => sprintf('legacy_phone_%d@example.com', random_int(10000, 99999)),
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('users', 'uid')) {
            $insert['uid'] = 'u_' . bin2hex(random_bytes(5));
        }
        if (Schema::hasColumn('users', 'phone_e164')) {
            $insert['phone_e164'] = $phone;
        }
        if (Schema::hasColumn('users', 'phone')) {
            $insert['phone'] = $phone;
        }
        if (Schema::hasColumn('users', 'phone_e164_hash')) {
            $insert['phone_e164_hash'] = $pii->phoneHash($phone);
        }
        if (Schema::hasColumn('users', 'phone_e164_enc')) {
            $insert['phone_e164_enc'] = null;
        }
        if (Schema::hasColumn('users', 'phone_verified_at')) {
            $insert['phone_verified_at'] = now();
        }

        DB::table('users')->insert($insert);

        $send = $this->postJson('/api/v0.3/auth/phone/send_code', [
            'phone' => $phone,
            'consent' => true,
        ]);
        $send->assertOk();

        $verify = $this->postJson('/api/v0.3/auth/phone/verify', [
            'phone' => $phone,
            'code' => (string) $send->json('dev_code', ''),
            'consent' => true,
            'scene' => 'login',
        ]);
        $verify->assertOk();
        $verify->assertJsonPath('user.phone', null);

        $snapshot = app(PiiReadFallbackMonitor::class)->snapshot('users.phone_read');
        $this->assertGreaterThanOrEqual(1, (int) ($snapshot['total'] ?? 0));
        $this->assertSame(0, (int) ($snapshot['fallback'] ?? -1));
    }
}
