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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Eq60ReportPaywallTest extends TestCase
{
    use RefreshDatabase;

    public function test_eq60_report_contract_is_full_all_free_without_entitlement(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder)->run();

        $attemptId = $this->createAttemptWithResult('zh-CN', 'anon_eq_free_contract');

        /** @var ReportGatekeeper $gatekeeper */
        $gatekeeper = app(ReportGatekeeper::class);
        $gate = $gatekeeper->resolve(0, $attemptId, null, 'anon_eq_free_contract', 'public');

        $this->assertTrue((bool) ($gate['ok'] ?? false));
        $this->assertFalse((bool) ($gate['locked'] ?? true));
        $this->assertSame(ReportAccess::VARIANT_FULL, (string) ($gate['variant'] ?? ''));
        $this->assertSame(ReportAccess::REPORT_ACCESS_FULL, (string) ($gate['access_level'] ?? ''));
        $this->assertSame(ReportAccess::UNLOCK_STAGE_FULL, (string) ($gate['unlock_stage'] ?? ''));
        $this->assertNull($gate['upgrade_sku'] ?? null);
        $this->assertNull($gate['upgrade_sku_effective'] ?? null);
        $this->assertSame([], (array) ($gate['offers'] ?? []));
        $this->assertSame(ReportAccess::eq60AllRuntimeModules(), array_values((array) ($gate['modules_allowed'] ?? [])));
        $this->assertSame([], (array) ($gate['modules_preview'] ?? []));
        $this->assertSame(ReportAccess::eq60FreeSectionKeys(), array_values((array) data_get($gate, 'view_policy.free_sections', [])));
        $this->assertFalse((bool) data_get($gate, 'view_policy.blur_others', true));

        $sections = (array) data_get($gate, 'report.sections', []);
        $this->assertNotEmpty($sections);
        $keys = array_map(
            static fn (array $section): string => (string) ($section['key'] ?? ''),
            array_filter($sections, 'is_array')
        );

        foreach (ReportAccess::eq60FreeSectionKeys() as $sectionKey) {
            $this->assertContains($sectionKey, $keys);
        }
        foreach ($sections as $section) {
            $this->assertSame('free', (string) ($section['access_level'] ?? ''));
        }
        $this->assertNotEmpty((array) data_get($gate, 'report.compat.free_blocks', []));
        $this->assertSame([], (array) data_get($gate, 'report.compat.paid_blocks', []));

        $this->assertSame('self_report', (string) data_get($gate, 'report.eq_report_mode'));
        $this->assertSame('self_report_trait_mixed_ei', (string) data_get($gate, 'report.measurement_type'));
        $this->assertTrue((bool) data_get($gate, 'report.access.all_results_free'));
        $this->assertFalse((bool) data_get($gate, 'report.access.locked', true));
        $this->assertFalse((bool) data_get($gate, 'report.access.blur', true));
        $this->assertFalse((bool) data_get($gate, 'report.access.paywall', true));
        $this->assertIsArray(data_get($gate, 'report.scores.global'));
        foreach (['SA', 'ER', 'EM', 'RM'] as $code) {
            $this->assertIsArray(data_get($gate, 'report.scores.dimensions.'.$code));
        }
        $this->assertCount(4, (array) data_get($gate, 'report.dimension_summary', []));
        $this->assertContains((string) data_get($gate, 'report.quality.confidence_label'), ['high', 'medium', 'low']);
        $this->assertSame('planned', (string) data_get($gate, 'report.next_module.status'));
        $this->assertFalse((bool) data_get($gate, 'report.next_module.available', true));
        $this->assertSame('provisional', (string) data_get($gate, 'report.methodology.norm_status'));
        $this->assertSame('eq_report_v5_minimal', (string) data_get($gate, 'report.methodology.report_version'));
        $this->assertStringNotContainsString('SKU_EQ_60_FULL_299', json_encode($gate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    public function test_eq60_entitlement_does_not_change_free_runtime_contract(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder)->run();

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
        $this->assertSame(ReportAccess::VARIANT_FULL, (string) ($gate['variant'] ?? ''));
        $this->assertNull($gate['upgrade_sku'] ?? null);
        $this->assertSame([], (array) ($gate['offers'] ?? []));
        $this->assertSame([], (array) ($gate['modules_preview'] ?? []));

        $sections = (array) data_get($gate, 'report.sections', []);
        foreach ($sections as $section) {
            $this->assertSame('free', (string) ($section['access_level'] ?? ''));
        }
        $this->assertSame([], (array) data_get($gate, 'report.compat.paid_blocks', []));
    }

    public function test_eq60_report_access_fallback_is_ready_full_all_free(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder)->run();

        $anonId = 'anon_eq_report_access_free';
        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createAttemptWithResult('zh-CN', $anonId);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])
            ->getJson('/api/v0.3/attempts/'.$attemptId.'/report-access');

        $response->assertOk()
            ->assertJsonPath('access_state', 'ready')
            ->assertJsonPath('report_state', 'ready')
            ->assertJsonPath('payload.access_level', ReportAccess::REPORT_ACCESS_FULL)
            ->assertJsonPath('payload.variant', ReportAccess::VARIANT_FULL)
            ->assertJsonPath('payload.unlock_stage', ReportAccess::UNLOCK_STAGE_FULL)
            ->assertJsonPath('payload.unlock_source', ReportAccess::UNLOCK_SOURCE_NONE)
            ->assertJsonPath('payload.access.all_results_free', true)
            ->assertJsonPath('payload.access.locked', false)
            ->assertJsonPath('payload.access.blur', false)
            ->assertJsonPath('payload.access.paywall', false)
            ->assertJsonPath('payload.upgrade_sku', null)
            ->assertJsonPath('payload.upgrade_sku_effective', null)
            ->assertJsonPath('payload.offers', [])
            ->assertJsonPath('payload.modules_allowed', ReportAccess::eq60AllRuntimeModules())
            ->assertJsonPath('payload.modules_preview', [])
            ->assertJsonPath('payload.view_policy.free_sections', ReportAccess::eq60FreeSectionKeys())
            ->assertJsonPath('payload.view_policy.blur_others', false);
    }

    public function test_eq60_registry_and_fallback_dimension_contracts_are_sixty_questions_and_four_dimensions(): void
    {
        (new ScaleRegistrySeeder)->run();

        $scale = DB::table('scales_registry')->where('code', 'EQ_60')->first();
        $this->assertNotNull($scale);
        $content = json_decode((string) $scale->content_i18n_json, true);
        $this->assertSame(60, (int) data_get($content, 'zh.catalog.questions_count'));
        $this->assertSame(60, (int) data_get($content, 'en.catalog.questions_count'));
        $this->assertSame('free_only', (string) data_get(json_decode((string) $scale->capabilities_json, true), 'paywall_mode'));

        $source = file_get_contents(base_path('app/Http/Controllers/API/V0_3/ScalesController.php')) ?: '';
        $this->assertStringContainsString("['SA', 'ER', 'EM', 'RM']", $source);
        $this->assertStringNotContainsString("['SA', 'ER', 'SE', 'RM']", $source);
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
