<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\Dataset\CareerPublicDatasetContractBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerPublicDatasetContractBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_public_safe_dataset_hub_contract_from_backend_authority(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $contract = app(CareerPublicDatasetContractBuilder::class)->buildHubContract()->toArray();

        $this->assertSame('career_public_dataset_hub', $contract['contract_kind']);
        $this->assertSame('career.dataset_public_contract.v1', $contract['contract_version']);
        $this->assertSame('career_first_wave_job_detail_dataset', $contract['dataset_key']);
        $this->assertSame('career_first_wave_10', $contract['dataset_scope']);
        $this->assertSame('FermatMind', data_get($contract, 'publication.publisher.name'));
        $this->assertSame('Proprietary Dataset License', data_get($contract, 'publication.license.name'));
        $this->assertSame(
            'https://www.fermatmind.com/datasets/occupations/download',
            data_get($contract, 'publication.distribution.download_url')
        );
        $this->assertSame(
            'https://www.fermatmind.com/datasets/occupations/method',
            data_get($contract, 'method_url')
        );
        $this->assertSame(10, data_get($contract, 'collection_summary.member_count'));
        $this->assertSame(6, data_get($contract, 'collection_summary.stable_count'));
        $this->assertSame(4, data_get($contract, 'collection_summary.hold_count'));
        $this->assertTrue((bool) data_get($contract, 'filters.family'));
        $this->assertTrue((bool) data_get($contract, 'filters.publish_track'));
        $this->assertTrue((bool) data_get($contract, 'filters.index_posture'));
        $this->assertArrayNotHasKey('source_path', $contract);
    }

    public function test_it_builds_a_public_safe_dataset_method_contract(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $contract = app(CareerPublicDatasetContractBuilder::class)->buildMethodContract()->toArray();

        $this->assertSame('career_public_dataset_method', $contract['contract_kind']);
        $this->assertSame('career.dataset_public_method.v1', $contract['contract_version']);
        $this->assertSame('career_first_wave_job_detail_dataset', $contract['dataset_key']);
        $this->assertSame('career_first_wave_10', $contract['dataset_scope']);
        $this->assertSame('https://www.fermatmind.com/datasets/occupations/method', $contract['method_url']);
        $this->assertSame('https://www.fermatmind.com/datasets/occupations', $contract['hub_url']);
        $this->assertNotEmpty($contract['included']);
        $this->assertNotEmpty($contract['excluded']);
        $this->assertNotEmpty($contract['boundary_notes']);
        $this->assertArrayNotHasKey('evidence_refs', $contract);
        $this->assertArrayNotHasKey('review_queue', $contract);
    }

    private function materializeCurrentFirstWaveFixture(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_readiness_summary_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--repair-safe-partials' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }
}
