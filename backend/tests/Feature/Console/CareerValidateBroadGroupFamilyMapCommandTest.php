<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerValidateBroadGroupFamilyMapCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_validates_current_broad_group_family_map_as_no_op(): void
    {
        $scopePath = $this->writeBroadGroupScopeFixture();
        $outputPath = storage_path('framework/testing/career-broad-group-family-map-dry-run.json');
        File::delete($outputPath);

        $exitCode = Artisan::call('career:validate-broad-group-family-map', [
            '--scope' => $scopePath,
            '--timestamp' => 'career-broad-group-family-map-test',
            '--output' => $outputPath,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame('validated', $payload['status'] ?? null);
        $this->assertTrue((bool) ($payload['dry_run'] ?? false));
        $this->assertFalse((bool) ($payload['did_write'] ?? true));
        $this->assertSame(75, (int) ($payload['broad_group_rows'] ?? 0));
        $this->assertSame(0, (int) ($payload['approved_family_hubs'] ?? -1));
        $this->assertSame(0, (int) ($payload['family_hubs_to_create'] ?? -1));
        $this->assertSame(0, (int) ($payload['family_hubs_to_update'] ?? -1));
        $this->assertSame(0, (int) ($payload['active_family_hubs'] ?? -1));
        $this->assertSame(0, (int) ($payload['display_asset_delta'] ?? -1));
        $this->assertSame(793, (int) ($payload['career_job_display_assets'] ?? 0));
        $this->assertSame(0, (int) ($payload['sitemap_family_urls'] ?? -1));
        $this->assertSame(0, (int) ($payload['llms_family_urls'] ?? -1));
        $this->assertSame(0, (int) ($payload['llms_full_family_urls'] ?? -1));
        $this->assertContains('child_canonical_slugs', (array) ($payload['required_future_fields'] ?? []));
        $this->assertContains('schema_policy', (array) ($payload['required_future_fields'] ?? []));
        $this->assertContains('trust_manifest_required', (array) ($payload['required_future_fields'] ?? []));
        $this->assertSame([], $payload['blockers'] ?? null);
        $this->assertFileExists($outputPath);
    }

    public function test_it_rejects_scope_with_preapproved_family_hubs_before_policy_train(): void
    {
        $scopePath = $this->writeBroadGroupScopeFixture(approvedFamilyHubs: 1);

        $exitCode = Artisan::call('career:validate-broad-group-family-map', [
            '--scope' => $scopePath,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload);
        $this->assertContains('approved_family_hubs_present_before_policy', (array) ($payload['blockers'] ?? []));
    }

    private function writeBroadGroupScopeFixture(int $approvedFamilyHubs = 0): string
    {
        $path = storage_path('framework/testing/career-phase2b-broad-group-scope.json');
        File::ensureDirectoryExists(dirname($path));

        $rows = [];
        for ($index = 1; $index <= 75; $index++) {
            $rows[] = [
                'row_number' => $index,
                'source_slug' => sprintf('broad-group-%04d-all-other', $index),
                'title' => sprintf('Broad Group %04d, All Other', $index),
                'current_status' => 'broad_group_hold',
                'possible_family_targets' => [],
                'possible_child_occupations' => [],
                'recommended_decision' => $index <= $approvedFamilyHubs ? 'public_family_hub' : 'blocked_until_broad_group_policy',
                'confidence' => $index <= $approvedFamilyHubs ? 'reviewed' : 'low',
                'blockers' => $index <= $approvedFamilyHubs ? [] : ['family_hub_target_not_proven_for_source_row'],
            ];
        }

        File::put($path, (string) json_encode([
            'scan' => 'complete',
            'broad_group_scope' => [
                'count' => 75,
                'rows' => $rows,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
