<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CareerJob;
use App\Models\CareerJobSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerReleaseGateNoindexCleanupCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.frontend_url', 'https://example.test');
    }

    #[Test]
    public function it_dry_runs_release_gate_noindex_cleanup_without_writes(): void
    {
        $en = $this->createCareerJob('approved-career', 'en');
        $zh = $this->createCareerJob('approved-career', 'zh-CN');
        $scope = $this->writeScope(['approved-career']);

        $exitCode = Artisan::call('career:release-gate-noindex-cleanup', [
            '--scope' => $scope,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($report['dry_run']);
        $this->assertFalse($report['did_write']);
        $this->assertSame(1, $report['approved_slug_count']);
        $this->assertSame(2, $report['approved_record_count']);
        $this->assertSame(2, $report['target_record_count']);
        $this->assertSame(0, $report['held_rows_updated']);
        $this->assertSame(0, $report['software_developers_updated']);

        $this->assertFalse((bool) $en->fresh()->is_indexable);
        $this->assertFalse((bool) $zh->fresh()->is_indexable);
        $this->assertSame('noindex,follow', $en->seoMeta()->firstOrFail()->robots);
        $this->assertSame('noindex,follow', $zh->seoMeta()->firstOrFail()->robots);
    }

    #[Test]
    public function it_forces_approved_career_jobs_to_indexable_through_existing_seo_owner(): void
    {
        $en = $this->createCareerJob('approved-career', 'en');
        $zh = $this->createCareerJob('approved-career', 'zh-CN');
        $scope = $this->writeScope(['approved-career']);

        $exitCode = Artisan::call('career:release-gate-noindex-cleanup', [
            '--scope' => $scope,
            '--force' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertFalse($report['dry_run']);
        $this->assertTrue($report['did_write']);
        $this->assertSame(2, $report['target_record_count']);

        $en->refresh();
        $zh->refresh();

        $this->assertTrue((bool) $en->is_indexable);
        $this->assertTrue((bool) $zh->is_indexable);
        $this->assertSame('index,follow', $en->seoMeta()->firstOrFail()->robots);
        $this->assertSame('https://example.test/en/career/jobs/approved-career', $en->seoMeta()->firstOrFail()->canonical_url);
        $this->assertSame('index,follow', $zh->seoMeta()->firstOrFail()->robots);
        $this->assertSame('https://example.test/zh/career/jobs/approved-career', $zh->seoMeta()->firstOrFail()->canonical_url);

        Artisan::call('career:release-gate-noindex-cleanup', [
            '--scope' => $scope,
        ]);
        $idempotency = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $idempotency['target_record_count']);
    }

    #[Test]
    public function it_rejects_scopes_that_do_not_prove_held_rows_are_excluded(): void
    {
        $this->createCareerJob('manual-hold-career', 'en');
        $this->createCareerJob('manual-hold-career', 'zh-CN');
        $scope = $this->writeScope(['manual-hold-career'], [
            'safety' => [
                'all_noindex_slugs_are_imported_canonical_assets' => false,
                'unsafe_slugs' => ['manual-hold-career'],
                'held_slug_intersections' => ['manual-hold-career'],
                'software_developers_included' => false,
            ],
            'blockers' => ['noindex scope includes non-imported, held, or forbidden slugs'],
        ]);

        $exitCode = Artisan::call('career:release-gate-noindex-cleanup', [
            '--scope' => $scope,
            '--force' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($report['did_write']);
        $this->assertStringContainsString('Scope safety does not prove', $report['blockers'][0]);
    }

    #[Test]
    public function it_rejects_software_developers_even_when_present_in_a_scope(): void
    {
        $scope = $this->writeScope(['software-developers'], [
            'safety' => [
                'all_noindex_slugs_are_imported_canonical_assets' => true,
                'unsafe_slugs' => [],
                'held_slug_intersections' => [],
                'software_developers_included' => true,
            ],
        ]);

        $exitCode = Artisan::call('career:release-gate-noindex-cleanup', [
            '--scope' => $scope,
            '--force' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($report['did_write']);
        $this->assertSame('Scope includes software-developers.', $report['blockers'][0]);
    }

    private function createCareerJob(string $slug, string $locale): CareerJob
    {
        $job = CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => $slug,
            'slug' => $slug,
            'locale' => $locale,
            'title' => 'Approved Career',
            'excerpt' => 'Approved career excerpt',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => false,
            'schema_version' => 'v1',
            'sort_order' => 0,
            'published_at' => Carbon::now()->subDay(),
        ]);

        CareerJobSeoMeta::query()->create([
            'job_id' => (int) $job->id,
            'seo_title' => 'Approved Career',
            'seo_description' => 'Approved career description',
            'canonical_url' => 'https://example.test/wrong',
            'og_title' => 'Approved Career',
            'og_description' => 'Approved career description',
            'og_image_url' => 'https://example.test/images/career.png',
            'twitter_title' => 'Approved Career',
            'twitter_description' => 'Approved career description',
            'twitter_image_url' => 'https://example.test/images/career.png',
            'robots' => 'noindex,follow',
        ]);

        return $job;
    }

    /**
     * @param  list<string>  $slugs
     * @param  array<string, mixed>  $overrides
     */
    private function writeScope(array $slugs, array $overrides = []): string
    {
        $scope = array_replace_recursive([
            'scope' => 'career_release_gate_noindex_cleanup',
            'imported_canonical_assets' => count($slugs),
            'noindex_blockers' => [
                'count' => count($slugs) * 2,
                'slug_count' => count($slugs),
                'slugs' => $slugs,
                'urls' => [],
                'current_state' => 'noindex',
                'target_state' => 'indexable',
            ],
            'exclusions' => [
                'held_rows' => true,
                'software_developers' => true,
                'manual_holds' => true,
                'duplicate_identity_holds' => true,
                'broad_group_holds' => true,
                'CN_proxy_holds' => true,
            ],
            'safety' => [
                'all_noindex_slugs_are_imported_canonical_assets' => true,
                'unsafe_slugs' => [],
                'held_slug_intersections' => [],
                'software_developers_included' => false,
            ],
            'blockers' => [],
        ], $overrides);

        $path = tempnam(sys_get_temp_dir(), 'career_release_noindex_scope_');
        $this->assertIsString($path);
        file_put_contents($path, json_encode($scope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $path;
    }
}
