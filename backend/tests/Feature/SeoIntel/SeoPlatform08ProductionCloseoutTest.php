<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Ledger\SeoLedgerProductionCloseoutService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeoPlatform08ProductionCloseoutTest extends TestCase
{
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $revisionPath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.seo_intel' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge('seo_intel');
        $migration = require database_path('migrations/seo_intel/2026_08_27_010000_create_seo_change_ledger_tables.php');
        $migration->up();

        $this->revisionPath = dirname(base_path()).DIRECTORY_SEPARATOR.'REVISION';
        file_put_contents($this->revisionPath, self::SHA.PHP_EOL);
    }

    protected function tearDown(): void
    {
        if (is_file($this->revisionPath)) {
            unlink($this->revisionPath);
        }

        DB::disconnect('seo_intel');
        parent::tearDown();
    }

    public function test_exact_sha_schema_snapshot_permission_and_disabled_levels_prove_production_without_rows(): void
    {
        $receipt = app(SeoLedgerProductionCloseoutService::class)->evaluate(self::SHA, 401);

        $this->assertSame('production_proven', $receipt['state']);
        $this->assertTrue($receipt['schema_ready']);
        $this->assertTrue($receipt['route_protected']);
        $this->assertTrue($receipt['exact_sha_bound']);
        $this->assertTrue($receipt['snapshot_readable']);
        $this->assertTrue($receipt['snapshot_empty']);
        $this->assertSame(0, $receipt['snapshot_count']);
        $this->assertSame('L2', $receipt['highest_enabled_level']);
        $this->assertFalse($receipt['l3_enabled']);
        $this->assertFalse($receipt['l4_enabled']);
        $this->assertFalse($receipt['real_experiment_required']);
        $this->assertFalse(data_get($receipt, 'boundaries.production_database_write'));
    }

    public function test_sha_permission_or_schema_drift_fails_closed(): void
    {
        $service = app(SeoLedgerProductionCloseoutService::class);

        $this->assertSame('production_unproven', $service->evaluate(str_repeat('b', 40), 401)['state']);
        $this->assertSame('production_unproven', $service->evaluate(self::SHA, 200)['state']);

        Schema::connection('seo_intel')->drop('seo_change_ledger_events');
        $receipt = $service->evaluate(self::SHA, 401);
        $this->assertSame('production_unproven', $receipt['state']);
        $this->assertFalse($receipt['schema_ready']);
    }

    public function test_closeout_command_emits_only_the_sanitized_read_only_receipt(): void
    {
        $exit = Artisan::call('seo-ledger:production-closeout', [
            '--expected-sha' => self::SHA,
            '--permission-negative-status' => '401',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('"state":"production_proven"', $output);
        foreach (['hypothesis', 'rationale', 'public_url_cohort', 'actor_json', 'evidence_json', '10.0.0.1'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $output);
        }
    }

    public function test_existing_deploy_control_plane_runs_the_closeout_after_ops_smoke(): void
    {
        $deploy = (string) file_get_contents(base_path('../deploy.php'));
        $start = strpos($deploy, "task('seo:ledger-production-closeout'");
        $end = strpos($deploy, "task('healthcheck:queue-smoke'", (int) $start);
        $this->assertIsInt($start);
        $this->assertIsInt($end);
        $task = substr($deploy, (int) $start, (int) $end - (int) $start);

        $this->assertStringContainsString('/api/v0.5/ops/seo-intel/experiment-ledger', $task);
        $this->assertStringContainsString('test "\\$permission_status" = 401', $task);
        $this->assertStringContainsString('seo-ledger:production-closeout', $task);
        $this->assertStringContainsString('--expected-sha=', $task);
        $this->assertStringNotContainsString('artisan migrate', $task);
        $this->assertStringNotContainsString('ssh ', $task);
        $this->assertStringContainsString(
            "after('healthcheck:ops-entry-contract', 'seo:ledger-production-closeout')",
            $deploy,
        );
    }
}
