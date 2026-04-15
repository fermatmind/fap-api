<?php

declare(strict_types=1);

namespace Tests\Feature\Attempts;

use App\Models\Attempt;
use App\Services\Attempts\AttemptStartService;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Big5RetakeCooldownTest extends TestCase
{
    use RefreshDatabase;

    private string $mappedBig5CanaryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mappedBig5CanaryPath = base_path('content_packs/BIG5_OCEAN_POLICY_CANARY');
        File::deleteDirectory($this->mappedBig5CanaryPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->mappedBig5CanaryPath);
        parent::tearDown();
    }

    public function test_big5_retake_cooldown_is_disabled_and_allows_recent_restart(): void
    {
        (new ScaleRegistrySeeder)->run();

        $anonId = 'anon_big5_cooldown';
        $this->createAttempt($anonId, now()->subHours(1), 'big5_120');

        $response = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'BIG5_OCEAN',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => $anonId,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $this->assertNotEmpty((string) $response->json('attempt_id'));
    }

    public function test_big5_retake_limit_is_disabled_and_allows_repeated_attempts_in_30_days(): void
    {
        (new ScaleRegistrySeeder)->run();

        $anonId = 'anon_big5_retake_limit';
        $this->createAttempt($anonId, now()->subDays(2), 'big5_120');
        $this->createAttempt($anonId, now()->subDays(10), 'big5_120');
        $this->createAttempt($anonId, now()->subDays(20), 'big5_120');

        $response = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'BIG5_OCEAN',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => $anonId,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $this->assertNotEmpty((string) $response->json('attempt_id'));
    }

    public function test_big5_retake_cooldown_is_form_aware_from_120_to_90(): void
    {
        (new ScaleRegistrySeeder)->run();

        $anonId = 'anon_big5_form_aware_120_to_90';

        $start120 = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'BIG5_OCEAN',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => $anonId,
            'form_code' => 'big5_120',
        ]);
        $start120->assertStatus(200);
        $start120->assertJsonPath('form_code', 'big5_120');

        $start90 = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'BIG5_OCEAN',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => $anonId,
            'form_code' => 'big5_90',
        ]);
        $start90->assertStatus(200);
        $start90->assertJsonPath('form_code', 'big5_90');
    }

    public function test_big5_retake_cooldown_is_form_aware_from_90_to_120(): void
    {
        (new ScaleRegistrySeeder)->run();

        $anonId = 'anon_big5_form_aware_90_to_120';

        $start90 = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'BIG5_OCEAN',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => $anonId,
            'form_code' => 'big5_90',
        ]);
        $start90->assertStatus(200);
        $start90->assertJsonPath('form_code', 'big5_90');

        $start120 = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'BIG5_OCEAN',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => $anonId,
            'form_code' => 'big5_120',
        ]);
        $start120->assertStatus(200);
        $start120->assertJsonPath('form_code', 'big5_120');
    }

    public function test_non_big5_scales_keep_start_behavior_unchanged(): void
    {
        (new ScaleRegistrySeeder)->run();

        $anonId = 'anon_mbti_retake_control';

        $first = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'MBTI',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => $anonId,
        ]);
        $first->assertStatus(200);
        $first->assertJsonPath('ok', true);

        $second = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'MBTI',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => $anonId,
        ]);
        $second->assertStatus(200);
        $second->assertJsonPath('ok', true);
    }

    public function test_big5_retake_policy_reads_mapped_raw_policy_when_content_path_mode_is_v2(): void
    {
        (new ScaleRegistrySeeder)->run();

        config()->set('scale_identity.content_path_mode', 'v2');
        DB::table('content_path_aliases')->updateOrInsert(
            [
                'scope' => 'backend_content_packs',
                'old_path' => 'content_packs/BIG5_OCEAN',
            ],
            [
                'new_path' => 'content_packs/BIG5_OCEAN_POLICY_CANARY',
                'scale_uid' => null,
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        File::ensureDirectoryExists($this->mappedBig5CanaryPath.'/v1/raw');
        file_put_contents($this->mappedBig5CanaryPath.'/v1/raw/policy.json', json_encode([
            'retake' => [
                'cooldown_hours' => 0,
                'max_attempts_per_30_days' => 1,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $service = app(AttemptStartService::class);
        $method = new \ReflectionMethod($service, 'resolveBigFiveRetakePolicy');
        $method->setAccessible(true);
        /** @var array{cooldown_hours:int,max_attempts_per_30_days:int} $policy */
        $policy = $method->invoke($service, 'v1');

        $this->assertSame(0, (int) ($policy['cooldown_hours'] ?? -1));
        $this->assertSame(1, (int) ($policy['max_attempts_per_30_days'] ?? -1));
    }

    private function createAttempt(string $anonId, \DateTimeInterface $startedAt, string $formCode = 'big5_120'): void
    {
        $normalizedFormCode = strtolower(trim($formCode)) === 'big5_90' ? 'big5_90' : 'big5_120';
        $dirVersion = $normalizedFormCode === 'big5_90' ? 'v1-form-90' : 'v1';
        $questionCount = $normalizedFormCode === 'big5_90' ? 90 : 120;
        $scoringSpecVersion = $normalizedFormCode === 'big5_90'
            ? 'big5_spec_2026Q2_form90_v1'
            : 'big5_spec_2026Q1_v1';

        Attempt::query()->create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => $questionCount,
            'client_platform' => 'test',
            'started_at' => $startedAt,
            'submitted_at' => $startedAt,
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => $dirVersion,
            'content_package_version' => $dirVersion,
            'scoring_spec_version' => $scoringSpecVersion,
            'answers_summary_json' => [
                'stage' => 'seed',
                'meta' => [
                    'form_code' => $normalizedFormCode,
                ],
            ],
        ]);
    }
}
