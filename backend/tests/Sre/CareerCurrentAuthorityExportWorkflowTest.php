<?php

declare(strict_types=1);

namespace Tests\Sre;

use Tests\TestCase;

final class CareerCurrentAuthorityExportWorkflowTest extends TestCase
{
    public function test_one_time_workflow_is_exact_release_bound_and_environment_protected(): void
    {
        $workflow = $this->repoFile('.github/workflows/career-current-authority-export.yml');

        foreach ([
            'expected_release_sha:',
            'operation_key:',
            'career-current-authority-export-production',
            'Elect one-time export owner before Environment access',
            'uses: ./.github/actions/controlled-operation-gate',
            'environment: production',
            'test "$(git rev-parse origin/main)" = "$RELEASE"',
            'test "$(git rev-parse HEAD)" = "$RELEASE"',
            'REVISION',
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
            'retention-days: 30',
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
    }

    public function test_exporter_enforces_read_only_database_and_cache_guards(): void
    {
        $exporter = $this->repoFile('backend/app/Domain/Career/Display/CareerCurrentAuthorityExporter.php');
        $runner = $this->repoFile('backend/scripts/operations/career_current_authority_export.php');

        foreach ([
            'START TRANSACTION READ ONLY',
            "if (\$verb !== 'select')",
            "'DATABASE_WRITE_ATTEMPT'",
            'WritingKey::class',
            'ForgettingKey::class',
            'CacheFlushing::class',
            "'CACHE_WRITE_ATTEMPT'",
            'jobDetailCacheReadiness',
            'jobDetailVerifyOnlyRead',
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
    }

    private function repoFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$path);
        self::assertIsString($contents, "Unable to read {$path}");

        return $contents;
    }
}
