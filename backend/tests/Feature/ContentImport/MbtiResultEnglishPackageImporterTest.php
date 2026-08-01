<?php

declare(strict_types=1);

namespace Tests\Feature\ContentImport;

use App\Services\ContentImport\MbtiResultEnglishPackageImporter;
use DomainException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class MbtiResultEnglishPackageImporterTest extends TestCase
{
    public function test_exact_package_produces_forty_six_redacted_locale_section_entitlement_and_access_plans_without_writes(): void
    {
        $exitCode = $this->runDryRun();
        $payload = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertTrue($payload['ok']);
        self::assertSame('pass', $payload['status']);
        self::assertSame('dry_run', $payload['mode']);
        self::assertTrue($payload['dry_run_only']);
        self::assertFalse($payload['write_supported_in_this_pr']);
        self::assertFalse($payload['writes_committed']);
        self::assertFalse($payload['database_write_attempted']);
        self::assertFalse($payload['cms_write_attempted']);
        self::assertFalse($payload['private_payload_read_attempted']);
        self::assertFalse($payload['attempt_or_report_accessed']);
        self::assertFalse($payload['activation_attempted']);
        self::assertFalse($payload['publish_attempted']);
        self::assertFalse($payload['indexability_attempted']);
        self::assertFalse($payload['search_submission_attempted']);
        self::assertSame(MbtiResultEnglishPackageImporter::PACKAGE_SHA256, $payload['package']['package_sha256']);
        self::assertSame(46, $payload['row_count']);
        self::assertCount(46, $payload['rows']);
        self::assertSame(24, $payload['package']['preserved_control_count']);
        self::assertSame(21, $payload['package']['candidate_asset_count']);
        self::assertSame(1, $payload['package']['w9_fixture_target_count']);
        self::assertFalse($payload['package']['reader_copy_in_receipt']);
        self::assertFalse($payload['package']['local_path_in_receipt']);
        self::assertSame('en', $payload['target_contract']['locale']);
        self::assertSame('MBTI.global.en.default', $payload['target_contract']['pack_id']);
        self::assertSame('inactive_draft', $payload['target_contract']['target_state']);
        self::assertFalse($payload['target_contract']['private_result_authority_read_allowed']);
        self::assertFalse($payload['target_contract']['active_pointer_change_allowed']);

        $counts = array_count_values(array_column($payload['rows'], 'disposition'));
        self::assertSame([
            'preserved_reference' => 24,
            'candidate_asset' => 21,
            'w9_fixture_target' => 1,
        ], $counts);

        $candidateRows = array_values(array_filter(
            $payload['rows'],
            static fn (array $row): bool => $row['disposition'] === 'candidate_asset',
        ));
        foreach ($candidateRows as $row) {
            self::assertSame('would_stage_inactive_english_candidate', $row['action']);
            self::assertSame('inactive_draft', $row['planned_state']);
            self::assertFalse($row['write_executed']);
            self::assertFalse($row['reader_copy_in_plan']);
            self::assertNotSame('', $row['stable_asset_identity']);
            self::assertContains($row['entitlement_level'], [
                'locked_upsell_only',
                'free_preview_or_full_by_access_policy',
                'premium_full',
            ]);
            if ($row['asset_kind'] === 'canonical_section_family') {
                self::assertSame('mbti:result:section:'.$row['section_key'], $row['stable_asset_identity']);
                self::assertSame(
                    ($row['entitlement_level'] === 'premium_full' ? 'premium_teaser.' : 'sections.').$row['section_key'],
                    $row['authority_field'],
                );
            }
        }

        $pdf = collect($payload['rows'])->firstWhere('disposition', 'w9_fixture_target');
        self::assertIsArray($pdf);
        self::assertSame('W1-RESULT-SURFACE-02-PDF', $pdf['row_id']);
        self::assertSame('synthetic_private_safe', $pdf['fixture_kind']);
        self::assertSame('en', $pdf['required_locale']);
        self::assertFalse($pdf['private_payload_read']);

        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['attempt_id', 'report_token', 'user_scores', 'raw_scores', 'answers_json', 'orders', 'payments', 'reader_copy', 'local_path'] as $forbidden) {
            self::assertStringNotContainsString('"'.$forbidden.'"', $serialized);
        }
    }

    public function test_wrong_unknown_rebuilt_and_manifest_drift_packages_are_rejected(): void
    {
        $exitCode = $this->runDryRun(str_repeat('0', 64));
        $payload = $this->jsonOutput();
        self::assertSame(1, $exitCode);
        self::assertSame('confirmed_package_sha256_mismatch', $payload['errors'][0]['code']);

        $rebuiltDirectory = $this->copyPackage();
        $assetsPath = $rebuiltDirectory.'/assets.json';
        $assets = json_decode((string) File::get($assetsPath), true, 512, JSON_THROW_ON_ERROR);
        $assets['assets'][0]['content']['title'] .= ' rebuilt';
        File::put($assetsPath, json_encode($assets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $exitCode = $this->runDryRun(MbtiResultEnglishPackageImporter::PACKAGE_SHA256, $rebuiltDirectory);
        $payload = $this->jsonOutput();
        self::assertSame(1, $exitCode);
        self::assertSame('package_file_size_mismatch', $payload['errors'][0]['code']);

        $uppercaseDirectory = $this->copyPackage();
        $manifestPath = $uppercaseDirectory.'/package_manifest.json';
        $manifest = json_decode((string) File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['files'][0]['sha256'] = strtoupper($manifest['files'][0]['sha256']);
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $exitCode = $this->runDryRun(MbtiResultEnglishPackageImporter::PACKAGE_SHA256, $uppercaseDirectory);
        $payload = $this->jsonOutput();
        self::assertSame(1, $exitCode);
        self::assertSame('package_file_size_mismatch', $payload['errors'][0]['code']);

        $manifestDriftDirectory = $this->copyPackage();
        $manifestPath = $manifestDriftDirectory.'/package_manifest.json';
        $manifest = json_decode((string) File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['schema_version'] = 'unknown.rebuilt.manifest.v2';
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $exitCode = $this->runDryRun(MbtiResultEnglishPackageImporter::PACKAGE_SHA256, $manifestDriftDirectory);
        $payload = $this->jsonOutput();
        self::assertSame(1, $exitCode);
        self::assertSame('package_file_size_mismatch', $payload['errors'][0]['code']);
    }

    public function test_untrusted_manifest_is_authenticated_before_any_declared_file_is_read(): void
    {
        $packageDirectory = $this->copyPackage();
        $manifestPath = $packageDirectory.'/package_manifest.json';
        $untrustedManifestBytes = str_replace(
            '"README.md"',
            '"secret.js"',
            (string) File::get($manifestPath),
        );
        File::put($manifestPath, $untrustedManifestBytes);
        $privatePath = $packageDirectory.'/secret.js';
        self::assertTrue(symlink('/private/definitely-not-readable-by-this-test', $privatePath));

        $exitCode = $this->runDryRun(MbtiResultEnglishPackageImporter::PACKAGE_SHA256, $packageDirectory);
        $payload = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertSame('manifest_sha256_mismatch', $payload['errors'][0]['code']);
    }

    public function test_symlinked_declared_file_is_rejected_before_target_bytes_are_read(): void
    {
        $packageDirectory = $this->copyPackage();
        $assetsPath = $packageDirectory.'/assets.json';
        $outsidePath = sys_get_temp_dir().'/w1-mbti-result-private-'.bin2hex(random_bytes(6)).'.json';
        File::put($outsidePath, '{"private_local_data":"must-not-be-read"}');
        File::delete($assetsPath);
        self::assertTrue(symlink($outsidePath, $assetsPath));

        $exitCode = $this->runDryRun(MbtiResultEnglishPackageImporter::PACKAGE_SHA256, $packageDirectory);
        $payload = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertSame('package_file_symlink_rejected', $payload['errors'][0]['code']);
    }

    public function test_hard_linked_declared_file_is_rejected_before_target_bytes_are_read(): void
    {
        $packageDirectory = $this->copyPackage();
        $assetsPath = $packageDirectory.'/assets.json';
        $outsidePath = sys_get_temp_dir().'/w1-mbti-result-private-'.bin2hex(random_bytes(6)).'.json';
        File::put($outsidePath, '{"private_local_data":"must-not-be-read"}');
        File::delete($assetsPath);
        self::assertTrue(link($outsidePath, $assetsPath));

        $exitCode = $this->runDryRun(MbtiResultEnglishPackageImporter::PACKAGE_SHA256, $packageDirectory);
        $payload = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertSame('package_file_hardlink_rejected', $payload['errors'][0]['code']);
    }

    public function test_replay_is_byte_deterministic_and_rebuilt_assets_fail_before_parsing(): void
    {
        self::assertSame(0, $this->runDryRun());
        $firstOutput = Artisan::output();
        self::assertSame(0, $this->runDryRun());
        self::assertSame($firstOutput, Artisan::output());

        $rebuiltDirectory = $this->copyPackage();
        $assetsPath = $rebuiltDirectory.'/assets.json';
        $rebuiltAssets = json_decode((string) File::get($assetsPath), true, 512, JSON_THROW_ON_ERROR);
        $rebuiltAssets['assets'][0]['row_id'] = 'unverified-concurrent-replacement';
        $rebuiltBytes = json_encode($rebuiltAssets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
        File::put($assetsPath, $rebuiltBytes);

        $exitCode = $this->runDryRun(MbtiResultEnglishPackageImporter::PACKAGE_SHA256, $rebuiltDirectory);
        $payload = $this->jsonOutput();
        self::assertSame(1, $exitCode);
        self::assertSame('package_file_size_mismatch', $payload['errors'][0]['code']);
    }

    public function test_descriptor_read_is_bounded_to_frozen_size_plus_one_and_requires_exact_length(): void
    {
        $source = (string) File::get((new \ReflectionClass(MbtiResultEnglishPackageImporter::class))->getFileName());

        self::assertStringContainsString(
            'stream_get_contents($handle, $expectedBytes + 1)',
            $source,
        );
        self::assertStringContainsString(
            'strlen($bytes) !== $expectedBytes',
            $source,
        );
    }

    public function test_exact_approval_imports_twenty_one_candidates_into_inactive_authority_and_replays_without_writes(): void
    {
        $authorityDirectory = sys_get_temp_dir().'/w1-mbti-result-authority-'.bin2hex(random_bytes(6));
        File::copyDirectory(MbtiResultEnglishPackageImporter::defaultAuthorityDirectory(), $authorityDirectory);
        File::deleteDirectory($authorityDirectory.'/drafts');
        $importer = $this->app->make(MbtiResultEnglishPackageImporter::class);

        $first = $importer->importDraft(
            MbtiResultEnglishPackageImporter::defaultPackageDirectory(),
            MbtiResultEnglishPackageImporter::PACKAGE_SHA256,
            MbtiResultEnglishPackageImporter::defaultApprovalPath(),
            MbtiResultEnglishPackageImporter::APPROVAL_SHA256,
            $authorityDirectory,
        );

        self::assertTrue($first['writes_committed']);
        self::assertTrue($first['content_authority_write_attempted']);
        self::assertFalse($first['database_write_attempted']);
        self::assertFalse($first['cms_write_attempted']);
        self::assertFalse($first['private_payload_read_attempted']);
        self::assertFalse($first['activation_attempted']);
        self::assertFalse($first['publish_attempted']);
        self::assertFalse($first['indexability_attempted']);
        self::assertSame(46, $first['row_count']);
        self::assertSame(21, $first['authority']['created_count']);
        self::assertFalse($first['authority']['active_pointer_changed']);
        self::assertFalse($first['authority']['runtime_registered']);

        $draftPath = $authorityDirectory.'/drafts/en-parity-w1-mbti-result-content-v1.json';
        $draftBytes = (string) File::get($draftPath);
        $draft = json_decode($draftBytes, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('inactive_draft', $draft['authority']['state']);
        self::assertFalse($draft['authority']['runtime_available']);
        self::assertFalse($draft['authority']['active_pointer_registered']);
        self::assertCount(46, $draft['rows']);
        self::assertCount(21, array_filter($draft['rows'], static fn (array $row): bool => isset($row['asset'])));
        self::assertSame($first['authority']['authority_sha256'], hash('sha256', $draftBytes));
        $serializedDraft = json_encode($draft, JSON_THROW_ON_ERROR);
        foreach (['attempt_id', 'report_token', 'result_lookup_token', 'share_token', 'user_id', 'account_id', 'email', 'phone', 'user_scores', 'raw_scores', 'answers', 'orders', 'payments', 'recovery_data', 'secret', 'authorization'] as $forbidden) {
            self::assertStringNotContainsString('"'.$forbidden.'"', $serializedDraft);
        }

        $second = $importer->importDraft(
            MbtiResultEnglishPackageImporter::defaultPackageDirectory(),
            MbtiResultEnglishPackageImporter::PACKAGE_SHA256,
            MbtiResultEnglishPackageImporter::defaultApprovalPath(),
            MbtiResultEnglishPackageImporter::APPROVAL_SHA256,
            $authorityDirectory,
        );
        self::assertFalse($second['writes_committed']);
        self::assertFalse($second['content_authority_write_attempted']);
        self::assertSame(46, $second['authority']['preserved_count']);
        self::assertSame($draftBytes, File::get($draftPath));
    }

    public function test_wrong_approval_fails_closed(): void
    {
        $authorityDirectory = sys_get_temp_dir().'/w1-mbti-result-authority-'.bin2hex(random_bytes(6));
        File::copyDirectory(MbtiResultEnglishPackageImporter::defaultAuthorityDirectory(), $authorityDirectory);
        File::deleteDirectory($authorityDirectory.'/drafts');
        $importer = $this->app->make(MbtiResultEnglishPackageImporter::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('confirmed_approval_sha256_mismatch');
        $importer->importDraft(
            MbtiResultEnglishPackageImporter::defaultPackageDirectory(),
            MbtiResultEnglishPackageImporter::PACKAGE_SHA256,
            MbtiResultEnglishPackageImporter::defaultApprovalPath(),
            str_repeat('0', 64),
            $authorityDirectory,
        );
    }

    public function test_existing_inactive_authority_collision_is_never_overwritten(): void
    {
        $authorityDirectory = sys_get_temp_dir().'/w1-mbti-result-authority-'.bin2hex(random_bytes(6));
        File::copyDirectory(MbtiResultEnglishPackageImporter::defaultAuthorityDirectory(), $authorityDirectory);
        $draftPath = $authorityDirectory.'/drafts/en-parity-w1-mbti-result-content-v1.json';
        File::put($draftPath, "protected unrelated draft\n");
        $importer = $this->app->make(MbtiResultEnglishPackageImporter::class);

        try {
            $importer->importDraft(
                MbtiResultEnglishPackageImporter::defaultPackageDirectory(),
                MbtiResultEnglishPackageImporter::PACKAGE_SHA256,
                MbtiResultEnglishPackageImporter::defaultApprovalPath(),
                MbtiResultEnglishPackageImporter::APPROVAL_SHA256,
                $authorityDirectory,
            );
            self::fail('Expected the unrelated inactive authority collision to fail closed.');
        } catch (DomainException $exception) {
            self::assertStringStartsWith('authority_target_collision:', $exception->getMessage());
        }
        self::assertSame("protected unrelated draft\n", File::get($draftPath));
        self::assertFalse($importer->authorityWriteAttempted());
    }

    public function test_command_replays_committed_default_inactive_authority_without_write(): void
    {
        $exitCode = Artisan::call('content:import-mbti-result-english-package', [
            '--package-sha' => MbtiResultEnglishPackageImporter::PACKAGE_SHA256,
            '--write' => true,
            '--approval-sha' => MbtiResultEnglishPackageImporter::APPROVAL_SHA256,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertTrue($payload['ok']);
        self::assertSame('write_inactive_draft', $payload['mode']);
        self::assertFalse($payload['writes_committed']);
        self::assertFalse($payload['content_authority_write_attempted']);
        self::assertSame(46, $payload['authority']['preserved_count']);
    }

    public function test_staging_environment_refuses_write_before_authority_attempt(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'staging');

        $exitCode = Artisan::call('content:import-mbti-result-english-package', [
            '--package-sha' => MbtiResultEnglishPackageImporter::PACKAGE_SHA256,
            '--write' => true,
            '--approval-sha' => MbtiResultEnglishPackageImporter::APPROVAL_SHA256,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertSame('environment_write_not_authorized', $payload['errors'][0]['code']);
        self::assertFalse($payload['writes_committed']);
        self::assertFalse($payload['content_authority_write_attempted']);
        self::assertFalse($payload['database_write_attempted']);
        self::assertFalse($payload['cms_write_attempted']);
        self::assertFalse($payload['private_payload_read_attempted']);
        self::assertFalse($payload['activation_attempted']);
        self::assertFalse($payload['publish_attempted']);
        self::assertFalse($payload['indexability_attempted']);
        self::assertFalse($payload['sitemap_attempted']);
        self::assertFalse($payload['llms_attempted']);
        self::assertFalse($payload['search_submission_attempted']);
        self::assertFalse($payload['deploy_attempted']);
    }

    private function runDryRun(
        string $packageSha = MbtiResultEnglishPackageImporter::PACKAGE_SHA256,
        ?string $packageDirectory = null,
    ): int {
        $arguments = [
            '--package-sha' => $packageSha,
            '--dry-run' => true,
            '--json' => true,
        ];
        if ($packageDirectory !== null) {
            $arguments['--package'] = $packageDirectory;
        }

        return Artisan::call('content:import-mbti-result-english-package', $arguments);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    private function copyPackage(): string
    {
        $directory = sys_get_temp_dir().'/w1-mbti-result-package-'.bin2hex(random_bytes(6));
        File::copyDirectory(MbtiResultEnglishPackageImporter::defaultPackageDirectory(), $directory);

        return $directory;
    }
}
