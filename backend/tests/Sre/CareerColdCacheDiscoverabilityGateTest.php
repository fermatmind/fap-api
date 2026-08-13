<?php

declare(strict_types=1);

namespace Tests\Sre;

use FermatMind\Deploy\CareerColdCacheDiscoverabilityFailure;
use FermatMind\Deploy\CareerColdCacheDiscoverabilityValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/scripts/deploy/verify_career_cold_cache_discoverability.php';

final class CareerColdCacheDiscoverabilityGateTest extends TestCase
{
    #[Test]
    public function deploy_runner_reads_the_root_active_generation_instead_of_latest_legacy_mtime(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/deploy/verify_career_cold_cache_discoverability.php');

        self::assertStringContainsString("CareerGenerationAuthorityLoader')->loadStrict()", $source);
        self::assertStringContainsString("['pointer']['artifacts']['projection']['sha256']", $source);
        self::assertStringNotContainsString("career_runtime_publish_projection';", $source);
        self::assertStringNotContainsString('filemtime(', $source);
    }

    #[Test]
    public function missing_projection_artifact_fails_closed(): void
    {
        $this->expectFailureCode('AUTHORITY_ARTIFACT_MISSING');

        CareerColdCacheDiscoverabilityValidator::authoritySnapshot(null, str_repeat('a', 64));
    }

    #[Test]
    public function product_visible_candidate_requires_discoverability_to_remain_held_until_release(): void
    {
        $projection = $this->projection();
        $projection['items'][0]['sitemap_live'] = true;

        $this->expectFailureCode('AUTHORITY_PUBLISHED_SURFACE_FLAGS_MISALIGNED');

        CareerColdCacheDiscoverabilityValidator::authoritySnapshot($projection, str_repeat('a', 64));
    }

    #[Test]
    public function cold_detail_cache_blocks_before_directory_or_sitemap_activation(): void
    {
        $snapshot = $this->completePreSitemapSnapshot();
        $snapshot['coverage'] = $this->coverage(4, 0, 'incomplete');

        $this->expectFailureCode('DETAIL_CACHE_COVERAGE_INCOMPLETE');

        CareerColdCacheDiscoverabilityValidator::validate('pre_sitemap', $snapshot);
    }

    #[Test]
    public function stale_directory_after_complete_detail_repair_fails_closed(): void
    {
        $snapshot = $this->completePreSitemapSnapshot();
        $snapshot['directory_en'] = CareerColdCacheDiscoverabilityValidator::directorySnapshot([
            'items' => [],
        ], 'en');

        $this->expectFailureCode('DIRECTORY_AUTHORITY_MISMATCH');

        CareerColdCacheDiscoverabilityValidator::validate('pre_sitemap', $snapshot);
    }

    #[Test]
    public function sitemap_career_shrink_fails_even_when_pre_sitemap_surfaces_match(): void
    {
        $snapshot = $this->completePreSitemapSnapshot();
        $snapshot['sitemap'] = CareerColdCacheDiscoverabilityValidator::sitemapSnapshot([
            'ok' => true,
            'source' => 'backend_sitemap_generator',
            'items' => [
                ['loc' => 'https://fermatmind.com/en/career/jobs/actuaries'],
                ['loc' => 'https://fermatmind.com/zh/career/jobs/actuaries'],
            ],
        ]);

        $this->expectFailureCode('SITEMAP_DISCOVERABILITY_MISMATCH');

        CareerColdCacheDiscoverabilityValidator::validate('post_sitemap', $snapshot);
    }

    #[Test]
    public function aligned_dynamic_cohort_passes_without_a_hardcoded_slug_or_total_url_count(): void
    {
        $snapshot = $this->completePreSitemapSnapshot();
        $snapshot['sitemap'] = CareerColdCacheDiscoverabilityValidator::sitemapSnapshot([
            'ok' => true,
            'source' => 'backend_sitemap_generator',
            'count' => 7,
            'items' => [
                ['loc' => 'https://fermatmind.com/en/career/jobs/accountants-and-auditors'],
                ['loc' => 'https://fermatmind.com/en/career/jobs/actuaries'],
                ['loc' => 'https://fermatmind.com/zh/career/jobs/accountants-and-auditors'],
                ['loc' => 'https://fermatmind.com/zh/career/jobs/actuaries'],
                ['loc' => 'https://fermatmind.com/en/tests'],
                ['loc' => 'https://fermatmind.com/zh/tests'],
                ['loc' => 'https://fermatmind.com/'],
            ],
        ]);

        $result = CareerColdCacheDiscoverabilityValidator::validate('post_sitemap', $snapshot);

        self::assertSame('pass', $result['status']);
        self::assertSame(2, $result['cohort']['slug_count']);
        self::assertSame(4, $result['cohort']['locale_row_count']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['cohort']['slug_set_sha256']);
    }

    #[Test]
    public function held_discoverability_permit_accepts_an_authoritative_sitemap_without_career_rows(): void
    {
        $snapshot = $this->completePreSitemapSnapshot(false);
        $snapshot['sitemap'] = CareerColdCacheDiscoverabilityValidator::sitemapSnapshot([
            'ok' => true,
            'source' => 'backend_sitemap_generator',
            'items' => [
                ['loc' => 'https://fermatmind.com/en/tests'],
                ['loc' => 'https://fermatmind.com/zh/tests'],
            ],
        ]);

        $result = CareerColdCacheDiscoverabilityValidator::validate('post_sitemap', $snapshot);

        self::assertSame('pass', $result['status']);
        self::assertSame(2, $result['cohort']['slug_count']);
        self::assertSame(4, $result['cohort']['locale_row_count']);
    }

    #[Test]
    public function held_discoverability_permit_rejects_an_unreleased_career_url(): void
    {
        $snapshot = $this->completePreSitemapSnapshot(false);
        $snapshot['sitemap'] = CareerColdCacheDiscoverabilityValidator::sitemapSnapshot([
            'ok' => true,
            'source' => 'backend_sitemap_generator',
            'items' => [
                ['loc' => 'https://fermatmind.com/en/career/jobs/actuaries'],
                ['loc' => 'https://fermatmind.com/zh/career/jobs/actuaries'],
            ],
        ]);

        $this->expectFailureCode('SITEMAP_DISCOVERABILITY_MISMATCH');

        CareerColdCacheDiscoverabilityValidator::validate('post_sitemap', $snapshot);
    }

    /** @return array<string, mixed> */
    private function completePreSitemapSnapshot(bool $discoverabilityReleased = true): array
    {
        $projection = $this->projection();
        $authority = CareerColdCacheDiscoverabilityValidator::authoritySnapshot(
            $projection,
            str_repeat('b', 64),
        );
        $runtimeItems = [];
        foreach ($projection['items'] as $item) {
            $locale = $item['locale'] === 'zh' ? 'zh-CN' : 'en';
            $runtimeItems[$item['slug'].'|'.$locale] = $item;
        }

        $jobItems = array_map(static fn (string $slug): array => [
            'identity' => ['canonical_slug' => $slug],
        ], $this->slugs());
        $directoryItems = array_map(static fn (string $slug): array => [
            'slug' => $slug,
            'indexable' => true,
            'detail_ready' => true,
        ], $this->slugs());

        return [
            'authority_artifact_sha256' => str_repeat('b', 64),
            'authority' => $authority,
            'runtime' => CareerColdCacheDiscoverabilityValidator::runtimeSnapshot($runtimeItems),
            'discoverability' => CareerColdCacheDiscoverabilityValidator::discoverabilitySnapshot(
                $runtimeItems,
                static fn (string $slug, string $locale): bool => $discoverabilityReleased,
            ),
            'coverage' => $this->coverage(4, 4, 'ready'),
            'jobs_en' => CareerColdCacheDiscoverabilityValidator::jobIndexSnapshot(['items' => $jobItems], 'en'),
            'jobs_zh-CN' => CareerColdCacheDiscoverabilityValidator::jobIndexSnapshot(['items' => $jobItems], 'zh-CN'),
            'directory_en' => CareerColdCacheDiscoverabilityValidator::directorySnapshot(['items' => $directoryItems], 'en'),
            'directory_zh-CN' => CareerColdCacheDiscoverabilityValidator::directorySnapshot(['items' => $directoryItems], 'zh-CN'),
        ];
    }

    /** @return array<string, mixed> */
    private function projection(): array
    {
        $items = [];
        foreach ($this->slugs() as $slug) {
            foreach (['en', 'zh'] as $locale) {
                $items[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'runtime_publish_state' => 'published',
                    'detail_route_enabled' => true,
                    'robots_indexable' => true,
                    'release_gate_pass' => true,
                    'dataset_visible' => true,
                    'search_visible' => true,
                    'sitemap_live' => false,
                    'llms_live' => false,
                ];
            }
        }

        return [
            'projection_kind' => 'career_runtime_publish_projection',
            'projection_version' => 'career.runtime_publish_projection.v1',
            'items' => $items,
        ];
    }

    /** @return list<string> */
    private function slugs(): array
    {
        return ['accountants-and-auditors', 'actuaries'];
    }

    /** @return array<string, int|string> */
    private function coverage(int $expected, int $covered, string $status): array
    {
        return [
            'status' => $status,
            'expected_target_count' => $expected,
            'eligible_target_count' => $expected,
            'covered_target_count' => $covered,
            'excluded_count' => 0,
        ];
    }

    private function expectFailureCode(string $safeCode): void
    {
        $this->expectException(CareerColdCacheDiscoverabilityFailure::class);
        $this->expectExceptionMessage($safeCode);
    }
}
