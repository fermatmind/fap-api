<?php

declare(strict_types=1);

namespace Tests\Feature\SRE;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class MbtiIntpASeoTitleProductionPromotionWorkflowTest extends TestCase
{
    public function test_workflow_is_exact_sha_protected_single_field_and_failure_rollback_capable(): void
    {
        $workflow = File::get(base_path('../.github/workflows/mbti-intp-a-seo-title-production-promotion.yml'));

        foreach ([
            'expected_control_plane_sha:',
            'expected_active_revision:',
            'expected_package_sha256:',
            'staging_run_id:',
            'staging_receipt_sha256:',
            'operator_approval_phrase:',
            'environment: production',
            'test "$(git rev-parse origin/main)" = "$CONTROL_SHA"',
            'test "$(tr -d \'\\r\\n\' < "$current/REVISION")" = "$ACTIVE_REVISION"',
            '--dry-run --json',
            'protected_override_count == 1',
            'protected_fields == ["seo_title"]',
            'rollback_approval=',
            'remote_command rollback',
            'live_qa_failed_rolled_back',
            'for attempt in $(seq 1 24)',
            'sleep 30',
            'production_api_title_verified:true',
            'public_html_title_verified:true',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }

        foreach ([
            '.seo_meta.seo_title',
            '.seo_surface_v1.title',
            '.seo_surface_v1.metadata_fingerprint',
            '.mbti_public_projection_v1.seo.title',
        ] as $allowedPath) {
            $this->assertStringContainsString($allowedPath, $workflow);
        }

        $this->assertStringNotContainsString('indexnow', strtolower($workflow));
        $this->assertStringNotContainsString('search queue', strtolower($workflow));
        $this->assertStringNotContainsString('sitemap:', strtolower($workflow));
        $this->assertStringNotContainsString('llms:', strtolower($workflow));
    }
}
