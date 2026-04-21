<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EnneagramPdfDeliveryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('enneagramFormsProvider')]
    public function test_enneagram_report_pdf_endpoint_returns_pdf_payload(string $formCode, string $anonId): void
    {
        (new ScaleRegistrySeeder)->run();

        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createSubmittedEnneagramAttempt($anonId, $token, $formCode);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->get('/api/v0.3/attempts/'.$attemptId.'/report.pdf?inline=1');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Report-Scale', 'ENNEAGRAM');
        $response->assertHeader('X-Report-Variant', 'full');
        $response->assertHeader('X-Report-Locked', 'false');
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        $this->assertDatabaseHas('events', [
            'event_code' => 'report_pdf_view',
            'attempt_id' => $attemptId,
        ]);
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function enneagramFormsProvider(): iterable
    {
        yield '105 likert' => ['enneagram_likert_105', 'anon_enneagram_pdf_105'];
        yield '144 forced choice' => ['enneagram_forced_choice_144', 'anon_enneagram_pdf_144'];
    }

    private function createSubmittedEnneagramAttempt(string $anonId, string $token, string $formCode): string
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
            'duration_ms' => 180000,
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
