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

    public function test_iq_norm_import_write_mode_requires_explicit_activation_and_claim_ready_gate(): void
    {
        $this->artisan('norms:iq:import --file=tests/Fixtures/iq/norms/iq_norm_dry_run_valid.json --dry-run=0')
            ->expectsOutputToContain('write_mode_requires_activate_1')
            ->expectsOutputToContain('activate_requires_require_claim_ready_1')
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

    public function test_iq_norm_import_activates_claim_ready_owner30_authority(): void
    {
        $this->artisan('norms:iq:import --file=tests/Fixtures/iq/norms/iq_owner30_claim_ready_valid.json --dry-run=0 --activate=1 --require-claim-ready=1')
            ->expectsOutputToContain('validated scale=IQ_INTELLIGENCE_QUOTIENT bank=IQ_OWNER_ORIGINAL_30 version=iq_owner30_norm_fixture_v1 rows=3 claim_eligible=true')
            ->expectsOutputToContain('activated owner IQ 30 norm authority.')
            ->expectsOutputToContain('imported_authority_id=')
            ->expectsOutputToContain('version_lock=new_authority_candidate')
            ->assertExitCode(0);

        $authority = DB::table('iq_norm_authorities')->first();

        $this->assertNotNull($authority);
        $this->assertSame('IQ_OWNER_ORIGINAL_30', $authority->bank_id);
        $this->assertSame('iq_owner30_norm_fixture_v1', $authority->norm_table_version);
        $this->assertSame('production_normed', $authority->status);
        $this->assertSame(1200, (int) $authority->sample_size);
        $this->assertSame(1, (int) $authority->license_verified);
        $this->assertSame(1, (int) $authority->locked);

        $metadata = json_decode((string) $authority->metadata, true);
        $this->assertSame('IQ-OWNER-30-NORM-AUTHORITY-04A', $metadata['import_scope'] ?? null);
        $this->assertSame(3, $metadata['rows_count'] ?? null);
        $this->assertTrue((bool) data_get($metadata, 'claim_gate.claim_eligible'));
    }

    public function test_iq_norm_import_rejects_non_owner30_activation(): void
    {
        $fixture = $this->temporaryFixture([
            'bank_id' => 'IQ_LEGACY_DEMO_BANK',
        ]);

        $this->artisan('norms:iq:import --file='.$fixture.' --dry-run=0 --activate=1 --require-claim-ready=1')
            ->expectsOutputToContain('owner30_authority_import_requires_bank_iq_owner_original_30')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('iq_norm_authorities')->count());
    }

    public function test_iq_norm_import_rejects_duplicate_locked_authority_version(): void
    {
        $command = 'norms:iq:import --file=tests/Fixtures/iq/norms/iq_owner30_claim_ready_valid.json --dry-run=0 --activate=1 --require-claim-ready=1';

        $this->artisan($command)
            ->expectsOutputToContain('activated owner IQ 30 norm authority.')
            ->assertExitCode(0);

        $this->artisan($command)
            ->expectsOutputToContain('authority_version_already_exists')
            ->assertExitCode(1);

        $this->assertSame(1, DB::table('iq_norm_authorities')->count());
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function temporaryFixture(array $overrides): string
    {
        $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/iq/norms/iq_owner30_claim_ready_valid.json')), true);
        $payload = array_merge($payload, $overrides);
        $path = tempnam(sys_get_temp_dir(), 'iq_norm_');
        $this->assertIsString($path);
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
