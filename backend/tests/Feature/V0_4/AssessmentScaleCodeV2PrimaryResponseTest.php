<?php

declare(strict_types=1);

namespace Tests\Feature\V0_4;

use App\Services\Auth\FmTokenService;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AssessmentScaleCodeV2PrimaryResponseTest extends TestCase
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

    /**
     * @return array{org_id:int,token:string}
     */
    private function createOrgAdminAndIssueToken(): array
    {
        $adminId = $this->createUser('assessment-v2-primary@example.com');
        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => 'Assessment V2 Primary Org',
            'owner_user_id' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organization_members')->insert([
            'org_id' => $orgId,
            'user_id' => $adminId,
            'role' => 'admin',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $issued = app(FmTokenService::class)->issueForUser((string) $adminId);

        return [
            'org_id' => $orgId,
            'token' => (string) ($issued['token'] ?? ''),
        ];
    }

    public function test_store_returns_v2_primary_scale_code_when_response_mode_is_v2(): void
    {
        $this->seedScales();
        Config::set('scale_identity.write_mode', 'dual');
        Config::set('scale_identity.api_response_scale_code_mode', 'v2');

        $auth = $this->createOrgAdminAndIssueToken();
        $orgId = (int) $auth['org_id'];
        $token = (string) $auth['token'];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson("/api/v0.4/orgs/{$orgId}/assessments", [
            'scale_code' => 'MBTI',
            'title' => 'Team baseline',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('assessment.scale_code', 'MBTI_PERSONALITY_TEST_16_TYPES');
        $response->assertJsonPath('assessment.scale_code_legacy', 'MBTI');
        $response->assertJsonPath('assessment.scale_code_v2', 'MBTI_PERSONALITY_TEST_16_TYPES');
        $response->assertJsonPath('assessment.scale_uid', '11111111-1111-4111-8111-111111111111');
    }
}

