<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\IndexState;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerValidateCanonicalBatchLiveAcceptanceCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_accepts_a_fully_aligned_batch_and_reports_read_only_json(): void
    {
        $slugs = array_map(static fn (int $index): string => 'batch-occupation-'.$index, range(1, 29));
        $this->seedOccupationAuthority($slugs);
        $projectionPath = $this->writeProjection($slugs, ['en', 'zh']);
        $this->fakeLiveHtml();

        $exitCode = Artisan::call('career:validate-canonical-batch-live-acceptance', [
            '--batch-id' => 'batch_001_canonical_29',
            '--slugs' => implode(',', $slugs),
            '--locales' => 'en,zh',
            '--projection' => $projectionPath,
            '--base-url' => 'https://example.test',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('pass', $payload['status']);
        $this->assertTrue($payload['accepted']);
        $this->assertTrue($payload['read_only']);
        $this->assertFalse($payload['writes_database']);
        $this->assertSame(58, $payload['expected_rows']);
        $this->assertSame(29, $payload['occupation_authority']['expected']);
        $this->assertSame(29, $payload['occupation_authority']['found']);
        $this->assertSame(58, $payload['projection_truth']['found_published']);
        $this->assertSame(58, $payload['release_gate']['pass']);
        $this->assertSame(0, $payload['release_gate']['blocked']);
        $this->assertSame('pass', $payload['surfaces']['surface_equality']);
        $this->assertSame(0, $payload['surfaces']['mismatch_count']);
        $this->assertSame(0, $payload['surfaces']['unexpected_exposure']);
        $this->assertSame([], $payload['failures']);
    }

    #[Test]
    public function it_blocks_acceptance_when_live_html_cannot_be_verified(): void
    {
        $slugs = ['batch-occupation-1'];
        $this->seedOccupationAuthority($slugs);
        $projectionPath = $this->writeProjection($slugs, ['en', 'zh']);

        $exitCode = Artisan::call('career:validate-canonical-batch-live-acceptance', [
            '--batch-id' => 'batch_001_canonical_1',
            '--slugs' => implode(',', $slugs),
            '--locales' => 'en,zh',
            '--projection' => $projectionPath,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['accepted']);
        $this->assertSame('validator_context_missing', $payload['surfaces']['unverified_surfaces'][0]['type']);
        $this->assertSame('live_html', $payload['surfaces']['unverified_surfaces'][0]['surface']);
    }

    #[Test]
    public function it_reports_real_surface_mismatch_separately_from_context_gaps(): void
    {
        $slugs = ['batch-occupation-1'];
        $this->seedOccupationAuthority($slugs);
        $projectionPath = $this->writeProjection($slugs, ['en']);
        Http::fake([
            '*' => Http::response('<!doctype html><html><head>'
                .'<link rel="canonical" href="https://example.test/career/jobs/batch-occupation-1">'
                .'<meta name="robots" content="noindex,follow">'
                .'</head><body>No CTA</body></html>', 200),
        ]);

        $exitCode = Artisan::call('career:validate-canonical-batch-live-acceptance', [
            '--batch-id' => 'batch_001_canonical_1',
            '--slugs' => implode(',', $slugs),
            '--locales' => 'en',
            '--projection' => $projectionPath,
            '--base-url' => 'https://example.test',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $payload['status']);
        $this->assertFalse($payload['accepted']);
        $this->assertSame('real_surface_mismatch', $payload['failures'][0]['type']);
        $reasons = collect($payload['surfaces']['real_surface_mismatches'])->pluck('reason')->all();
        $this->assertContains('canonical_not_self', $reasons);
        $this->assertContains('noindex_present', $reasons);
        $this->assertContains('cta_missing_or_unattributed', $reasons);
    }

    #[Test]
    public function it_rejects_missing_latest_index_state_without_using_a_global_fallback(): void
    {
        $slugs = ['batch-occupation-1'];
        $this->seedOccupationAuthority($slugs, withIndexState: false);
        $projectionPath = $this->writeProjection($slugs, ['en']);
        $this->fakeLiveHtml();

        $exitCode = Artisan::call('career:validate-canonical-batch-live-acceptance', [
            '--batch-id' => 'batch_001_canonical_1',
            '--slugs' => implode(',', $slugs),
            '--locales' => 'en',
            '--projection' => $projectionPath,
            '--base-url' => 'https://example.test',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['accepted']);
        $this->assertSame(['batch-occupation-1'], $payload['index_state_authority']['missing_latest_index_state']);
    }

    /**
     * @param  list<string>  $slugs
     */
    private function seedOccupationAuthority(array $slugs, bool $withIndexState = true): void
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'batch-family',
            'title_en' => 'Batch Family',
            'title_zh' => '批次职业族',
        ]);

        foreach ($slugs as $slug) {
            $occupation = Occupation::query()->create([
                'family_id' => $family->id,
                'canonical_slug' => $slug,
                'entity_level' => 'market_child',
                'truth_market' => 'US',
                'display_market' => 'zh-CN',
                'crosswalk_mode' => 'exact',
                'canonical_title_en' => str($slug)->replace('-', ' ')->title()->toString(),
                'canonical_title_zh' => '批次职业',
                'search_h1_zh' => '批次职业',
                'structural_stability' => null,
                'task_prototype_signature' => [],
                'market_semantics_gap' => null,
                'regulatory_divergence' => null,
                'toolchain_divergence' => null,
                'skill_gap_threshold' => null,
                'trust_inheritance_scope' => [],
            ]);

            if ($withIndexState) {
                IndexState::query()->create([
                    'occupation_id' => $occupation->id,
                    'index_state' => 'indexable',
                    'index_eligible' => true,
                    'canonical_path' => '/career/jobs/'.$slug,
                    'canonical_target' => '/career/jobs/'.$slug,
                    'reason_codes' => [],
                    'changed_at' => now(),
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     */
    private function writeProjection(array $slugs, array $locales): string
    {
        $items = [];
        foreach ($slugs as $slug) {
            foreach ($locales as $locale) {
                $items[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'public_resolution_type' => 'public_canonical_job',
                    'runtime_publish_state' => 'published',
                    'detail_route_enabled' => true,
                    'dataset_visible' => true,
                    'search_visible' => true,
                    'sitemap_live' => true,
                    'llms_live' => true,
                    'llms_full_live' => true,
                    'canonical_url' => 'https://fermatmind.com/'.$locale.'/career/jobs/'.$slug,
                    'canonical_self' => true,
                    'robots_indexable' => true,
                    'release_gate_pass' => true,
                    'blockers' => [],
                ];
            }
        }

        $path = storage_path('framework/testing/career-batch-live-acceptance-projection.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode([
            'projection_kind' => 'career_runtime_publish_projection',
            'projection_version' => 'career.runtime_publish_projection.v1',
            'items' => $items,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function fakeLiveHtml(): void
    {
        Http::fake([
            '*' => function ($request) {
                $path = parse_url((string) $request->url(), PHP_URL_PATH);
                $segments = explode('/', trim((string) $path, '/'));
                $locale = (string) ($segments[0] ?? 'en');
                $slug = (string) ($segments[3] ?? 'unknown');

                return Http::response('<!doctype html><html><head>'
                    .'<link rel="canonical" href="https://example.test/'.$locale.'/career/jobs/'.$slug.'">'
                    .'<meta name="robots" content="index,follow">'
                    .'</head><body>'
                    .'<a href="/'.$locale.'/tests/holland-career-interest-test-riasec?target_action=start_riasec_test&entry_surface=career_job_detail&subject_key='.$slug.'">Start</a>'
                    .'</body></html>', 200);
            },
        ]);
    }
}
