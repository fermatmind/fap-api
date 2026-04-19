<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CareerImportRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerExportOccupationDirectoryReviewQueuesCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_exports_editable_review_queue_files(): void
    {
        $package = $this->writePackage();
        $run = CareerImportRun::query()->create([
            'dataset_name' => 'china_us_occupation_directories_2026',
            'dataset_version' => 'test',
            'dataset_checksum' => hash('sha256', 'test'),
            'source_path' => $package['input'],
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
        $outputDir = sys_get_temp_dir().'/career-review-queues-'.bin2hex(random_bytes(6));

        $this->artisan('career:export-occupation-directory-review-queues', [
            '--input' => $package['input'],
            '--alias-review' => $package['alias_review'],
            '--child-role-review' => $package['child_role_review'],
            '--import-run' => $run->id,
            '--output-dir' => $outputDir,
        ])
            ->expectsOutputToContain('translation_review_total=1')
            ->expectsOutputToContain('alias_review_total=1')
            ->expectsOutputToContain('child_role_review_total=1')
            ->expectsOutputToContain('review queues exported')
            ->assertExitCode(0);

        $this->assertFileExists($outputDir.'/translation_review_queue.csv');
        $this->assertFileExists($outputDir.'/alias_review_decisions.csv');
        $this->assertFileExists($outputDir.'/child_role_review_decisions.csv');
        $this->assertFileExists($outputDir.'/review_manifest.json');
        $this->assertStringContainsString('review_decision', (string) file_get_contents($outputDir.'/translation_review_queue.csv'));
        $this->assertStringContainsString('approved_target_slug', (string) file_get_contents($outputDir.'/alias_review_decisions.csv'));
        $this->assertStringContainsString('approved_parent_slug', (string) file_get_contents($outputDir.'/child_role_review_decisions.csv'));
    }

    /**
     * @return array{input:string, alias_review:string, child_role_review:string}
     */
    private function writePackage(): array
    {
        $dir = sys_get_temp_dir().'/career-review-source-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        $input = $dir.'/career_create_import.jsonl';
        file_put_contents($input, json_encode([
            'import_action' => 'create',
            'dry_run_only' => true,
            'market' => 'CN',
            'authority' => [
                'source' => 'china_occupation_directory_2026',
                'code' => '1-01-00-01',
                'source_url' => 'https://example.test/source',
            ],
            'identity' => [
                'proposed_slug' => 'cn-1-01-00-01',
                'canonical_title_en' => 'Example translated title',
                'canonical_title_zh' => '示例职业',
                'source_title_en' => '',
                'source_title_zh' => '示例职业',
            ],
            'localization' => [
                'translation_status' => 'machine_assisted_rule_review',
                'translation_review_required' => true,
            ],
            'content_seed' => [
                'definition' => 'Test definition.',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $aliasReview = $dir.'/career_alias_review.csv';
        file_put_contents($aliasReview, "action,market,authority_code\nalias_review,US,11-1011.00\n");

        $childRoleReview = $dir.'/career_child_role_review.csv';
        file_put_contents($childRoleReview, "action,market,authority_code\nchild_role_review,CN,3-02-03-01::特种救援员\n");

        return [
            'input' => $input,
            'alias_review' => $aliasReview,
            'child_role_review' => $childRoleReview,
        ];
    }
}
