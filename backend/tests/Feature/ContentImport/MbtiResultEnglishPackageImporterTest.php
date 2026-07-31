<?php

declare(strict_types=1);

namespace Tests\Feature\ContentImport;

use App\Services\ContentImport\MbtiResultEnglishPackageImporter;
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
        self::assertSame('manifest_file_sha256_mismatch', $payload['errors'][0]['code']);

        $uppercaseDirectory = $this->copyPackage();
        $manifestPath = $uppercaseDirectory.'/package_manifest.json';
        $manifest = json_decode((string) File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['files'][0]['sha256'] = strtoupper($manifest['files'][0]['sha256']);
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $exitCode = $this->runDryRun(MbtiResultEnglishPackageImporter::PACKAGE_SHA256, $uppercaseDirectory);
        $payload = $this->jsonOutput();
        self::assertSame(1, $exitCode);
        self::assertSame('manifest_file_sha256_invalid', $payload['errors'][0]['code']);

        $manifestDriftDirectory = $this->copyPackage();
        $manifestPath = $manifestDriftDirectory.'/package_manifest.json';
        $manifest = json_decode((string) File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['schema_version'] = 'unknown.rebuilt.manifest.v2';
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $exitCode = $this->runDryRun(MbtiResultEnglishPackageImporter::PACKAGE_SHA256, $manifestDriftDirectory);
        $payload = $this->jsonOutput();
        self::assertSame(1, $exitCode);
        self::assertSame('manifest_sha256_mismatch', $payload['errors'][0]['code']);
    }

    public function test_replay_is_byte_deterministic_and_assets_are_parsed_only_from_verified_bytes(): void
    {
        self::assertSame(0, $this->runDryRun());
        $firstOutput = Artisan::output();
        self::assertSame(0, $this->runDryRun());
        self::assertSame($firstOutput, Artisan::output());

        $assetsPath = MbtiResultEnglishPackageImporter::defaultPackageDirectory().'/assets.json';
        $rebuiltAssets = json_decode((string) File::get($assetsPath), true, 512, JSON_THROW_ON_ERROR);
        $rebuiltAssets['assets'][0]['row_id'] = 'unverified-concurrent-replacement';
        $rebuiltBytes = json_encode($rebuiltAssets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;

        File::partialMock()
            ->shouldReceive('get')
            ->once()
            ->with($assetsPath)
            ->andReturn($rebuiltBytes);

        $exitCode = $this->runDryRun();
        $payload = $this->jsonOutput();
        self::assertSame(1, $exitCode);
        self::assertSame('manifest_file_sha256_mismatch', $payload['errors'][0]['code']);
    }

    public function test_write_mode_fails_closed_without_private_or_database_access(): void
    {
        $exitCode = Artisan::call('content:import-mbti-result-english-package', [
            '--package-sha' => MbtiResultEnglishPackageImporter::PACKAGE_SHA256,
            '--write' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertFalse($payload['ok']);
        self::assertSame('write_mode_not_supported', $payload['errors'][0]['code']);
        self::assertFalse($payload['writes_committed']);
        self::assertFalse($payload['database_write_attempted']);
        self::assertFalse($payload['cms_write_attempted']);
        self::assertFalse($payload['private_payload_read_attempted']);
        self::assertFalse($payload['attempt_or_report_accessed']);
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
