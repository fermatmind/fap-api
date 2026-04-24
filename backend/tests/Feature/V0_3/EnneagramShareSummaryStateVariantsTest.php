<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EnneagramShareSummaryStateVariantsTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('stateProvider')]
    public function test_share_summary_uses_scope_specific_public_safe_copy(
        string $scope,
        string $expectedSummary,
        ?string $expectedPairKey
    ): void {
        (new ScaleRegistrySeeder)->run();

        [$attemptId, $anonId, $token] = $this->createSubmittedEnneagramAttempt('anon_enneagram_share_'.$scope, 'enneagram_likert_105');
        $this->primeSnapshot($attemptId, $anonId, $token);
        $this->overwriteSnapshotState($attemptId, $scope, '4', '5', '9');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/share");

        $response->assertOk();
        $response->assertJsonPath('enneagram_public_summary_v1.interpretation_scope', $scope);
        $this->assertStringContainsString($expectedSummary, (string) $response->json('summary'));
        $this->assertSame($expectedPairKey, data_get($response->json(), 'enneagram_public_summary_v1.close_call_pair.pair_key'));
    }

    /**
     * @return iterable<string,array{string,string,?string}>
     */
    public static function stateProvider(): iterable
    {
        yield 'clear' => ['clear', '最可能是 4 号', null];
        yield 'close_call' => ['close_call', '可能在 4 号与 5 号之间摇摆', '4_5'];
        yield 'diffuse' => ['diffuse', '呈现分散结构', null];
        yield 'low_quality' => ['low_quality', '解释边界较宽', null];
    }

    /**
     * @return array{string,string,string}
     */
    private function createSubmittedEnneagramAttempt(string $anonId, string $formCode): array
    {
        $token = $this->issueAnonToken($anonId);
        $start = $this->withHeaders(['X-Anon-Id' => $anonId])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'ENNEAGRAM',
            'anon_id' => $anonId,
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'form_code' => $formCode,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');

        $questions = $this->getJson('/api/v0.3/scales/ENNEAGRAM/questions?form_code='.$formCode);
        $questions->assertStatus(200);
        $answers = $this->buildAnswersFromItems((array) $questions->json('questions.items'));

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 180000,
        ]);
        $submit->assertStatus(200);

        return [$attemptId, $anonId, $token];
    }

    private function primeSnapshot(string $attemptId, string $anonId, string $token): void
    {
        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report")->assertOk();
    }

    private function overwriteSnapshotState(string $attemptId, string $scope, string $primary, string $second, string $third): void
    {
        $row = DB::table('report_snapshots')->where('attempt_id', $attemptId)->first();
        $report = json_decode((string) ($row->report_full_json ?? '{}'), true);
        $this->assertIsArray($report);

        data_set($report, '_meta.enneagram_public_projection_v2.scores.primary_candidate', $primary);
        data_set($report, '_meta.enneagram_public_projection_v2.scores.second_candidate', $second);
        data_set($report, '_meta.enneagram_public_projection_v2.scores.third_candidate', $third);
        data_set($report, '_meta.enneagram_public_projection_v2.scores.top_types', [
            ['type' => $primary, 'candidate_role' => 'primary', 'display_score' => 82],
            ['type' => $second, 'candidate_role' => 'secondary', 'display_score' => 76],
            ['type' => $third, 'candidate_role' => 'tertiary', 'display_score' => 63],
        ]);
        data_set($report, '_meta.enneagram_public_projection_v2.scores.all9_profile', $this->all9Profile($primary, $second, $third));
        data_set($report, '_meta.enneagram_public_projection_v2.classification.interpretation_scope', $scope);
        data_set($report, '_meta.enneagram_public_projection_v2.classification.confidence_level', $scope === 'low_quality' ? 'boundary' : 'medium');
        data_set($report, '_meta.enneagram_public_projection_v2.classification.confidence_label', '两型接近');
        data_set($report, '_meta.enneagram_public_projection_v2.classification.interpretation_reason', 'share_scope_test');
        data_set($report, '_meta.enneagram_public_projection_v2.dynamics.close_call_pair', $scope === 'close_call' ? [
            'pair_key' => '4_5',
            'type_a' => '4',
            'type_b' => '5',
        ] : null);
        data_set($report, '_meta.enneagram_report_v2.classification.interpretation_scope', $scope);
        data_set($report, '_meta.enneagram_report_v2.classification.confidence_level', $scope === 'low_quality' ? 'boundary' : 'medium');
        data_set($report, '_meta.enneagram_report_v2.classification.interpretation_reason', 'share_scope_test');

        DB::table('report_snapshots')->where('attempt_id', $attemptId)->update([
            'report_full_json' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'report_free_json' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'report_json' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function all9Profile(string $primary, string $second, string $third): array
    {
        $ordered = [$primary, $second, $third, '1', '2', '3', '6', '7', '8'];
        $seen = [];
        $items = [];
        foreach ($ordered as $index => $type) {
            if (isset($seen[$type])) {
                continue;
            }
            $seen[$type] = true;
            $items[] = [
                'type' => $type,
                'rank' => count($items) + 1,
                'display_score' => max(10, 90 - ($index * 8)),
            ];
        }

        return array_slice($items, 0, 9);
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array{question_id:string,code:string}>
     */
    private function buildAnswersFromItems(array $items): array
    {
        $answers = [];
        foreach ($items as $index => $item) {
            $questionId = trim((string) ($item['question_id'] ?? ''));
            $options = is_array($item['options'] ?? null) ? $item['options'] : [];
            if ($questionId === '' || $options === []) {
                continue;
            }

            $selected = $options[$index % count($options)] ?? $options[0];
            $answers[] = [
                'question_id' => $questionId,
                'code' => trim((string) ($selected['code'] ?? '')),
            ];
        }

        return array_values(array_filter($answers, static fn (array $answer): bool => $answer['code'] !== ''));
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
