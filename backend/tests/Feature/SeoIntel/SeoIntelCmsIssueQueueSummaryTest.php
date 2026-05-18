<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\SeoIssueQueueContract;
use App\Services\SeoIntel\SeoIssueQueueProducer;
use App\Services\SeoIntel\SeoIssueSanitizer;
use App\Services\SeoIntel\SeoIssueSummaryService;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelCmsIssueQueueSummaryTest extends TestCase
{
    #[Test]
    public function issue_queue_config_and_collector_are_disabled_by_default(): void
    {
        $this->assertContains('issue_queue_foundation', config('seo_intel.allowed_collectors'));
        $this->assertFalse((bool) config('seo_intel.enabled'));
        $this->assertFalse((bool) config('seo_intel.write_enabled'));
        $this->assertFalse((bool) config('seo_intel.collectors_enabled'));
        $this->assertTrue((bool) config('seo_intel.dry_run_default'));
        $this->assertFalse((bool) config('seo_intel.allow_external_api_calls'));
        $this->assertFalse((bool) config('seo_intel.issue_queue_enabled'));
        $this->assertFalse((bool) config('seo_intel.issue_summary_api_enabled'));
        $this->assertFalse((bool) config('seo_intel.auto_publish_enabled'));
        $this->assertFalse((bool) config('seo_intel.auto_cms_mutation_enabled'));
        $this->assertFalse((bool) config('seo_intel.auto_pseo_enabled'));
    }

    #[Test]
    public function issue_queue_migration_does_not_include_forbidden_columns(): void
    {
        $paths = glob(base_path('database/migrations/seo_intel/*seo_issue_queue*'));

        $this->assertCount(1, $paths);

        $contents = strtolower((string) file_get_contents($paths[0]));

        foreach ($this->forbiddenColumns() as $column) {
            $this->assertStringNotContainsString("'".$column."'", $contents, 'migration must not define '.$column);
            $this->assertStringNotContainsString('"'.$column.'"', $contents, 'migration must not define '.$column);
        }

        foreach ([
            'issue_uid',
            'issue_type',
            'severity',
            'source_system',
            'source_engine',
            'canonical_url_hash',
            'canonical_url',
            'locale',
            'page_entity_type',
            'entity_id_or_slug',
            'cluster',
            'status',
            'lifecycle_state',
            'detected_at',
            'acknowledged_at',
            'resolved_at',
            'ignored_at',
            'summary',
            'recommendation',
            'evidence_hash',
            'metadata_json',
        ] as $requiredColumn) {
            $this->assertStringContainsString($requiredColumn, $contents);
        }
    }

    #[Test]
    public function issue_contract_lists_allowed_types_severity_and_lifecycle_values(): void
    {
        $contract = new SeoIssueQueueContract;

        foreach ([
            'url_truth_drift',
            'metadata_drift',
            'canonical_drift',
            'robots_drift',
            'jsonld_drift',
            'sitemap_missing',
            'sitemap_extra',
            'llms_missing',
            'llms_extra',
            'private_flow_exposed',
            'noindex_leak',
            'crawler_error',
            'crawler_private_hit',
            'crawler_noindex_hit',
            'gsc_ctr_drop',
            'gsc_position_drop',
            'baidu_push_failed',
            'indexnow_submission_failed',
            'domestic_index_unknown',
            'landing_conversion_drop',
            'revenue_drop',
            'claim_boundary_warning',
            'pii_policy_warning',
            'internal_qa_filter_warning',
        ] as $issueType) {
            $this->assertContains($issueType, $contract->issueTypes());
        }

        $this->assertSame(['info', 'warning', 'high', 'critical'], $contract->severityValues());
        $this->assertSame(['open', 'acknowledged', 'resolved', 'ignored'], $contract->lifecycleValues());
    }

    #[Test]
    public function sanitizer_removes_pii_and_raw_identifiers_from_issue_payloads(): void
    {
        $sanitized = (new SeoIssueSanitizer)->sanitize([
            'issue_type' => 'pii_policy_warning',
            'severity' => 'critical',
            'source_system' => 'fixture',
            'canonical_url' => 'https://fermatmind.com/zh/articles/example?token=secret',
            'summary' => 'Contact qa@example.invalid for order ORDERABC123456 and payment PAYMENTABC123456.',
            'recommendation' => 'Remove token=secret before review.',
            'metadata_json' => [
                'email' => 'qa@example.invalid',
                'order_no' => 'ORDERABC123456',
                'attempt_id' => 'ATTEMPTABC123456',
                'payment_id' => 'PAYMENTABC123456',
                'provider_event_id' => 'evt_123',
                'cookie' => 'secret',
                'raw_ip' => '203.0.113.7',
                'raw_user_agent' => 'Baiduspider/2.0',
                'safe_count' => 1,
            ],
        ]);

        $encoded = json_encode($sanitized, JSON_THROW_ON_ERROR);

        $this->assertSame('https://fermatmind.com/zh/articles/example', $sanitized['canonical_url']);
        $this->assertSame('critical', $sanitized['severity']);
        $this->assertSame(1, $sanitized['metadata_json']['safe_count'] ?? null);

        foreach ([
            'qa@example.invalid',
            'ORDERABC123456',
            'ATTEMPTABC123456',
            'PAYMENTABC123456',
            'evt_123',
            '203.0.113.7',
            'Baiduspider/2.0',
            'token=secret',
        ] as $forbiddenValue) {
            $this->assertStringNotContainsString($forbiddenValue, $encoded);
        }
    }

    #[Test]
    public function producer_and_summary_service_are_sanitized_and_do_not_mutate_cms(): void
    {
        $producer = new SeoIssueQueueProducer;
        $produced = $producer->produce();
        $summary = (new SeoIssueSummaryService)->summarize($produced['issues']);
        $encoded = json_encode([$produced, $summary], JSON_THROW_ON_ERROR);

        $this->assertGreaterThanOrEqual(3, $produced['metadata']['issue_count'] ?? 0);
        $this->assertFalse((bool) ($produced['metadata']['cms_mutation_attempted'] ?? true));
        $this->assertFalse((bool) ($produced['metadata']['auto_publish_attempted'] ?? true));
        $this->assertFalse((bool) ($produced['metadata']['auto_pseo_attempted'] ?? true));
        $this->assertTrue((bool) ($summary['cms_summary_read_only'] ?? false));
        $this->assertFalse((bool) ($summary['cms_mutation_allowed'] ?? true));
        $this->assertFalse((bool) ($summary['auto_publish_allowed'] ?? true));
        $this->assertFalse((bool) ($summary['auto_pseo_allowed'] ?? true));
        $this->assertFalse((bool) ($summary['raw_evidence_included'] ?? true));
        $this->assertArrayHasKey('metadata_drift', $summary['issue_type_counts'] ?? []);
        $this->assertArrayHasKey('high', $summary['severity_counts'] ?? []);

        foreach (['qa@example.invalid', '203.0.113.10', 'secret', 'raw_payload'] as $forbiddenValue) {
            $this->assertStringNotContainsString($forbiddenValue, $encoded);
        }
    }

    #[Test]
    public function issue_queue_foundation_dry_run_command_outputs_safe_summary_json(): void
    {
        $exitCode = Artisan::call('seo-intel:collect', [
            '--collector' => 'issue_queue_foundation',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame('issue_queue_foundation', $decoded['collector'] ?? null);
        $this->assertSame('success', $decoded['status'] ?? null);
        $this->assertTrue((bool) ($decoded['dry_run'] ?? false));
        $this->assertFalse((bool) ($decoded['writes_attempted'] ?? true));
        $this->assertFalse((bool) ($decoded['writes_committed'] ?? true));
        $this->assertFalse((bool) ($decoded['external_calls_attempted'] ?? true));
        $this->assertTrue((bool) ($decoded['metadata']['cms_summary_read_only'] ?? false));
        $this->assertFalse((bool) ($decoded['metadata']['cms_mutation_attempted'] ?? true));
        $this->assertFalse((bool) ($decoded['metadata']['auto_publish_attempted'] ?? true));
        $this->assertFalse((bool) ($decoded['metadata']['auto_pseo_attempted'] ?? true));
        $this->assertGreaterThanOrEqual(3, $decoded['metadata']['issue_count'] ?? 0);
        $this->assertContains('metadata_drift', $decoded['metadata']['issue_types'] ?? []);
        $this->assertContains('crawler_private_hit', $decoded['metadata']['issue_types'] ?? []);

        foreach (['qa@example.invalid', '203.0.113', 'ORDER', 'ATTEMPT', 'PAYMENT', 'cookie', 'token='] as $forbiddenValue) {
            $this->assertStringNotContainsString($forbiddenValue, $output);
        }
    }

    #[Test]
    public function generated_artifact_locks_cms_issue_queue_boundary_and_next_task(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(1, $artifact['version'] ?? null);
        $this->assertContains('SEO-DASH-05', $artifact['source_documents'] ?? []);
        $this->assertSame('seo_issue_queue', $artifact['table'] ?? null);
        $this->assertSame('issue_queue_foundation', $artifact['collector'] ?? null);
        $this->assertFalse((bool) ($artifact['enabled_by_default'] ?? true));
        $this->assertFalse((bool) ($artifact['write_enabled_by_default'] ?? true));
        $this->assertFalse((bool) ($artifact['external_api_calls_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['scheduler_enabled'] ?? true));
        $this->assertTrue((bool) ($artifact['cms_summary_read_only'] ?? false));
        $this->assertFalse((bool) ($artifact['cms_mutation_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['auto_publish_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['auto_pseo_allowed'] ?? true));
        $this->assertTrue((bool) ($artifact['pii_forbidden'] ?? false));
        $this->assertSame('seo_intel_only', $artifact['metabase_data_source'] ?? null);
        $this->assertFalse((bool) ($artifact['seo_intel_publishes_content'] ?? true));
        $this->assertFalse((bool) ($artifact['search_channel_purchase_attribution_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['node2_local_laravel_data_source'] ?? true));
        $this->assertSame('SEO-DASH-MVP-COMPLETE', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function docs_forbid_cms_mutation_auto_publish_pseo_and_scheduler_activation(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/cms-issue-queue-summary.md')));
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        foreach ([
            'cms may display issue summaries only',
            'does not publish content',
            'auto-edit cms records',
            'generate pseo pages',
            'must not store raw email',
            'next task: `seo-dash-mvp-complete`',
        ] as $requiredBoundary) {
            $this->assertStringContainsString($requiredBoundary, $doc);
        }

        $this->assertStringNotContainsString('issue_queue_foundation', $bootstrap);
        $this->assertStringNotContainsString('SeoIssueQueueProducer', $bootstrap);
        $this->assertStringNotContainsString('seo-intel:collect', $bootstrap);
    }

    /**
     * @return list<string>
     */
    private function forbiddenColumns(): array
    {
        return [
            'email',
            'order_no',
            'attempt_id',
            'payment_id',
            'provider_event_id',
            'cookie',
            'raw_payload',
            'payment_payload',
            'raw_email',
            'raw_ip',
            'raw_cookie',
            'raw_user_agent',
            'token',
            'api_key',
            'secret',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/cms-issue-queue-summary.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
