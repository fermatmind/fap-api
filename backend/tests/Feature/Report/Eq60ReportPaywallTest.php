<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessment\Scorers\Eq60ScorerV1NormedValidity;
use App\Services\Commerce\EntitlementManager;
use App\Services\Content\Eq60PackLoader;
use App\Services\Report\ReportAccess;
use App\Services\Report\ReportGatekeeper;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Eq60ReportPaywallTest extends TestCase
{
    use RefreshDatabase;

    public function test_locked_report_only_contains_free_sections(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();

        $attemptId = $this->createAttemptWithResult('zh-CN', 'anon_eq_paywall_locked');

        /** @var ReportGatekeeper $gatekeeper */
        $gatekeeper = app(ReportGatekeeper::class);
        $gate = $gatekeeper->resolve(0, $attemptId, null, 'anon_eq_paywall_locked', 'public');

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
        $this->assertContains('eq_summary_free', $keys);
        $this->assertContains('eq_dimensions_free', $keys);
        $this->assertContains('eq_paywall_teaser', $keys);
        $this->assertNotContains('eq_cross_insights', $keys);
        $this->assertNotContains('eq_growth_plan', $keys);
        $this->assertSame([], (array) data_get($gate, 'report.compat.paid_blocks', []));
    }

    public function test_unlocked_report_contains_paid_sections(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();

        $anonId = 'anon_eq_paywall_unlocked';
        $attemptId = $this->createAttemptWithResult('zh-CN', $anonId);

        /** @var EntitlementManager $entitlements */
        $entitlements = app(EntitlementManager::class);
        $entitlements->grantAttemptUnlock(
            0,
            null,
            $anonId,
            'EQ_60_FULL',
            $attemptId,
            null,
            null,
            null,
            [
                ReportAccess::MODULE_EQ_FULL,
                ReportAccess::MODULE_EQ_CROSS_INSIGHTS,
                ReportAccess::MODULE_EQ_GROWTH_PLAN,
            ]
        );

        /** @var ReportGatekeeper $gatekeeper */
        $gatekeeper = app(ReportGatekeeper::class);
        $gate = $gatekeeper->resolve(0, $attemptId, null, $anonId, 'public');

        $this->assertTrue((bool) ($gate['ok'] ?? false));
        $this->assertFalse((bool) ($gate['locked'] ?? true));
        $this->assertSame('full', (string) ($gate['variant'] ?? ''));

        $sections = (array) data_get($gate, 'report.sections', []);
        $keys = array_map(
            static fn (array $section): string => (string) ($section['key'] ?? ''),
            array_filter($sections, 'is_array')
        );
        $this->assertContains('eq_cross_insights', $keys);
        $this->assertContains('eq_growth_plan', $keys);
        $this->assertNotEmpty((array) data_get($gate, 'report.compat.paid_blocks', []));
    }

    private function createAttemptWithResult(string $locale, string $anonId): string
    {
        $attemptId = (string) Str::uuid();
        $attempt = Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'EQ_60',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => $locale,
            'question_count' => 60,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinutes(8),
            'submitted_at' => now(),
            'pack_id' => 'EQ_60',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'eq60_spec_2026_v2',
        ]);

        $score = $this->scoreEq60([
            'started_at' => $attempt->started_at,
            'submitted_at' => $attempt->submitted_at,
            'locale' => $locale,
            'region' => 'CN_MAINLAND',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'EQ_60',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => (array) ($score['scores'] ?? []),
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'scale_code' => 'EQ_60',
                'quality' => $score['quality'] ?? [],
                'norms' => $score['norms'] ?? [],
                'scores' => $score['scores'] ?? [],
                'report_tags' => $score['report_tags'] ?? [],
                'version_snapshot' => $score['version_snapshot'] ?? [],
                'normed_json' => $score,
                'breakdown_json' => ['score_result' => $score],
                'axis_scores_json' => ['score_result' => $score],
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

    /**
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed>
     */
    private function scoreEq60(array $ctx = []): array
    {
        /** @var Eq60PackLoader $loader */
        $loader = app(Eq60PackLoader::class);
        /** @var Eq60ScorerV1NormedValidity $scorer */
        $scorer = app(Eq60ScorerV1NormedValidity::class);

        $answers = [];
        for ($i = 1; $i <= 60; $i++) {
            $answers[$i] = 'C';
        }

        return $scorer->score(
            $answers,
            $loader->loadQuestionIndex('v1'),
            $loader->loadPolicy('v1'),
            array_merge([
                'pack_id' => 'EQ_60',
                'dir_version' => 'v1',
                'score_map' => data_get($loader->loadOptions('v1'), 'score_map', []),
                'server_duration_seconds' => 420,
            ], $ctx)
        );
    }
}
