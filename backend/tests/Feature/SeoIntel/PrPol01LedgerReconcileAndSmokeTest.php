<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PrPol01LedgerReconcileAndSmokeTest extends TestCase
{
    #[Test]
    public function generated_artifact_records_runtime_and_safety_boundaries(): void
    {
        $payload = $this->artifact();

        $this->assertSame('pr_pol_01_ledger_reconcile_and_smoke.v1', $payload['schema_version'] ?? null);
        $this->assertSame('PR-POL-01-LEDGER-RECONCILE-AND-SMOKE', $payload['task'] ?? null);
        $this->assertSame('pr_pol_01_ledger_reconcile_and_smoke_completed_stable', $payload['final_decision'] ?? null);
        $this->assertSame(['policies'], $payload['target_pages'] ?? null);
        $this->assertSame(['en', 'zh-CN'], $payload['locales'] ?? null);

        foreach (['en', 'zh-CN'] as $locale) {
            $this->assertSame('published', data_get($payload, "cms_record_state.$locale.status"));
            $this->assertTrue((bool) data_get($payload, "cms_record_state.$locale.is_public"));
            $this->assertTrue((bool) data_get($payload, "cms_record_state.$locale.is_indexable"));
            $this->assertSame(200, data_get($payload, "public_runtime_check.$locale.http_status"));
            $this->assertSame('index, follow', data_get($payload, "public_runtime_check.$locale.robots"));
            $this->assertFalse((bool) data_get($payload, "public_runtime_check.$locale.noindex_detected"));
            $this->assertFalse((bool) data_get($payload, "public_runtime_check.$locale.staging_canonical_detected"));
            $this->assertFalse((bool) data_get($payload, "public_runtime_check.$locale.frontend_fallback_marker_detected"));
        }

        $policiesQueueItems = data_get($payload, 'search_channel_check.policies_queue_items');
        $this->assertSame(0, $policiesQueueItems['https://fermatmind.com/en/policies'] ?? null);
        $this->assertSame(0, $policiesQueueItems['https://fermatmind.com/zh/policies'] ?? null);
        $this->assertSame('submitted', data_get($payload, 'search_channel_check.queue_item_2_state.execution_state'));
        $this->assertSame('submitted', data_get($payload, 'search_channel_check.queue_item_3_state.execution_state'));

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

        $this->assertSame('PR-HIRING-01-POST-PUBLISH-SMOKE', $payload['next_task'] ?? null);
    }

    #[Test]
    public function report_contains_required_sections(): void
    {
        $reportPath = base_path('docs/seo/pr-pol-01-ledger-reconcile-and-smoke.md');

        $this->assertFileExists($reportPath);

        $report = (string) file_get_contents($reportPath);

        foreach ([
            '## 1. Executive Summary',
            '## 2. PR / Ledger Reconciliation',
            '## 3. CMS Published State',
            '## 4. API Runtime Check',
            '## 5. Public Runtime Check',
            '## 6. Sitemap / llms / Footer Exposure',
            '## 7. Search Channel Safety',
            '## 8. Sidecar Issues',
            '## 9. Validation',
            '## 10. What Was Not Done',
            '## 11. Final Decision',
            '## 12. Next Task',
        ] as $heading) {
            $this->assertStringContainsString($heading, $report);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/pr-pol-01-ledger-reconcile-and-smoke.v1.json');

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
