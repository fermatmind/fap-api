<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Auth\FmTokenService;
use App\Services\Report\ReportComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

final class ShareClickSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_click_ignores_client_attempt_id_and_uses_share_bound_attempt(): void
    {
        config(['features.enable_v0_2_report' => true]);

        $orgId = 1;
        $ownerUserId = $this->seedUser('share-click-owner@example.com');
        $otherUserId = $this->seedUser('share-click-other@example.com');
        $attemptA = (string) Str::uuid();
        $attemptB = (string) Str::uuid();
        $shareId = bin2hex(random_bytes(16));

        $this->seedAttemptAndResult($attemptA, $orgId, 'anon_click_owner', (string) $ownerUserId, 'INTJ-A');
        $this->seedAttemptAndResult($attemptB, $orgId, 'anon_click_other', (string) $otherUserId, 'ENTP-A');
        $this->seedShare($shareId, $attemptA, 'anon_click_owner');
        $this->seedShareGenerateEvent($shareId, $attemptA, $orgId, 'anon_click_owner');

        $this->mock(ReportComposer::class, function (MockInterface $mock) use ($attemptA): void {
            $mock->shouldReceive('compose')
                ->once()
                ->withArgs(function (Attempt $attempt, array $ctx, Result $result) use ($attemptA): bool {
                    return (string) $attempt->id === $attemptA
                        && (string) ($ctx['attempt_id'] ?? '') === $attemptA
                        && (string) $result->attempt_id === $attemptA;
                })
                ->andReturn([
                    'ok' => true,
                    'report' => [
                        'profile' => ['type_code' => 'INTJ-A'],
                        '_meta' => [],
                    ],
                ]);
        });

        $response = $this->postJson("/api/v0.2/shares/{$shareId}/click", [
            'attempt_id' => $attemptB,
            'anon_id' => 'anon_click_owner',
            'meta_json' => [
                'attempt_id' => $attemptB,
                'source' => 'attack-sim',
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('attempt_id', $attemptA);

        $eventId = (string) $response->json('id');
        $row = DB::table('events')->where('id', $eventId)->first();

        $this->assertNotNull($row);
        $this->assertSame($attemptA, (string) ($row->attempt_id ?? ''));

        $meta = $this->decodeJsonObject($row->meta_json ?? null);
        $this->assertSame($attemptA, (string) ($meta['attempt_id'] ?? ''));
    }

    public function test_report_endpoint_returns_404_when_user_b_reads_user_a_attempt(): void
    {
        config(['features.enable_v0_2_report' => true]);

        $orgId = 2;
        $userA = $this->seedUser('report-owner@example.com');
        $userB = $this->seedUser('report-attacker@example.com');

        $attemptId = (string) Str::uuid();
        $this->seedAttemptAndResult($attemptId, $orgId, 'anon_report_owner', (string) $userA, 'INTJ-A');

        $tokenB = $this->issueTokenForUser($userB);

        $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->withHeader('X-Org-Id', (string) $orgId)
            ->getJson("/api/v0.2/attempts/{$attemptId}/report")
            ->assertStatus(404);
    }

    private function issueTokenForUser(int $userId): string
    {
        $issued = app(FmTokenService::class)->issueForUser((string) $userId);
        return (string) ($issued['token'] ?? '');
    }

    private function seedUser(string $email): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => 'User ' . $email,
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedAttemptAndResult(string $attemptId, int $orgId, string $anonId, string $userId, string $typeCode): void
    {
        $now = now();
        $packId = (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.2.2');
        $dirVersion = (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.2.2');

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'user_id' => $userId,
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

    private function decodeJsonObject(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        if (is_object($raw)) {
            return (array) $raw;
        }

        return [];
    }
}
