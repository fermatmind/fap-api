<?php

declare(strict_types=1);

namespace Tests\Feature\Attempts;

use App\Models\Attempt;
use App\Models\UnifiedAccessProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BigFiveHistoryCompareTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_attempts_big5_returns_history_compare_summary(): void
    {
        $userId = 8101;
        $anonId = 'anon_big5_history';
        $this->seedUser($userId);
        $token = $this->seedFmToken($anonId, $userId);

        $olderAttemptId = $this->seedBigFiveAttempt($anonId, (string) $userId, now()->subDays(2));
        $latestAttemptId = $this->seedBigFiveAttempt($anonId, (string) $userId, now()->subDay());

        $this->seedBigFiveResult($olderAttemptId, ['O' => 3.0, 'C' => 3.1, 'E' => 3.2, 'A' => 3.3, 'N' => 3.4]);
        $this->seedBigFiveResult($latestAttemptId, ['O' => 3.5, 'C' => 3.1, 'E' => 3.0, 'A' => 3.6, 'N' => 3.2]);
        $this->seedAccessProjection($olderAttemptId, [
            'access_state' => 'locked',
            'report_state' => 'ready',
            'pdf_state' => 'missing',
            'reason_code' => 'preview_only',
            'payload_json' => [
                'access_level' => 'preview',
                'variant' => 'free',
                'modules_allowed' => ['summary'],
                'modules_preview' => ['report.full'],
            ],
        ]);
        $this->seedAccessProjection($latestAttemptId, [
            'access_state' => 'ready',
            'report_state' => 'ready',
            'pdf_state' => 'ready',
            'reason_code' => 'entitlement_granted',
            'payload_json' => [
                'access_level' => 'full',
                'variant' => 'full',
                'modules_allowed' => ['summary', 'report.full', 'pdf'],
                'modules_preview' => [],
            ],
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/v0.3/me/attempts?scale=BIG5_OCEAN');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('scale_code', 'BIG5_OCEAN');
        $response->assertJsonPath('history_compare.current_attempt_id', $latestAttemptId);
        $response->assertJsonPath('history_compare.previous_attempt_id', $olderAttemptId);
        $response->assertJsonPath('history_compare.current_domains_mean.O', 3.5);
        $response->assertJsonPath('history_compare.previous_domains_mean.O', 3);
        $response->assertJsonPath('history_compare.current_domains_mean.A', 3.6);
        $response->assertJsonPath('history_compare.previous_domains_mean.A', 3.3);
        $response->assertJsonPath('history_compare.domains_delta.O.delta', 0.5);
        $response->assertJsonPath('history_compare.domains_delta.O.direction', 'up');
        $response->assertJsonPath('history_compare.domains_delta.E.delta', -0.2);
        $response->assertJsonPath('history_compare.domains_delta.E.direction', 'down');
        $response->assertJsonPath('items.0.attempt_id', $latestAttemptId);
        $response->assertJsonPath('items.0.result_summary.domains_mean.O', 3.5);
        $response->assertJsonPath('items.0.access_summary.access_state', 'ready');
        $response->assertJsonPath('items.0.access_summary.report_state', 'ready');
        $response->assertJsonPath('items.0.access_summary.pdf_state', 'ready');
        $response->assertJsonPath('items.0.access_summary.reason_code', 'entitlement_granted');
        $response->assertJsonPath('items.0.access_summary.access_level', 'full');
        $response->assertJsonPath('items.0.access_summary.variant', 'full');
        $response->assertJsonPath('items.0.access_summary.actions.page_href', "/result/{$latestAttemptId}");
        $response->assertJsonPath('items.0.access_summary.actions.pdf_href', "/api/v0.3/attempts/{$latestAttemptId}/report.pdf");
        $response->assertJsonPath('items.1.access_summary.access_state', 'locked');
        $response->assertJsonPath('items.1.access_summary.report_state', 'ready');
        $response->assertJsonPath('items.1.access_summary.pdf_state', 'unavailable');
        $response->assertJsonPath('items.1.access_summary.access_level', 'preview');
        $response->assertJsonPath('items.1.access_summary.variant', 'free');
        $response->assertJsonPath('items.1.access_summary.actions.page_href', "/result/{$olderAttemptId}");
        $response->assertJsonPath('items.1.access_summary.actions.pdf_href', null);
    }

    private function seedBigFiveAttempt(string $anonId, string $userId, \DateTimeInterface $submittedAt): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'ticket_code' => 'FMT-' . strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 8)),
            'org_id' => 0,
            'anon_id' => $anonId,
            'user_id' => $userId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v1',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 120,
            'answers_summary_json' => ['seed' => true],
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => (clone $submittedAt)->modify('-10 minutes'),
            'submitted_at' => $submittedAt,
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
        ]);

        return $attemptId;
    }

    /**
     * @param array{O:float,C:float,E:float,A:float,N:float} $domainsMean
     */
    private function seedBigFiveResult(string $attemptId, array $domainsMean): void
    {
        DB::table('results')->insert([
            'id' => (string) Str::uuid(),
            'attempt_id' => $attemptId,
            'org_id' => 0,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v1',
            'type_code' => 'BIG5',
            'scores_json' => json_encode(['domains_mean' => $domainsMean], JSON_UNESCAPED_UNICODE),
            'scores_pct' => json_encode([], JSON_UNESCAPED_UNICODE),
            'axis_states' => json_encode([], JSON_UNESCAPED_UNICODE),
            'profile_version' => null,
            'content_package_version' => 'v1',
            'result_json' => json_encode([
                'raw_scores' => [
                    'domains_mean' => $domainsMean,
                    'facets_mean' => [],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => 1,
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function seedAccessProjection(string $attemptId, array $attributes): void
    {
        UnifiedAccessProjection::query()->create(array_merge([
            'attempt_id' => $attemptId,
            'access_state' => 'locked',
            'report_state' => 'pending',
            'pdf_state' => 'missing',
            'reason_code' => null,
            'projection_version' => 1,
            'actions_json' => [],
            'payload_json' => [],
            'produced_at' => now(),
            'refreshed_at' => now(),
        ], $attributes));
    }

    private function seedUser(int $id): void
    {
        DB::table('users')->insert([
            'id' => $id,
            'name' => "user_{$id}",
            'email' => "user_{$id}@example.test",
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedFmToken(string $anonId, int $userId): string
    {
        $token = 'fm_' . (string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'anon_id' => $anonId,
            'user_id' => $userId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
