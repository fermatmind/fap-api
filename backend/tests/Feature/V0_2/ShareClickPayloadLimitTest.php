<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use App\Services\Report\ReportComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

final class ShareClickPayloadLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_oversized_raw_payload_returns_413_and_does_not_write_share_click_event(): void
    {
        config(['security_limits.public_event_max_payload_bytes' => 16384]);

        $rawBody = json_encode([
            'meta_json' => [
                'blob' => str_repeat('x', 17000),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertIsString($rawBody);
        self::assertGreaterThan(16384, strlen($rawBody));

        $response = $this->call(
            'POST',
            '/api/v0.2/shares/share_limit_test_01/click',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $rawBody
        );

        $response->assertStatus(413);
        $response->assertJsonPath('error_code', 'PAYLOAD_TOO_LARGE');
        $response->assertJsonPath('details.max_bytes', 16384);

        $this->assertSame(0, DB::table('events')->where('event_code', 'share_click')->count());
    }

    public function test_meta_json_keys_limit_returns_413_and_does_not_write_share_click_event(): void
    {
        config([
            'security_limits.public_event_meta_max_keys' => 50,
            'security_limits.public_event_meta_max_bytes' => 4096,
        ]);

        $shareId = 'share_limit_test_02';
        $attemptId = (string) Str::uuid();
        $this->seedShare($shareId, $attemptId, 'anon_click_limit');

        $meta = [];
        for ($i = 0; $i < 60; $i++) {
            $meta['k' . $i] = 'v';
        }

        $response = $this->postJson("/api/v0.2/shares/{$shareId}/click", [
            'anon_id' => 'anon_click_limit',
            'meta_json' => $meta,
        ]);

        $response->assertStatus(413);
        $response->assertJsonPath('error_code', 'META_TOO_LARGE');
        $this->assertSame(0, DB::table('events')->where('event_code', 'share_click')->count());
    }

    public function test_valid_small_payload_returns_200_and_writes_share_click_event(): void
    {
        config(['features.enable_v0_2_report' => true]);

        $orgId = 1;
        $shareId = bin2hex(random_bytes(16));
        $attemptId = (string) Str::uuid();
        $anonId = 'anon_click_valid';

        $this->seedAttemptAndResult($attemptId, $orgId, $anonId, 'INTJ-A');
        $this->seedShare($shareId, $attemptId, $anonId);
        $this->seedShareGenerateEvent($shareId, $attemptId, $orgId, $anonId);

        $this->mock(ReportComposer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('compose')
                ->once()
                ->andReturn([
                    'ok' => true,
                    'report' => [
                        'profile' => ['type_code' => 'INTJ-A'],
                        '_meta' => [],
                    ],
                ]);
        });

        $response = $this->postJson("/api/v0.2/shares/{$shareId}/click", [
            'anon_id' => $anonId,
            'meta_json' => [
                'source' => 'payload_limit_test',
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('share_id', $shareId);
        $response->assertJsonPath('attempt_id', $attemptId);

        $this->assertSame(1, DB::table('events')->where('event_code', 'share_click')->count());
    }

    private function seedAttemptAndResult(string $attemptId, int $orgId, string $anonId, string $typeCode): void
    {
        $now = now();
        $packId = (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.2.2');
        $dirVersion = (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.2.2');

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'user_id' => null,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 10,
            'answers_summary_json' => json_encode(['answered' => 10], JSON_UNESCAPED_UNICODE),
            'client_platform' => 'web',
            'content_package_version' => 'v0.2.2',
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'started_at' => $now->copy()->subMinutes(3),
            'submitted_at' => $now->copy()->subMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('results')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'content_package_version' => 'v0.2.2',
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'type_code' => $typeCode,
            'scores_json' => json_encode(['EI' => ['a' => 10, 'b' => 20, 'sum' => -10, 'total' => 30]], JSON_UNESCAPED_UNICODE),
            'scores_pct' => json_encode(['EI' => 33, 'SN' => 50, 'TF' => 50, 'JP' => 50, 'AT' => 50], JSON_UNESCAPED_UNICODE),
            'axis_states' => json_encode(['EI' => 'clear', 'SN' => 'moderate', 'TF' => 'moderate', 'JP' => 'moderate', 'AT' => 'moderate'], JSON_UNESCAPED_UNICODE),
            'result_json' => json_encode(['type_code' => $typeCode, 'type_name' => 'Seeded'], JSON_UNESCAPED_UNICODE),
            'computed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedShare(string $shareId, string $attemptId, string $anonId): void
    {
        DB::table('shares')->insert([
            'id' => $shareId,
            'attempt_id' => $attemptId,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'content_package_version' => 'v0.2.2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedShareGenerateEvent(string $shareId, string $attemptId, int $orgId, string $anonId): void
    {
        DB::table('events')->insert([
            'id' => (string) Str::uuid(),
            'event_code' => 'share_generate',
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'attempt_id' => $attemptId,
            'meta_json' => json_encode([
                'share_id' => $shareId,
                'attempt_id' => $attemptId,
                'type_code' => 'INTJ-A',
                'content_package_version' => 'v0.2.2',
                'engine' => 'v1.2',
                'profile_version' => 'mbti32-v2.5',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
