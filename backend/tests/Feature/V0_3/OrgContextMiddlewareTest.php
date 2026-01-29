<?php

namespace Tests\Feature\V0_3;

use App\Services\Auth\FmTokenService;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrgContextMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithToken(string $email): array
    {
        $now = now();
        $userId = DB::table('users')->insertGetId([
            'name' => 'User ' . $email,
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $issued = app(FmTokenService::class)->issueForUser((string) $userId);

        return [
            'user_id' => (int) $userId,
            'token' => (string) ($issued['token'] ?? ''),
        ];
    }

    public function test_non_member_with_org_header_returns_404(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        (new ScaleRegistrySeeder())->run();

        $orgId = DB::table('organizations')->insertGetId([
            'name' => 'Org A',
            'owner_user_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = $this->createUserWithToken('a@example.com');

        $resp = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user['token'],
            'X-Org-Id' => (string) $orgId,
        ])->getJson('/api/v0.3/scales');

        $resp->assertStatus(404);
        $resp->assertJson([
            'ok' => false,
            'error' => 'ORG_NOT_FOUND',
        ]);
    }

    public function test_member_with_org_header_passes(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        (new ScaleRegistrySeeder())->run();

        $orgId = DB::table('organizations')->insertGetId([
            'name' => 'Org B',
            'owner_user_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = $this->createUserWithToken('b@example.com');

        DB::table('organization_members')->insert([
            'org_id' => $orgId,
            'user_id' => $user['user_id'],
            'role' => 'member',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resp = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user['token'],
            'X-Org-Id' => (string) $orgId,
        ])->getJson('/api/v0.3/scales');

        $resp->assertStatus(200);
        $resp->assertJson([
            'ok' => true,
        ]);
    }
}
