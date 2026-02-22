<?php

declare(strict_types=1);

namespace Tests\Feature\ClinicalCombo68;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\ReportGatekeeper;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\ClinicalCombo68\Concerns\BuildsClinicalComboScorerInput;
use Tests\TestCase;

final class ClinicalComboCrisisStopsUpsellTest extends TestCase
{
    use RefreshDatabase;
    use BuildsClinicalComboScorerInput;

    public function test_crisis_alert_clears_offers_and_keeps_crisis_banner_on_top(): void
    {
        $this->artisan('content:compile --pack=CLINICAL_COMBO_68 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();
        (new Pr19CommerceSeeder())->run();

        $attemptId = $this->createCrisisAttemptWithResult();

        /** @var ReportGatekeeper $gatekeeper */
        $gatekeeper = app(ReportGatekeeper::class);
        $gate = $gatekeeper->resolve(0, $attemptId, null, 'anon_cc68_crisis', 'public');

        $this->assertTrue((bool) ($gate['ok'] ?? false));
        $this->assertSame([], (array) ($gate['offers'] ?? ['unexpected']));
        $this->assertTrue((bool) data_get($gate, 'quality.crisis_alert', false));

        $sections = (array) data_get($gate, 'report.sections', []);
        $this->assertGreaterThanOrEqual(2, count($sections));
        $this->assertSame('disclaimer_top', (string) data_get($sections, '0.key', ''));
        $this->assertSame('crisis_banner', (string) data_get($sections, '1.key', ''));

        $keys = array_map(
            static fn (array $section): string => (string) ($section['key'] ?? ''),
            array_filter($sections, 'is_array')
        );
        $this->assertNotContains('paid_deep_dive', $keys);
        $this->assertNotContains('action_plan', $keys);
        $this->assertNotEmpty((array) data_get($sections, '1.resources', []));
    }

    private function createCrisisAttemptWithResult(): string
    {
        $attemptId = (string) Str::uuid();
        $attempt = Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => 'anon_cc68_crisis',
            'scale_code' => 'CLINICAL_COMBO_68',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 68,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinutes(6),
            'submitted_at' => now(),
            'pack_id' => 'CLINICAL_COMBO_68',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'v1.0_2026',
        ]);

        $score = $this->scoreClinical([
            9 => 'A',
            68 => 'D',
        ], [
            'started_at' => $attempt->started_at,
            'submitted_at' => $attempt->submitted_at,
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'CLINICAL_COMBO_68',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => (array) ($score['scores'] ?? []),
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'scale_code' => 'CLINICAL_COMBO_68',
                'normed_json' => $score,
                'breakdown_json' => ['score_result' => $score],
                'axis_scores_json' => ['score_result' => $score],
            ],
            'pack_id' => 'CLINICAL_COMBO_68',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'v1.0_2026',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }
}
