<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait BuildsEnneagramAttempts
{
    private function createSubmittedEnneagramAttempt(
        string $anonId,
        string $token,
        string $formCode = 'enneagram_likert_105',
        int $durationMs = 105000
    ): string {
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
            'duration_ms' => $durationMs,
        ]);
        $submit->assertStatus(200);

        return $attemptId;
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

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function patchEnneagramProjection(
        string $attemptId,
        string $formCode,
        string $scope,
        array $overrides = []
    ): void {
        $row = DB::table('results')->where('attempt_id', $attemptId)->first();
        $payload = json_decode((string) ($row->result_json ?? '{}'), true);
        if (! is_array($payload)) {
            $payload = [];
        }

        $projection = array_replace_recursive([
            'schema_version' => 'enneagram.public_projection.v2',
            'projection_version' => 'enneagram_projection.v2',
            'form' => [
                'form_code' => $formCode,
                'score_space_version' => $formCode === 'enneagram_forced_choice_144'
                    ? 'fc144_forced_choice_space.v1'
                    : 'e105_likert_space.v1',
            ],
            'methodology' => [
                'compare_compatibility_group' => $formCode,
                'cross_form_comparable' => false,
            ],
            'classification' => [
                'interpretation_scope' => $scope,
                'confidence_level' => 'medium',
                'interpretation_reason' => 'test fixture',
            ],
            'dynamics' => [
                'close_call_pair' => [
                    'pair_key' => 'T4_T5',
                    'type_a' => 'T4',
                    'type_b' => 'T5',
                ],
            ],
            'content_binding' => [
                'interpretation_context_id' => 'ic-'.$attemptId,
                'content_release_hash' => 'sha256:test-release',
                'content_snapshot_status' => 'frozen',
            ],
            'top_types' => [
                ['type_code' => 'T4'],
                ['type_code' => 'T5'],
                ['type_code' => 'T1'],
            ],
        ], $overrides);

        $payload['enneagram_public_projection_v2'] = $projection;

        DB::table('results')
            ->where('attempt_id', $attemptId)
            ->update([
                'result_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
    }
}
