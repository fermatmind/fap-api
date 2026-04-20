<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
