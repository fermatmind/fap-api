<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PrHiring01PostPublishSmokeTest extends TestCase
{
    #[Test]
    public function generated_artifact_records_careers_runtime_and_role_boundaries(): void
    {
        $payload = $this->artifact();

        $this->assertSame('pr_hiring_01_post_publish_smoke.v1', $payload['schema_version'] ?? null);
        $this->assertSame('PR-HIRING-01-POST-PUBLISH-SMOKE', $payload['task'] ?? null);
        $this->assertSame('pr_hiring_01_post_publish_smoke_completed_stable', $payload['final_decision'] ?? null);
        $this->assertSame(['careers'], $payload['target_pages'] ?? null);
        $this->assertSame(['en', 'zh-CN'], $payload['locales'] ?? null);
        $this->assertCount(3, $payload['role_draft_keys'] ?? []);

        foreach (['en', 'zh-CN'] as $locale) {
            $this->assertSame('published', data_get($payload, "cms_record_state.$locale.status"));
            $this->assertTrue((bool) data_get($payload, "cms_record_state.$locale.is_public"));
            $this->assertTrue((bool) data_get($payload, "cms_record_state.$locale.is_indexable"));
            $this->assertSame(200, data_get($payload, "public_runtime_check.$locale.http_status"));
            $this->assertSame('index, follow', data_get($payload, "public_runtime_check.$locale.robots"));
            $this->assertFalse((bool) data_get($payload, "public_runtime_check.$locale.staging_canonical_detected"));
        }

        foreach ($payload['role_draft_runtime_check'] ?? [] as $role) {
            $this->assertSame(404, $role['http_status'] ?? null);
            $this->assertFalse((bool) ($role['runtime_exposed'] ?? true));
        }

        $this->assertSame(0, data_get($payload, 'discoverability_check.sitemap_xml.role_draft_hits'));
        $this->assertSame(0, data_get($payload, 'discoverability_check.llms_txt.role_draft_hits'));
        $this->assertSame(0, data_get($payload, 'discoverability_check.llms_full_txt.role_draft_hits'));
        $this->assertSame(0, data_get($payload, 'discoverability_check.footer_nav.role_draft_hits'));
        $this->assertFalse((bool) data_get($payload, 'claim_boundary_check.role_package_runtime_exposed'));
        $this->assertSame([], data_get($payload, 'claim_boundary_check.forbidden_claim_hits'));

        foreach ([
            'no_cms_mutation',
            'no_publish',
            'no_deploy',
            'no_search_channel_action',
            'no_url_submission',
            'no_external_search_api_call',
            'no_env_dns_nginx_edit',
            'no_raw_log_read',
            'no_fap_web_mutation',
        ] as $flag) {
            $this->assertTrue((bool) ($payload[$flag] ?? false), $flag);
        }
    }

    #[Test]
    public function report_contains_required_sections(): void
    {
        $reportPath = base_path('docs/seo/pr-hiring-01-post-publish-smoke.md');

        $this->assertFileExists($reportPath);

        $report = (string) file_get_contents($reportPath);

        foreach ([
            '## 1. Executive Summary',
            '## 2. Source PR State',
            '## 3. CMS Published State',
            '## 4. API Runtime Check',
            '## 5. Public Runtime Check',
            '## 6. Role Draft Exposure Check',
            '## 7. Sitemap / llms / Footer Exposure',
            '## 8. Search Channel Safety',
            '## 9. Claim Boundary',
            '## 10. Sidecar Issues',
            '## 11. Validation',
            '## 12. What Was Not Done',
            '## 13. Final Decision',
            '## 14. Next Task',
        ] as $heading) {
            $this->assertStringContainsString($heading, $report);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/pr-hiring-01-post-publish-smoke.v1.json');

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
