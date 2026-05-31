<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Services\SeoIntel\OpsDashboard\CareerRuntimeReadModelService;
use Tests\TestCase;

final class Career1046OpsReadModelRepair01Test extends TestCase
{
    public function test_runtime_read_model_separates_runtime_projection_from_legacy_cms_scope(): void
    {
        $service = new CareerRuntimeReadModelService(
            $this->fakeProjection(1046),
            static fn (): int => 378,
        );

        $payload = $service->read();

        $this->assertSame('career_runtime_projection_ops_read_model', $payload['read_model_kind'] ?? null);
        $this->assertSame('career_runtime_publish_projection', $payload['source_of_truth'] ?? null);
        $this->assertSame('legacy_cms_career_jobs_table_scope', $payload['legacy_cms_scope_label'] ?? null);
        $this->assertSame('runtime_projection_public_career_detail_scope', $payload['runtime_scope_label'] ?? null);
        $this->assertSame(378, $payload['legacy_cms_career_jobs_count'] ?? null);
        $this->assertSame(1046, $payload['runtime_public_career_slug_count'] ?? null);
        $this->assertSame(2092, $payload['localized_public_career_url_count'] ?? null);
        $this->assertSame(2092, $payload['sitemap_career_url_count_expected'] ?? null);
        $this->assertSame(2092, $payload['llms_career_url_count_expected'] ?? null);

        foreach (CareerRuntimeReadModelService::EXCLUDED_SLUGS as $slug) {
            $this->assertTrue((bool) data_get($payload, "excluded_slugs_absent.{$slug}"), $slug);
        }

        $this->assertFalse((bool) ($payload['search_channel_action_performed'] ?? true));
        $this->assertFalse((bool) ($payload['url_submission_performed'] ?? true));
        $this->assertFalse((bool) ($payload['production_write_performed'] ?? true));
        $this->assertFalse((bool) ($payload['public_runtime_mutation_performed'] ?? true));
    }

    public function test_generated_report_and_artifact_record_expected_boundaries(): void
    {
        $reportPath = base_path('docs/seo/career-1046-ops-read-model-repair-01.md');
        $artifactPath = base_path('docs/seo/generated/career-1046-ops-read-model-repair-01.v1.json');

        $this->assertFileExists($reportPath);
        $this->assertFileExists($artifactPath);

        $report = (string) file_get_contents($reportPath);
        $artifact = json_decode((string) file_get_contents($artifactPath), true, 512, JSON_THROW_ON_ERROR);

        foreach ([
            '## 1. Executive Summary',
            '## 2. Implementation',
            '## 3. Runtime vs CMS Scope',
            '## 4. SEO/Ops Read Model',
            '## 5. Safety Boundaries',
            '## 6. Validation',
            '## 7. Final Decision',
            '## 8. Next Task',
        ] as $heading) {
            $this->assertStringContainsString($heading, $report);
        }

        $this->assertSame('career_1046_ops_read_model_repair.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('CAREER-1046-OPS-READ-MODEL-REPAIR-01', $artifact['task'] ?? null);
        $this->assertSame('career_1046_ops_read_model_repair_completed_ready_for_observability_slo', $artifact['final_decision'] ?? null);
        $this->assertSame(1046, $artifact['runtime_public_career_slug_count'] ?? null);
        $this->assertSame(2092, $artifact['localized_public_career_url_count'] ?? null);
        $this->assertSame(378, $artifact['legacy_cms_career_jobs_count'] ?? null);
        $this->assertFalse((bool) ($artifact['search_channel_action_performed'] ?? true));
        $this->assertFalse((bool) ($artifact['url_submission_performed'] ?? true));
        $this->assertFalse((bool) ($artifact['production_write_performed'] ?? true));
        $this->assertFalse((bool) ($artifact['public_runtime_mutation_performed'] ?? true));
        $this->assertSame('CAREER-1046-OBSERVABILITY-SLO-01', $artifact['next_task'] ?? null);
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
