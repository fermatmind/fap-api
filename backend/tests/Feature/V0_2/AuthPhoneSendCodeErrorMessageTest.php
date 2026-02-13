<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use App\Services\Auth\PhoneOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class AuthPhoneSendCodeErrorMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_code_runtime_error_does_not_leak_internal_message(): void
    {
        $otp = Mockery::mock(PhoneOtpService::class);
        $otp->shouldReceive('send')
            ->once()
            ->andThrow(new \RuntimeException('upstream failed token=secret-123'));
        $this->app->instance(PhoneOtpService::class, $otp);

        $response = $this->postJson('/api/v0.2/auth/phone/send_code', [
            'phone' => '+15551234567',
            'scene' => 'login',
            'consent' => true,
        ]);

        $response->assertStatus(429)
            ->assertJsonPath('error_code', 'OTP_SEND_FAILED')
            ->assertJsonPath('message', 'otp send failed.');

        $this->assertStringNotContainsString('secret-123', (string) $response->json('message'));
    }
}
