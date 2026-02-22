<?php

declare(strict_types=1);

namespace Tests\Feature\Sds20;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\ReportGatekeeper;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Sds20\Concerns\BuildsSds20ScorerInput;
use Tests\TestCase;

final class Sds20ReportLocaleTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSds20ScorerInput;

    public function test_report_uses_english_locale_copy_when_attempt_locale_is_en(): void
    {
        (new ScaleRegistrySeeder())->run();

        $attemptId = $this->createAttemptWithResult('en', false, 'anon_sds_en');

        /** @var ReportGatekeeper $gatekeeper */
        $gatekeeper = app(ReportGatekeeper::class);
        $gate = $gatekeeper->resolve(0, $attemptId, null, 'anon_sds_en', 'public');

        $this->assertTrue((bool) ($gate['ok'] ?? false));
        $this->assertSame('en', (string) data_get($gate, 'report.locale', ''));
        $this->assertSame('Important Disclaimer', (string) data_get($gate, 'report.sections.0.title', ''));
        $this->assertStringContainsString('self-report', strtolower((string) data_get($gate, 'report.sections.0.blocks.0.content', '')));
    }

    private function createAttemptWithResult(string $locale, bool $crisis, string $anonId): string
    {
        $attemptId = (string) Str::uuid();
        $attempt = Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'SDS_20',
            'scale_version' => 'v0.3',
            'region' => 'GLOBAL',
            'locale' => $locale,
            'question_count' => 20,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinutes(3),
            'submitted_at' => now(),
            'pack_id' => 'SDS_20',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'v2.0_Factor_Logic',
        ]);

        $score = $this->scoreSds($crisis ? [19 => 'C'] : [], [
            'duration_ms' => 98000,
            'started_at' => $attempt->started_at,
            'submitted_at' => $attempt->submitted_at,
            'locale' => $locale,
            'region' => 'GLOBAL',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'SDS_20',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => (array) ($score['scores'] ?? []),
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'scale_code' => 'SDS_20',
                'normed_json' => $score,
                'breakdown_json' => ['score_result' => $score],
                'axis_scores_json' => ['score_result' => $score],
            ],
            'pack_id' => 'SDS_20',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'v2.0_Factor_Logic',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }
}
