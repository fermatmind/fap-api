<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Iq\IqNormAuthorityContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IqNormAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $migration = require base_path('database/migrations/2026_05_31_090000_create_iq_norm_authorities_table.php');
        $migration->up();
    }

    public function test_iq_norm_authority_schema_exists_without_seeded_production_norms(): void
    {
        $this->assertTrue(Schema::hasTable('iq_norm_authorities'));

        foreach ([
            'id',
            'org_id',
            'scale_code',
            'bank_id',
            'norm_table_version',
            'status',
            'population_key',
            'locale',
            'sample_size',
            'mean',
            'standard_deviation',
            'min_raw_score',
            'max_raw_score',
            'source_kind',
            'source_ref',
            'license_verified',
            'locked',
            'effective_at',
            'retired_at',
            'metadata',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('iq_norm_authorities', $column), $column.' column missing');
        }

        $this->assertSame(0, DB::table('iq_norm_authorities')->count());
    }

    public function test_iq_norm_authority_contract_blocks_claims_until_license_lock_and_sample_gate_pass(): void
    {
        $gate = IqNormAuthorityContract::publicClaimGate([
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'bank_id' => 'IQ_BETA_30_ORIGINAL',
            'norm_table_version' => 'iq_norm_prod_v1',
            'status' => 'production_normed',
            'population_key' => 'general_adult_online',
            'locale' => 'zh-CN',
            'sample_size' => 120,
            'mean' => 15.2,
            'standard_deviation' => 4.1,
            'min_raw_score' => 0,
            'max_raw_score' => 30,
            'source_kind' => 'internal_calibration',
            'source_ref' => 'calibration-run-placeholder',
            'license_verified' => false,
            'locked' => false,
        ]);

        $this->assertFalse($gate['claim_eligible']);
        $this->assertContains('sample_size_below_public_claim_minimum', $gate['errors']);
        $this->assertContains('license_verification_required', $gate['errors']);
        $this->assertContains('locked_authority_required', $gate['errors']);
    }

    public function test_iq_norm_authority_contract_allows_claims_only_for_locked_verified_iq_authority(): void
    {
        $record = [
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'bank_id' => 'IQ_BETA_30_ORIGINAL',
            'norm_table_version' => 'iq_norm_prod_v1',
            'status' => 'production_normed',
            'population_key' => 'general_adult_online',
            'locale' => 'zh-CN',
            'sample_size' => 1200,
            'mean' => 15.2,
            'standard_deviation' => 4.1,
            'min_raw_score' => 0,
            'max_raw_score' => 30,
            'source_kind' => 'internal_calibration',
            'source_ref' => 'calibration-run-placeholder',
            'license_verified' => true,
            'locked' => true,
            'effective_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('iq_norm_authorities')->insert($record);

        $gate = IqNormAuthorityContract::publicClaimGate($record);

        $this->assertTrue($gate['claim_eligible']);
        $this->assertNull($gate['reason_code']);
        $this->assertSame(1, DB::table('iq_norm_authorities')->where('scale_code', 'IQ_INTELLIGENCE_QUOTIENT')->count());
    }

    public function test_iq_norm_authority_contract_rejects_non_iq_or_retired_authority(): void
    {
        $gate = IqNormAuthorityContract::publicClaimGate([
            'scale_code' => 'BIG5_OCEAN',
            'bank_id' => 'IQ_BETA_30_ORIGINAL',
            'norm_table_version' => 'iq_norm_prod_v1',
            'status' => 'production_normed',
            'population_key' => 'general_adult_online',
            'locale' => 'zh-CN',
            'sample_size' => 1200,
            'mean' => 15.2,
            'standard_deviation' => 4.1,
            'min_raw_score' => 0,
            'max_raw_score' => 30,
            'source_kind' => 'internal_calibration',
            'source_ref' => 'calibration-run-placeholder',
            'license_verified' => true,
            'locked' => true,
            'retired_at' => now()->toISOString(),
        ]);

        $this->assertFalse($gate['claim_eligible']);
        $this->assertContains('scale_code_must_be_iq_intelligence_quotient', $gate['errors']);
        $this->assertContains('retired_authority_cannot_claim', $gate['errors']);
    }
}
