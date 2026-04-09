<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Import\FirstWaveAuthorityOverrideReader;
use App\Domain\Career\Import\FirstWaveEligibilityPolicy;
use App\Services\Career\Import\CareerAuthorityRowNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class FirstWaveAuthorityOverrideReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_unsupported_override_fields(): void
    {
        $reader = app(FirstWaveAuthorityOverrideReader::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not supported');

        $reader->read(base_path('tests/Fixtures/Career/authority_wave/first_wave_authority_overrides_invalid.json'));
    }

    public function test_it_allows_explicit_source_code_override_for_exact_existing_rows_only(): void
    {
        $row = [
            '_row_number' => 2,
            'Occupation Title' => 'Software developers, quality assurance analysts, and testers',
            'Slug' => 'software-developers',
            'Category' => 'computer-and-information-technology',
            'SOC Code' => '',
            'AI Exposure (0-10)' => '8',
            'Median Pay Annual (USD)' => '133080',
            'Median Pay Hourly (USD)' => '63.98',
            'Jobs 2024' => '1880000',
            'Projected Jobs 2034' => '2022400',
            'Employment Change' => '142400',
            'Outlook %' => '8',
            'Outlook Description' => 'Faster than average',
            'Entry Education' => "Bachelor's degree",
            'AI Rationale' => 'Engineering workflow exposure',
            'BLS URL' => 'https://www.bls.gov/ooh/computer-and-information-technology/software-developers.htm',
            'AI Exposure Source' => 'https://example.test/software',
        ];

        $manifest = [
            'defaults' => [
                'truth_market' => 'US',
                'display_market' => 'US',
            ],
            'authority_overrides_by_slug' => app(FirstWaveAuthorityOverrideReader::class)->bySlug(
                base_path('tests/Fixtures/Career/authority_wave/first_wave_authority_overrides_fixture.json')
            ),
            'occupations' => [[
                'occupation_uuid' => '664c2c76-4ee7-41c4-98fd-ffd1dc3ad308',
                'canonical_slug' => 'software-developers',
                'canonical_title_en' => 'Software Developers',
                'family_uuid' => 'f639c622-1a90-4b6d-a67c-081f6de2f48f',
                'mapping_mode' => 'exact',
                'family_slug' => 'computer-and-information-technology',
                'family_title_en' => 'Computer And Information Technology',
            ]],
        ];

        $normalized = app(CareerAuthorityRowNormalizer::class)->normalize($row, $manifest);
        $eligibility = app(FirstWaveEligibilityPolicy::class)->evaluate($normalized, ['exact']);

        $this->assertSame('15-1252', $normalized['crosswalk_source_code']);
        $this->assertTrue($eligibility['accepted']);
        $this->assertSame([], $eligibility['reasons']);
    }
}
