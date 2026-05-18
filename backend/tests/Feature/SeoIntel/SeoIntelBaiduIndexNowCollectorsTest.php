<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\BaiduPushPayloadValidator;
use App\Services\SeoIntel\IndexNowPayloadValidator;
use App\Services\SeoIntel\SearchChannelSubmissionStatusNormalizer;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelBaiduIndexNowCollectorsTest extends TestCase
{
    #[Test]
    public function baidu_and_indexnow_collectors_are_registered_and_disabled_by_default(): void
    {
        $this->assertContains('baidu_foundation', config('seo_intel.allowed_collectors'));
        $this->assertContains('indexnow_foundation', config('seo_intel.allowed_collectors'));
        $this->assertFalse((bool) config('seo_intel.collectors_enabled'));
        $this->assertFalse((bool) config('seo_intel.write_enabled'));
        $this->assertFalse((bool) config('seo_intel.baidu_enabled'));
        $this->assertFalse((bool) config('seo_intel.baidu_live_api_enabled'));
        $this->assertFalse((bool) config('seo_intel.indexnow_enabled'));
        $this->assertFalse((bool) config('seo_intel.indexnow_live_api_enabled'));
        $this->assertFalse((bool) config('seo_intel.allow_external_api_calls'));
    }

    #[Test]
    public function search_channel_migrations_do_not_include_forbidden_columns(): void
    {
        $paths = [
            ...glob(base_path('database/migrations/seo_intel/*seo_baidu_push_logs*')),
            ...glob(base_path('database/migrations/seo_intel/*seo_baidu_landing_daily*')),
            ...glob(base_path('database/migrations/seo_intel/*seo_indexnow_submissions*')),
        ];

        $this->assertCount(3, $paths);

        foreach ($paths as $path) {
            $contents = strtolower((string) file_get_contents($path));

            foreach ($this->forbiddenColumns() as $column) {
                $this->assertStringNotContainsString("'".$column."'", $contents, $path.' must not define '.$column);
                $this->assertStringNotContainsString('"'.$column.'"', $contents, $path.' must not define '.$column);
            }
        }
    }

    #[Test]
    public function baidu_dry_run_command_outputs_safe_json_without_credentials_external_calls_or_submissions(): void
    {
        $exitCode = Artisan::call('seo-intel:collect', [
            '--collector' => 'baidu_foundation',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $decoded = $this->safeCommandOutput();

        $this->assertSame(0, $exitCode);
        $this->assertSame('baidu_foundation', $decoded['collector'] ?? null);
        $this->assertSame('success', $decoded['status'] ?? null);
        $this->assertTrue((bool) ($decoded['dry_run'] ?? false));
        $this->assertFalse((bool) ($decoded['writes_attempted'] ?? true));
        $this->assertFalse((bool) ($decoded['writes_committed'] ?? true));
        $this->assertFalse((bool) ($decoded['external_calls_attempted'] ?? true));
        $this->assertSame(3, $decoded['metadata']['urls_seen'] ?? null);
        $this->assertSame(2, $decoded['metadata']['urls_validated'] ?? null);
        $this->assertFalse((bool) ($decoded['metadata']['submissions_attempted'] ?? true));
        $this->assertSame('baidu', $decoded['metadata']['source_engine'] ?? null);
        $this->assertFalse((bool) ($decoded['metadata']['credentials_required'] ?? true));
        $this->assertFalse((bool) ($decoded['metadata']['real_url_submission_allowed'] ?? true));
        $this->assertFalse((bool) ($decoded['metadata']['search_channel_purchase_attribution_allowed'] ?? true));
        $this->assertFalse((bool) ($decoded['metadata']['seo_truth_source'] ?? true));
    }

    #[Test]
    public function indexnow_dry_run_command_outputs_safe_json_without_credentials_external_calls_or_submissions(): void
    {
        $exitCode = Artisan::call('seo-intel:collect', [
            '--collector' => 'indexnow_foundation',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $decoded = $this->safeCommandOutput();

        $this->assertSame(0, $exitCode);
        $this->assertSame('indexnow_foundation', $decoded['collector'] ?? null);
        $this->assertSame('success', $decoded['status'] ?? null);
        $this->assertTrue((bool) ($decoded['dry_run'] ?? false));
        $this->assertFalse((bool) ($decoded['writes_attempted'] ?? true));
        $this->assertFalse((bool) ($decoded['writes_committed'] ?? true));
        $this->assertFalse((bool) ($decoded['external_calls_attempted'] ?? true));
        $this->assertSame(3, $decoded['metadata']['urls_seen'] ?? null);
        $this->assertSame(2, $decoded['metadata']['urls_validated'] ?? null);
        $this->assertFalse((bool) ($decoded['metadata']['submissions_attempted'] ?? true));
        $this->assertSame('bing_indexnow', $decoded['metadata']['source_engine'] ?? null);
        $this->assertFalse((bool) ($decoded['metadata']['credentials_required'] ?? true));
        $this->assertFalse((bool) ($decoded['metadata']['real_url_submission_allowed'] ?? true));
        $this->assertFalse((bool) ($decoded['metadata']['search_channel_purchase_attribution_allowed'] ?? true));
        $this->assertFalse((bool) ($decoded['metadata']['seo_truth_source'] ?? true));
    }

    #[Test]
    public function payload_validators_reject_draft_private_and_non_indexable_urls(): void
    {
        $baidu = new BaiduPushPayloadValidator;
        $indexNow = new IndexNowPayloadValidator;

        $draft = $baidu->validate([
            'canonical_url' => 'https://example.invalid/zh/drafts/test',
            'is_draft' => true,
            'indexability_state' => 'indexable',
        ]);
        $private = $indexNow->validate([
            'canonical_url' => 'https://example.invalid/zh/result/private',
            'is_private_flow' => true,
            'indexability_state' => 'indexable',
        ]);
        $noindex = $baidu->validate([
            'canonical_url' => 'https://example.invalid/zh/noindex',
            'indexability_state' => 'noindex',
        ]);

        $this->assertFalse($draft['eligible']);
        $this->assertContains('draft_url_rejected', $draft['issues']);
        $this->assertFalse($private['eligible']);
        $this->assertContains('private_flow_rejected', $private['issues']);
        $this->assertFalse($noindex['eligible']);
        $this->assertContains('non_indexable_rejected', $noindex['issues']);
    }

    #[Test]
    public function status_normalizer_keeps_dry_run_and_known_statuses_safe(): void
    {
        $normalizer = new SearchChannelSubmissionStatusNormalizer;

        $this->assertSame('dry_run', $normalizer->normalize(''));
        $this->assertSame('dry_run', $normalizer->normalize('dry-run'));
        $this->assertSame('accepted', $normalizer->normalize('submitted'));
        $this->assertSame('failed', $normalizer->normalize('error'));
        $this->assertSame('blocked', $normalizer->normalize('rejected'));
        $this->assertSame('unknown', $normalizer->normalize('unexpected'));
    }

    #[Test]
    public function generated_artifact_locks_baidu_indexnow_boundary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(1, $artifact['version'] ?? null);
        $this->assertContains('SEO-DASH-04A', $artifact['source_documents'] ?? []);
        $this->assertContains('baidu_foundation', $artifact['collectors'] ?? []);
        $this->assertContains('indexnow_foundation', $artifact['collectors'] ?? []);
        $this->assertFalse((bool) ($artifact['enabled_by_default'] ?? true));
        $this->assertFalse((bool) ($artifact['live_api_enabled_by_default'] ?? true));
        $this->assertFalse((bool) ($artifact['write_enabled_by_default'] ?? true));
        $this->assertTrue((bool) ($artifact['dry_run_default'] ?? false));
        $this->assertFalse((bool) ($artifact['external_api_calls_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['real_url_submission_allowed'] ?? true));
        $this->assertContains('baidu', $artifact['source_engines'] ?? []);
        $this->assertContains('bing_indexnow', $artifact['source_engines'] ?? []);
        $this->assertSame('backend_orders_payment_benefits', $artifact['purchase_truth_source'] ?? null);
        $this->assertFalse((bool) ($artifact['search_channel_purchase_attribution_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['seo_truth_source'] ?? true));
        $this->assertTrue((bool) ($artifact['pii_forbidden'] ?? false));
        $this->assertFalse((bool) ($artifact['draft_url_submission_allowed'] ?? true));
        $this->assertSame('CHINA-SEARCH-03', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function baidu_indexnow_foundation_does_not_enable_scheduler_gsc_live_or_sitemap_llms_changes(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringNotContainsString('seo-intel:collect', $bootstrap);
        $this->assertStringNotContainsString('SeoIntelCollectCommand', $bootstrap);
        $this->assertFalse((bool) config('seo_intel.gsc_live_api_enabled'));
        $this->assertFalse((bool) config('seo_intel.baidu_live_api_enabled'));
        $this->assertFalse((bool) config('seo_intel.indexnow_live_api_enabled'));
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
            'baidu_token',
            'indexnow_key',
            'api_key',
            'secret',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-intel-baidu-indexnow-collectors.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function safeCommandOutput(): array
    {
        $output = trim(Artisan::output());

        foreach (['email', 'order_no', 'attempt_id', 'payment_id', 'provider_event_id', 'cookie', 'token', 'secret'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $output);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
