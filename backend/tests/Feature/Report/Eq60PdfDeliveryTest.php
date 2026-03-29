<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessment\Scorers\Eq60ScorerV1NormedValidity;
use App\Services\Content\Eq60PackLoader;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Eq60PdfDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_eq60_report_pdf_endpoint_returns_pdf_payload(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder)->run();

        $anonId = 'anon_eq_pdf';
        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createEqAttemptWithResult($anonId);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->get('/api/v0.3/attempts/'.$attemptId.'/report.pdf');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Report-Scale', 'EQ_60');
        $response->assertHeader('X-Report-Variant', 'free');
        $response->assertHeader('X-Report-Locked', 'true');

        $pdf = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('FermatMind Report', $pdf);
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

    private function createEqAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();
        $attempt = Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'EQ_60',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 60,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinutes(7),
            'submitted_at' => now(),
            'pack_id' => 'EQ_60',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'eq60_spec_2026_v2',
        ]);

        /** @var Eq60PackLoader $loader */
        $loader = app(Eq60PackLoader::class);
        /** @var Eq60ScorerV1NormedValidity $scorer */
        $scorer = app(Eq60ScorerV1NormedValidity::class);

        $answers = [];
        for ($i = 1; $i <= 60; $i++) {
            $answers[$i] = 'C';
        }

        $normed = $scorer->score(
            $answers,
            $loader->loadQuestionIndex('v1'),
            $loader->loadPolicy('v1'),
            [
                'score_map' => (array) data_get($loader->loadOptions('v1'), 'score_map', []),
                'duration_ms' => 420000,
                'started_at' => $attempt->started_at,
                'submitted_at' => $attempt->submitted_at,
                'pack_id' => 'EQ_60',
                'dir_version' => 'v1',
                'content_manifest_hash' => $loader->resolveManifestHash('v1'),
            ]
        );

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'EQ_60',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => (array) ($normed['scores'] ?? []),
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'scale_code' => 'EQ_60',
                'normed_json' => $normed,
                'breakdown_json' => ['score_result' => $normed],
                'axis_scores_json' => ['score_result' => $normed],
            ],
            'pack_id' => 'EQ_60',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'eq60_spec_2026_v2',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }
}
