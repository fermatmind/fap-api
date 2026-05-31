<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Services\SeoIntel\OpsDashboard\CareerRuntimeReadModelService;
use Tests\TestCase;

final class Career1046ObservabilitySlo01Test extends TestCase
{
    public function test_generated_slo_artifact_records_required_baselines_and_boundaries(): void
    {
        $artifactPath = base_path('docs/seo/generated/career-1046-observability-slo-01.v1.json');

        $this->assertFileExists($artifactPath);

        $artifact = json_decode((string) file_get_contents($artifactPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('career_1046_observability_slo.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('CAREER-1046-OBSERVABILITY-SLO-01', $artifact['task'] ?? null);
        $this->assertSame('career_1046_observability_slo_completed_ready_for_hiring_content_authority', $artifact['final_decision'] ?? null);
        $this->assertSame(1046, $artifact['api_en_expected_count'] ?? null);
        $this->assertSame(1046, $artifact['api_zh_expected_count'] ?? null);
        $this->assertSame(1046, $artifact['runtime_public_slug_expected_count'] ?? null);
        $this->assertSame(2092, $artifact['localized_public_url_expected_count'] ?? null);
        $this->assertSame(2092, $artifact['sitemap_expected_career_url_count'] ?? null);
        $this->assertSame(2092, $artifact['llms_expected_career_url_count'] ?? null);
        $this->assertSame(2092, $artifact['llms_full_expected_career_url_count'] ?? null);

        $this->assertSame(CareerRuntimeReadModelService::EXCLUDED_SLUGS, $artifact['excluded_slugs'] ?? null);
        $this->assertContains('career_api_public_count_regression', $artifact['p0_conditions'] ?? []);
        $this->assertContains('llms_full_degraded_response_rate_above_budget', $artifact['p1_conditions'] ?? []);
        $this->assertContains('minor_metadata_copy_drift', $artifact['p2_conditions'] ?? []);

        $this->assertTrue((bool) ($artifact['no_production_write'] ?? false));
        $this->assertTrue((bool) ($artifact['no_search_channel_action'] ?? false));
        $this->assertTrue((bool) ($artifact['no_url_submission'] ?? false));
        $this->assertTrue((bool) ($artifact['no_cache_mutation'] ?? false));
        $this->assertTrue((bool) ($artifact['no_deploy'] ?? false));
        $this->assertSame('PR-HIRING-01', $artifact['next_task'] ?? null);
    }

    public function test_slo_baseline_matches_runtime_read_model_fixture(): void
    {
        $service = new CareerRuntimeReadModelService(
            $this->fakeProjection(1046),
            static fn (): int => 378,
        );

        $readModel = $service->read();
        $artifact = json_decode(
            (string) file_get_contents(base_path('docs/seo/generated/career-1046-observability-slo-01.v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame($readModel['runtime_public_career_slug_count'], $artifact['runtime_public_slug_expected_count']);
        $this->assertSame($readModel['localized_public_career_url_count'], $artifact['localized_public_url_expected_count']);
        $this->assertSame($readModel['sitemap_career_url_count_expected'], $artifact['sitemap_expected_career_url_count']);
        $this->assertSame($readModel['llms_career_url_count_expected'], $artifact['llms_expected_career_url_count']);

        foreach (CareerRuntimeReadModelService::EXCLUDED_SLUGS as $slug) {
            $this->assertTrue((bool) data_get($readModel, "excluded_slugs_absent.{$slug}"), $slug);
        }
    }

    public function test_generated_report_has_required_sections(): void
    {
        $reportPath = base_path('docs/seo/career-1046-observability-slo-01.md');

        $this->assertFileExists($reportPath);

        $report = (string) file_get_contents($reportPath);

        foreach ([
            '## 1. Executive Summary',
            '## 2. SLO Baselines',
            '## 3. Priority Conditions',
            '## 4. Observation Cadence',
            '## 5. Safety Boundaries',
            '## 6. Validation',
            '## 7. Final Decision',
            '## 8. Next Task',
        ] as $heading) {
            $this->assertStringContainsString($heading, $report);
        }
    }

    private function fakeProjection(int $count): CareerRuntimePublishProjectionVisibility
    {
        return new class($count) implements CareerRuntimePublishProjectionVisibility
        {
            public function __construct(private readonly int $count) {}

            public function itemForSlug(string $slug, string $locale = 'en'): ?array
            {
                return null;
            }

            public function publicDatasetItems(): array
            {
                return $this->publicDetailItems();
            }

            public function publicDetailItems(): array
            {
                $items = [];

                for ($i = 1; $i <= $this->count; $i++) {
                    $items[] = ['slug' => 'career-slug-'.$i];
                }

                return $items;
            }

            public function datasetVisible(string $slug): bool
            {
                return false;
            }

            public function searchVisible(string $slug): bool
            {
                return false;
            }

            public function detailRouteEnabled(string $slug): bool
            {
                return false;
            }

            public function robotsIndexable(string $slug): bool
            {
                return false;
            }

            public function releaseGatePass(string $slug): bool
            {
                return false;
            }

            public function familyHubLive(string $slug): bool
            {
                return false;
            }
        };
    }
}
