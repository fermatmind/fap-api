<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class FirstWavePublishReadyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_blocked_subjects_when_no_first_wave_rows_are_materialized(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"publish_ready": 0', $output);
        $this->assertStringContainsString('"blocked": 10', $output);
    }

    public function test_it_materializes_only_manifest_scoped_rows_and_reports_publish_ready_counts(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_publish_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"source_row_missing"', $output);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(10, count($report['occupations']));
        $this->assertSame(6, $report['counts']['blocked']);
        $this->assertSame(4, $report['counts']['publish_ready'] + $report['counts']['partial']);

        $this->assertDatabaseHas('occupations', ['canonical_slug' => 'data-scientists']);
        $this->assertDatabaseHas('occupations', ['canonical_slug' => 'project-management-specialists']);
        $this->assertDatabaseHas('occupations', ['canonical_slug' => 'human-resources-specialists']);
        $this->assertDatabaseHas('occupations', ['canonical_slug' => 'management-analysts']);
        $this->assertDatabaseMissing('occupations', ['canonical_slug' => 'civil-engineers']);
    }
}
