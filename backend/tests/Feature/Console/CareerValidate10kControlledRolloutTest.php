<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerValidate10kControlledRolloutTest extends TestCase
{
    public function test_allows_only_readiness_handoff_for_a_complete_ordered_batch(): void
    {
        $path = $this->evidence(1000, [100, 500]);
        $exit = Artisan::call('career:validate-10k-controlled-rollout', ['--batch' => 1000, '--evidence' => $path, '--json' => true]);
        $report = json_decode((string) Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('passed', $report['status']);
        $this->assertSame([100, 500, 1000, 2500, 5000, 10000], $report['batch_sequence']);
        $this->assertTrue($report['ready_for_separate_exact_sha_approval']);
        $this->assertFalse($report['apply_allowed']);
        $this->assertFalse($report['promotion_executed']);
        $this->assertFalse($report['production_write_performed']);
        $this->assertFalse($report['deployment_triggered']);
        $this->assertFalse($report['search_channel_action_performed']);
    }

    public function test_blocks_skipped_batch_and_each_failed_operational_gate(): void
    {
        $data = $this->payload(2500, [100]);
        $data['api_slo']['passed'] = false;
        $data['authority']['zh_count'] = 2499;
        $data['cache']['warm_completion_rate'] = 0.9;
        $data['publication_gate']['passed'] = false;
        $path = $this->write($data);

        $exit = Artisan::call('career:validate-10k-controlled-rollout', ['--batch' => 2500, '--evidence' => $path, '--json' => true]);
        $report = json_decode((string) Artisan::output(), true);

        $this->assertSame(1, $exit);
        foreach (['previous_batches_not_completed', 'api_slo_failed', 'locale_parity_failed', 'cache_warm_failed', 'publication_indexability_gate_failed'] as $error) {
            $this->assertContains($error, $report['errors']);
        }
        $this->assertFalse($report['ready_for_separate_exact_sha_approval']);
    }

    public function test_rejects_truthy_string_boolean_evidence(): void
    {
        $data = $this->payload(100, []);
        $data['api_slo']['passed'] = 'false';
        $data['seo']['canonical_robots_structured_data_passed'] = 'true';
        $data['rollback']['ready'] = 'yes';
        $data['publication_gate']['passed'] = 1;

        $exit = Artisan::call('career:validate-10k-controlled-rollout', [
            '--batch' => 100,
            '--evidence' => $this->write($data),
            '--json' => true,
        ]);
        $report = json_decode((string) Artisan::output(), true);

        $this->assertSame(1, $exit);
        foreach (['api_slo_failed', 'seo_contracts_failed', 'rollback_ready_failed', 'publication_indexability_gate_failed'] as $error) {
            $this->assertContains($error, $report['errors']);
        }
    }

    private function evidence(int $count, array $completed): string
    {
        return $this->write($this->payload($count, $completed));
    }

    private function payload(int $count, array $completed): array
    {
        return [
            'completed_batches' => $completed,
            'api_slo' => ['passed' => true],
            'frontend' => ['success_rate' => 0.999],
            'authority' => ['public_count' => $count, 'en_count' => $count, 'zh_count' => $count],
            'seo' => ['canonical_robots_structured_data_passed' => true],
            'discoverability' => ['sitemap_url_count' => $count * 2, 'llms_url_count' => $count * 2],
            'cache' => ['warm_completion_rate' => 1.0],
            'errors' => ['http_404_rate' => 0.001, 'http_5xx_rate' => 0.001, 'http_504_count' => 0],
            'rollback' => ['ready' => true, 'previous_version' => 'career-runtime-v1'],
            'publication_gate' => ['passed' => true, 'approved_count' => $count],
        ];
    }

    private function write(array $payload): string
    {
        $path = storage_path('framework/testing/career-rollout-'.uniqid().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_THROW_ON_ERROR));

        return $path;
    }
}
