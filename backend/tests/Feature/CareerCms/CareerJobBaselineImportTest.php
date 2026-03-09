<?php

declare(strict_types=1);

namespace Tests\Feature\CareerCms;

use App\Models\CareerJob;
use App\Models\CareerJobRevision;
use App\Models\CareerJobSection;
use App\Models\CareerJobSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerJobBaselineImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_write_database(): void
    {
        $this->artisan('career-jobs:import-local-baseline', [
            '--dry-run' => true,
            '--source-dir' => 'tests/Fixtures/career_job_baseline',
        ])
            ->expectsOutputToContain('files_found=2')
            ->expectsOutputToContain('jobs_found=4')
            ->expectsOutputToContain('will_create=4')
            ->expectsOutputToContain('revisions_to_create=4')
            ->assertExitCode(0);

        $this->assertSame(0, CareerJob::query()->count());
        $this->assertSame(0, CareerJobSection::query()->count());
        $this->assertSame(0, CareerJobSeoMeta::query()->count());
        $this->assertSame(0, CareerJobRevision::query()->count());
    }

    public function test_default_mode_creates_missing_jobs_sections_seo_meta_and_revision(): void
    {
        $this->artisan('career-jobs:import-local-baseline', [
            '--locale' => ['en'],
            '--status' => 'draft',
            '--source-dir' => 'tests/Fixtures/career_job_baseline',
        ])
            ->expectsOutputToContain('jobs_found=2')
            ->expectsOutputToContain('will_create=2')
            ->assertExitCode(0);

        $this->assertSame(2, CareerJob::query()->count());
        $this->assertSame(1, CareerJobSection::query()->count());
        $this->assertSame(2, CareerJobSeoMeta::query()->count());
        $this->assertSame(2, CareerJobRevision::query()->count());

        $job = CareerJob::query()
            ->withoutGlobalScopes()
            ->where('job_code', 'product-manager')
            ->where('locale', 'en')
            ->firstOrFail();

        $this->assertSame(0, (int) $job->org_id);
        $this->assertSame('draft', $job->status);
        $this->assertNull($job->published_at);
        $this->assertNull($job->fit_personality_codes_json);
        $this->assertSame(['raw' => 82], $job->market_demand_json);
        $this->assertSame(
            ['raw' => ['openness' => ['min' => 40, 'max' => 80], 'conscientiousness' => ['min' => 50, 'max' => 90]]],
            $job->big5_targets_json,
        );
        $this->assertSame(
            ['iq_range' => ['min' => 50, 'max' => 98], 'eq_range' => ['min' => 55, 'max' => 100]],
            $job->iq_eq_notes_json,
        );

        $revision = CareerJobRevision::query()
            ->where('job_id', (int) $job->id)
            ->orderBy('revision_no')
            ->firstOrFail();

        $this->assertSame(1, (int) $revision->revision_no);
        $this->assertSame('baseline import', $revision->note);
    }

    public function test_default_mode_skips_existing_jobs_without_overwriting(): void
    {
        $job = CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'locale' => 'en',
            'title' => 'Do Not Overwrite',
            'status' => CareerJob::STATUS_DRAFT,
            'is_public' => true,
            'is_indexable' => true,
            'schema_version' => 'v1',
        ]);

        CareerJobRevision::query()->create([
            'job_id' => (int) $job->id,
            'revision_no' => 1,
            'snapshot_json' => ['title' => 'Do Not Overwrite'],
            'note' => 'seed',
            'created_at' => now(),
        ]);

        $this->artisan('career-jobs:import-local-baseline', [
            '--locale' => ['en'],
            '--job' => ['product-manager'],
            '--source-dir' => 'tests/Fixtures/career_job_baseline',
        ])
            ->expectsOutputToContain('jobs_found=1')
            ->expectsOutputToContain('will_skip=1')
            ->assertExitCode(0);

        $this->assertSame('Do Not Overwrite', (string) $job->fresh()->title);
        $this->assertSame(1, CareerJobRevision::query()->where('job_id', (int) $job->id)->count());
    }

    public function test_upsert_updates_existing_jobs_and_only_creates_revision_when_changed(): void
    {
        $job = CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'locale' => 'en',
            'title' => 'Legacy PM',
            'excerpt' => 'Legacy excerpt',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now()->subDay(),
            'schema_version' => 'v1',
            'salary_json' => ['raw' => '$1'],
            'mbti_primary_codes_json' => ['INTJ'],
            'mbti_secondary_codes_json' => ['INFJ'],
            'riasec_profile_json' => ['R' => 1, 'I' => 2, 'A' => 3, 'S' => 4, 'E' => 5, 'C' => 6],
        ]);

        CareerJobSection::query()->create([
            'job_id' => (int) $job->id,
            'section_key' => 'faq',
            'title' => 'Legacy FAQ',
            'render_variant' => 'faq',
            'payload_json' => ['items' => [['question' => 'Old', 'answer' => 'Old']]],
            'sort_order' => 80,
            'is_enabled' => true,
        ]);
        CareerJobSection::query()->create([
            'job_id' => (int) $job->id,
            'section_key' => 'day_to_day',
            'title' => 'Legacy',
            'render_variant' => 'rich_text',
            'body_md' => 'Old',
            'sort_order' => 10,
            'is_enabled' => true,
        ]);
        CareerJobSeoMeta::query()->create([
            'job_id' => (int) $job->id,
            'seo_title' => 'Legacy title',
            'seo_description' => 'Legacy description',
        ]);
        CareerJobRevision::query()->create([
            'job_id' => (int) $job->id,
            'revision_no' => 1,
            'snapshot_json' => ['title' => 'Legacy PM'],
            'note' => 'seed',
            'created_at' => now()->subDay(),
        ]);

        $this->artisan('career-jobs:import-local-baseline', [
            '--locale' => ['en'],
            '--job' => ['product-manager'],
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => 'tests/Fixtures/career_job_baseline',
        ])
            ->expectsOutputToContain('jobs_found=1')
            ->expectsOutputToContain('will_update=1')
            ->expectsOutputToContain('revisions_to_create=1')
            ->assertExitCode(0);

        $job->refresh();

        $this->assertSame('Product Manager', $job->title);
        $this->assertTrue((bool) $job->is_indexable);
        $this->assertNotNull($job->published_at);
        $this->assertSame(
            ['faq'],
            CareerJobSection::query()
                ->where('job_id', (int) $job->id)
                ->orderBy('section_key')
                ->pluck('section_key')
                ->all(),
        );
        $this->assertSame(
            'Product Manager Career Guide | FermatMind',
            CareerJobSeoMeta::query()->where('job_id', (int) $job->id)->firstOrFail()->seo_title,
        );
        $this->assertSame(
            2,
            CareerJobRevision::query()->where('job_id', (int) $job->id)->max('revision_no'),
        );

        $this->artisan('career-jobs:import-local-baseline', [
            '--locale' => ['en'],
            '--job' => ['product-manager'],
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => 'tests/Fixtures/career_job_baseline',
        ])
            ->expectsOutputToContain('will_skip=1')
            ->assertExitCode(0);

        $this->assertSame(
            2,
            CareerJobRevision::query()->where('job_id', (int) $job->id)->count(),
        );
    }

    public function test_locale_and_job_filters_limit_import_scope(): void
    {
        $this->artisan('career-jobs:import-local-baseline', [
            '--locale' => ['zh-CN'],
            '--job' => ['product-manager'],
            '--status' => 'draft',
            '--source-dir' => 'tests/Fixtures/career_job_baseline',
        ])
            ->expectsOutputToContain('jobs_found=1')
            ->expectsOutputToContain('will_create=1')
            ->assertExitCode(0);

        $this->assertSame(1, CareerJob::query()->count());

        $job = CareerJob::query()->firstOrFail();
        $this->assertSame('product-manager', $job->job_code);
        $this->assertSame('zh-CN', $job->locale);
    }

    public function test_invalid_baseline_fails_with_non_zero_exit_code(): void
    {
        $sourceDir = base_path('tests/Fixtures/career_job_baseline_invalid');

        File::deleteDirectory($sourceDir);
        File::ensureDirectoryExists($sourceDir);
        File::put($sourceDir.'/career_jobs.en.json', json_encode([
            'meta' => [
                'schema_version' => 'v1',
                'locale' => 'en',
                'source' => 'test fixture',
                'generated_at' => '2026-03-09T00:00:00Z',
            ],
            'jobs' => [
                [
                    'job_code' => 'bad-job',
                    'slug' => 'bad-job',
                    'locale' => 'en',
                    'title' => 'Bad Job',
                    'excerpt' => 'Bad',
                    'body_md' => '# Bad',
                    'body_html' => null,
                    'salary_json' => ['raw' => '$1'],
                    'outlook_json' => ['summary' => 'Bad'],
                    'skills_json' => ['core' => ['Bad'], 'supporting' => []],
                    'work_contents_json' => ['items' => ['Bad']],
                    'growth_path_json' => ['raw' => ['Bad']],
                    'fit_personality_codes_json' => null,
                    'mbti_primary_codes_json' => ['BAD'],
                    'mbti_secondary_codes_json' => ['ENFP'],
                    'riasec_profile_json' => ['R' => 1, 'I' => 2, 'A' => 3, 'S' => 4, 'E' => 5, 'C' => 6],
                    'big5_targets_json' => ['raw' => ['openness' => ['min' => 40, 'max' => 80]]],
                    'iq_eq_notes_json' => ['iq_range' => ['min' => 1, 'max' => 2]],
                    'market_demand_json' => ['raw' => 1],
                    'status' => 'published',
                    'is_public' => true,
                    'is_indexable' => true,
                    'published_at' => null,
                    'scheduled_at' => null,
                    'sort_order' => 0,
                    'sections' => [],
                    'seo_meta' => [],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        try {
            $this->artisan('career-jobs:import-local-baseline', [
                '--source-dir' => 'tests/Fixtures/career_job_baseline_invalid',
            ])
                ->expectsOutputToContain('invalid MBTI code')
                ->assertExitCode(1);
        } finally {
            File::deleteDirectory($sourceDir);
        }
    }
}
