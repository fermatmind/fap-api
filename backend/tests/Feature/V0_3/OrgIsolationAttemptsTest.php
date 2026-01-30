<?php

namespace Tests\Feature\V0_3;

use App\Services\Auth\FmTokenService;
use App\Services\Scale\ScaleRegistryWriter;
use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrgIsolationAttemptsTest extends TestCase
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

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr17SimpleScoreDemoSeeder())->run();
    }

    public function test_cross_org_attempt_access_returns_404(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->seedScales();

        $user = $this->createUserWithToken('isolation@org.test');

        $orgId1 = DB::table('organizations')->insertGetId([
            'name' => 'Org One',
            'owner_user_id' => $user['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orgId2 = DB::table('organizations')->insertGetId([
            'name' => 'Org Two',
            'owner_user_id' => $user['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organization_members')->insert([
            [
                'org_id' => $orgId1,
                'user_id' => $user['user_id'],
                'role' => 'owner',
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'org_id' => $orgId2,
                'user_id' => $user['user_id'],
                'role' => 'member',
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $start = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user['token'],
            'X-Org-Id' => (string) $orgId1,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
        ]);

        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $submit = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user['token'],
            'X-Org-Id' => (string) $orgId1,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => [
                ['question_id' => 'SS-001', 'code' => '5'],
            ],
            'duration_ms' => 1000,
        ]);

        $submit->assertStatus(200);

        $cross = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user['token'],
            'X-Org-Id' => (string) $orgId2,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/result");

        $cross->assertStatus(404);
    }

    public function test_public_scale_policy_blocks_private(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $writer = app(ScaleRegistryWriter::class);

        $writer->upsertScale([
            'code' => 'PRIVATE_SCALE',
            'org_id' => 0,
            'primary_slug' => 'private-scale',
            'slugs_json' => ['private-scale'],
            'driver_type' => 'simple_score',
            'default_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.2.1-TEST',
            'default_region' => 'CN_MAINLAND',
            'default_locale' => 'zh-CN',
            'default_dir_version' => 'MBTI-CN-v0.2.1-TEST',
            'is_public' => false,
            'is_active' => true,
        ]);

        $resp = $this->getJson('/api/v0.3/scales/PRIVATE_SCALE');
        $resp->assertStatus(404);
        $resp->assertJson([
            'ok' => false,
            'error' => 'NOT_FOUND',
        ]);
    }
}
