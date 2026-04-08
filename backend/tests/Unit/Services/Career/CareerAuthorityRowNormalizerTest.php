<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\Import\CareerAuthorityRowNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerAuthorityRowNormalizerTest extends TestCase
{
    #[Test]
    public function it_normalizes_first_wave_rows_and_applies_manifest_overrides(): void
    {
        $normalizer = app(CareerAuthorityRowNormalizer::class);

        $normalized = $normalizer->normalize([
            '_row_number' => 11,
            'Occupation Title' => 'Administrative services and facilities managers',
            'Slug' => 'administrative-services-managers',
            'Category' => 'management',
            'SOC Code' => '11-3010',
            'AI Exposure (0-10)' => '5',
            'Median Pay Annual (USD)' => '106880',
            'Jobs 2024' => '422600',
            'Projected Jobs 2034' => '440900',
            'Employment Change' => '18300',
            'Outlook %' => '4',
            'BLS URL' => 'https://example.test/bls',
            'AI Exposure Source' => 'https://example.test/source',
        ], [
            'defaults' => [
                'truth_market' => 'US',
                'display_market' => 'US',
            ],
            'occupations' => [
                'administrative-services-managers' => [
                    'mapping_mode' => 'trust_inheritance',
                    'display_market' => 'CN',
                    'canonical_title_zh' => '行政服务与设施经理',
                ],
            ],
        ]);

        $this->assertSame('administrative-services-managers', $normalized['canonical_slug']);
        $this->assertSame('trust_inheritance', $normalized['mapping_mode']);
        $this->assertSame('US', $normalized['truth_market']);
        $this->assertSame('CN', $normalized['display_market']);
        $this->assertSame('行政服务与设施经理', $normalized['canonical_title_zh']);
        $this->assertSame('/career/jobs/administrative-services-managers', $normalized['canonical_path']);
    }
}
