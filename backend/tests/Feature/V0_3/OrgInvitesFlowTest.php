<?php

namespace Tests\Feature\V0_3;

use App\Services\Auth\FmTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrgInvitesFlowTest extends TestCase
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

    public function test_invite_accept_flow(): void
    {
        $this->artisan('migrate', ['--force' => true]);

        $userA = $this->createUserWithToken('a@org.test');
        $userB = $this->createUserWithToken('b@org.test');

        $create = $this->withHeaders([
            'Authorization' => 'Bearer ' . $userB['token'],
        ])->postJson('/api/v0.3/orgs', [
            'name' => 'Org Two',
        ]);

        $create->assertStatus(200);
        $orgId = (int) $create->json('org.org_id');
        $this->assertNotSame(0, $orgId);

        $invite = $this->withHeaders([
            'Authorization' => 'Bearer ' . $userB['token'],
            'X-Org-Id' => (string) $orgId,
        ])->postJson("/api/v0.3/orgs/{$orgId}/invites", [
            'email' => 'a@org.test',
        ]);

        $invite->assertStatus(200);
        $token = (string) $invite->json('invite.token');
        $this->assertNotSame('', $token);

        $accept = $this->withHeaders([
            'Authorization' => 'Bearer ' . $userA['token'],
            'X-Org-Id' => '0',
        ])->postJson('/api/v0.3/orgs/invites/accept', [
            'token' => $token,
        ]);

        $accept->assertStatus(200);
        $accept->assertJson([
            'ok' => true,
            'org_id' => $orgId,
        ]);

        $me = $this->withHeaders([
            'Authorization' => 'Bearer ' . $userA['token'],
        ])->getJson('/api/v0.3/orgs/me');

        $me->assertStatus(200);
        $items = $me->json('items');
        $this->assertIsArray($items);
        $found = false;
        foreach ($items as $item) {
            if ((int) ($item['org_id'] ?? 0) === $orgId) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }
}
