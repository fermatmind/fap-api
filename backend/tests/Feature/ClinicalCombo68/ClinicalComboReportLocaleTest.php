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

final class ClinicalComboReportLocaleTest extends TestCase
{
    use RefreshDatabase;
    use BuildsClinicalComboScorerInput;

    public function test_report_uses_english_content_when_attempt_locale_is_en(): void
    {
        $this->artisan('content:compile --pack=CLINICAL_COMBO_68 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();
        (new Pr19CommerceSeeder())->run();

        $attemptId = $this->createAttemptWithResult('en');

        /** @var ReportGatekeeper $gatekeeper */
        $gatekeeper = app(ReportGatekeeper::class);
        $gate = $gatekeeper->resolve(0, $attemptId, null, 'anon_cc68_locale', 'public');

        $this->assertTrue((bool) ($gate['ok'] ?? false));
        $this->assertSame('en', (string) data_get($gate, 'report.locale', ''));
        $this->assertSame('Important Disclaimer', (string) data_get($gate, 'report.sections.0.title', ''));
        $keys = array_map(
            static fn (array $section): string => (string) ($section['key'] ?? ''),
            array_filter((array) data_get($gate, 'report.sections', []), 'is_array')
        );
        $this->assertContains('quick_overview', $keys);
        $this->assertContains('scoring_notes', $keys);
    }

    private function createAttemptWithResult(string $locale): string
    {
        $attemptId = (string) Str::uuid();
        $attempt = Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => 'anon_cc68_locale',
            'scale_code' => 'CLINICAL_COMBO_68',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => $locale,
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

        $score = $this->scoreClinical([], [
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
