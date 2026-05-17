<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\SeoIntelCollectorManager;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelCollectorSkeletonTest extends TestCase
{
    #[Test]
    public function collector_config_remains_disabled_by_default(): void
    {
        $this->assertFalse((bool) config('seo_intel.enabled'));
        $this->assertFalse((bool) config('seo_intel.write_enabled'));
        $this->assertFalse((bool) config('seo_intel.collectors_enabled'));
        $this->assertTrue((bool) config('seo_intel.dry_run_default'));
        $this->assertFalse((bool) config('seo_intel.allow_external_api_calls'));
        $this->assertSame([
            'noop',
            'url_truth_inventory',
            'drift_foundation',
            'crawler_log_foundation',
            'attribution_revenue_foundation',
            'gsc_foundation',
            'baidu_foundation',
            'indexnow_foundation',
            'so360_foundation',
            'sogou_foundation',
            'shenma_foundation',
            'chinese_crawler_log_foundation',
        ], config('seo_intel.allowed_collectors'));
        $this->assertSame('noop', config('seo_intel.default_collector'));
    }

    #[Test]
    public function manager_rejects_unknown_collectors_without_writes_or_external_calls(): void
    {
        $result = (new SeoIntelCollectorManager)->collect('gsc', ['dry_run' => true]);

        $this->assertSame('gsc', $result->collector);
        $this->assertSame('blocked', $result->status);
        $this->assertTrue($result->dryRun);
        $this->assertFalse($result->writesAttempted);
        $this->assertFalse($result->writesCommitted);
        $this->assertFalse($result->externalCallsAttempted);
        $this->assertContains('unknown_collector', $result->issues);
    }

    #[Test]
    public function noop_collector_returns_dry_run_success_without_writes_or_external_calls(): void
    {
        $result = (new SeoIntelCollectorManager)->collect('noop', ['dry_run' => true]);

        $this->assertSame('noop', $result->collector);
        $this->assertSame('success', $result->status);
        $this->assertTrue($result->dryRun);
        $this->assertFalse($result->writesAttempted);
        $this->assertFalse($result->writesCommitted);
        $this->assertFalse($result->externalCallsAttempted);
        $this->assertSame(0, $result->itemsSeen);
        $this->assertFalse((bool) ($result->metadata['production_data_reads'] ?? true));
        $this->assertFalse((bool) ($result->metadata['node2_local_laravel_data_source'] ?? true));
    }

    #[Test]
    public function noop_command_outputs_safe_json_without_sensitive_identifiers(): void
    {
        $exitCode = Artisan::call('seo-intel:collect', [
            '--collector' => 'noop',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame('noop', $decoded['collector'] ?? null);
        $this->assertSame('success', $decoded['status'] ?? null);
        $this->assertTrue((bool) ($decoded['dry_run'] ?? false));
        $this->assertFalse((bool) ($decoded['writes_attempted'] ?? true));
        $this->assertFalse((bool) ($decoded['writes_committed'] ?? true));
        $this->assertFalse((bool) ($decoded['external_calls_attempted'] ?? true));

        foreach (['email', 'order_no', 'attempt_id', 'payment_id', 'cookie', 'token'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $output);
        }
    }

    #[Test]
    public function collector_skeleton_does_not_add_scheduler_activation(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringNotContainsString('seo-intel:collect', $bootstrap);
        $this->assertStringNotContainsString('SeoIntelCollectCommand', $bootstrap);
    }

    #[Test]
    public function generated_artifact_locks_disabled_skeleton_boundary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(1, $artifact['version'] ?? null);
        $this->assertContains('SEO-DASH-01A', $artifact['source_documents'] ?? []);
        $this->assertContains('BACKEND-RUNTIME-02D', $artifact['source_documents'] ?? []);
        $this->assertFalse((bool) ($artifact['collectors_enabled_by_default'] ?? true));
        $this->assertFalse((bool) ($artifact['write_enabled_by_default'] ?? true));
        $this->assertTrue((bool) ($artifact['dry_run_default'] ?? false));
        $this->assertFalse((bool) ($artifact['external_api_calls_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['scheduler_enabled'] ?? true));
        $this->assertFalse((bool) ($artifact['queue_worker_enabled'] ?? true));
        $this->assertSame(['noop'], $artifact['collectors'] ?? []);
        $this->assertSame([], $artifact['real_collectors_implemented'] ?? ['unexpected']);
        $this->assertFalse((bool) ($artifact['db_writes_in_noop'] ?? true));
        $this->assertFalse((bool) ($artifact['production_data_reads_in_noop'] ?? true));
        $this->assertFalse((bool) ($artifact['node2_local_laravel_data_source'] ?? true));
        $this->assertFalse((bool) ($artifact['gsc_connected'] ?? true));
        $this->assertFalse((bool) ($artifact['baidu_connected'] ?? true));
        $this->assertFalse((bool) ($artifact['indexnow_connected'] ?? true));
        $this->assertFalse((bool) ($artifact['metabase_deployed'] ?? true));
        $this->assertSame('SEO-DASH-02A', $artifact['next_task'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-intel-collector-skeleton.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
