<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AuthPhoneNoPlaintextWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_verify_writes_encrypted_phone_fields_without_plaintext_column(): void
    {
        $phone = '+86139' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

        $send = $this->postJson('/api/v0.3/auth/phone/send_code', [
            'phone' => $phone,
            'consent' => true,
        ]);
        $send->assertOk();

        $code = (string) $send->json('dev_code', '');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $verify = $this->postJson('/api/v0.3/auth/phone/verify', [
            'phone' => $phone,
            'code' => $code,
            'consent' => true,
            'scene' => 'login',
            'anon_id' => 'auth-phone-no-plaintext-write',
        ]);
        $verify->assertOk();

        $user = DB::table('users')->orderByDesc('id')->first();
        $this->assertNotNull($user);

        $this->assertTrue(Schema::hasColumn('users', 'phone_e164_hash'));
        $this->assertTrue(Schema::hasColumn('users', 'phone_e164_enc'));
        $this->assertSame(app(PiiCipher::class)->phoneHash($phone), (string) ($user->phone_e164_hash ?? ''));
        $this->assertSame($phone, app(PiiCipher::class)->decrypt((string) ($user->phone_e164_enc ?? '')));

        if (Schema::hasColumn('users', 'phone_e164')) {
            $this->assertNull($user->phone_e164);
        }
        if (Schema::hasColumn('users', 'phone')) {
            $this->assertNull($user->phone);
        }
    }
}
