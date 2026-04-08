<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\Import\CareerAuthorityDatasetReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerAuthorityDatasetReaderTest extends TestCase
{
    #[Test]
    public function it_reads_normalized_csv_fixtures_with_dataset_metadata(): void
    {
        $dataset = app(CareerAuthorityDatasetReader::class)->read(
            CareerFoundationFixture::firstWaveCsvPath(),
            CareerFoundationFixture::firstWaveManifestPath(),
        );

        $this->assertSame('first_wave_rows.csv', $dataset['dataset_name']);
        $this->assertSame('2026.04.08.test', $dataset['dataset_version']);
        $this->assertCount(3, $dataset['rows']);
        $this->assertSame('accountants-and-auditors', $dataset['rows'][0]['Slug']);
        $this->assertSame('trust_inheritance', $dataset['manifest']['occupations']['administrative-services-managers']['mapping_mode']);
        $this->assertNotSame('', $dataset['dataset_checksum']);
    }
}
