<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Contracts\CouncilContractValidator;
use App\Services\SeoCouncil\Entrypoints\LocalSkillMissionAdapter;
use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;
use App\Services\SeoCouncil\Persistence\CouncilRunRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

final class SeoPlatform12A04ProductionPersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.seo_intel', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('seo_council.connection', 'seo_intel');
        config()->set('seo_council.mission_persistence_enabled', false);
        config()->set('seo_council.mission_persistence_runtime_state', 'DISABLED');
        DB::purge('seo_intel');
        DB::connection('seo_intel')->getPdo();

        $runtime = require database_path('migrations/seo_intel/2026_08_29_030000_create_seo_council_runtime_tables.php');
        $runtime->up();
        $receipts = require database_path('migrations/seo_intel/2026_09_04_030000_expand_seo_council_run_receipts.php');
        $receipts->up();

        Schema::connection('seo_intel')->create('cms_write_sentinel', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
        Schema::connection('seo_intel')->create('business_write_sentinel', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
        DB::connection('seo_intel')->table('cms_write_sentinel')->insert(['value' => 'unchanged']);
        DB::connection('seo_intel')->table('business_write_sentinel')->insert(['value' => 'unchanged']);
    }

    protected function tearDown(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'testing');
        DB::purge('seo_intel');

        parent::tearDown();
    }

    public function test_production_simulation_persists_only_when_config_and_runtime_state_are_active(): void
    {
        $this->enableProductionPersistence();

        $input = $this->request();
        $first = app(LocalSkillMissionAdapter::class)->submit($input);
        $replay = app(LocalSkillMissionAdapter::class)->submit($input);
        $different = $input;
        $different['locale'] = 'en';
        $conflict = app(LocalSkillMissionAdapter::class)->submit($different);

        $this->assertSame($first, $replay);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $conflict['status']);
        $this->assertFalse($conflict['execution_allowed']);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_council_runs')->count());
        $this->assertSame(14, DB::connection('seo_intel')->table('seo_council_run_steps')->count());
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_council_run_receipts')->count());
        $this->assertSame(1, DB::connection('seo_intel')->table('cms_write_sentinel')->count());
        $this->assertSame(1, DB::connection('seo_intel')->table('business_write_sentinel')->count());
        $this->assertSame('unchanged', DB::connection('seo_intel')->table('cms_write_sentinel')->value('value'));
        $this->assertSame('unchanged', DB::connection('seo_intel')->table('business_write_sentinel')->value('value'));
    }

    public function test_default_production_state_remains_disabled_under_either_open_half_of_the_gate(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('seo_council.mission_persistence_enabled', true);
        config()->set('seo_council.mission_persistence_runtime_state', 'DISABLED');

        $repository = app(CouncilRunRepository::class);
        $snapshot = app(RuntimeCapabilitySnapshotBuilder::class)->snapshot();
        app(LocalSkillMissionAdapter::class)->submit($this->request());

        $this->assertFalse($repository->enabled());
        $this->assertFalse($snapshot['mission_persistence_enabled']);
        $this->assertSame('DISABLED', $snapshot['mission_persistence_runtime_state']);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_council_runs')->count());

        config()->set('seo_council.mission_persistence_enabled', false);
        config()->set('seo_council.mission_persistence_runtime_state', 'ACTIVE');
        $second = $this->request();
        $second['mission_id'] = 'mission:persistence:disabled-second';
        $second['idempotency_key'] = 'idempotency:persistence:disabled-second';
        app(LocalSkillMissionAdapter::class)->submit($second);

        $this->assertFalse($repository->enabled());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_council_runs')->count());
    }

    public function test_transaction_failure_returns_safe_hold_without_partial_run_step_conflict_or_receipt(): void
    {
        $this->enableProductionPersistence();
        DB::connection('seo_intel')->statement(<<<'SQL'
CREATE TRIGGER fail_council_step_insert
BEFORE INSERT ON seo_council_run_steps
BEGIN
    SELECT RAISE(ABORT, 'forced step failure');
END
SQL);

        $receipt = app(LocalSkillMissionAdapter::class)->submit($this->request());

        $this->assertSame('PERSISTENCE_HOLD', $receipt['status']);
        $this->assertSame('council_persistence_transaction_failed', $receipt['stop_reason']);
        $this->assertFalse($receipt['execution_allowed']);
        foreach (['seo_council_runs', 'seo_council_run_steps', 'seo_council_conflicts', 'seo_council_run_receipts'] as $table) {
            $this->assertSame(0, DB::connection('seo_intel')->table($table)->count(), $table);
        }
        $this->assertStringNotContainsString('forced step failure', json_encode($receipt, JSON_THROW_ON_ERROR));
    }

    public function test_unique_key_race_resolves_to_deterministic_replay_or_conflict_without_database_error(): void
    {
        $this->enableProductionPersistence();
        $input = $this->request();
        $first = app(LocalSkillMissionAdapter::class)->submit($input);
        $repository = app(CouncilRunRepository::class);

        $replay = $repository->persist($first, $input['idempotency_key']);
        $competing = $first;
        $competing['request_hash'] = str_repeat('f', 64);
        $conflict = $repository->persist($competing, $input['idempotency_key']);

        $this->assertSame('REPLAY', $replay['decision']);
        $this->assertSame($first, $replay['receipt']);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $conflict['decision']);
        $this->assertSame($first, $conflict['receipt']);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_council_runs')->count());
        $this->assertSame(14, DB::connection('seo_intel')->table('seo_council_run_steps')->count());
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_council_run_receipts')->count());
    }

    public function test_resume_requires_receipt_step_catalog_policy_binding_evidence_and_capability_hashes(): void
    {
        $this->enableProductionPersistence();
        $receipt = app(LocalSkillMissionAdapter::class)->submit($this->request());
        DB::connection('seo_intel')->table('seo_council_runs')
            ->where('run_id', $receipt['run_id'])
            ->update(['status' => 'RESUMABLE']);

        $resume = [
            'receipt_hash' => $receipt['receipt_hash'],
            'step_hash' => $receipt['steps'][0]['step_hash'],
            'catalog_hash' => $receipt['catalog_ref']['hash'],
            'policy_hash' => $receipt['policy_ref']['hash'],
            'binding_hash' => $receipt['binding_ref']['hash'],
            'evidence_hash' => $receipt['evidence_hash'],
            'capability_hash' => $receipt['capability_hash'],
        ];
        $bindings = array_intersect_key($resume, array_flip([
            'catalog_hash', 'policy_hash', 'binding_hash', 'evidence_hash', 'capability_hash',
        ]));
        $repository = app(CouncilRunRepository::class);

        $this->assertTrue($repository->resumeValid($resume, $bindings));
        foreach (array_keys($bindings) as $field) {
            $drifted = $resume;
            $drifted[$field] = str_repeat('0', 64);
            $this->assertFalse($repository->resumeValid($drifted, $bindings), $field);
        }
        $driftedStep = $resume;
        $driftedStep['step_hash'] = str_repeat('0', 64);
        $this->assertFalse($repository->resumeValid($driftedStep, $bindings));

        DB::connection('seo_intel')->table('seo_council_run_receipts')->delete();
        $this->assertFalse($repository->resumeValid($resume, $bindings));
    }

    public function test_resume_contract_rejects_legacy_partial_reference(): void
    {
        $input = $this->request();
        $input['resume_from'] = [
            'receipt_hash' => str_repeat('a', 64),
            'step_hash' => str_repeat('b', 64),
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RESUME_REF_FIELDS_INVALID');
        app(CouncilContractValidator::class)->missionRequest($input);
    }

    private function enableProductionPersistence(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('seo_council.mission_persistence_enabled', true);
        config()->set('seo_council.mission_persistence_runtime_state', 'ACTIVE');
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        return [
            'mission_id' => 'mission:persistence:platform12',
            'idempotency_key' => 'idempotency:persistence:platform12',
            'mission_type' => 'weekly_opportunity',
            'family' => 'tests',
            'locale' => 'zh-CN',
            'review_domain' => null,
            'requested_role' => null,
            'evidence_bundle_refs' => [[
                'bundle_id' => 'bundle:persistence:platform12',
                'bundle_version' => 1,
                'bundle_hash' => hash('sha256', 'platform12-persistence'),
                'evidence_type' => 'search_measurement',
                'status' => 'READY',
                'authority_revision' => str_repeat('a', 64),
            ]],
            'autonomy' => 'L0',
            'budget' => [
                'model_calls' => 0,
                'tool_calls' => 0,
                'external_calls' => 0,
                'execution_seconds' => 0,
                'retry_count' => 0,
                'context_bytes' => 0,
                'cost_amount' => 0,
                'currency' => 'USD',
            ],
            'tool_scope' => [],
            'egress_scope' => [],
            'resume_from' => null,
        ];
    }
}
