<?php

declare(strict_types=1);

namespace Tests\Feature\Attempts;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EnneagramHistorySummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_attempts_enneagram_returns_form_and_history_summary(): void
    {
        (new ScaleRegistrySeeder)->run();

        $anonId = 'anon_enneagram_history';
        $token = $this->issueAnonToken($anonId);
        $olderAttemptId = $this->createSubmittedEnneagramAttempt($anonId, $token, 'enneagram_likert_105', 105000);
        $latestAttemptId = $this->createSubmittedEnneagramAttempt($anonId, $token, 'enneagram_forced_choice_144', 144000);
        DB::table('attempts')->where('id', $olderAttemptId)->update(['submitted_at' => now()->subMinutes(10)]);
        DB::table('attempts')->where('id', $latestAttemptId)->update(['submitted_at' => now()]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson('/api/v0.3/me/attempts?scale=ENNEAGRAM');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('scale_code', 'ENNEAGRAM');
        $response->assertJsonPath('items.0.attempt_id', $latestAttemptId);
        $response->assertJsonPath('items.0.enneagram_form_v1.form_code', 'enneagram_forced_choice_144');
        $response->assertJsonPath('items.0.enneagram_form_v1.question_count', 144);
        $response->assertJsonPath('items.0.access_summary.access_state', 'ready');
        $response->assertJsonPath('items.0.access_summary.report_state', 'ready');
        $response->assertJsonPath('items.0.access_summary.pdf_state', 'ready');
        $response->assertJsonPath('items.0.access_summary.actions.page_href', "/result/{$latestAttemptId}");
        $response->assertJsonPath('items.0.access_summary.actions.pdf_href', "/api/v0.3/attempts/{$latestAttemptId}/report.pdf");
        $this->assertNotSame('', (string) $response->json('items.0.enneagram_summary_v1.primary_type'));
        $response->assertJsonPath('items.1.attempt_id', $olderAttemptId);
        $response->assertJsonPath('items.1.enneagram_form_v1.form_code', 'enneagram_likert_105');
        $response->assertJsonPath('items.1.enneagram_form_v1.question_count', 105);
        $response->assertJsonPath('history_compare.scale_code', 'ENNEAGRAM');
        $response->assertJsonPath('history_compare.current_attempt_id', $latestAttemptId);
        $response->assertJsonPath('history_compare.previous_attempt_id', $olderAttemptId);
        $this->assertNotSame('', (string) $response->json('history_compare.current_primary_type'));
    }

    private function createSubmittedEnneagramAttempt(string $anonId, string $token, string $formCode, int $durationMs): string
    {
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
}
