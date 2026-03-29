<?php

declare(strict_types=1);

namespace Tests\Feature\Attempts;

use App\Models\Attempt;
use App\Models\UnifiedAccessProjection;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BigFiveHistoryCompareTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_attempts_big5_returns_history_compare_summary(): void
    {
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();

        $userId = 8101;
        $anonId = 'anon_big5_history';
        $this->seedUser($userId);
        $token = $this->seedFmToken($anonId, $userId);

        $olderAttemptId = $this->seedBigFiveAttempt($anonId, (string) $userId, now()->subDays(2));
        $latestAttemptId = $this->seedBigFiveAttempt($anonId, (string) $userId, now()->subDay());

        $this->seedBigFiveResult(
            $olderAttemptId,
            ['O' => 3.0, 'C' => 3.1, 'E' => 3.2, 'A' => 3.3, 'N' => 3.4],
            [
                'scores_0_100' => [
                    'domains_percentile' => ['O' => 42, 'C' => 48, 'E' => 55, 'A' => 63, 'N' => 71],
                    'facets_percentile' => ['N1' => 79, 'E2' => 35, 'A3' => 66],
                ],
                'facts' => [
                    'facet_buckets' => ['N1' => 'high', 'E2' => 'low', 'A3' => 'high'],
                    'top_strength_facets' => ['N1', 'A3'],
                    'top_growth_facets' => ['E2'],
                ],
                'quality' => ['level' => 'B'],
                'norms' => ['status' => 'CALIBRATED', 'norms_version' => '2025Q4'],
            ]
        );
        $this->seedBigFiveResult(
            $latestAttemptId,
            ['O' => 3.5, 'C' => 3.1, 'E' => 3.0, 'A' => 3.6, 'N' => 3.2],
            [
                'scores_0_100' => [
                    'domains_percentile' => ['O' => 84, 'C' => 63, 'E' => 51, 'A' => 78, 'N' => 40],
                    'facets_percentile' => ['O5' => 88, 'A3' => 73, 'C4' => 69, 'E2' => 34],
                ],
                'facts' => [
                    'facet_buckets' => ['O5' => 'high', 'A3' => 'high', 'C4' => 'mid', 'E2' => 'low'],
                    'top_strength_facets' => ['O5', 'A3', 'C4'],
                    'top_growth_facets' => ['E2'],
                ],
                'quality' => ['level' => 'A'],
                'norms' => ['status' => 'CALIBRATED', 'norms_version' => '2026Q1'],
            ]
        );
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
        $response->assertJsonPath('items.0.top_facets_summary_v1.items.0.key', 'O5');
        $response->assertJsonPath('items.0.top_facets_summary_v1.items.1.key', 'A3');
        $response->assertJsonPath('items.0.quality_summary.level', 'A');
        $response->assertJsonPath('items.0.quality_summary.grade', 'A');
        $response->assertJsonPath('items.0.norms_summary.status', 'CALIBRATED');
        $response->assertJsonPath('items.0.norms_summary.norms_version', '2026Q1');
        $response->assertJsonPath('items.0.offer_summary.primary_offer', null);
        $response->assertJsonPath('items.0.share_summary.enabled', true);
        $response->assertJsonPath('items.0.share_summary.share_kind', 'big5_result');
        $response->assertJsonPath('items.1.access_summary.access_state', 'locked');
        $response->assertJsonPath('items.1.access_summary.report_state', 'ready');
        $response->assertJsonPath('items.1.access_summary.pdf_state', 'unavailable');
        $response->assertJsonPath('items.1.access_summary.access_level', 'preview');
        $response->assertJsonPath('items.1.access_summary.variant', 'free');
        $response->assertJsonPath('items.1.access_summary.actions.page_href', "/result/{$olderAttemptId}");
        $response->assertJsonPath('items.1.access_summary.actions.pdf_href', null);
        $response->assertJsonPath('items.1.top_facets_summary_v1.items.0.key', 'N1');
        $response->assertJsonPath('items.1.quality_summary.level', 'B');
        $response->assertJsonPath('items.1.quality_summary.grade', 'B');
        $response->assertJsonPath('items.1.norms_summary.status', 'CALIBRATED');
        $response->assertJsonPath('items.1.norms_summary.norms_version', '2025Q4');
        $response->assertJsonPath('items.1.offer_summary.primary_offer.sku', 'SKU_BIG5_FULL_REPORT_299');
        $response->assertJsonPath('items.1.offer_summary.primary_offer.benefit_code', 'BIG5_FULL_REPORT');
        $response->assertJsonPath('items.1.offer_summary.primary_offer.formatted_price', '¥2.99');
        $response->assertJsonPath('items.1.offer_summary.primary_offer.modules_included.0', 'big5_full');
        $response->assertJsonPath('items.1.share_summary.enabled', true);
    }

    private function seedBigFiveAttempt(string $anonId, string $userId, \DateTimeInterface $submittedAt): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 8)),
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
     * @param  array{O:float,C:float,E:float,A:float,N:float}  $domainsMean
     */
    private function seedBigFiveResult(string $attemptId, array $domainsMean, array $overrides = []): void
    {
        $scorePayload = array_replace_recursive([
            'raw_scores' => [
                'domains_mean' => $domainsMean,
                'facets_mean' => [],
            ],
            'scores_0_100' => [
                'domains_percentile' => [],
                'facets_percentile' => [],
            ],
            'facts' => [
                'facet_buckets' => [],
                'top_strength_facets' => [],
                'top_growth_facets' => [],
            ],
            'quality' => [
                'level' => 'A',
            ],
            'norms' => [
                'status' => 'MISSING',
                'norms_version' => null,
            ],
        ], $overrides);

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
                'normed_json' => $scorePayload,
                'breakdown_json' => ['score_result' => $scorePayload],
                'axis_scores_json' => ['score_result' => $scorePayload],
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
        $token = 'fm_'.(string) Str::uuid();

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
