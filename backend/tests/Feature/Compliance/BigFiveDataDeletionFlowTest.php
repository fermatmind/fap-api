<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessment\Scorers\BigFiveScorerV3;
use App\Services\Attempts\AttemptDataLifecycleService;
use App\Services\Content\BigFivePackLoader;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BigFiveDataDeletionFlowTest extends TestCase
{
    use RefreshDatabase;

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_' . (string) Str::uuid();
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
     * @return array<string,mixed>
     */
    private function buildScorePayload(): array
    {
        /** @var BigFivePackLoader $loader */
        $loader = app(BigFivePackLoader::class);
        $questions = $loader->readCompiledJson('questions.compiled.json', 'v1');
        $norms = $loader->readCompiledJson('norms.compiled.json', 'v1');
        $policyCompiled = $loader->readCompiledJson('policy.compiled.json', 'v1');

        $questionIndex = [];
        foreach ((array) ($questions['question_index'] ?? []) as $qid => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $questionIndex[(int) $qid] = $meta;
        }

        $answersById = [];
        for ($i = 1; $i <= 120; $i++) {
            $answersById[$i] = 3;
        }

        /** @var BigFiveScorerV3 $scorer */
        $scorer = app(BigFiveScorerV3::class);

        return $scorer->score(
            $answersById,
            $questionIndex,
            (array) $norms,
            (array) (($policyCompiled['policy'] ?? [])),
            [
                'locale' => 'zh-CN',
                'country' => 'CN_MAINLAND',
                'region' => 'CN_MAINLAND',
                'gender' => 'ALL',
                'age_band' => 'all',
                'time_seconds_total' => 420,
                'duration_ms' => 420000,
            ]
        );
    }

    public function test_purged_big5_attempt_becomes_inaccessible_for_report_and_pdf(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();

        $anonId = 'anon_big5_delete_flow';
        $token = $this->issueAnonToken($anonId);
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 120,
            'answers_json' => [['question_id' => 1, 'code' => '3']],
            'answers_hash' => hash('sha256', 'seed'),
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
        ]);

        $scorePayload = $this->buildScorePayload();

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => [
                'domains_mean' => $scorePayload['raw_scores']['domains_mean'] ?? [],
            ],
            'scores_pct' => $scorePayload['scores_0_100']['domains_percentile'] ?? [],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'normed_json' => $scorePayload,
                'breakdown_json' => ['score_result' => $scorePayload],
                'axis_scores_json' => ['score_result' => $scorePayload],
            ],
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        $headers = [
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $token,
        ];

        $this->withHeaders($headers)
            ->get('/api/v0.3/attempts/' . $attemptId . '/report')
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->get('/api/v0.3/attempts/' . $attemptId . '/report.pdf')
            ->assertStatus(200);

        DB::table('report_snapshots')->insertOrIgnore([
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'order_no' => null,
            'scale_code' => 'BIG5_OCEAN',
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'report_engine_version' => 'v1.2',
            'snapshot_version' => 'v1',
            'report_json' => json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'report_free_json' => json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'report_full_json' => json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'ready',
            'last_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertGreaterThan(
            0,
            DB::table('report_snapshots')->where('attempt_id', $attemptId)->count()
        );

        /** @var AttemptDataLifecycleService $lifecycle */
        $lifecycle = app(AttemptDataLifecycleService::class);
        $purge = $lifecycle->purgeAttempt($attemptId, 0, [
            'reason' => 'user_delete_request',
            'scale_code' => 'BIG5_OCEAN',
        ]);

        $this->assertTrue((bool) ($purge['ok'] ?? false));
        $this->assertGreaterThan(0, (int) data_get($purge, 'counts.results_deleted', 0));
        $this->assertGreaterThan(0, (int) data_get($purge, 'counts.report_snapshots_deleted', 0));

        $this->withHeaders($headers)
            ->get('/api/v0.3/attempts/' . $attemptId . '/report')
            ->assertStatus(404);

        $this->withHeaders($headers)
            ->get('/api/v0.3/attempts/' . $attemptId . '/report.pdf')
            ->assertStatus(404);

        $this->assertSame(0, DB::table('results')->where('attempt_id', $attemptId)->count());
        $this->assertSame(0, DB::table('report_snapshots')->where('attempt_id', $attemptId)->count());

        $attemptRow = DB::table('attempts')->where('id', $attemptId)->first();
        $this->assertNotNull($attemptRow);
        $this->assertNull($attemptRow->answers_hash);
        $this->assertNull($attemptRow->type_code);

        $requestRow = DB::table('data_lifecycle_requests')
            ->where('request_type', 'attempt_purge')
            ->where('subject_ref', $attemptId)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($requestRow);
        $this->assertSame('done', (string) ($requestRow->status ?? ''));
        $this->assertSame('success', (string) ($requestRow->result ?? ''));
    }
}
