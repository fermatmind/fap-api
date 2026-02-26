<?php

declare(strict_types=1);

namespace Tests\Feature\V0_4;

use App\Services\Auth\FmTokenService;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AssessmentScaleCodeLegacyRejectTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
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

    private function createOrgAdminAndIssueToken(): array
    {
        $adminId = $this->createUser('assessment-legacy-reject@example.com');
        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => 'Assessment Legacy Reject Org',
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
        $token = (string) ($issued['token'] ?? '');

        return [
            'org_id' => $orgId,
            'token' => $token,
        ];
    }

    public function test_store_rejects_legacy_scale_code_and_accepts_v2_scale_code(): void
    {
        $this->seedScales();
        Config::set('scale_identity.accept_legacy_scale_code', false);
        Config::set('scale_identity.api_response_scale_code_mode', 'v2');

        $auth = $this->createOrgAdminAndIssueToken();
        $orgId = (int) $auth['org_id'];
        $token = (string) $auth['token'];

        $legacyResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson("/api/v0.4/orgs/{$orgId}/assessments", [
            'scale_code' => 'MBTI',
            'title' => 'legacy reject',
        ]);
        $legacyResponse->assertStatus(410);
        $legacyResponse->assertJsonPath('error_code', 'SCALE_CODE_LEGACY_NOT_ACCEPTED');
        $legacyResponse->assertJsonPath('details.requested_scale_code', 'MBTI');
        $legacyResponse->assertJsonPath('details.scale_code_legacy', 'MBTI');
        $legacyResponse->assertJsonPath('details.replacement_scale_code_v2', 'MBTI_PERSONALITY_TEST_16_TYPES');

        $v2Response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson("/api/v0.4/orgs/{$orgId}/assessments", [
            'scale_code' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'title' => 'v2 accepted',
        ]);
        $v2Response->assertStatus(200);
        $v2Response->assertJsonPath('ok', true);
        $v2Response->assertJsonPath('assessment.scale_code', 'MBTI_PERSONALITY_TEST_16_TYPES');
    }
}
