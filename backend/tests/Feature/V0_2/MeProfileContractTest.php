<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use App\Services\Auth\FmTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MeProfileContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_contract_contains_org_anon_and_user_ids(): void
    {
        $orgId = 21;
        $userId = $this->seedUser('me-profile@example.com');
        $anonId = 'anon_profile_contract';

        $token = $this->issueToken((string) $userId, $orgId, $anonId);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v0.2/me/profile');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('org_id', $orgId);
        $response->assertJsonPath('anon_id', $anonId);
        $response->assertJsonPath('user_id', (string) $userId);
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
