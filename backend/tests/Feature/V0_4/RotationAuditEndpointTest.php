<?php

declare(strict_types=1);

namespace Tests\Feature\V0_4;

use App\Services\Auth\FmTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RotationAuditEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_admin_can_query_rotation_audits_with_retention_window(): void
    {
        $adminId = $this->createUser('rotation_admin@fm.test');
        $orgId = $this->createOrgWithRole($adminId, 'admin');
        $token = $this->issueToken($adminId);

        $activeAuditId = (string) Str::uuid();
        DB::table('rotation_audits')->insert([
            'id' => $activeAuditId,
            'org_id' => $orgId,
            'actor' => 'ops_bot',
            'actor_user_id' => $adminId,
            'scope' => 'pii',
            'key_version' => 2,
            'batch_ref' => 'batch_2026_02_27',
            'result' => 'success',
            'meta_json' => json_encode(['records' => 120], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        DB::table('rotation_audits')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'actor' => 'ops_bot',
            'actor_user_id' => $adminId,
            'scope' => 'pii',
            'key_version' => 1,
            'batch_ref' => 'batch_2025_01_01',
            'result' => 'success',
            'meta_json' => json_encode(['records' => 10], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now()->subDays(400),
            'updated_at' => now()->subDays(400),
        ]);

        $list = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.4/orgs/{$orgId}/compliance/rotation/audits?scope=pii&limit=10");

        $list->assertStatus(200);
        $list->assertJsonPath('ok', true);
        $list->assertJsonPath('retention.policy', 'ttl');
        $list->assertJsonPath('retention.keep_days', 180);
        $list->assertJsonCount(1, 'items');
        $list->assertJsonPath('items.0.id', $activeAuditId);
        $list->assertJsonPath('items.0.scope', 'pii');
        $list->assertJsonPath('items.0.key_version', 2);
        $list->assertJsonPath('items.0.batch', 'batch_2026_02_27');
        $list->assertJsonPath('items.0.result', 'success');
        $list->assertJsonPath('items.0.meta.records', 120);

        $show = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.4/orgs/{$orgId}/compliance/rotation/audits/{$activeAuditId}");

        $show->assertStatus(200);
        $show->assertJsonPath('ok', true);
        $show->assertJsonPath('audit.id', $activeAuditId);
    }

    public function test_non_admin_role_cannot_query_rotation_audits(): void
    {
        $memberId = $this->createUser('rotation_member@fm.test');
        $orgId = $this->createOrgWithRole($memberId, 'member');
        $token = $this->issueToken($memberId);

        DB::table('rotation_audits')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'actor' => 'ops_bot',
            'actor_user_id' => $memberId,
            'scope' => 'pii',
            'key_version' => 2,
            'batch_ref' => 'batch_2026_02_27',
            'result' => 'success',
            'meta_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.4/orgs/{$orgId}/compliance/rotation/audits");

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'ORG_NOT_FOUND');
    }

    private function createUser(string $email): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => $email,
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOrgWithRole(int $userId, string $role): int
    {
        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => 'Rotation Org '.$role,
            'owner_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organization_members')->insert([
            'org_id' => $orgId,
            'user_id' => $userId,
            'role' => $role,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orgId;
    }

    private function issueToken(int $userId): string
    {
        $issued = app(FmTokenService::class)->issueForUser((string) $userId);

        return (string) ($issued['token'] ?? '');
    }
}
