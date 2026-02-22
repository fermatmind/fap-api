<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Jobs\GenerateBigFiveReportPdfJob;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessment\Scorers\BigFiveScorerV3;
use App\Services\Commerce\EntitlementManager;
use App\Services\Content\BigFivePackLoader;
use App\Services\Report\BigFivePdfDocumentService;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class GenerateBigFiveReportPdfJobTest extends TestCase
{
    use RefreshDatabase;

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
            if (! is_array($meta)) {
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

    private function createBigFiveAttemptWithResult(string $anonId): string
    {
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

        return $attemptId;
    }

    public function test_big5_pdf_job_generates_artifact_and_event(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder)->run();
        Storage::fake('local');

        $attemptId = $this->createBigFiveAttemptWithResult('anon_big5_pdf_job');
        /** @var EntitlementManager $entitlements */
        $entitlements = app(EntitlementManager::class);
        $grant = $entitlements->grantAttemptUnlock(
            0,
            null,
            'anon_big5_pdf_job',
            'BIG5_FULL_REPORT',
            $attemptId,
            'ord_big5_pdf_job_1'
        );
        $this->assertTrue((bool) ($grant['ok'] ?? false));

        $job = new GenerateBigFiveReportPdfJob(0, $attemptId, 'payment_unlock', 'ord_big5_pdf_job_1');
        app()->call([$job, 'handle']);

        /** @var BigFivePdfDocumentService $pdfService */
        $pdfService = app(BigFivePdfDocumentService::class);
        $fullPath = $pdfService->artifactPath($attemptId, 'full');
        $freePath = $pdfService->artifactPath($attemptId, 'free');

        $this->assertTrue(
            Storage::disk('local')->exists($fullPath) || Storage::disk('local')->exists($freePath),
            'expected full or free pdf artifact to be generated'
        );

        $event = DB::table('events')
            ->where('event_code', 'report_pdf_generated')
            ->orderByDesc('created_at')
            ->first();
        $this->assertNotNull($event);
        $meta = json_decode((string) ($event->meta_json ?? '{}'), true);
        $this->assertIsArray($meta);
        $this->assertSame('BIG5_OCEAN', (string) ($meta['scale_code'] ?? ''));
        $this->assertSame($attemptId, (string) ($meta['attempt_id'] ?? ''));
    }
}
