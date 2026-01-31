<?php

namespace Tests\Feature\V0_4;

use App\Services\Auth\FmTokenService;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssessmentsRbacIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
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

    private function issueToken(int $userId): string
    {
        $issued = app(FmTokenService::class)->issueForUser((string) $userId);
        return (string) ($issued['token'] ?? '');
    }

    public function test_member_viewer_blocked_and_cross_org_isolated(): void
    {
        $this->seedScales();

        $adminId = $this->createUser('admin@org.test');
        $memberId = $this->createUser('member@org.test');
        $viewerId = $this->createUser('viewer@org.test');

        $orgId = DB::table('organizations')->insertGetId([
            'name' => 'Org Alpha',
            'owner_user_id' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organization_members')->insert([
            [
                'org_id' => $orgId,
                'user_id' => $adminId,
                'role' => 'admin',
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'org_id' => $orgId,
                'user_id' => $memberId,
                'role' => 'member',
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'org_id' => $orgId,
                'user_id' => $viewerId,
                'role' => 'viewer',
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $adminToken = $this->issueToken($adminId);
        $memberToken = $this->issueToken($memberId);
        $viewerToken = $this->issueToken($viewerId);

        $create = $this->withHeaders([
            'Authorization' => 'Bearer ' . $adminToken,
        ])->postJson("/api/v0.4/orgs/{$orgId}/assessments", [
            'scale_code' => 'MBTI',
            'title' => 'Q1 MBTI',
            'due_at' => now()->addDays(14)->toISOString(),
        ]);

        $create->assertStatus(200);
        $assessmentId = (int) $create->json('assessment.id');
        $this->assertGreaterThan(0, $assessmentId);

        $memberProgress = $this->withHeaders([
            'Authorization' => 'Bearer ' . $memberToken,
        ])->getJson("/api/v0.4/orgs/{$orgId}/assessments/{$assessmentId}/progress");
        $memberProgress->assertStatus(404);

        $viewerSummary = $this->withHeaders([
            'Authorization' => 'Bearer ' . $viewerToken,
        ])->getJson("/api/v0.4/orgs/{$orgId}/assessments/{$assessmentId}/summary");
        $viewerSummary->assertStatus(404);

        $otherOrgId = DB::table('organizations')->insertGetId([
            'name' => 'Org Beta',
            'owner_user_id' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cross = $this->withHeaders([
            'Authorization' => 'Bearer ' . $adminToken,
        ])->getJson("/api/v0.4/orgs/{$otherOrgId}/assessments/{$assessmentId}/progress");
        $cross->assertStatus(404);
    }
}
