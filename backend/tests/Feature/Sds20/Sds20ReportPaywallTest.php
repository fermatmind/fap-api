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

final class Sds20ReportPaywallTest extends TestCase
{
    use BuildsSds20ScorerInput;
    use RefreshDatabase;

    public function test_locked_report_only_returns_free_sections(): void
    {
        (new ScaleRegistrySeeder)->run();

        $attemptId = $this->createAttemptWithResult('zh-CN', false, 'anon_sds_paywall');

        /** @var ReportGatekeeper $gatekeeper */
        $gatekeeper = app(ReportGatekeeper::class);
        $gate = $gatekeeper->resolve(0, $attemptId, null, 'anon_sds_paywall', 'public');

        $this->assertTrue((bool) ($gate['ok'] ?? false));
        $this->assertTrue((bool) ($gate['locked'] ?? true));
        $this->assertSame('free', (string) ($gate['variant'] ?? ''));

        $sections = (array) data_get($gate, 'report.sections', []);
        $this->assertNotEmpty($sections);

        $keys = array_map(
            static fn (array $section): string => (string) ($section['key'] ?? ''),
            array_filter($sections, 'is_array')
        );

        $this->assertSame('disclaimer_top', (string) data_get($sections, '0.key', ''));
        $this->assertContains('result_summary_free', $keys);
        $this->assertNotContains('paid_deep_dive', $keys);
        $this->assertSame([], (array) data_get($gate, 'report.compat.paid_blocks', []));
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
            'region' => 'CN_MAINLAND',
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
