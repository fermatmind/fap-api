<?php

declare(strict_types=1);

namespace Tests\Feature\Psychometrics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class IqNormsImportDryRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_iq_norm_import_dry_run_validates_fixture_without_writes_or_claim_unlock(): void
    {
        $this->artisan('norms:iq:import --file=tests/Fixtures/iq/norms/iq_norm_dry_run_valid.json --dry-run=1')
            ->expectsOutputToContain('dry-run=1, no write performed.')
            ->expectsOutputToContain('validated scale=IQ_INTELLIGENCE_QUOTIENT bank=IQ_OWNER_ORIGINAL_30 version=iq_norm_dryrun_fixture_v1 rows=3 claim_eligible=false')
            ->expectsOutputToContain('version_lock=new_authority_candidate')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('iq_norm_authorities')->count());
    }

    public function test_iq_norm_import_rejects_invalid_fixture_in_dry_run(): void
    {
        $this->artisan('norms:iq:import --file=tests/Fixtures/iq/norms/iq_norm_dry_run_invalid.json --dry-run=1')
            ->expectsOutputToContain('standard_deviation_positive_required')
            ->expectsOutputToContain('rows.0.confidence_interval_invalid')
            ->expectsOutputToContain('rows.1.raw_score_duplicate')
            ->expectsOutputToContain('rows.1.iq_estimate_out_of_range')
            ->expectsOutputToContain('rows.1.percentile_out_of_range')
            ->expectsOutputToContain('rows_must_include_min_raw_score')
            ->expectsOutputToContain('rows_must_include_max_raw_score')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('iq_norm_authorities')->count());
    }

    public function test_iq_norm_import_write_mode_is_blocked_in_norm_02(): void
    {
        $this->artisan('norms:iq:import --file=tests/Fixtures/iq/norms/iq_norm_dry_run_valid.json --dry-run=0')
            ->expectsOutputToContain('IQ norm import writes are disabled in IQ-NORM-02. Use --dry-run=1.')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('iq_norm_authorities')->count());
    }

    public function test_iq_norm_import_can_require_claim_ready_gate_without_unlocking_claims(): void
    {
        $this->artisan('norms:iq:import --file=tests/Fixtures/iq/norms/iq_norm_dry_run_valid.json --dry-run=1 --require-claim-ready=1')
            ->expectsOutputToContain('authority_not_public_claim_ready:sample_size_below_public_claim_minimum')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('iq_norm_authorities')->count());
    }
}
