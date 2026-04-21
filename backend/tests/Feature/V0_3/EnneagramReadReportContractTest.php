<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Result;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EnneagramReadReportContractTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('enneagramFormsProvider')]
    public function test_enneagram_result_report_and_access_expose_formal_public_projection(
        string $formCode,
        int $questionCount,
        string $anonId
    ): void {
        (new ScaleRegistrySeeder)->run();

        [$attemptId, $anonId, $token] = $this->createSubmittedEnneagramAttempt($anonId, $formCode);
        $stored = Result::query()->where('attempt_id', $attemptId)->firstOrFail();
        $storedComputedAt = (string) data_get($stored->result_json, 'computed_at');

        $headers = [
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ];

        $result = $this->withHeaders($headers)->getJson("/api/v0.3/attempts/{$attemptId}/result");
        $result->assertStatus(200);
        $result->assertJsonPath('ok', true);
        $result->assertJsonPath('meta.scale_code', 'ENNEAGRAM');
        $result->assertJsonPath('result.computed_at', $storedComputedAt);
        $result->assertJsonPath('enneagram_form_v1.form_code', $formCode);
        $result->assertJsonPath('enneagram_form_v1.question_count', $questionCount);
        $result->assertJsonPath('enneagram_public_projection_v1.schema_version', 'enneagram.public_projection.v1');
        $result->assertJsonPath('enneagram_public_projection_v1.scale_code', 'ENNEAGRAM');
        $this->assertNotSame('', (string) $result->json('enneagram_public_projection_v1.primary_type'));
        $this->assertCount(9, (array) $result->json('enneagram_public_projection_v1.type_vector'));

        $report = $this->withHeaders($headers)->getJson("/api/v0.3/attempts/{$attemptId}/report");
        $report->assertStatus(200);
        $report->assertJsonPath('ok', true);
        $report->assertJsonPath('scale_code', 'ENNEAGRAM');
        $report->assertJsonPath('locked', false);
        $report->assertJsonPath('access_level', 'full');
        $report->assertJsonPath('variant', 'full');
        $report->assertJsonPath('report.schema_version', 'enneagram.report.v1');
        $report->assertJsonPath('report.scale_code', 'ENNEAGRAM');
        $report->assertJsonPath('enneagram_form_v1.form_code', $formCode);
        $report->assertJsonPath('enneagram_public_projection_v1.schema_version', 'enneagram.public_projection.v1');
        $this->assertSame(
            $result->json('enneagram_public_projection_v1.primary_type'),
            $report->json('enneagram_public_projection_v1.primary_type')
        );

        $access = $this->withHeaders($headers)->getJson("/api/v0.3/attempts/{$attemptId}/report-access");
        $access->assertStatus(200);
        $access->assertJsonPath('ok', true);
        $access->assertJsonPath('access_state', 'ready');
        $access->assertJsonPath('report_state', 'ready');
        $access->assertJsonPath('pdf_state', 'ready');
        $access->assertJsonPath('payload.access_level', 'full');
        $access->assertJsonPath('payload.variant', 'full');
        $access->assertJsonPath('enneagram_form_v1.form_code', $formCode);
        $access->assertJsonPath('actions.page_href', "/result/{$attemptId}");
        $access->assertJsonPath('actions.pdf_href', "/api/v0.3/attempts/{$attemptId}/report.pdf");
    }

    /**
     * @return iterable<string,array{string,int,string}>
     */
    public static function enneagramFormsProvider(): iterable
    {
        yield '105 likert' => ['enneagram_likert_105', 105, 'enneagram_read_report_105'];
        yield '144 forced choice' => ['enneagram_forced_choice_144', 144, 'enneagram_read_report_144'];
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
