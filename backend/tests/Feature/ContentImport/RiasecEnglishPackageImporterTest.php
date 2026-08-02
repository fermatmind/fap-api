<?php

declare(strict_types=1);

namespace Tests\Feature\ContentImport;

use App\Services\ContentImport\RiasecEnglishPackageImporter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class RiasecEnglishPackageImporterTest extends TestCase
{
    public function test_exact_package_returns_a_deterministic_redacted_1550_row_no_write_plan(): void
    {
        $first = $this->runDryRun();
        $second = $this->runDryRun();

        self::assertSame($first, $second);
        self::assertTrue($first['ok']);
        self::assertSame('pass', $first['status']);
        self::assertSame(1550, $first['row_count']);
        self::assertCount(1550, $first['rows']);
        self::assertSame(14, $first['package']['logical_group_count']);
        self::assertSame(15, $first['package']['normalized_unordered_pair_count']);
        self::assertSame(['share' => 3, 'pdf' => 2, 'history' => 2], $first['package']['safe_surface_counts']);
        self::assertSame(RiasecEnglishPackageImporter::PACKAGE_SHA256, $first['package']['package_sha256']);
        self::assertSame(RiasecEnglishPackageImporter::W9_REPORT_SHA256, $first['control']['w9_report_sha256']);
        self::assertFalse($first['writes_committed']);
        self::assertFalse($first['database_write_attempted']);
        self::assertFalse($first['cms_write_attempted']);
        self::assertFalse($first['runtime_write_attempted']);
        self::assertFalse($first['activation_attempted']);
        self::assertFalse($first['publish_attempted']);
        self::assertFalse($first['indexability_attempted']);
        self::assertFalse($first['private_payload_read_attempted']);
        self::assertFalse($first['attempt_or_report_accessed']);
        self::assertFalse($first['package']['reader_copy_in_receipt']);
        self::assertFalse($first['package']['local_path_in_receipt']);
        self::assertArrayNotHasKey('copy', $first['rows'][0]);
        self::assertStringNotContainsString(base_path(), (string) json_encode($first));
        self::assertStringNotContainsString('attempt', (string) json_encode($first['rows']));
        self::assertStringNotContainsString('payment', (string) json_encode($first['rows']));
    }

    public function test_rejects_wrong_sha_and_write_mode_without_attempting_writes(): void
    {
        $wrongSha = $this->runDryRun(['--package-sha' => str_repeat('0', 64)]);
        $write = $this->runDryRun(['--write' => true]);

        self::assertFalse($wrongSha['ok']);
        self::assertSame('confirmed_package_sha256_mismatch', $wrongSha['errors'][0]['code']);
        self::assertFalse($write['ok']);
        self::assertSame('write_not_authorized', $write['errors'][0]['code']);
        self::assertFalse($write['writes_committed']);
        self::assertFalse($write['database_write_attempted']);
        self::assertFalse($write['cms_write_attempted']);
    }

    public function test_rejects_tampered_payload_and_external_evidence_permissions(): void
    {
        $directory = $this->copyPackage();
        File::append($directory.'/handoff.md', "\nchanged");
        $tampered = $this->runDryRun(['--package' => $directory]);
        self::assertFalse($tampered['ok']);
        self::assertSame('payload_sha256_mismatch', $tampered['errors'][0]['code']);

        $directory = $this->copyPackage();
        $evidence = json_decode((string) File::get($directory.'/external_package_evidence.json'), true, 512, JSON_THROW_ON_ERROR);
        $evidence['permissions']['cms_write_authorized'] = true;
        File::put($directory.'/external_package_evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        $openPermission = $this->runDryRun(['--package' => $directory]);
        self::assertFalse($openPermission['ok']);
        self::assertSame('permission_open', $openPermission['errors'][0]['code']);

        $directory = $this->copyPackage();
        $evidence = json_decode((string) File::get($directory.'/external_package_evidence.json'), true, 512, JSON_THROW_ON_ERROR);
        $evidence['control_acceptance']['qa_report_sha256'] = str_repeat('0', 63);
        File::put($directory.'/external_package_evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        $driftedW9 = $this->runDryRun(['--package' => $directory]);
        self::assertFalse($driftedW9['ok']);
        self::assertSame('external_evidence_mismatch', $driftedW9['errors'][0]['code']);
    }

    public function test_rejects_symlinked_payload(): void
    {
        if (! function_exists('symlink')) {
            self::markTestSkipped('symlink is unavailable on this platform.');
        }
        $directory = $this->copyPackage();
        $target = $directory.'/scope_manifest.original.json';
        File::move($directory.'/scope_manifest.json', $target);
        if (! @symlink($target, $directory.'/scope_manifest.json')) {
            self::markTestSkipped('symlink creation is unavailable in this test environment.');
        }

        $payload = $this->runDryRun(['--package' => $directory]);
        self::assertFalse($payload['ok']);
        self::assertSame('unsafe_package_file', $payload['errors'][0]['code']);
    }

    public function test_rejects_hard_linked_payload(): void
    {
        if (! function_exists('link')) {
            self::markTestSkipped('hard links are unavailable on this platform.');
        }
        $directory = $this->copyPackage();
        $linked = $directory.'/scope_manifest.link.json';
        if (! @link($directory.'/scope_manifest.json', $linked)) {
            self::markTestSkipped('hard-link creation is unavailable in this test environment.');
        }

        $payload = $this->runDryRun(['--package' => $directory]);
        self::assertFalse($payload['ok']);
        self::assertSame('unsafe_package_file', $payload['errors'][0]['code']);
    }

    public function test_authority_plan_binds_all_nine_physical_segments_and_rejects_reader_visible_cjk(): void
    {
        $authority = app(RiasecEnglishPackageImporter::class)->authorityPlan(
            RiasecEnglishPackageImporter::defaultPackageDirectory(),
            RiasecEnglishPackageImporter::PACKAGE_SHA256,
        );
        self::assertCount(1550, $authority['authority_rows']);
        self::assertSame(9, count(array_unique(array_column($authority['authority_rows'], 'snapshot_segment'))));
        self::assertSame(1550, count(array_unique(array_column($authority['authority_rows'], 'source_line_sha256'))));
        foreach ($authority['authority_rows'] as $row) {
            self::assertNotEmpty($row['reader_payload']);
            self::assertDoesNotMatchRegularExpression('/[\x{3400}-\x{9fff}]/u', json_encode($row['reader_payload'], JSON_THROW_ON_ERROR));
        }

        $directory = $this->copyPackage();
        $path = $directory.'/payloads/dimension-core.jsonl';
        $lines = explode("\n", trim((string) File::get($path)));
        $first = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $first['reader_copy'] = '中文泄漏';
        $lines[0] = json_encode($first, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        File::put($path, implode("\n", $lines)."\n");
        $evidencePath = $directory.'/external_package_evidence.json';
        $evidence = json_decode((string) File::get($evidencePath), true, 512, JSON_THROW_ON_ERROR);
        foreach ($evidence['authority_snapshot']['segment_payloads'] as &$payload) {
            if ($payload['segment'] === 'dimension-core') {
                $payload['sha256'] = hash_file('sha256', $path);
            }
        }
        unset($payload);
        File::put($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('reader_visible_cjk_leakage');
        app(RiasecEnglishPackageImporter::class)->authorityPlan($directory, RiasecEnglishPackageImporter::PACKAGE_SHA256);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private function runDryRun(array $options = []): array
    {
        $options += ['--package-sha' => RiasecEnglishPackageImporter::PACKAGE_SHA256, '--dry-run' => true, '--json' => true];
        Artisan::call('content:import-riasec-english-package', $options);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function copyPackage(): string
    {
        $directory = storage_path('framework/testing/w4-riasec-'.bin2hex(random_bytes(8)));
        File::copyDirectory(RiasecEnglishPackageImporter::defaultPackageDirectory(), $directory);
        $this->beforeApplicationDestroyed(static fn () => File::deleteDirectory($directory));

        return $directory;
    }
}
