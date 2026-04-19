<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerApplyOccupationDirectoryReviewDecisionsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dry_run_blocks_pending_rows_by_default(): void
    {
        $run = $this->createRun();
        $dir = $this->writeQueues([
            'translation' => [['review_decision' => '']],
            'alias' => [],
            'child' => [],
        ]);

        $this->artisan('career:apply-occupation-directory-review-decisions', [
            '--queue-dir' => $dir,
            '--import-run' => $run->id,
        ])
            ->expectsOutputToContain('pending_total=1')
            ->expectsOutputToContain('review decision apply blocked')
            ->assertExitCode(1);
    }

    #[Test]
    public function dry_run_does_not_write_completed_decisions(): void
    {
        $run = $this->createRun();
        $this->createOccupation('cn-1-01-00-01', $run);
        $dir = $this->writeQueues([
            'translation' => [[
                'proposed_slug' => 'cn-1-01-00-01',
                'review_decision' => 'edit',
                'approved_title_zh' => '已审核中文名',
                'approved_title_en' => 'Reviewed English Title',
            ]],
            'alias' => [],
            'child' => [],
        ]);

        $this->artisan('career:apply-occupation-directory-review-decisions', [
            '--queue-dir' => $dir,
            '--import-run' => $run->id,
        ])
            ->expectsOutputToContain('review decision dry-run complete')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('occupations', [
            'canonical_slug' => 'cn-1-01-00-01',
            'canonical_title_zh' => '已审核中文名',
        ]);
    }

    #[Test]
    public function apply_updates_titles_and_creates_review_aliases(): void
    {
        $run = $this->createRun();
        $this->createOccupation('cn-1-01-00-01', $run);
        $this->createOccupation('existing-target', $run);
        $this->createOccupation('parent-firefighter', $run);
        $dir = $this->writeQueues([
            'translation' => [[
                'proposed_slug' => 'cn-1-01-00-01',
                'review_decision' => 'edit',
                'approved_title_zh' => '已审核中文名',
                'approved_title_en' => 'Reviewed English Title',
            ]],
            'alias' => [[
                'review_decision' => 'alias_existing',
                'approved_target_slug' => 'existing-target',
                'approved_alias_zh' => '审核别名',
                'approved_alias_en' => 'Reviewed Alias',
                'authority_source' => 'onet_soc_2019',
                'authority_code' => '11-1011.00',
            ]],
            'child' => [[
                'review_decision' => 'alias_parent',
                'approved_parent_slug' => 'parent-firefighter',
                'approved_child_title_zh' => '森林灭火员',
                'approved_child_title_en' => 'Forest Firefighter',
                'authority_source' => 'china_new_work_types_2025',
                'authority_code' => '3-02-03-01::森林灭火员',
            ]],
        ]);

        $this->artisan('career:apply-occupation-directory-review-decisions', [
            '--queue-dir' => $dir,
            '--import-run' => $run->id,
            '--apply' => true,
        ])
            ->expectsOutputToContain('translation_titles_updated=1')
            ->expectsOutputToContain('alias_existing_created=2')
            ->expectsOutputToContain('child_role_aliases_created=2')
            ->expectsOutputToContain('review decisions applied')
            ->assertExitCode(0);

        $this->assertDatabaseHas('occupations', [
            'canonical_slug' => 'cn-1-01-00-01',
            'canonical_title_zh' => '已审核中文名',
            'canonical_title_en' => 'Reviewed English Title',
        ]);
        $this->assertSame(4, OccupationAlias::query()->where('import_run_id', $run->id)->where('register', 'review_decision')->count());
    }

    private function createRun(): CareerImportRun
    {
        return CareerImportRun::query()->create([
            'dataset_name' => 'china_us_occupation_directories_2026',
            'dataset_version' => 'test',
            'dataset_checksum' => hash('sha256', 'test'),
            'source_path' => '/tmp/test.jsonl',
            'scope_mode' => 'occupation_directory_draft',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
            'rows_seen' => 1,
            'rows_accepted' => 1,
            'rows_skipped' => 0,
            'rows_failed' => 0,
        ]);
    }

    private function createOccupation(string $slug, CareerImportRun $run): Occupation
    {
        $family = OccupationFamily::query()->firstOrCreate(
            ['canonical_slug' => 'test-family'],
            ['title_en' => 'Test Family', 'title_zh' => '测试大类'],
        );
        $occupation = Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'dataset_candidate',
            'truth_market' => 'CN',
            'display_market' => 'CN',
            'crosswalk_mode' => 'directory_draft',
            'canonical_title_en' => 'Original English',
            'canonical_title_zh' => '原中文',
            'search_h1_zh' => '原中文',
        ]);
        OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => 'test_source',
            'source_code' => $slug,
            'source_title' => $slug,
            'mapping_type' => 'directory_candidate',
            'confidence_score' => 0.5,
            'notes' => 'test',
            'import_run_id' => $run->id,
            'row_fingerprint' => hash('sha256', $slug),
        ]);

        return $occupation;
    }

    /**
     * @param  array{
     *   translation: list<array<string,string>>,
     *   alias: list<array<string,string>>,
     *   child: list<array<string,string>>
     * }  $rows
     */
    private function writeQueues(array $rows): string
    {
        $dir = sys_get_temp_dir().'/career-apply-review-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        $this->writeCsv($dir.'/translation_review_queue.csv', [
            'proposed_slug',
            'review_decision',
            'approved_title_zh',
            'approved_title_en',
        ], $rows['translation']);
        $this->writeCsv($dir.'/alias_review_decisions.csv', [
            'review_decision',
            'approved_target_slug',
            'approved_alias_zh',
            'approved_alias_en',
            'suggested_title_zh',
            'suggested_title_en',
            'source_title_zh',
            'source_title_en',
            'authority_source',
            'authority_code',
        ], $rows['alias']);
        $this->writeCsv($dir.'/child_role_review_decisions.csv', [
            'review_decision',
            'approved_parent_slug',
            'approved_child_title_zh',
            'approved_child_title_en',
            'suggested_title_zh',
            'suggested_title_en',
            'source_title_zh',
            'source_title_en',
            'authority_source',
            'authority_code',
        ], $rows['child']);

        return $dir;
    }

    /**
     * @param  list<string>  $header
     * @param  list<array<string, string>>  $rows
     */
    private function writeCsv(string $path, array $header, array $rows): void
    {
        $handle = fopen($path, 'wb');
        $this->assertNotFalse($handle);
        fputcsv($handle, $header);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                static fn (string $key): string => $row[$key] ?? '',
                $header,
            ));
        }
        fclose($handle);
    }
}
