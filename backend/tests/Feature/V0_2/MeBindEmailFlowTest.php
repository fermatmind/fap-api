<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use App\Mail\EmailBindingVerificationMail;
use App\Services\Auth\FmTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class MeBindEmailFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_bind_email_persists_identity_and_sends_mail(): void
    {
        Mail::fake();

        $orgId = 22;
        $userId = $this->seedUser('me-bind-old@example.com');
        $anonId = 'anon_bind_email_flow';
        $token = $this->issueToken((string) $userId, $orgId, $anonId);

        $targetEmail = 'me-bind-new@example.com';

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v0.2/me/bind-email', [
                'email' => $targetEmail,
                'consent' => true,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('email', $targetEmail);

        $this->assertDatabaseHas('identities', [
            'user_id' => (string) $userId,
            'provider' => 'email',
            'provider_uid' => $targetEmail,
        ]);

        Mail::assertSent(EmailBindingVerificationMail::class, 1);
    }

    private function seedUser(string $email): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => 'User ' . $email,
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function issueToken(string $userId, int $orgId, string $anonId): string
    {
        $issued = app(FmTokenService::class)->issueForUser($userId, [
            'org_id' => $orgId,
            'anon_id' => $anonId,
        ]);

        return (string) ($issued['token'] ?? '');
    }
}
