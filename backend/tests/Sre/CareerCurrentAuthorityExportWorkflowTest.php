<?php

declare(strict_types=1);

namespace Tests\Sre;

use Tests\TestCase;

final class CareerCurrentAuthorityExportWorkflowTest extends TestCase
{
    public function test_one_time_workflow_is_exact_release_bound_and_environment_protected(): void
    {
        self::assertFileDoesNotExist(dirname(__DIR__, 3).'/.github/workflows/career-current-authority-export.yml');
        $workflow = $this->repoFile('.github/workflows/backend-greenfield-current-baseline.yml');
        $replacementWorkflow = $this->repoFile('.github/workflows/career-1046-display-asset-replacement.yml');

        foreach ([
            '- career-current-authority',
            'operation_key:',
            'run-name: Backend Greenfield Current Baseline ${{ inputs.mode }} [op:${{ inputs.operation_key }}]',
            'group: deploy-${{ github.repository }}-production',
            'Elect Career Current export owner before Environment access',
            'uses: ./.github/actions/controlled-operation-gate',
            'IDENTITY: control=${{ inputs.expected_control_plane_sha }}|release=${{ inputs.expected_active_revision }}|projection=${{ inputs.expected_projection_sha256 }}|mode=career-current-authority',
            'environment: production',
            'test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'test "$EXPECTED_ACTIVE_REVISION" = "$EXPECTED_CONTROL_PLANE_SHA"',
            '[[ "$EXPECTED_PROJECTION_SHA256" =~ ^[0-9a-f]{64}$ ]]',
            'CAREER_CURRENT_EXPORT_EXPECTED_PROJECTION_SHA256=$q_projection',
            '.career_runtime_projection_sha256 == $projection',
            'REVISION',
            'releases=\$(readlink -f',
            'case \"\$current\" in \"\$releases\"/*)',
            'test ! -e $q_path/.dep/deploy.lock',
            'CAREER_CURRENT_EXPORT_EXECUTE=1',
            'career_current_authority_export.php',
            '.database_query_verbs == ["select"]',
            '.database_write_count == 0',
            '.cache_write_count == 0',
            '.pointer_write_count == 0',
            '.discoverability_write_count == 0',
            '.database_public_content_sha256 == .active_cache_public_content_sha256',
            '.database_public_content_sha256 == .api_public_content_sha256',
            'manifest_canonical="$(jq -S -c',
            'and .manifest_sha256 == $manifest',
            '.counts.public_projection_locale_pages == 2090',
            '.counts.manual_hold_locale_pages == 2',
            'remote_status="${pipeline_status[1]}"',
            'FAIL_CURRENT_AUTHORITY_EXPORT',
            'safe_error_code',
            'CAREER_CURRENT_REMOTE_STREAM_INTERRUPTED',
            'PENDING_CURRENT_AUTHORITY_EXPORT_VALIDATION',
            'career-current-authority-export.remote-receipt.json',
            "if: failure() && inputs.mode == 'career-current-authority'",
            'CAREER_CURRENT_EXPORT_OR_VALIDATION_FAILED',
            'rm -f "$dir/assets.jsonl" "$dir/manifest.json"',
            'and (.safe_error_code | test("^[A-Z0-9_]+$"))',
            'zero_write_confirmed: false',
            'if: always() && inputs.mode == \'career-current-authority\'',
            'retention-days: 3',
        ] as $required) {
            self::assertStringContainsString($required, $workflow);
        }

        foreach ([
            'php artisan migrate',
            'queue:restart',
            'deploy:symlink',
            'indexnow',
            'googleapis',
            ' --apply',
            'CAREER_DISPLAY_REPLACEMENT_EXECUTE',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $workflow);
        }

        self::assertGreaterThan(
            strpos($workflow, 'jq -e -s \'length == 1046'),
            strrpos($workflow, 'cp "$remote_receipt" "$dir/receipt.json"'),
            'The PASS receipt must only become publishable after every runner-side integrity check passes.',
        );
        self::assertStringContainsString(
            'if ! jq -e',
            $workflow,
            'The failure finalizer must preserve an already-sanitized domain failure receipt.',
        );
        self::assertSame(
            2,
            substr_count($workflow, 'cp "$remote_receipt" "$dir/receipt.json"'),
            'Both the validated success receipt and a sanitized remote failure receipt must be retained.',
        );
        self::assertStringContainsString(
            'group: deploy-${{ github.repository }}-production',
            $replacementWorkflow,
            'The export and the legacy replacement must serialize through the same production group.',
        );
    }

    public function test_exporter_enforces_read_only_database_and_cache_guards(): void
    {
        $exporter = $this->repoFile('backend/app/Domain/Career/Display/CareerCurrentAuthorityExporter.php');
        $runner = $this->repoFile('backend/scripts/operations/career_current_authority_export.php');

        foreach ([
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
            'SET TRANSACTION READ ONLY',
            'START TRANSACTION WITH CONSISTENT SNAPSHOT',
            "if (\$verb !== 'select')",
            "'DATABASE_WRITE_ATTEMPT'",
            'WritingKey::class',
            'ForgettingKey::class',
            'CacheFlushing::class',
            "'CACHE_WRITE_ATTEMPT'",
            'jobDetailCacheReadiness',
            'jobDetailVerifyOnlyRead',
            'CareerJobDetailReaderSafeReviewProjector',
            'readerSafeProjector->project',
            'MANUAL_HOLD_PUBLIC_PROJECTION_DRIFT',
            'CAREER_RUNTIME_PROJECTION_SHA256_MISMATCH',
            'CareerGenerationAuthorityLoader',
            'generationAuthority->loadStrict',
            'pointer.artifacts.projection.sha256',
            'CAREER_ACTIVE_GENERATION_PROJECTION_UNAVAILABLE',
            'CURRENT_V4_2_ORDER',
            "'id',",
            "'occupation_id',",
            "'import_run_id',",
            "'created_at',",
            "'updated_at',",
            "'remote_file_write_count' => 0",
        ] as $required) {
            self::assertStringContainsString($required, $exporter.$runner);
        }

        foreach (['->create(', '->update(', '->delete(', '::insert(', '::upsert(', 'Cache::put(', 'Cache::forget('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $exporter.$runner);
        }

        self::assertStringNotContainsString('PUBLIC_CONTENT_FIELDS', $exporter);
        self::assertStringNotContainsString('contentProjection(', $exporter);

        self::assertMatchesRegularExpression(
            '/SET TRANSACTION ISOLATION LEVEL REPEATABLE READ.*SET TRANSACTION READ ONLY.*START TRANSACTION WITH CONSISTENT SNAPSHOT/s',
            $exporter,
        );
    }

    private function repoFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$path);
        self::assertIsString($contents, "Unable to read {$path}");

        return $contents;
    }
}
