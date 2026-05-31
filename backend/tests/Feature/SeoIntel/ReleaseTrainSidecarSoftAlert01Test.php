<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReleaseTrainSidecarSoftAlert01Test extends TestCase
{
    #[Test]
    public function generated_artifact_records_soft_alert_boundaries(): void
    {
        $payload = $this->payload();

        $this->assertSame('release_train_sidecar_soft_alert.v1', $payload['schema_version'] ?? null);
        $this->assertSame('RELEASE-TRAIN-SIDECAR-SOFT-ALERT-01', $payload['task'] ?? null);
        $this->assertSame(
            'release_train_sidecar_soft_alert_completed_ready_for_deploy_readiness',
            $payload['final_decision'] ?? null,
        );

        foreach ([
            'required_github_checks',
            'core_page_api_smoke',
            'sitemap_private_url_leakage',
            'held_slug_exposure',
            'clinical_depression_exposure',
            'search_channel_anomaly',
            'staging_containment_regression',
            'deploy_wrapper_failure',
        ] as $hardBlock) {
            $this->assertContains($hardBlock, $payload['hard_block_checks'] ?? []);
        }

        $this->assertContains('llms-full', $payload['soft_alert_eligible_surfaces'] ?? []);
        $this->assertTrue((bool) data_get($payload, 'soft_alert_required_manifest_fields.smoke_check.soft_alert'));
        $this->assertFalse((bool) data_get($payload, 'soft_alert_required_manifest_fields.smoke_check.hard_block'));
        $this->assertFalse((bool) data_get($payload, 'soft_alert_required_manifest_fields.smoke_check.core_smoke'));
    }

    #[Test]
    public function non_mutation_and_guard_flags_are_fail_closed(): void
    {
        $payload = $this->payload();

        foreach ([
            'private_or_held_exposure_still_blocks',
            'search_channel_or_staging_guard_still_blocks',
            'core_smoke_still_blocks',
            'required_checks_still_block',
        ] as $flag) {
            $this->assertTrue((bool) ($payload[$flag] ?? false), $flag);
        }

        foreach ([
            'deploy_performed',
            'rollback_performed',
            'cms_mutation_performed',
            'db_mutation_performed',
            'search_channel_action_performed',
            'url_submission_performed',
            'external_search_api_call_performed',
        ] as $flag) {
            $this->assertFalse((bool) ($payload[$flag] ?? true), $flag);
        }
    }

    #[Test]
    public function report_records_required_sections_and_next_task(): void
    {
        $path = base_path('docs/seo/release-train-sidecar-soft-alert-01.md');

        $this->assertFileExists($path);

        $report = (string) file_get_contents($path);

        foreach ([
            '## 1. Executive Summary',
            '## 2. Hard-Block Rules',
            '## 3. Soft-Alert Boundary',
            '## 4. Implementation',
            '## 5. Validation',
            '## 6. What Was Not Done',
            '## 7. Final Decision',
            '## 8. Next Task',
        ] as $heading) {
            $this->assertStringContainsString($heading, $report);
        }

        $this->assertSame(
            'DEPLOY-READINESS｜Deploy CAREER 1046 growth foundation fixes',
            $this->payload()['next_task'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $path = base_path('docs/seo/generated/release-train-sidecar-soft-alert-01.v1.json');

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
