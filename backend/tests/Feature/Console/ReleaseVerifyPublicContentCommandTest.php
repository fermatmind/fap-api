<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReleaseVerifyPublicContentCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_fails_when_backend_content_page_baselines_are_missing(): void
    {
        $this->artisan('release:verify-public-content', [
            '--expected-occupations' => 0,
            '--min-career-job-items' => 0,
            '--content-source-dir' => '../content_baselines/content_pages',
        ])
            ->expectsOutputToContain('content_pages ok=0')
            ->expectsOutputToContain('missing_content_page:zh-CN:about')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_passes_when_content_pages_and_career_directory_counts_are_ready(): void
    {
        $this->artisan('content-pages:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/content_pages',
        ])->assertExitCode(0);

        $this->createDirectoryDraftOccupation('example-directory-job', 'Example directory job', '示例目录职业');
        $this->createDirectoryDraftOccupation('sample-directory-job', 'Sample directory job', '样例目录职业');

        $this->artisan('release:verify-public-content', [
            '--expected-occupations' => 2,
            '--min-career-job-items' => 2,
            '--content-source-dir' => '../content_baselines/content_pages',
        ])
            ->expectsOutputToContain('content_pages ok=1')
            ->expectsOutputToContain('career_dataset ok=1')
            ->expectsOutputToContain('career_job_list ok=1')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_warns_on_career_completeness_failures_by_default(): void
    {
        $this->artisan('content-pages:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/content_pages',
        ])->assertExitCode(0);

        $this->createDirectoryDraftOccupation('example-directory-job', 'Example directory job', '示例目录职业');

        $this->artisan('release:verify-public-content', [
            '--expected-occupations' => 999,
            '--min-career-job-items' => 999,
            '--content-source-dir' => '../content_baselines/content_pages',
        ])
            ->expectsOutputToContain('career_completeness_strict=0')
            ->expectsOutputToContain('content_pages ok=1')
            ->expectsOutputToContain('career_dataset ok=0')
            ->expectsOutputToContain('career_job_list ok=0')
            ->expectsOutputToContain('warning career_dataset: career_dataset_member_count_below_expected:')
            ->expectsOutputToContain('warning career_job_list: career_job_list_count_below_expected:')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_validates_career_public_resolution_counts_without_old_dataset_thresholds(): void
    {
        $this->importContentPageBaselines();
        $this->createDirectoryDraftOccupation('example-directory-job', 'Example directory job', '示例目录职业');

        $ledgerPath = $this->writePublicResolutionLedger(canonicalRows: 793, governedRows: 1993);

        $this->artisan('release:verify-public-content', [
            '--public-resolution-ledger' => $ledgerPath,
            '--content-source-dir' => '../content_baselines/content_pages',
        ])
            ->expectsOutputToContain('career_dataset ok=1')
            ->expectsOutputToContain('career_dataset.contract_scope=dataset_jobs_api_count')
            ->expectsOutputToContain('career_job_list ok=1')
            ->expectsOutputToContain('career_public_resolution ok=1')
            ->expectsOutputToContain('career_public_resolution.terminal_resolution_rows=2786')
            ->expectsOutputToContain('career_public_resolution.canonical_public_assets=793')
            ->expectsOutputToContain('career_public_resolution.governed_non_public_rows=1993')
            ->expectsOutputToContain('career_public_resolution.held_leakage=0')
            ->expectsOutputToContain('career_public_resolution.software_developers_leakage=0')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_keeps_public_resolution_guard_failures_blocking(): void
    {
        $this->importContentPageBaselines();
        $this->createDirectoryDraftOccupation('example-directory-job', 'Example directory job', '示例目录职业');

        $ledgerPath = $this->writePublicResolutionLedger(
            canonicalRows: 792,
            governedRows: 1993,
            extraRows: [
                [
                    'source_slug' => 'held-public-row',
                    'current_status' => 'manual_hold',
                    'public_resolution_type' => 'public_canonical_job',
                    'indexability' => 'indexable',
                    'public_eligible' => true,
                    'sitemap_eligible' => true,
                    'llms_eligible' => true,
                    'llms_full_eligible' => true,
                ],
                [
                    'source_slug' => 'software-developers',
                    'current_status' => 'manual_hold',
                    'public_resolution_type' => 'keep_non_public_with_policy',
                    'indexability' => 'not_public',
                    'public_eligible' => false,
                    'sitemap_eligible' => true,
                    'llms_eligible' => false,
                    'llms_full_eligible' => false,
                ],
                [
                    'source_slug' => 'llms-leak',
                    'current_status' => 'duplicate_identity_hold',
                    'public_resolution_type' => 'blocked_until_governance_approval',
                    'indexability' => 'not_public',
                    'public_eligible' => false,
                    'sitemap_eligible' => false,
                    'llms_eligible' => true,
                    'llms_full_eligible' => true,
                ],
                [
                    'source_slug' => 'held-public-noindex-row',
                    'current_status' => 'manual_hold',
                    'public_resolution_type' => 'public_nonindex_reference',
                    'indexability' => 'noindex',
                    'public_eligible' => false,
                    'sitemap_eligible' => false,
                    'llms_eligible' => false,
                    'llms_full_eligible' => false,
                ],
            ],
        );

        $this->artisan('release:verify-public-content', [
            '--public-resolution-ledger' => $ledgerPath,
            '--content-source-dir' => '../content_baselines/content_pages',
        ])
            ->expectsOutputToContain('career_public_resolution ok=0')
            ->expectsOutputToContain('career_public_resolution: career_public_resolution_terminal_rows_mismatch:2789<>2786')
            ->expectsOutputToContain('career_public_resolution: career_public_resolution_held_leakage:1')
            ->expectsOutputToContain('career_public_resolution: career_public_resolution_held_public_noindex_leakage:1')
            ->expectsOutputToContain('career_public_resolution: career_public_resolution_software_developers_leakage:1')
            ->expectsOutputToContain('career_public_resolution: career_public_resolution_sitemap_bad_count:1')
            ->expectsOutputToContain('career_public_resolution: career_public_resolution_llms_bad_count:1')
            ->expectsOutputToContain('career_public_resolution: career_public_resolution_llms_full_bad_count:1')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_fails_on_career_completeness_failures_when_strict_mode_is_enabled(): void
    {
        $this->artisan('content-pages:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/content_pages',
        ])->assertExitCode(0);

        $this->createDirectoryDraftOccupation('example-directory-job', 'Example directory job', '示例目录职业');

        $this->artisan('release:verify-public-content', [
            '--expected-occupations' => 999,
            '--min-career-job-items' => 999,
            '--content-source-dir' => '../content_baselines/content_pages',
            '--strict-career' => true,
        ])
            ->expectsOutputToContain('career_completeness_strict=1')
            ->expectsOutputToContain('content_pages ok=1')
            ->expectsOutputToContain('career_dataset ok=0')
            ->expectsOutputToContain('career_job_list ok=0')
            ->expectsOutputToContain('career_dataset: career_dataset_member_count_below_expected:')
            ->expectsOutputToContain('career_job_list: career_job_list_count_below_expected:')
            ->assertExitCode(1);
    }

    private function createDirectoryDraftOccupation(string $slug, string $titleEn, string $titleZh): void
    {
        $family = OccupationFamily::query()->firstOrCreate(
            ['canonical_slug' => 'test-family'],
            [
                'title_en' => 'Test family',
                'title_zh' => '测试职业族',
            ],
        );

        Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'dataset_candidate',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'directory_draft',
            'canonical_title_en' => $titleEn,
            'canonical_title_zh' => $titleZh,
            'search_h1_zh' => $titleZh,
            'structural_stability' => null,
            'task_prototype_signature' => null,
            'market_semantics_gap' => null,
            'regulatory_divergence' => null,
            'toolchain_divergence' => null,
            'skill_gap_threshold' => null,
            'trust_inheritance_scope' => null,
        ]);
    }

    private function importContentPageBaselines(): void
    {
        $this->artisan('content-pages:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/content_pages',
        ])->assertExitCode(0);
    }

    /**
     * @param  list<array<string, mixed>>  $extraRows
     */
    private function writePublicResolutionLedger(int $canonicalRows, int $governedRows, array $extraRows = []): string
    {
        $rows = [];
        for ($index = 1; $index <= $canonicalRows; $index++) {
            $rows[] = [
                'source_slug' => sprintf('canonical-%04d', $index),
                'current_status' => 'already_imported_validated',
                'public_resolution_type' => 'public_canonical_job',
                'indexability' => 'indexable',
                'public_eligible' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'llms_full_eligible' => true,
            ];
        }

        for ($index = 1; $index <= $governedRows; $index++) {
            $rows[] = [
                'source_slug' => sprintf('governed-%04d', $index),
                'current_status' => 'duplicate_identity_hold',
                'public_resolution_type' => 'blocked_until_governance_approval',
                'indexability' => 'not_public',
                'public_eligible' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'llms_full_eligible' => false,
            ];
        }

        $rows = [...$rows, ...$extraRows];
        $path = storage_path('framework/testing/release-public-content-public-resolution-ledger.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode([
            'public_resolution' => [
                'ledger_kind' => 'career_public_resolution_ledger',
                'ledger_version' => 'career.public_resolution_ledger.2786.v1',
                'rows' => $rows,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
