<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\DomesticSearchSubmissionStatusNormalizer;
use App\Services\SeoIntel\DomesticSearchUrlEligibilityValidator;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelDomesticSearchAdapterContractsTest extends TestCase
{
    #[Test]
    public function domestic_search_collectors_are_registered_and_disabled_by_default(): void
    {
        $this->assertContains('so360_foundation', config('seo_intel.allowed_collectors'));
        $this->assertContains('sogou_foundation', config('seo_intel.allowed_collectors'));
        $this->assertContains('shenma_foundation', config('seo_intel.allowed_collectors'));
        $this->assertFalse((bool) config('seo_intel.collectors_enabled'));
        $this->assertFalse((bool) config('seo_intel.write_enabled'));
        $this->assertFalse((bool) config('seo_intel.so360_enabled'));
        $this->assertFalse((bool) config('seo_intel.so360_live_api_enabled'));
        $this->assertFalse((bool) config('seo_intel.sogou_enabled'));
        $this->assertFalse((bool) config('seo_intel.sogou_live_api_enabled'));
        $this->assertFalse((bool) config('seo_intel.shenma_enabled'));
        $this->assertFalse((bool) config('seo_intel.shenma_live_api_enabled'));
        $this->assertFalse((bool) config('seo_intel.allow_external_api_calls'));
    }

    #[Test]
    public function domestic_search_migrations_do_not_include_forbidden_columns(): void
    {
        $paths = [
            ...glob(base_path('database/migrations/*seo_search_engine_verification_statuses*')),
            ...glob(base_path('database/migrations/*seo_domestic_submission_logs*')),
            ...glob(base_path('database/migrations/*seo_domestic_index_samples*')),
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
    public function domestic_search_dry_run_commands_output_safe_json_without_credentials_external_calls_or_submissions(): void
    {
        foreach ([
            'so360_foundation' => 'so360',
            'sogou_foundation' => 'sogou',
            'shenma_foundation' => 'shenma',
        ] as $collector => $engine) {
            $exitCode = Artisan::call('seo-intel:collect', [
                '--collector' => $collector,
                '--dry-run' => true,
                '--json' => true,
            ]);

            $decoded = $this->safeCommandOutput();

            $this->assertSame(0, $exitCode);
            $this->assertSame($collector, $decoded['collector'] ?? null);
            $this->assertSame('success', $decoded['status'] ?? null);
            $this->assertTrue((bool) ($decoded['dry_run'] ?? false));
            $this->assertFalse((bool) ($decoded['writes_attempted'] ?? true));
            $this->assertFalse((bool) ($decoded['writes_committed'] ?? true));
            $this->assertFalse((bool) ($decoded['external_calls_attempted'] ?? true));
            $this->assertSame(4, $decoded['metadata']['urls_seen'] ?? null);
            $this->assertSame(2, $decoded['metadata']['urls_validated'] ?? null);
            $this->assertFalse((bool) ($decoded['metadata']['submissions_attempted'] ?? true));
            $this->assertSame($engine, $decoded['metadata']['engine'] ?? null);
            $this->assertSame($engine, $decoded['metadata']['source_engine'] ?? null);
            $this->assertFalse((bool) ($decoded['metadata'][$engine.'_live_api_enabled'] ?? true));
            $this->assertFalse((bool) ($decoded['metadata']['credentials_required'] ?? true));
            $this->assertFalse((bool) ($decoded['metadata']['real_url_submission_allowed'] ?? true));
            $this->assertFalse((bool) ($decoded['metadata']['engine_specific_page_generation_allowed'] ?? true));
            $this->assertFalse((bool) ($decoded['metadata']['search_channel_purchase_attribution_allowed'] ?? true));
            $this->assertFalse((bool) ($decoded['metadata']['seo_truth_source'] ?? true));
        }
    }

    #[Test]
    public function eligibility_validator_rejects_draft_private_noindex_and_claim_unsafe_urls(): void
    {
        $validator = new DomesticSearchUrlEligibilityValidator;

        $draft = $validator->validate([
            'canonical_url' => 'https://example.invalid/zh/drafts/test',
            'is_draft' => true,
            'indexability_state' => 'indexable',
        ], 'so360', 'so360');
        $private = $validator->validate([
            'canonical_url' => 'https://example.invalid/zh/result/private',
            'is_private_flow' => true,
            'page_entity_type' => 'result',
            'indexability_state' => 'indexable',
        ], 'sogou', 'sogou');
        $noindex = $validator->validate([
            'canonical_url' => 'https://example.invalid/zh/noindex',
            'indexability_state' => 'noindex',
        ], 'shenma', 'shenma');
        $claimUnsafe = $validator->validate([
            'canonical_url' => 'https://example.invalid/zh/claims/unsafe',
            'indexability_state' => 'indexable',
            'claim_safe' => false,
        ], 'so360', 'so360');

        $this->assertFalse($draft['eligible']);
        $this->assertContains('draft_url_rejected', $draft['issues']);
        $this->assertFalse($private['eligible']);
        $this->assertContains('private_flow_rejected', $private['issues']);
        $this->assertContains('private_flow_entity_type_rejected', $private['issues']);
        $this->assertFalse($noindex['eligible']);
        $this->assertContains('non_indexable_rejected', $noindex['issues']);
        $this->assertFalse($claimUnsafe['eligible']);
        $this->assertContains('claim_boundary_rejected', $claimUnsafe['issues']);
    }

    #[Test]
    public function domestic_status_normalizer_keeps_dry_run_and_known_statuses_safe(): void
    {
        $normalizer = new DomesticSearchSubmissionStatusNormalizer;

        $this->assertSame('dry_run', $normalizer->normalize(''));
        $this->assertSame('dry_run', $normalizer->normalize('dry-run'));
        $this->assertSame('accepted', $normalizer->normalize('submitted'));
        $this->assertSame('failed', $normalizer->normalize('error'));
        $this->assertSame('blocked', $normalizer->normalize('rejected'));
        $this->assertSame('unknown', $normalizer->normalize('unexpected'));
    }

    #[Test]
    public function generated_artifact_locks_domestic_search_boundary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(1, $artifact['version'] ?? null);
        $this->assertContains('SEO-DASH-04B', $artifact['source_documents'] ?? []);
        $this->assertContains('so360_foundation', $artifact['collectors'] ?? []);
        $this->assertContains('sogou_foundation', $artifact['collectors'] ?? []);
        $this->assertContains('shenma_foundation', $artifact['collectors'] ?? []);
        $this->assertFalse((bool) ($artifact['enabled_by_default'] ?? true));
        $this->assertFalse((bool) ($artifact['live_api_enabled_by_default'] ?? true));
        $this->assertFalse((bool) ($artifact['write_enabled_by_default'] ?? true));
        $this->assertTrue((bool) ($artifact['dry_run_default'] ?? false));
        $this->assertFalse((bool) ($artifact['external_api_calls_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['real_url_submission_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['engine_specific_page_generation_allowed'] ?? true));
        $this->assertContains('so360', $artifact['source_engines'] ?? []);
        $this->assertContains('sogou', $artifact['source_engines'] ?? []);
        $this->assertContains('shenma', $artifact['source_engines'] ?? []);
        $this->assertSame('backend_orders_payment_benefits', $artifact['purchase_truth_source'] ?? null);
        $this->assertFalse((bool) ($artifact['search_channel_purchase_attribution_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['seo_truth_source'] ?? true));
        $this->assertTrue((bool) ($artifact['pii_forbidden'] ?? false));
        $this->assertFalse((bool) ($artifact['draft_url_submission_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['private_flow_submission_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['noindex_url_submission_allowed'] ?? true));
        $this->assertSame('CHINA-SEARCH-04', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function domestic_search_foundation_does_not_enable_scheduler_or_engine_specific_pages(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringNotContainsString('seo-intel:collect', $bootstrap);
        $this->assertStringNotContainsString('SeoIntelCollectCommand', $bootstrap);
        $this->assertFalse((bool) config('seo_intel.so360_live_api_enabled'));
        $this->assertFalse((bool) config('seo_intel.sogou_live_api_enabled'));
        $this->assertFalse((bool) config('seo_intel.shenma_live_api_enabled'));
        $this->assertFalse((bool) config('seo_intel.domestic_search_foundation.engine_specific_page_generation_allowed'));
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
            'api_key',
            'secret',
            'token',
            'verification_file_content',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/china-search-adapter-contracts.v1.json');

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
