<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\Dataset\CareerDatasetPublicationMetadataService;
use Tests\TestCase;

final class CareerDatasetPublicationMetadataServiceTest extends TestCase
{
    public function test_it_builds_dedicated_career_owned_publication_metadata_authority(): void
    {
        $payload = app(CareerDatasetPublicationMetadataService::class)->build()->toArray();

        $this->assertSame('career_dataset_publication_metadata', $payload['authority_kind']);
        $this->assertSame('career.dataset_publication.v1', $payload['authority_version']);
        $this->assertSame('career_all_342_occupations_dataset', $payload['dataset_key']);
        $this->assertSame('career_all_342', $payload['dataset_scope']);
        $this->assertSame('FermatMind', data_get($payload, 'publisher.name'));
        $this->assertSame('https://www.fermatmind.com', data_get($payload, 'publisher.url'));
        $this->assertSame('Proprietary Dataset License', data_get($payload, 'license.name'));
        $this->assertSame(
            'https://www.fermatmind.com/datasets/occupations/license',
            data_get($payload, 'license.url')
        );
        $this->assertTrue((bool) data_get($payload, 'usage.allowed_for_public_display'));
        $this->assertTrue((bool) data_get($payload, 'usage.allowed_for_download'));
        $this->assertSame('landing_page_and_download', data_get($payload, 'distribution.access_mode'));
        $this->assertSame(
            'https://www.fermatmind.com/datasets/occupations/download',
            data_get($payload, 'distribution.download_url')
        );
        $this->assertSame(
            'https://www.fermatmind.com/datasets/occupations/method',
            data_get($payload, 'distribution.methodology_url')
        );
        $this->assertSame(
            'https://www.fermatmind.com/datasets/occupations',
            data_get($payload, 'distribution.documentation_url')
        );
        $this->assertSame(['json', 'csv'], data_get($payload, 'distribution.format'));
    }
}
