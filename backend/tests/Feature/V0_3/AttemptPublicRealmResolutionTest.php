<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Http\Middleware\FmTokenAuth;
use App\Http\Middleware\ForcePublicAttemptRealm;
use App\Http\Middleware\ResolveAnonId;
use App\Http\Middleware\ResolveOrgContext;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Auth\FmTokenService;
use App\Services\Scale\ScaleRegistry;
use App\Support\OrgContext;
use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttemptPublicRealmResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function registerDebugRoutes(): void
    {
        if (Route::has('api.v0_3.attempts.debug_context_org')) {
            return;
        }

        $debugResponder = function (Request $request) {
            return response()->json([
                'request_org_id' => (int) $request->attributes->get('org_id', -1),
                'request_fm_org_id' => (int) $request->attributes->get('fm_org_id', -1),
                'context_org_id' => (int) app(OrgContext::class)->orgId(),
                'user_id' => (string) ($request->attributes->get('fm_user_id') ?? ''),
                'anon_id' => (string) ($request->attributes->get('anon_id') ?? ''),
            ]);
        };

        Route::middleware([ResolveAnonId::class, ForcePublicAttemptRealm::class, ResolveOrgContext::class])
            ->post('/api/v0.3/attempts/debug-context-org', $debugResponder)
            ->defaults('public_realm', true)
            ->name('api.v0_3.attempts.debug_context_org');

        Route::middleware([ResolveAnonId::class, ForcePublicAttemptRealm::class, ResolveOrgContext::class, FmTokenAuth::class])
            ->post('/api/v0.3/attempts/debug-context', $debugResponder)
            ->defaults('public_realm', true)
            ->name('api.v0_3.attempts.debug_context');

        Route::middleware([ResolveAnonId::class, ForcePublicAttemptRealm::class, ResolveOrgContext::class])
            ->get('/api/v0.3/attempts/debug-result/{attempt_id}', function (string $attemptId) {
                $orgId = app(OrgContext::class)->orgId();
                $result = Result::query()->where('org_id', $orgId)->where('attempt_id', $attemptId)->first();

                return response()->json([
                    'context_org_id' => $orgId,
                    'found' => $result instanceof Result,
                ]);
            })
            ->defaults('public_realm', true)
            ->name('api.v0_3.attempts.debug_result');

        Route::middleware([ResolveAnonId::class, ForcePublicAttemptRealm::class, ResolveOrgContext::class])
            ->post('/api/v0.3/attempts/debug-start-scale', function () {
                $orgId = app(OrgContext::class)->orgId();
                $row = app(ScaleRegistry::class)->getByCode('MBTI', $orgId);

                return response()->json([
                    'context_org_id' => $orgId,
                    'scale_found' => is_array($row),
                ]);
            })
            ->defaults('public_realm', true)
            ->name('api.v0_3.attempts.debug_start_scale');
    }

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
        (new Pr17SimpleScoreDemoSeeder)->run();
    }

    /**
     * @return array{user_id:int,org_id:int,token:string}
     */
    private function createTenantUserToken(string $anonId): array
    {
        $now = now();
        $userId = DB::table('users')->insertGetId([
            'name' => 'Public Realm Owner',
            'email' => 'public-realm-'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('secret'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $orgId = DB::table('organizations')->insertGetId([
            'name' => 'Tenant '.Str::lower(Str::random(6)),
            'owner_user_id' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('organization_members')->insert([
            'org_id' => $orgId,
            'user_id' => $userId,
            'role' => 'owner',
            'joined_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $issued = app(FmTokenService::class)->issueForUser((string) $userId, [
            'anon_id' => $anonId,
            'org_id' => $orgId,
            'role' => 'owner',
        ]);

        return [
            'user_id' => (int) $userId,
            'org_id' => (int) $orgId,
            'token' => (string) ($issued['token'] ?? ''),
        ];
    }

    private function createPublicMbtiAttemptAndResult(string $attemptId, int $userId, string $anonId): void
    {
        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'user_id' => $userId,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'attempt-v1',
            'scoring_spec_version' => 'attempt-score-v1',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => [
                'EI' => 50,
                'SN' => 50,
                'TF' => 50,
                'JP' => 50,
                'AT' => 50,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'clear',
                'AT' => 'clear',
            ],
            'content_package_version' => 'result-v1',
            'result_json' => [
                'type_code' => 'INTJ-A',
                'axis_scores_json' => [
                    'scores_pct' => [
                        'EI' => 50,
                        'SN' => 50,
                        'TF' => 50,
                        'JP' => 50,
                        'AT' => 50,
                    ],
                    'axis_states' => [
                        'EI' => 'clear',
                        'SN' => 'clear',
                        'TF' => 'clear',
                        'JP' => 'clear',
                        'AT' => 'clear',
                    ],
                ],
            ],
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => 'result-score-v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);
    }

    public function test_public_mbti_result_and_report_access_ignore_tenant_token_realm_without_explicit_org(): void
    {
        $this->registerDebugRoutes();
        $this->seedScales();

        $anonId = 'anon_public_realm_result_owner';
        $actor = $this->createTenantUserToken($anonId);
        $attemptId = (string) Str::uuid();
        $this->createPublicMbtiAttemptAndResult($attemptId, $actor['user_id'], $anonId);

        $headers = [
            'Authorization' => 'Bearer '.$actor['token'],
            'X-Anon-Id' => $anonId,
        ];

        $resultDebug = $this->withHeaders($headers)
            ->getJson("/api/v0.3/attempts/debug-result/{$attemptId}");

        $resultDebug->assertStatus(200);
        $resultDebug->assertJsonPath('context_org_id', 0);
        $resultDebug->assertJsonPath('found', true);

        $result = $this->withHeaders($headers)
            ->getJson("/api/v0.3/attempts/{$attemptId}/result");

        $result->assertStatus(200);
        $result->assertJsonPath('attempt_id', $attemptId);
        $result->assertJsonPath('type_code', 'INTJ-A');

        $reportAccess = $this->withHeaders($headers)
            ->getJson("/api/v0.3/attempts/{$attemptId}/report-access");

        $reportAccess->assertStatus(200);
        $reportAccess->assertJsonPath('attempt_id', $attemptId);
        $reportAccess->assertJsonPath('report_state', 'ready');
    }

    public function test_public_attempt_routes_force_public_org_context_even_with_tenant_token(): void
    {
        $this->registerDebugRoutes();
        $anonId = 'anon_public_realm_debug';
        $actor = $this->createTenantUserToken($anonId);

        $orgOnly = $this->withHeaders([
            'Authorization' => 'Bearer '.$actor['token'],
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/debug-context-org', []);

        $orgOnly->assertStatus(200);
        $orgOnly->assertJsonPath('request_org_id', 0);
        $orgOnly->assertJsonPath('context_org_id', 0);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$actor['token'],
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/debug-context', []);

        $response->assertStatus(200);
        $response->assertJsonPath('request_org_id', 0);
        $response->assertJsonPath('request_fm_org_id', 0);
        $response->assertJsonPath('context_org_id', 0);
        $response->assertJsonPath('user_id', (string) $actor['user_id']);
        $response->assertJsonPath('anon_id', $anonId);

        $resultDebug = $this->withHeaders([
            'Authorization' => 'Bearer '.$actor['token'],
            'X-Anon-Id' => $anonId,
        ])->getJson('/api/v0.3/attempts/debug-result/'.Str::uuid());

        $resultDebug->assertStatus(200);
        $resultDebug->assertJsonPath('context_org_id', 0);

        $scaleDebug = $this->withHeaders([
            'Authorization' => 'Bearer '.$actor['token'],
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/debug-start-scale', []);

        $scaleDebug->assertStatus(200);
        $scaleDebug->assertJsonPath('context_org_id', 0);
        $scaleDebug->assertJsonPath('scale_found', true);
    }

    public function test_public_attempt_start_ignores_tenant_token_realm_without_explicit_org(): void
    {
        $this->registerDebugRoutes();
        $this->seedScales();

        $anonId = 'anon_public_realm_submit_owner';
        $actor = $this->createTenantUserToken($anonId);

        $headers = [
            'Authorization' => 'Bearer '.$actor['token'],
            'X-Anon-Id' => $anonId,
        ];

        $start = $this->withHeaders($headers)->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'MBTI',
            'anon_id' => $anonId,
        ]);

        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $attempt = Attempt::query()->findOrFail($attemptId);
        $this->assertSame(0, (int) $attempt->org_id);
        $this->assertSame($actor['user_id'], (int) $attempt->user_id);
    }
}
