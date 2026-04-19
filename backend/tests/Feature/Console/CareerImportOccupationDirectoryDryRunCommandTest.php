<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerImportOccupationDirectoryDryRunCommandTest extends TestCase
{
    #[Test]
    public function it_validates_a_safe_dry_run_package_without_database_writes(): void
    {
        $package = $this->writePackage([
            $this->createRecord('CN', 'china_occupation_directory_2026', '1-01-00-01', 'cn-1-01-00-01'),
            $this->createRecord('US', 'onet_soc_2019', '11-1021.00', 'general-and-operations-managers'),
        ]);

        $this->artisan('career:import-occupation-directory-dry-run', [
            '--input' => $package['input'],
            '--alias-review' => $package['alias_review'],
            '--child-role-review' => $package['child_role_review'],
            '--manifest' => $package['manifest'],
        ])
            ->expectsOutputToContain('records_seen=2')
            ->expectsOutputToContain('create_total=2')
            ->expectsOutputToContain('alias_review_total=1')
            ->expectsOutputToContain('child_role_review_total=1')
            ->expectsOutputToContain('authority_duplicate_count=0')
            ->expectsOutputToContain('proposed_slug_duplicate_count=0')
            ->expectsOutputToContain('gate_failure_count=0')
            ->expectsOutputToContain('dry-run validation complete')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_rejects_duplicate_authority_keys_before_import(): void
    {
        $package = $this->writePackage([
            $this->createRecord('US', 'onet_soc_2019', '11-1021.00', 'general-and-operations-managers'),
            $this->createRecord('US', 'onet_soc_2019', '11-1021.00', 'general-and-operations-managers-copy'),
        ]);

        $this->artisan('career:import-occupation-directory-dry-run', [
            '--input' => $package['input'],
            '--manifest' => $package['manifest'],
        ])
            ->expectsOutputToContain('authority_duplicate_count=1')
            ->expectsOutputToContain('dry-run validation failed')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_rejects_records_that_are_not_draft_dry_run_inputs(): void
    {
        $record = $this->createRecord('CN', 'china_occupation_directory_2026', '1-01-00-01', 'cn-1-01-00-01');
        $record['dry_run_only'] = false;
        $record['governance']['publish_state'] = 'published';

        $package = $this->writePackage([$record]);

        $this->artisan('career:import-occupation-directory-dry-run', [
            '--input' => $package['input'],
            '--manifest' => $package['manifest'],
            '--json' => true,
        ])
            ->expectsOutputToContain('"gate_failure_count": 2')
            ->expectsOutputToContain('dry-run validation failed')
            ->assertExitCode(1);
    }

    /**
     * @return array<string, mixed>
     */
    private function createRecord(string $market, string $source, string $code, string $slug): array
    {
        return [
            'import_action' => 'create',
            'dry_run_only' => true,
            'market' => $market,
            'authority' => [
                'source' => $source,
                'code' => $code,
                'source_sheet' => $market === 'CN' ? 'China_2026_Directory' : 'US_English_Directory',
                'source_url' => 'https://example.test/source',
                'source_status' => 'test_source',
            ],
            'identity' => [
                'proposed_slug' => $slug,
                'slug_review_required' => $market === 'CN',
                'canonical_title_en' => $market === 'CN' ? 'Example translated title' : 'General and Operations Managers',
                'canonical_title_zh' => $market === 'CN' ? '示例职业' : '总经理和运营经理',
                'source_title_en' => $market === 'US' ? 'General and Operations Managers' : '',
                'source_title_zh' => $market === 'CN' ? '示例职业' : '',
            ],
            'localization' => [
                'translation_status' => 'machine_assisted_rule_review',
                'translation_review_required' => true,
            ],
            'taxonomy' => [
                'release_status' => 'test',
            ],
            'content_seed' => [
                'definition' => 'Test definition.',
            ],
            'governance' => [
                'publish_state' => 'draft',
                'detail_page_state' => 'dataset_only_until_backend_compile',
                'requires_backend_truth_compute' => true,
                'requires_editorial_review' => true,
                'source_package' => 'china_us_occupation_directories_2026',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array{input: string, alias_review: string, child_role_review: string, manifest: string}
     */
    private function writePackage(array $records): array
    {
        $dir = sys_get_temp_dir().'/career-import-dry-run-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        $input = $dir.'/career_create_import.jsonl';
        $handle = fopen($input, 'wb');
        $this->assertNotFalse($handle);
        foreach ($records as $record) {
            fwrite($handle, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }
        fclose($handle);

        $aliasReview = $dir.'/career_alias_review.csv';
        file_put_contents($aliasReview, "action,market,authority_code\nalias_review,US,11-1011.00\n");

        $childRoleReview = $dir.'/career_child_role_review.csv';
        file_put_contents($childRoleReview, "action,market,authority_code\nchild_role_review,CN,3-02-03-01::特种救援员\n");

        $manifest = $dir.'/import_manifest.json';
        file_put_contents($manifest, json_encode([
            'package_kind' => 'career_cms_import_dry_run_package',
            'counts' => [
                'create_total_top_level' => count($records),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'input' => $input,
            'alias_review' => $aliasReview,
            'child_role_review' => $childRoleReview,
            'manifest' => $manifest,
        ];
    }
}
