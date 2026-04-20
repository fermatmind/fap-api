<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\BigFiveReportComposer;
use App\Services\Report\ReportAccess;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BigFiveCanonicalTruthFixturesTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECTION_ORDERED_SECTION_KEYS = [
        'traits.overview',
        'traits.why_this_profile',
        'relationships.interpersonal_style',
        'career.work_style',
        'growth.next_actions',
    ];

    private const REPORT_SECTION_KEYS = [
        'traits.overview',
        'traits.why_this_profile',
        'relationships.interpersonal_style',
        'career.work_style',
        'growth.next_actions',
        'disclaimer_top',
        'summary',
        'domains_overview',
        'facet_table',
        'top_facets',
        'facets_deepdive',
        'action_plan',
        'disclaimer',
    ];

    public function test_big5_canonical_truth_fixtures_are_stable_for_120_90_and_degraded_samples(): void
    {
        $this->bootstrapBigFiveRuntime();

        $canonical120 = $this->runScenario(
            scenarioId: 'canonical_120_readable',
            formCode: 'big5_120',
            locale: 'zh-CN',
            region: 'CN_MAINLAND',
            durationMs: 420000,
            answerMode: 'mid',
            validityItems: []
        );
        $this->assertScenarioContract($canonical120, 'big5_120', 120, false);
        $this->assertFixture('canonical_120_readable.truth.json', $canonical120);

        $canonical90 = $this->runScenario(
            scenarioId: 'canonical_90_readable',
            formCode: 'big5_90',
            locale: 'zh-CN',
            region: 'CN_MAINLAND',
            durationMs: 360000,
            answerMode: 'wave',
            validityItems: []
        );
        $this->assertScenarioContract($canonical90, 'big5_90', 90, false);
        $this->assertFixture('canonical_90_readable.truth.json', $canonical90);

        $degraded = $this->runScenario(
            scenarioId: 'canonical_degraded',
            formCode: 'big5_120',
            locale: 'zh-CN',
            region: 'CN_MAINLAND',
            durationMs: 120000,
            answerMode: 'degraded',
            validityItems: [
                ['item_id' => 'V1', 'code' => 1],
                ['item_id' => 'V2', 'code' => 1],
            ]
        );
        $this->assertScenarioContract($degraded, 'big5_120', 120, true);
        $this->assertFixture('canonical_degraded.truth.json', $degraded);
    }

    private function bootstrapBigFiveRuntime(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $this->artisan('norms:import --scale=BIG5_OCEAN --csv=resources/norms/big5/big5_norm_stats_seed.csv --activate=1')
            ->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();
    }

    /**
     * @param  list<array{item_id:string,code:int}>  $validityItems
     * @return array<string,mixed>
     */
    private function runScenario(
        string $scenarioId,
        string $formCode,
        string $locale,
        string $region,
        int $durationMs,
        string $answerMode,
        array $validityItems
    ): array {
        $anonId = 'anon_'.$scenarioId;
        $userId = match ($scenarioId) {
            'canonical_120_readable' => 41001,
            'canonical_90_readable' => 41002,
            'canonical_degraded' => 41003,
            default => 41999,
        };
        $token = $this->issueToken($anonId, $userId);

        $startPayload = [
            'scale_code' => 'BIG5_OCEAN',
            'anon_id' => $anonId,
            'form_code' => $formCode,
            'locale' => $locale,
            'region' => $region,
        ];

        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', $startPayload);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        DB::table('attempts')
            ->where('id', $attemptId)
            ->update(['user_id' => (string) $userId]);

        $questions = $this->getJson('/api/v0.3/scales/BIG5_OCEAN/questions?form='.$formCode);
        $questions->assertStatus(200);
        $questionItems = (array) $questions->json('questions.items');
        $answers = $this->buildAnswers($questionItems, $answerMode);
        $this->assertNotEmpty($answers);

        $submitPayload = [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => $durationMs,
        ];
        if ($validityItems !== []) {
            $submitPayload['validity_items'] = $validityItems;
        }

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ];

        $submit = $this->withHeaders($headers)->postJson('/api/v0.3/attempts/submit', $submitPayload);
        $submit->assertStatus(200);
        $submitPayloadJson = (array) $submit->json();

        $result = $this->withHeaders($headers)->getJson('/api/v0.3/attempts/'.$attemptId.'/result');
        $result->assertStatus(200);
        $resultPayload = (array) $result->json();

        /** @var Attempt $attempt */
        $attempt = Attempt::query()->findOrFail($attemptId);
        /** @var Result $resultModel */
        $resultModel = Result::query()->where('attempt_id', $attemptId)->firstOrFail();
        /** @var BigFiveReportComposer $composer */
        $composer = app(BigFiveReportComposer::class);
        $composed = $composer->composeVariant(
            $attempt,
            $resultModel,
            ReportAccess::VARIANT_FULL,
            [
                'modules_allowed' => [
                    ReportAccess::MODULE_BIG5_CORE,
                    ReportAccess::MODULE_BIG5_FULL,
                    ReportAccess::MODULE_BIG5_ACTION_PLAN,
                ],
            ]
        );
        $this->assertTrue((bool) ($composed['ok'] ?? false));

        $reportPayload = [
            'ok' => true,
            'locked' => false,
            'access_level' => 'full',
            'variant' => 'full',
            'modules_allowed' => [
                ReportAccess::MODULE_BIG5_CORE,
                ReportAccess::MODULE_BIG5_FULL,
                ReportAccess::MODULE_BIG5_ACTION_PLAN,
            ],
            'modules_offered' => [
                ReportAccess::MODULE_BIG5_FULL,
                ReportAccess::MODULE_BIG5_ACTION_PLAN,
            ],
            'modules_preview' => [],
            'norms' => data_get($submitPayloadJson, 'result.norms', []),
            'quality' => data_get($submitPayloadJson, 'result.quality', []),
            'big5_form_v1' => data_get($resultPayload, 'big5_form_v1', []),
            'big5_public_projection_v1' => data_get($resultPayload, 'big5_public_projection_v1', []),
            'comparative_v1' => data_get($resultPayload, 'comparative_v1', []),
            'report' => data_get($composed, 'report', []),
        ];

        $reportAccess = $this->withHeaders($headers)->getJson('/api/v0.3/attempts/'.$attemptId.'/report-access');
        $reportAccess->assertStatus(200);
        $reportAccessPayload = (array) $reportAccess->json();

        $history = $this->withHeaders($headers)->getJson('/api/v0.3/me/attempts?scale=BIG5_OCEAN');
        $history->assertStatus(200);
        $historyPayload = (array) $history->json();

        $pdf = $this->withHeaders($headers)->get('/api/v0.3/attempts/'.$attemptId.'/report.pdf');
        $pdf->assertStatus(200);
        $pdfBody = (string) $pdf->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdfBody);

        return $this->sanitizeForFixture([
            'scenario_id' => $scenarioId,
            'form_code' => $formCode,
            'start' => [
                'form_code' => (string) $start->json('form_code'),
                'question_count' => (int) $start->json('question_count'),
                'dir_version' => (string) $start->json('dir_version'),
                'content_package_version' => (string) $start->json('content_package_version'),
                'scoring_spec_version' => (string) $start->json('scoring_spec_version'),
                'norm_version' => (string) $start->json('norm_version'),
            ],
            'submit' => [
                'ok' => (bool) ($submitPayloadJson['ok'] ?? false),
                'quality' => data_get($submitPayloadJson, 'result.quality'),
                'norms' => data_get($submitPayloadJson, 'result.norms'),
                'domains_percentile' => data_get($submitPayloadJson, 'result.scores_0_100.domains_percentile', []),
                'facet_percentile_count' => count((array) data_get($submitPayloadJson, 'result.scores_0_100.facets_percentile', [])),
            ],
            'result_payload' => $resultPayload,
            'report_payload' => $reportPayload,
            'report_access_payload' => $reportAccessPayload,
            'history_payload' => $historyPayload,
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
    private function assertScenarioContract(array $scenario, string $expectedFormCode, int $expectedQuestionCount, bool $expectDegradedQuality): void
    {
        $this->assertSame($expectedFormCode, (string) data_get($scenario, 'start.form_code'));
        $this->assertSame($expectedQuestionCount, (int) data_get($scenario, 'start.question_count'));
        $this->assertSame($expectedFormCode, (string) data_get($scenario, 'result_payload.big5_form_v1.form_code'));
        $this->assertSame($expectedQuestionCount, (int) data_get($scenario, 'result_payload.big5_form_v1.question_count'));
        $this->assertSame($expectedFormCode, (string) data_get($scenario, 'report_payload.big5_form_v1.form_code'));
        $this->assertSame($expectedQuestionCount, (int) data_get($scenario, 'report_payload.big5_form_v1.question_count'));
        $this->assertSame('full', (string) data_get($scenario, 'report_payload.variant'));
        $this->assertFalse((bool) data_get($scenario, 'report_payload.locked'));
        $this->assertSame(
            self::PROJECTION_ORDERED_SECTION_KEYS,
            (array) data_get($scenario, 'report_payload.big5_public_projection_v1.ordered_section_keys', [])
        );
        $reportSections = (array) data_get($scenario, 'report_payload.report.sections', []);
        if ($reportSections === []) {
            $reportSections = (array) data_get($scenario, 'report_payload.sections', []);
        }
        $this->assertSame(
            self::REPORT_SECTION_KEYS,
            array_values(array_map(
                static fn (array $section): string => (string) ($section['key'] ?? ''),
                $reportSections
            ))
        );
        $this->assertCount(30, (array) data_get($scenario, 'report_payload.big5_public_projection_v1.facet_vector', []));
        $this->assertCount(5, (array) data_get($scenario, 'report_payload.big5_public_projection_v1.trait_vector', []));
        $this->assertSame('CALIBRATED', (string) data_get($scenario, 'report_payload.norms.status'));
        $this->assertNotSame('', trim((string) data_get($scenario, 'report_payload.norms.norms_version', '')));
        $this->assertSame('ready', (string) data_get($scenario, 'report_access_payload.access_state'));
        $this->assertSame('ready', (string) data_get($scenario, 'report_access_payload.report_state'));
        $this->assertSame('ready', (string) data_get($scenario, 'report_access_payload.pdf_state'));
        $this->assertNull(data_get($scenario, 'history_payload.items.0.offer_summary.primary_offer'));
        $this->assertSame('full', (string) data_get($scenario, 'history_payload.items.0.access_summary.variant'));
        $this->assertSame('false', strtolower((string) data_get($scenario, 'pdf_contract.x_report_locked')));
        $this->assertSame('full', strtolower((string) data_get($scenario, 'pdf_contract.x_report_variant')));

        $qualityLevel = strtoupper((string) data_get($scenario, 'submit.quality.level', ''));
        $this->assertNotSame('', $qualityLevel);
        if ($expectDegradedQuality) {
            $this->assertNotContains($qualityLevel, ['A', 'B']);
            $this->assertContains('ATTENTION_CHECK_FAILED', (array) data_get($scenario, 'submit.quality.flags', []));
        } else {
            $this->assertNotContains('ATTENTION_CHECK_FAILED', (array) data_get($scenario, 'submit.quality.flags', []));
        }
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
            if ($questionId === '') {
                continue;
            }
            $options = is_array($item['options'] ?? null) ? array_values($item['options']) : [];
            if ($options === []) {
                continue;
            }

            $code = '';
            if ($mode === 'mid') {
                $code = $this->findOptionCode($options, '3')
                    ?? $this->findOptionCode($options, '4')
                    ?? $this->findOptionCode($options, '2')
                    ?? trim((string) (($options[0]['code'] ?? '3')));
            } elseif ($mode === 'wave') {
                $target = (string) ((($index % 5) + 1));
                $code = $this->findOptionCode($options, $target)
                    ?? trim((string) (($options[$index % count($options)]['code'] ?? '3')));
            } else {
                $target = ($index % 2 === 0) ? '1' : '5';
                $code = $this->findOptionCode($options, $target)
                    ?? trim((string) (($options[0]['code'] ?? '1')));
            }

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

    private function issueToken(string $anonId, int $userId): string
    {
        DB::table('users')->insert([
            'id' => $userId,
            'name' => "big5_fixture_user_{$userId}",
            'email' => "big5_fixture_user_{$userId}@example.test",
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = 'fm_'.(string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => (string) $userId,
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
     * @return mixed
     */
    private function sanitizeForFixture(mixed $value): mixed
    {
        if (is_float($value)) {
            if (fmod($value, 1.0) === 0.0) {
                return (int) round($value);
            }

            return round($value, 6);
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn ($item) => $this->sanitizeForFixture($item), $value);
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

        $normalized = $value;
        $normalized = preg_replace(
            '/\b[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{12}\b/u',
            '<uuid>',
            $normalized
        ) ?? $normalized;
        $normalized = preg_replace('/\bFMT\-[A-Z0-9]{8}\b/u', 'FMT-<ticket>', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b20\d{2}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})\b/u', '<timestamp>', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b20\d{2}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\b/u', '<timestamp>', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b20\d{2}-\d{2}-\d{2}\b/u', '<date>', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b<date> \d{2}:\d{2}:\d{2}\b/u', '<timestamp>', $normalized) ?? $normalized;

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $actual
     */
    private function assertFixture(string $filename, array $actual): void
    {
        $fixtureDir = base_path('tests/Fixtures/big5');
        $fixturePath = $fixtureDir.'/'.$filename;

        if ((string) env('UPDATE_BIG5_CANONICAL_FIXTURES', '0') === '1') {
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
