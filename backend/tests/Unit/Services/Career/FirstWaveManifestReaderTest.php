<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\FirstWaveManifestReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FirstWaveManifestReaderTest extends TestCase
{
    #[Test]
    public function it_reads_the_first_wave_manifest_and_freezes_the_expected_ten_occupations(): void
    {
        $manifest = app(FirstWaveManifestReader::class)->read();

        $this->assertSame('career_first_wave_10', $manifest['wave_name']);
        $this->assertSame(10, $manifest['count_expected']);
        $this->assertSame(10, $manifest['count_actual']);
        $this->assertCount(10, $manifest['occupations']);
        $this->assertSame([
            'software-developers',
            'data-scientists',
            'accountants-and-auditors',
            'financial-analysts',
            'project-management-specialists',
            'human-resources-specialists',
            'marketing-managers',
            'registered-nurses',
            'elementary-school-teachers-except-special-education',
            'management-analysts',
        ], array_column($manifest['occupations'], 'canonical_slug'));
    }

    #[Test]
    public function it_requires_machine_readable_publish_seed_fields_for_each_manifest_entry(): void
    {
        $manifest = app(FirstWaveManifestReader::class)->read();

        foreach ($manifest['occupations'] as $occupation) {
            foreach ([
                'occupation_uuid',
                'canonical_slug',
                'canonical_title_en',
                'family_uuid',
                'crosswalk_mode',
                'wave_classification',
                'publish_reason_codes',
                'trust_seed',
                'reviewer_seed',
                'index_seed',
                'claim_seed',
            ] as $requiredField) {
                $this->assertArrayHasKey($requiredField, $occupation);
            }
        }
    }
}
