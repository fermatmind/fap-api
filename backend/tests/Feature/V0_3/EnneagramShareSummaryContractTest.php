<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EnneagramShareSummaryContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_enneagram_share_summary_uses_snapshot_safe_public_contract(): void
    {
        (new ScaleRegistrySeeder)->run();

        [$attemptId, $anonId, $token] = $this->createSubmittedEnneagramAttempt('anon_enneagram_share_contract', 'enneagram_likert_105');
        $this->primeSnapshot($attemptId, $anonId, $token);
        $this->overwriteSnapshotState($attemptId, 'clear', '4', '5', '9');
        $this->mutateResultProjection($attemptId, 'diffuse', '1', '2', '3');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/share");

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('scale_code', 'ENNEAGRAM')
            ->assertJsonPath('enneagram_public_summary_v1.version', 'enneagram.public_summary.v1')
            ->assertJsonPath('enneagram_public_summary_v1.public_surface_version', 'enneagram.public_surface.v1')
            ->assertJsonPath('enneagram_public_summary_v1.form_code', 'enneagram_likert_105')
            ->assertJsonPath('enneagram_public_summary_v1.form_label', '105题李克特版')
            ->assertJsonPath('enneagram_public_summary_v1.form_kind', 'likert')
            ->assertJsonPath('enneagram_public_summary_v1.methodology_variant', 'e105_standard')
            ->assertJsonPath('enneagram_public_summary_v1.primary_candidate', '4')
            ->assertJsonPath('enneagram_public_summary_v1.second_candidate', '5')
            ->assertJsonPath('enneagram_public_summary_v1.third_candidate', '9')
            ->assertJsonPath('enneagram_public_summary_v1.interpretation_scope', 'clear')
            ->assertJsonPath('enneagram_public_summary_v1.cross_form_comparable', false)
            ->assertJsonPath('enneagram_public_summary_v1.report_schema_version', 'enneagram.report.v2')
            ->assertJsonMissingPath('mbti_public_summary_v1');

        $this->assertCount(3, (array) $response->json('enneagram_public_summary_v1.top_types'));
        $this->assertCount(9, (array) $response->json('enneagram_public_summary_v1.all9_profile_mini'));
        $this->assertNotSame('', (string) $response->json('enneagram_public_summary_v1.compare_compatibility_group'));
        $this->assertNotSame('', (string) $response->json('enneagram_public_summary_v1.registry_release_hash'));
        $this->assertNotSame('', (string) $response->json('enneagram_public_summary_v1.projection_version'));
        $this->assertNotSame('', (string) $response->json('enneagram_public_summary_v1.interpretation_context_id'));
        $this->assertNotSame('', (string) $response->json('enneagram_public_summary_v1.generated_at'));
        $this->assertStringContainsString('最可能是 4 号', (string) $response->json('summary'));
        $this->assertSame('4', (string) $response->json('type_code'));
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
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $response->assertOk();
    }

    private function overwriteSnapshotState(string $attemptId, string $scope, string $primary, string $second, string $third): void
    {
        $row = DB::table('report_snapshots')->where('attempt_id', $attemptId)->first();
        $report = json_decode((string) ($row->report_full_json ?? '{}'), true);
        $this->assertIsArray($report);

        data_set($report, '_meta.enneagram_public_projection_v2.scores.primary_candidate', $primary);
        data_set($report, '_meta.enneagram_public_projection_v2.scores.second_candidate', $second);
        data_set($report, '_meta.enneagram_public_projection_v2.scores.third_candidate', $third);
        data_set($report, '_meta.enneagram_public_projection_v2.scores.top_types', $this->topTypes($primary, $second, $third));
        data_set($report, '_meta.enneagram_public_projection_v2.scores.all9_profile', $this->all9Profile($primary, $second, $third));
        data_set($report, '_meta.enneagram_public_projection_v2.classification.interpretation_scope', $scope);
        data_set($report, '_meta.enneagram_public_projection_v2.classification.confidence_level', $scope === 'low_quality' ? 'boundary' : 'medium');
        data_set($report, '_meta.enneagram_public_projection_v2.classification.confidence_label', '两型接近');
        data_set($report, '_meta.enneagram_public_projection_v2.classification.interpretation_reason', 'snapshot_test_override');
        data_set($report, '_meta.enneagram_public_projection_v2.dynamics.close_call_pair', $scope === 'close_call' ? [
            'pair_key' => $primary.'_'.$second,
            'type_a' => $primary,
            'type_b' => $second,
        ] : null);
        data_set($report, '_meta.enneagram_report_v2.classification.interpretation_scope', $scope);
        data_set($report, '_meta.enneagram_report_v2.classification.confidence_level', $scope === 'low_quality' ? 'boundary' : 'medium');
        data_set($report, '_meta.enneagram_report_v2.classification.interpretation_reason', 'snapshot_test_override');
        $report = $this->overwriteTop3Module($report, $primary, $second, $third);

        DB::table('report_snapshots')->where('attempt_id', $attemptId)->update([
            'report_full_json' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'report_free_json' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'report_json' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    private function mutateResultProjection(string $attemptId, string $scope, string $primary, string $second, string $third): void
    {
        $row = DB::table('results')->where('attempt_id', $attemptId)->first();
        $payload = json_decode((string) ($row->result_json ?? '{}'), true);
        $this->assertIsArray($payload);

        data_set($payload, 'enneagram_public_projection_v2.scores.primary_candidate', $primary);
        data_set($payload, 'enneagram_public_projection_v2.scores.second_candidate', $second);
        data_set($payload, 'enneagram_public_projection_v2.scores.third_candidate', $third);
        data_set($payload, 'enneagram_public_projection_v2.classification.interpretation_scope', $scope);

        DB::table('results')->where('attempt_id', $attemptId)->update([
            'result_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function overwriteTop3Module(array $report, string $primary, string $second, string $third): array
    {
        $pages = is_array(data_get($report, '_meta.enneagram_report_v2.pages')) ? data_get($report, '_meta.enneagram_report_v2.pages') : [];
        foreach ($pages as $pageIndex => $page) {
            foreach ((array) ($page['modules'] ?? []) as $moduleIndex => $module) {
                if ((string) ($module['module_key'] ?? '') !== 'top3_cards') {
                    continue;
                }
                data_set($pages, "{$pageIndex}.modules.{$moduleIndex}.content.cards", $this->topTypes($primary, $second, $third));
            }
        }
        data_set($report, '_meta.enneagram_report_v2.pages', $pages);

        return $report;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function topTypes(string $primary, string $second, string $third): array
    {
        return [
            ['type' => $primary, 'candidate_role' => 'primary', 'display_score' => 82],
            ['type' => $second, 'candidate_role' => 'secondary', 'display_score' => 76],
            ['type' => $third, 'candidate_role' => 'tertiary', 'display_score' => 63],
        ];
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
