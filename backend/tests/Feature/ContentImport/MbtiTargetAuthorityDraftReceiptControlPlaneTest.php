<?php

declare(strict_types=1);

namespace Tests\Feature\ContentImport;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class MbtiTargetAuthorityDraftReceiptControlPlaneTest extends TestCase
{
    public function test_two_independent_human_operator_artifacts_are_exact_and_keep_every_release_boundary_closed(): void
    {
        $cases = [
            ['W1-MBTI-COMPARISONS', 'deecc8175fb43ba3730d6513b496a0ab6834459108e3b24e25550bbf40e001a2', '5455bc63ea094bb2adb11576d29a67f812a9189b6ddeadcebd0c20ac2dc5b5d6', 'W1-MBTI-COMPARISONS'],
            ['W1-MBTI-RESULT-CONTENT', '9325013b870fd2496efc0882656240f91ce28ff4faaf1da42fb3dde3577b0ed3', 'ba793884a5517f1194edab787c99a5be5159a2660954a15deb6cf0659544fa40', 'W1-MBTI-RESULT-CONTENT'],
        ];

        foreach ($cases as [$directory, $packageSha, $approvalSha, $subscopeId]) {
            $path = base_path('content_assets/en-content-parity/CONTROL-approvals/'.$directory.'/target-authority-draft-receipt-approval-2026-08-01.json');
            $bytes = (string) File::get($path);
            $approval = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);

            self::assertSame($approvalSha, hash('sha256', $bytes));
            self::assertSame('human_operator', $approval['approval_owner']);
            self::assertSame($subscopeId, $approval['subscope_id']);
            self::assertSame($packageSha, $approval['package_sha256']);
            self::assertSame('target_authority_draft_receipt', $approval['gate']);
            self::assertSame('APPROVED', $approval['verdict']);
            self::assertTrue($approval['permissions']['target_authority_draft_write_authorized']);
            self::assertTrue($approval['permissions']['target_authority_readback_authorized']);
            foreach (array_diff(array_keys($approval['permissions']), ['target_authority_draft_write_authorized', 'target_authority_readback_authorized']) as $permission) {
                self::assertFalse($approval['permissions'][$permission], $permission.' must remain closed.');
            }
        }
    }

    public function test_executor_and_workflow_are_fixed_to_inactive_draft_receipts_without_deployment_or_public_release(): void
    {
        $executor = (string) File::get(base_path('scripts/mbti_target_authority_draft_receipt.php'));
        self::assertFileDoesNotExist(base_path('../.github/workflows/mbti-target-authority-draft-receipt.yml'));

        foreach ([
            'mbti_cross_type_comparison_authorities',
            'content_pack_releases',
            'content_release_manifests',
            "'publish_attempted' => false",
            "'activation_attempted' => false",
            "'active_pointer_changed' => false",
            "'indexability_attempted' => false",
            "'deploy_attempted' => false",
            "'private_authority_read_attempted' => false",
            'set_exception_handler',
            'target_authority_receipt_failed',
        ] as $required) {
            self::assertStringContainsString($required, $executor);
        }

    }

    public function test_result_manifest_storage_schema_fits_the_existing_authority_column_without_changing_the_document_schema(): void
    {
        $executor = (string) File::get(base_path('scripts/mbti_target_authority_draft_receipt.php'));

        self::assertMatchesRegularExpression("/const RESULT_MANIFEST_STORAGE_SCHEMA_VERSION = '([^']+)'/", $executor);
        preg_match("/const RESULT_MANIFEST_STORAGE_SCHEMA_VERSION = '([^']+)'/", $executor, $matches);
        self::assertLessThanOrEqual(32, strlen($matches[1]));
        self::assertStringContainsString("'schema_version' => 'fermatmind.mbti.en_result_content_inactive_draft.v1'", $executor);
        self::assertStringContainsString("'schema_version' => RESULT_MANIFEST_STORAGE_SCHEMA_VERSION", $executor);
    }
}
