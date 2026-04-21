<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\Result;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EnneagramGoldenCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_enneagram_canonical_truth_fixtures_are_stable_for_105_and_144_forms(): void
    {
        (new ScaleRegistrySeeder)->run();

        $likert105 = $this->runScenario(
            scenarioId: 'canonical_likert_105',
            formCode: 'enneagram_likert_105',
            questionCount: 105,
            durationMs: 180000,
            answerMode: 'likert_wave'
        );
        $this->assertScenarioContract($likert105, 'enneagram_likert_105', 105, 'enneagram_likert_105_weighted_v1');
        $this->assertFixture('canonical_likert_105.truth.json', $likert105);

        $forcedChoice144 = $this->runScenario(
            scenarioId: 'canonical_forced_choice_144',
            formCode: 'enneagram_forced_choice_144',
            questionCount: 144,
            durationMs: 240000,
            answerMode: 'forced_choice_wave'
        );
        $this->assertScenarioContract($forcedChoice144, 'enneagram_forced_choice_144', 144, 'enneagram_forced_choice_144_pair_v1');
        $this->assertFixture('canonical_forced_choice_144.truth.json', $forcedChoice144);
    }

    /**
     * @return array<string,mixed>
     */
    private function runScenario(string $scenarioId, string $formCode, int $questionCount, int $durationMs, string $answerMode): array
    {
        $anonId = 'anon_enneagram_'.$scenarioId;
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
        $this->assertNotSame('', $attemptId);

        $questions = $this->getJson('/api/v0.3/scales/ENNEAGRAM/questions?form_code='.$formCode.'&locale=zh-CN');
        $questions->assertStatus(200);
        $answers = $this->buildAnswers((array) $questions->json('questions.items'), $answerMode);
        $this->assertCount($questionCount, $answers);

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ];
        $submit = $this->withHeaders($headers)->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => $durationMs,
        ]);
        $submit->assertStatus(200);
        $submitPayload = (array) $submit->json();

        $result = $this->withHeaders($headers)->getJson('/api/v0.3/attempts/'.$attemptId.'/result');
        $result->assertStatus(200);
        $resultPayload = (array) $result->json();

        $report = $this->withHeaders($headers)->getJson('/api/v0.3/attempts/'.$attemptId.'/report');
        $report->assertStatus(200);
        $reportPayload = (array) $report->json();

        $access = $this->withHeaders($headers)->getJson('/api/v0.3/attempts/'.$attemptId.'/report-access');
        $access->assertStatus(200);
        $accessPayload = (array) $access->json();

        $pdf = $this->withHeaders($headers)->get('/api/v0.3/attempts/'.$attemptId.'/report.pdf?inline=1');
        $pdf->assertStatus(200);
        $this->assertStringStartsWith('%PDF-', (string) $pdf->getContent());

        /** @var Result $stored */
        $stored = Result::query()->where('attempt_id', $attemptId)->firstOrFail();
        $storedResult = is_array($stored->result_json ?? null) ? $stored->result_json : [];

        return $this->sanitizeForFixture([
            'scenario_id' => $scenarioId,
            'form_code' => $formCode,
            'start' => [
                'form_code' => (string) $start->json('form_code'),
                'question_count' => (int) $start->json('question_count'),
                'dir_version' => (string) $start->json('dir_version'),
                'content_package_version' => (string) $start->json('content_package_version'),
                'scoring_spec_version' => (string) $start->json('scoring_spec_version'),
            ],
            'submit_truth' => [
                'ok' => (bool) ($submitPayload['ok'] ?? false),
                'form_code' => data_get($submitPayload, 'result.form_code'),
                'score_method' => data_get($submitPayload, 'result.score_method'),
                'primary_type' => data_get($submitPayload, 'result.primary_type'),
                'top3' => $this->topTypeCodes((array) data_get($submitPayload, 'result.ranking', [])),
                'scores_0_100' => data_get($submitPayload, 'result.scores_0_100', []),
                'raw_scores' => data_get($submitPayload, 'result.raw_scores', []),
                'ranking' => data_get($submitPayload, 'result.ranking', []),
                'confidence' => data_get($submitPayload, 'result.confidence', []),
            ],
            'stored_truth' => [
                'scale_code' => (string) $stored->scale_code,
                'type_code' => (string) $stored->type_code,
                'form_code' => data_get($storedResult, 'form_code'),
                'score_method' => data_get($storedResult, 'score_method'),
                'primary_type' => data_get($storedResult, 'primary_type'),
                'top3' => $this->topTypeCodes((array) data_get($storedResult, 'ranking', [])),
                'scores_0_100' => data_get($storedResult, 'scores_0_100', []),
                'raw_scores' => data_get($storedResult, 'raw_scores', []),
                'version_snapshot' => [
                    'pack_id' => (string) $stored->pack_id,
                    'dir_version' => (string) $stored->dir_version,
                    'content_package_version' => (string) $stored->content_package_version,
                    'scoring_spec_version' => (string) $stored->scoring_spec_version,
                    'report_engine_version' => (string) $stored->report_engine_version,
                    'engine_version' => (string) data_get($storedResult, 'engine_version'),
                    'content_manifest_hash' => (string) data_get($storedResult, 'content_manifest_hash'),
                ],
            ],
            'result_contract' => [
                'ok' => (bool) data_get($resultPayload, 'ok'),
                'form_code' => data_get($resultPayload, 'enneagram_form_v1.form_code'),
                'question_count' => data_get($resultPayload, 'enneagram_form_v1.question_count'),
                'projection_schema' => data_get($resultPayload, 'enneagram_public_projection_v1.schema_version'),
                'primary_type' => data_get($resultPayload, 'enneagram_public_projection_v1.primary_type'),
                'top_types' => data_get($resultPayload, 'enneagram_public_projection_v1.top_types', []),
                'type_vector' => data_get($resultPayload, 'enneagram_public_projection_v1.type_vector', []),
            ],
            'report_contract' => [
                'ok' => (bool) data_get($reportPayload, 'ok'),
                'locked' => (bool) data_get($reportPayload, 'locked'),
                'access_level' => data_get($reportPayload, 'access_level'),
                'variant' => data_get($reportPayload, 'variant'),
                'form_code' => data_get($reportPayload, 'enneagram_form_v1.form_code'),
                'report_schema' => data_get($reportPayload, 'report.schema_version'),
                'primary_type' => data_get($reportPayload, 'report.primary_type'),
                'ordered_section_keys' => data_get($reportPayload, 'enneagram_public_projection_v1.ordered_section_keys', []),
            ],
            'access_contract' => [
                'ok' => (bool) data_get($accessPayload, 'ok'),
                'access_state' => data_get($accessPayload, 'access_state'),
                'report_state' => data_get($accessPayload, 'report_state'),
                'pdf_state' => data_get($accessPayload, 'pdf_state'),
                'form_code' => data_get($accessPayload, 'enneagram_form_v1.form_code'),
                'payload_variant' => data_get($accessPayload, 'payload.variant'),
            ],
            'pdf_contract' => [
                'status' => $pdf->status(),
                'content_type' => (string) ($pdf->headers->get('Content-Type') ?? ''),
                'x_report_scale' => (string) ($pdf->headers->get('X-Report-Scale') ?? ''),
                'x_report_variant' => (string) ($pdf->headers->get('X-Report-Variant') ?? ''),
                'x_report_locked' => (string) ($pdf->headers->get('X-Report-Locked') ?? ''),
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $scenario
     */
    private function assertScenarioContract(array $scenario, string $expectedFormCode, int $expectedQuestionCount, string $expectedScoreMethod): void
    {
        $this->assertSame($expectedFormCode, (string) data_get($scenario, 'start.form_code'));
        $this->assertSame($expectedQuestionCount, (int) data_get($scenario, 'start.question_count'));
        $this->assertSame($expectedFormCode, (string) data_get($scenario, 'submit_truth.form_code'));
        $this->assertSame($expectedScoreMethod, (string) data_get($scenario, 'submit_truth.score_method'));
        $this->assertSame($expectedFormCode, (string) data_get($scenario, 'stored_truth.form_code'));
        $this->assertSame($expectedScoreMethod, (string) data_get($scenario, 'stored_truth.score_method'));
        $this->assertSame($expectedFormCode, (string) data_get($scenario, 'result_contract.form_code'));
        $this->assertSame($expectedQuestionCount, (int) data_get($scenario, 'result_contract.question_count'));
        $this->assertSame($expectedFormCode, (string) data_get($scenario, 'report_contract.form_code'));
        $this->assertSame($expectedFormCode, (string) data_get($scenario, 'access_contract.form_code'));
        $this->assertSame('enneagram.public_projection.v1', (string) data_get($scenario, 'result_contract.projection_schema'));
        $this->assertSame('enneagram.report.v1', (string) data_get($scenario, 'report_contract.report_schema'));
        $this->assertSame('ready', (string) data_get($scenario, 'access_contract.access_state'));
        $this->assertSame('ready', (string) data_get($scenario, 'access_contract.report_state'));
        $this->assertSame('ready', (string) data_get($scenario, 'access_contract.pdf_state'));
        $this->assertSame('ENNEAGRAM', (string) data_get($scenario, 'pdf_contract.x_report_scale'));
        $this->assertSame('full', strtolower((string) data_get($scenario, 'pdf_contract.x_report_variant')));
        $this->assertSame('false', strtolower((string) data_get($scenario, 'pdf_contract.x_report_locked')));
        $this->assertCount(3, (array) data_get($scenario, 'submit_truth.top3', []));
        $this->assertCount(9, (array) data_get($scenario, 'submit_truth.scores_0_100', []));
        $this->assertCount(9, (array) data_get($scenario, 'result_contract.type_vector', []));
        $this->assertNotSame('', (string) data_get($scenario, 'submit_truth.primary_type'));
        $this->assertSame(
            (string) data_get($scenario, 'submit_truth.primary_type'),
            (string) data_get($scenario, 'stored_truth.primary_type')
        );
        $this->assertSame(
            (string) data_get($scenario, 'submit_truth.primary_type'),
            (string) data_get($scenario, 'result_contract.primary_type')
        );
        $this->assertNotSame('', (string) data_get($scenario, 'stored_truth.version_snapshot.engine_version'));
        $this->assertNotSame('', (string) data_get($scenario, 'stored_truth.version_snapshot.scoring_spec_version'));
        $this->assertNotSame('', (string) data_get($scenario, 'stored_truth.version_snapshot.content_package_version'));
        $this->assertNotSame('', (string) data_get($scenario, 'stored_truth.version_snapshot.report_engine_version'));
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array{question_id:string,code:string}>
     */
    private function buildAnswers(array $items, string $mode): array
    {
        $answers = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $questionId = trim((string) ($item['question_id'] ?? ''));
            $options = is_array($item['options'] ?? null) ? array_values($item['options']) : [];
            if ($questionId === '' || $options === []) {
                continue;
            }

            $target = $mode === 'forced_choice_wave'
                ? (($index % 3) === 1 ? 'B' : 'A')
                : ['2', '1', '0', '-1', '-2'][$index % 5];
            $code = $this->findOptionCode($options, $target)
                ?? trim((string) ($options[$index % count($options)]['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $answers[] = [
                'question_id' => $questionId,
                'code' => $code,
            ];
        }

        return $answers;
    }

    /**
     * @param  list<array<string,mixed>>  $options
     */
    private function findOptionCode(array $options, string $target): ?string
    {
        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }
            $code = trim((string) ($option['code'] ?? ''));
            if ($code === $target) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $ranking
     * @return list<string>
     */
    private function topTypeCodes(array $ranking): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) ($row['type_code'] ?? ''),
            array_slice($ranking, 0, 3)
        ));
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

    private function sanitizeForFixture(mixed $value): mixed
    {
        if (is_float($value)) {
            return fmod($value, 1.0) === 0.0 ? (int) round($value) : round($value, 6);
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn (mixed $item): mixed => $this->sanitizeForFixture($item), $value);
            }

            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[(string) $key] = $this->sanitizeForFixture($item);
            }
            ksort($normalized);

            return $normalized;
        }

        if (! is_string($value)) {
            return $value;
        }

        $normalized = preg_replace(
            '/\b[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{12}\b/u',
            '<uuid>',
            $value
        ) ?? $value;
        $normalized = preg_replace('/\b20\d{2}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})\b/u', '<timestamp>', $normalized) ?? $normalized;

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $actual
     */
    private function assertFixture(string $filename, array $actual): void
    {
        $fixtureDir = base_path('tests/Fixtures/enneagram');
        $fixturePath = $fixtureDir.'/'.$filename;

        if ((string) env('UPDATE_ENNEAGRAM_CANONICAL_FIXTURES', '0') === '1') {
            if (! is_dir($fixtureDir)) {
                mkdir($fixtureDir, 0777, true);
            }
            file_put_contents(
                $fixturePath,
                json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL
            );
        }

        $raw = file_get_contents($fixturePath);
        $this->assertIsString($raw, 'canonical fixture missing: '.$fixturePath);
        $expected = json_decode($raw, true);
        $this->assertIsArray($expected, 'canonical fixture invalid json: '.$fixturePath);
        $this->assertSame($expected, $actual);
    }
}
