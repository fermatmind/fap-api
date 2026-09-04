<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Platform12\Operations\Platform12DecisionExperimentReadService;
use App\Services\SeoIntel\Decision\SeoDecisionCardContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeoPlatform12E03DecisionExperimentUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.seo_intel', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
        DB::purge('seo_intel');
    }

    public function test_missing_sources_are_unavailable_and_never_fabricated(): void
    {
        $snapshot = app(Platform12DecisionExperimentReadService::class)->snapshot();

        $this->assertSame('unavailable', $snapshot['cards']['state']);
        $this->assertSame('unavailable', $snapshot['experiments']['state']);
        $this->assertSame([], $snapshot['cards']['items']);
        $this->assertSame([], $snapshot['experiments']['items']);
        $this->assertTrue($snapshot['read_only']);
        $this->assertFalse($snapshot['write_allowed']);
        $this->assertFalse($snapshot['publish_allowed']);
    }

    public function test_real_empty_authorities_render_not_started(): void
    {
        $this->migrateSources();
        $snapshot = app(Platform12DecisionExperimentReadService::class)->snapshot();

        $this->assertSame('not_started', $snapshot['cards']['state']);
        $this->assertSame('not_started', $snapshot['experiments']['state']);
        $html = view('filament.ops.components.ops-decision-experiment-workspace')->render();
        $this->assertStringContainsString('data-cards-state="not_started"', $html);
        $this->assertStringContainsString('data-experiments-state="not_started"', $html);
    }

    public function test_cards_and_experiments_show_real_status_expiry_sample_window_owner_readback_and_rollback(): void
    {
        $this->migrateSources();
        $this->seedDecisionCard();
        $this->seedExperiment(sharedLayer: false);

        $snapshot = app(Platform12DecisionExperimentReadService::class)->snapshot();
        $this->assertSame('available', $snapshot['cards']['state']);
        $this->assertSame('selected', $snapshot['cards']['items'][0]['status']);
        $this->assertSame('2026-09-12 00:00:00', $snapshot['cards']['items'][0]['expires_at']);
        $this->assertSame(120, $snapshot['experiments']['items'][0]['sample_size']);
        $this->assertSame(28, $snapshot['experiments']['items'][0]['window_days']);
        $this->assertSame('seo_ops', $snapshot['experiments']['items'][0]['owner']);
        $this->assertSame('healthy', $snapshot['experiments']['items'][0]['readback']);
        $this->assertSame('ready', $snapshot['experiments']['items'][0]['rollback']);
    }

    public function test_shared_layer_canary_without_flag_and_allowlist_is_held(): void
    {
        $this->migrateSources();
        $this->seedExperiment(sharedLayer: true);

        $experiment = app(Platform12DecisionExperimentReadService::class)->snapshot()['experiments']['items'][0];
        $this->assertSame('HOLD', $experiment['canary_state']);
        $this->assertFalse($experiment['feature_flag']);
        $this->assertFalse($experiment['allowlist']);
    }

    public function test_ui_is_view_and_navigation_only_and_keeps_existing_cms_authority(): void
    {
        $component = (string) file_get_contents(resource_path('views/filament/ops/components/ops-decision-experiment-workspace.blade.php'));
        $page = (string) file_get_contents(resource_path('views/filament/ops/pages/seo-operations.blade.php'));

        foreach (['<button', '<form', 'wire:click', 'write_allowed="true"', 'publish_allowed="true"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $component);
        }
        $this->assertStringContainsString('ops-decision-experiment-workspace', $page);
        $this->assertStringContainsString('ops-content-publishing-workspace', $page);
        $this->assertSame('existing_filament_resources', app(Platform12DecisionExperimentReadService::class)->snapshot()['cms_authority']);
    }

    private function migrateSources(): void
    {
        (require database_path('migrations/seo_intel/2026_08_27_010000_create_seo_change_ledger_tables.php'))->up();
        (require database_path('migrations/seo_intel/2026_08_27_020000_create_seo_decision_card_authority.php'))->up();
    }

    private function seedDecisionCard(): void
    {
        $cluster = 'seo_cluster_'.str_repeat('a', 48);
        $card = 'seo_decision_'.str_repeat('b', 48);
        $revision = '00000000-0000-4000-8000-000000000001';
        DB::connection('seo_intel')->table('seo_decision_cards')->insert([
            'schema_version' => SeoDecisionCardContract::VERSION, 'decision_card_id' => $card, 'decision_revision_id' => $revision,
            'idempotency_key' => 'e03-card', 'cluster_uid' => $cluster, 'revision_number' => 1, 'ledger_id' => '10000000-0000-4000-8000-000000000001',
            'detector' => 'technical_authority', 'root_cause' => 'canonical_mismatch', 'page_family' => 'personality_hub', 'locale' => 'zh-CN',
            'authority_revision' => 'authority-v1', 'runtime_revision' => 'runtime-v1', 'affected_unique_url_count' => 1,
            'evidence_state' => 'verified', 'evidence_freshness' => 'fresh', 'measurement_state' => 'READY', 'measurement_independent' => true,
            'business_priority' => 'L1', 'risk_tier' => 'P2', 'estimated_fix_cost' => 'bounded', 'priority_score' => 90,
            'highest_allowed_action' => 'L2', 'next_step' => 'Review.', 'owner' => 'seo_ops', 'first_observed_at' => '2026-09-05 00:00:00',
            'last_observed_at' => '2026-09-05 00:00:00', 'expires_at' => '2026-09-12 00:00:00', 'status' => 'selected',
            'evidence_hash' => str_repeat('c', 64), 'created_at' => '2026-09-05 00:00:00',
        ]);
        DB::connection('seo_intel')->table('seo_current_decision_cards')->insert(['cluster_uid' => $cluster, 'decision_card_id' => $card, 'decision_revision_id' => $revision, 'updated_at' => '2026-09-05 00:00:00']);
    }

    private function seedExperiment(bool $sharedLayer): void
    {
        DB::connection('seo_intel')->table('seo_change_ledgers')->insert([
            'ledger_id' => '20000000-0000-4000-8000-000000000001', 'schema_version' => 'seo.change_ledger.v1', 'idempotency_key' => 'e03-experiment',
            'change_type' => 'metadata', 'hypothesis' => 'Bounded title change.', 'rationale' => 'Measured public cohort.', 'public_url_cohort_json' => '[]',
            'page_family' => 'article', 'locale' => 'en', 'baseline_window_json' => '{}',
            'primary_metric_json' => json_encode(['sample_size' => 120], JSON_THROW_ON_ERROR),
            'guardrail_metrics_json' => '{}', 'observation_window_json' => json_encode(['window_days' => 28], JSON_THROW_ON_ERROR),
            'canary_scope_json' => json_encode(['shared_layer' => $sharedLayer, 'feature_flag' => false, 'allowlist' => false, 'sample_size' => 120], JSON_THROW_ON_ERROR),
            'public_runtime_readback_json' => json_encode(['status' => 'healthy'], JSON_THROW_ON_ERROR),
            'gsc_funnel_evidence_state_json' => '{}', 'rollback_plan_json' => json_encode(['status' => 'ready'], JSON_THROW_ON_ERROR),
            'owner_actor_json' => json_encode(['role' => 'seo_ops', 'email' => 'private@example.test'], JSON_THROW_ON_ERROR),
            'current_state' => 'canary', 'transition_sequence' => 1, 'created_at' => '2026-09-05 00:00:00', 'updated_at' => '2026-09-05 00:00:00',
        ]);
    }
}
