<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Filament\Ops\Support\SeoWorkbenchUiContract;
use App\Http\Middleware\EnsureSeoIntelReadAuthorized;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Services\SeoIntel\Decision\SeoDecisionCardContract;
use App\Services\SeoIntel\Decision\SeoDecisionCardReadService;
use App\Services\SeoIntel\Decision\SeoWeeklyDecisionSelector;
use App\Support\Rbac\PermissionNames;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform09WeeklyWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'admin.totp.enabled' => false,
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('seo_intel');
        (require database_path('migrations/seo_intel/2026_08_27_020000_create_seo_decision_card_authority.php'))->up();
        CarbonImmutable::setTestNow('2026-08-27T12:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::disconnect('seo_intel');
        parent::tearDown();
    }

    #[Test]
    public function selector_uses_iso_week_defaults_to_three_caps_at_five_and_never_pads(): void
    {
        foreach ([
            ['a', 'P2', 95.0, 'candidate', 'observed', 'fresh', 'READY'],
            ['b', 'P1', 20.0, 'candidate', 'verified', 'fresh', 'READY'],
            ['c', 'P0', 10.0, 'candidate', 'verified', 'fresh', 'READY'],
            ['d', 'P2', 90.0, 'selected', 'verified', 'fresh', 'READY'],
            ['e', 'P0', 100.0, 'held', 'verified', 'fresh', 'READY'],
            ['f', 'P2', 100.0, 'candidate', 'verified', 'fresh', 'MEASUREMENT_HOLD'],
            ['7', 'P2', 99.0, 'candidate', 'verified', 'stale', 'READY'],
            ['8', 'P2', 80.0, 'in_progress', 'verified', 'fresh', 'READY'],
            ['9', 'P2', 70.0, 'recovery_pending', 'verified', 'fresh', 'READY'],
        ] as $index => [$suffix, $risk, $score, $status, $evidence, $freshness, $measurement]) {
            $this->seedCard($index + 1, $suffix, $risk, $score, $status, $evidence, $freshness, $measurement);
        }

        $selector = new SeoWeeklyDecisionSelector(new SeoDecisionCardReadService('seo_intel'));
        $default = $selector->snapshot();
        $maximum = $selector->snapshot(limit: 99);

        $this->assertSame('2026-W35', $default['iso_week']);
        $this->assertSame(3, $default['count']);
        $this->assertSame(['c', 'b', 'a'], $this->suffixes($default['decisions']));
        $this->assertSame([1, 2, 3], array_column($default['decisions'], 'selection_rank'));
        $this->assertSame(5, $maximum['count']);
        $this->assertSame(['c', 'b', 'a', 'd', '8'], $this->suffixes($maximum['decisions']));
        $this->assertFalse($maximum['padded']);
        $this->assertNotContains('held', array_column($maximum['decisions'], 'status'));
        $this->assertCount(1, array_unique(array_column($maximum['decisions'], 'selection_revision')));
    }

    #[Test]
    public function verified_zero_and_short_candidate_sets_remain_real_zero_to_five_results(): void
    {
        $selector = new SeoWeeklyDecisionSelector(new SeoDecisionCardReadService('seo_intel'));
        $zero = $selector->snapshot();
        $this->assertSame('verified_zero', $zero['state']);
        $this->assertSame(0, $zero['count']);
        $this->assertSame([], $zero['decisions']);
        $this->assertNotNull($zero['selection_revision']);

        $this->seedCard(1, 'a', 'P2', 50.0, 'candidate', 'verified', 'fresh', 'READY');
        $this->seedCard(2, 'b', 'P2', 40.0, 'candidate', 'verified', 'fresh', 'READY');
        $short = $selector->snapshot();

        $this->assertSame(2, $short['count']);
        $this->assertCount(2, $short['decisions']);
        $this->assertFalse($short['padded']);
    }

    #[Test]
    public function protected_api_and_existing_workbench_render_the_same_order_count_and_revision(): void
    {
        $route = Route::getRoutes()->getByName('api.v0_5.ops.seo_intel.weekly_decisions');
        $this->assertNotNull($route);
        $this->assertContains(EnsureSeoIntelReadAuthorized::class, $route->gatherMiddleware());
        $this->getJson('/api/v0.5/ops/seo-intel/weekly-decisions')->assertUnauthorized();

        foreach ([
            [1, 'a', 70.0],
            [2, 'b', 90.0],
            [3, 'c', 80.0],
            [4, 'd', 60.0],
        ] as [$revision, $suffix, $score]) {
            $this->seedCard($revision, $suffix, 'P2', $score, 'candidate', 'verified', 'fresh', 'READY');
        }

        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_SEO_INTEL_READ]);
        $api = $this->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/ops/seo-intel/weekly-decisions?limit=5')
            ->assertOk()
            ->assertJsonPath('meta.contract_version', SeoWeeklyDecisionSelector::CONTRACT_VERSION)
            ->assertJsonPath('meta.read_only', true)
            ->json('data');
        $ui = SeoWorkbenchUiContract::snapshot();
        $html = view('filament.ops.components.ops-seo-workbench-workspace')->render();

        $this->assertSame(array_column($api['decisions'], 'cluster_uid'), array_column($ui['decisions'], 'cluster_uid'));
        $this->assertSame($api['count'], $ui['count']);
        $this->assertSame($api['selection_revision'], $ui['selection_revision']);
        $this->assertStringContainsString('data-decision-count="3"', $html);
        $this->assertStringContainsString('data-selection-revision="'.$api['selection_revision'].'"', $html);
        $positions = array_map(fn (array $decision): int => (int) strpos($html, $decision['cluster_uid']), $api['decisions']);
        $this->assertSame($positions, array_values($positions));
        $this->assertTrue($positions[0] < $positions[1] && $positions[1] < $positions[2]);
        $this->assertStringNotContainsString('wire:click', $html);
        $this->assertStringNotContainsString('wire:model', $html);
    }

    private function seedCard(
        int $revision,
        string $suffix,
        string $risk,
        float $score,
        string $status,
        string $evidenceState,
        string $freshness,
        string $measurementState,
    ): void {
        $clusterUid = 'seo_cluster_'.str_repeat($suffix, 48);
        $revisionId = sprintf('00000000-0000-4000-8000-%012d', $revision);
        $cardId = 'seo_decision_'.substr(hash('sha256', $clusterUid), 0, 48);
        DB::connection('seo_intel')->table('seo_decision_cards')->insert([
            'schema_version' => SeoDecisionCardContract::VERSION,
            'decision_card_id' => $cardId,
            'decision_revision_id' => $revisionId,
            'idempotency_key' => 'weekly-card-'.$revision,
            'cluster_uid' => $clusterUid,
            'revision_number' => 1,
            'ledger_id' => sprintf('10000000-0000-4000-8000-%012d', $revision),
            'detector' => 'technical_authority',
            'root_cause' => 'canonical_mismatch_'.$suffix,
            'page_family' => 'personality_hub',
            'locale' => 'zh-CN',
            'authority_revision' => 'authority-v1',
            'runtime_revision' => 'runtime-v1',
            'affected_unique_url_count' => $revision,
            'evidence_state' => $evidenceState,
            'evidence_freshness' => $freshness,
            'measurement_state' => $measurementState,
            'measurement_independent' => true,
            'business_priority' => 'L1',
            'risk_tier' => $risk,
            'estimated_fix_cost' => 'bounded',
            'priority_score' => $score,
            'highest_allowed_action' => 'L2',
            'next_step' => 'Review the bounded decision.',
            'owner' => 'seo_ops',
            'first_observed_at' => '2026-08-27 00:00:00',
            'last_observed_at' => '2026-08-27 00:00:00',
            'expires_at' => '2026-08-28 00:00:00',
            'status' => $status,
            'evidence_hash' => hash('sha256', 'weekly-evidence-'.$revision),
            'created_at' => '2026-08-27 00:00:00',
        ]);
        DB::connection('seo_intel')->table('seo_current_decision_cards')->insert([
            'cluster_uid' => $clusterUid,
            'decision_card_id' => $cardId,
            'decision_revision_id' => $revisionId,
            'updated_at' => '2026-08-27 00:00:00',
        ]);
    }

    /** @param list<array<string, mixed>> $decisions @return list<string> */
    private function suffixes(array $decisions): array
    {
        return array_map(fn (array $decision): string => substr((string) $decision['cluster_uid'], -1), $decisions);
    }

    /** @param list<string> $permissions */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'seo_'.Str::lower(Str::random(6)),
            'email' => 'seo_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);
        $role = Role::query()->create([
            'name' => 'role_'.Str::lower(Str::random(8)),
            'description' => null,
        ]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => null],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }
}
