<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use App\Models\SourceTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerImportOccupationDirectoryDraftsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dry_run_reports_staging_plan_without_database_writes(): void
    {
        $package = $this->writePackage([
            $this->createRecord('CN', 'china_occupation_directory_2026', '1-01-00-01', 'cn-1-01-00-01'),
            $this->createRecord('US', 'onet_soc_2019', '11-1021.00', 'general-and-operations-managers'),
        ]);

        $this->artisan('career:import-occupation-directory-drafts', [
            '--input' => $package['input'],
            '--alias-review' => $package['alias_review'],
            '--child-role-review' => $package['child_role_review'],
            '--manifest' => $package['manifest'],
        ])
            ->expectsOutputToContain('records_seen=2')
            ->expectsOutputToContain('will_create_occupations=2')
            ->expectsOutputToContain('draft import dry-run complete')
            ->assertExitCode(0);

        $this->assertSame(0, CareerImportRun::query()->count());
        $this->assertSame(0, Occupation::query()->count());
        $this->assertSame(0, OccupationAlias::query()->count());
    }

    #[Test]
    public function apply_is_blocked_while_review_queues_are_pending_without_explicit_override(): void
    {
        $package = $this->writePackage([
            $this->createRecord('CN', 'china_occupation_directory_2026', '1-01-00-01', 'cn-1-01-00-01'),
        ]);

        $this->artisan('career:import-occupation-directory-drafts', [
            '--input' => $package['input'],
            '--alias-review' => $package['alias_review'],
            '--child-role-review' => $package['child_role_review'],
            '--manifest' => $package['manifest'],
            '--apply' => true,
        ])
            ->expectsOutputToContain('draft import apply blocked')
            ->assertExitCode(1);

        $this->assertSame(0, CareerImportRun::query()->count());
        $this->assertSame(0, Occupation::query()->count());
    }

    #[Test]
    public function apply_stages_dataset_only_draft_authority_rows_when_explicitly_allowed(): void
    {
        $package = $this->writePackage([
            $this->createRecord('CN', 'china_occupation_directory_2026', '1-01-00-01', 'cn-1-01-00-01'),
            $this->createRecord('US', 'onet_soc_2019', '11-1021.00', 'general-and-operations-managers'),
        ]);

        $this->artisan('career:import-occupation-directory-drafts', [
            '--input' => $package['input'],
            '--alias-review' => $package['alias_review'],
            '--child-role-review' => $package['child_role_review'],
            '--manifest' => $package['manifest'],
            '--apply' => true,
            '--allow-pending-review' => true,
        ])
            ->expectsOutputToContain('draft import complete')
            ->assertExitCode(0);

        $run = CareerImportRun::query()->firstOrFail();
        $this->assertFalse($run->dry_run);
        $this->assertSame('occupation_directory_draft', $run->scope_mode);
        $this->assertSame(2, $run->rows_seen);
        $this->assertSame(2, $run->rows_accepted);
        $this->assertSame(0, $run->rows_failed);

        $this->assertSame(2, Occupation::query()->count());
        $this->assertSame(1, OccupationFamily::query()->count());
        $this->assertSame(4, OccupationAlias::query()->count());
        $this->assertSame(2, OccupationCrosswalk::query()->count());
        $this->assertSame(2, SourceTrace::query()->count());
        $this->assertDatabaseHas('occupation_families', [
            'canonical_slug' => 'management',
            'title_zh' => '管理',
            'title_en' => 'Management',
        ]);
        $this->assertDatabaseHas('occupations', [
            'canonical_slug' => 'cn-1-01-00-01',
            'entity_level' => 'dataset_candidate',
            'crosswalk_mode' => 'directory_draft',
            'truth_market' => 'CN',
        ]);
        $this->assertDatabaseHas('occupation_crosswalks', [
            'source_system' => 'onet_soc_2019',
            'source_code' => '11-1021.00',
            'mapping_type' => 'directory_candidate',
            'import_run_id' => $run->id,
        ]);

        $this->getJson('/api/v0.5/career/jobs')
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.trust_summary.status', 'unavailable')
            ->assertJsonPath('items.0.seo_contract.index_eligible', false)
            ->assertJsonMissingPath('items.0.trust_summary.reviewer_status')
            ->assertJsonMissingPath('items.0.trust_summary.content_version')
            ->assertJsonMissingPath('items.0.trust_summary.data_version')
            ->assertJsonMissingPath('items.0.provenance_meta.import_run_id');
        $this->getJson('/api/v0.5/career/search?q='.urlencode('示例职业').'&locale=zh-CN')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.trust_summary.status', 'unavailable')
            ->assertJsonPath('items.0.seo_contract.index_eligible', false)
            ->assertJsonMissingPath('items.0.trust_summary.reviewer_status')
            ->assertJsonMissingPath('items.0.trust_summary.content_version')
            ->assertJsonMissingPath('items.0.provenance_meta.import_run_id');
        $this->getJson('/api/v0.5/career/jobs/cn-1-01-00-01')
            ->assertNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function createRecord(string $market, string $source, string $code, string $slug): array
    {
        $isCn = $market === 'CN';

        return [
            'import_action' => 'create',
            'dry_run_only' => true,
            'market' => $market,
            'authority' => [
                'source' => $source,
                'code' => $code,
                'source_sheet' => $isCn ? 'China_2026_Directory' : 'US_English_Directory',
                'source_url' => 'https://example.test/source',
                'source_status' => 'test_source',
            ],
            'identity' => [
                'proposed_slug' => $slug,
                'slug_review_required' => $isCn,
                'canonical_title_en' => $isCn ? 'Example translated title' : 'General and Operations Managers',
                'canonical_title_zh' => $isCn ? '示例职业' : '总经理和运营经理',
                'source_title_en' => $isCn ? '' : 'General and Operations Managers',
                'source_title_zh' => $isCn ? '示例职业' : '',
            ],
            'localization' => [
                'translation_status' => 'machine_assisted_rule_review',
                'translation_review_required' => true,
            ],
            'taxonomy' => [
                'major_code' => $isCn ? '1' : '',
                'major_name_zh' => $isCn ? '测试职业大类' : '',
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
        $dir = sys_get_temp_dir().'/career-import-drafts-'.bin2hex(random_bytes(6));
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
            'source_package' => 'china_us_occupation_directories_2026',
            'package_version' => 'test',
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
