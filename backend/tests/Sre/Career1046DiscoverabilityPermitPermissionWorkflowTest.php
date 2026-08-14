<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class Career1046DiscoverabilityPermitPermissionWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_is_gated_before_production_environment_and_binds_task7b_evidence(): void
    {
        $workflow = $this->read('.github/workflows/career-1046-discoverability-permit-permission.yml');

        foreach ([
            '[op:${{ inputs.operation_key }}]',
            'uses: ./.github/actions/controlled-operation-gate',
            "if: needs.operation_gate.outputs.decision == 'execute'",
            'environment: production',
            "TASK7B_RUN_ID: '31746747008'",
            'sha256:c780c89fdeb67faf7478966b55e4514424725f6af4418b0fa147c5ea947fe3ae',
            'bc3285d5f91dfc0879bd10479028f192add720cc92504dd0c0dff9bc87e28a87',
            'career-1046-512d8e51876c6f48a4f09b88632d1a14',
            '3b63a14825334b805a36552600d925aa89a06582be5f5e2f739727d8a1e9e466',
            'PASS_APPLY_DISCOVERABILITY_RELEASE',
            'PASS_DISCOVERABILITY_PERMIT_PERMISSION_REPAIR_REQUIRED',
            'PASS_DISCOVERABILITY_PERMIT_PERMISSION_REPAIR_VERIFIED',
        ] as $required) {
            self::assertStringContainsString($required, $workflow);
        }

        self::assertLessThan(strpos($workflow, 'environment: production'), strpos($workflow, 'operation_gate:'));
    }

    #[Test]
    public function preflight_and_apply_are_receipt_bound_and_metadata_only(): void
    {
        $workflow = $this->read('.github/workflows/career-1046-discoverability-permit-permission.yml');
        $control = $this->read('backend/scripts/deploy/career_1046_discoverability_permit_permission_control.sh');
        $combined = $workflow.$control;

        foreach ([
            'CAREER_PERMIT_PERMISSION_EXPECTED_TARGET_SET_SHA256',
            'CAREER_PERMIT_PERMISSION_EXPECTED_SNAPSHOT_SHA256',
            'CAREER_PERMIT_PERMISSION_EXPECTED_PERMIT_SHA256',
            'CAREER_PERMIT_PERMISSION_EXPECTED_REPAIR_TARGET_COUNT',
            'modify exactly ${REPAIR_COUNT} current-generation owner/group/mode targets',
            'content_write_count:0',
            'database_write_count:0',
            'cms_write_count:0',
            'cache_write_count:0',
            'pointer_write_count:0',
            'sitemap_write_count:0',
            'llms_write_count:0',
            'search_submission_count:0',
            'automatic_retry_allowed:false',
            'bytes_unchanged:true',
        ] as $required) {
            self::assertStringContainsString($required, $combined);
        }

        foreach (['chmod -R', 'chown -R', 'find "$authority_root"', 'php artisan migrate', 'queue:restart', 'curl -k', '--insecure', 'indexnow', 'googleapis'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $combined);
        }
    }

    #[Test]
    public function control_targets_only_current_permit_metadata_and_revalidates_exact_counts(): void
    {
        $control = $this->read('backend/scripts/deploy/career_1046_discoverability_permit_permission_control.sh');

        foreach ([
            'targets=("$permit_parent" "$permit_generation" "$permit_file")',
            'modes=(2750 2750 640)',
            'chown "$expected_owner:$expected_group" "${targets[$index]}"',
            'chmod "${modes[$index]}" "${targets[$index]}"',
            '[ "$permit_sha256" = "$before_permit" ]',
            '($payload["slug_count"] ?? null) === 1046',
            '($payload["locale_row_count"] ?? null) === 2092',
            '($payload["search_submission_enabled"] ?? null) === false',
            '[ "$(stat -c \'%h\' "$permit_file")" = 1 ]',
            '[ ! -L "$permit_file" ]',
        ] as $required) {
            self::assertStringContainsString($required, $control);
        }
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$path);
        self::assertIsString($contents);

        return $contents;
    }
}
