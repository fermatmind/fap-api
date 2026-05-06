<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class AuditTest extends TestCase
{
    private const APPROVAL_PATH = 'content_assets/big5/result_page_v2/governance/release_approval_v0_1';

    private const ARCHIVE_PATH = 'content_assets/big5/result_page_v2/qa/release_evidence_archive/v0_1';

    public function test_release_evidence_archive_package_exists(): void
    {
        $this->assertFileExists(base_path(self::ARCHIVE_PATH.'/README.md'));
        $this->assertFileExists(base_path(self::ARCHIVE_PATH.'/manifest.json'));
        $this->assertFileExists(base_path(self::ARCHIVE_PATH.'/big5_v2_release_evidence_archive_v0_1.json'));

        $manifest = $this->archiveJson('manifest.json');

        $this->assertSame('big5_v2_release_evidence_archive', $manifest['package'] ?? null);
        $this->assertSame('production_governance_evidence_archive', $manifest['mode'] ?? null);
        $this->assertProductionDisabled($manifest);
    }

    public function test_audit_archive_records_required_evidence_references(): void
    {
        $archive = $this->archiveJson('big5_v2_release_evidence_archive_v0_1.json');
        $evidence = (array) ($archive['evidence'] ?? []);

        $this->assertSame([
            'production_policy',
            'release_snapshot',
            'import_gate',
            'runtime_gate',
            'all_surface_qa',
            'approval_record',
            'governance_decision_log',
        ], array_keys($evidence));

        foreach ($evidence as $key => $record) {
            $this->assertNotSame('', (string) ($record['path'] ?? ''), (string) $key);
            $this->assertContains($record['status'] ?? null, [
                'present',
                'pass',
                'present_no_production_approval',
            ], (string) $key);
        }
    }

    public function test_required_release_evidence_is_marked_present_without_production_approval(): void
    {
        $archive = $this->archiveJson('big5_v2_release_evidence_archive_v0_1.json');

        $this->assertSame('NO-GO', $archive['production_decision'] ?? null);
        foreach ((array) ($archive['required_release_evidence'] ?? []) as $key => $status) {
            $this->assertSame('present', $status, (string) $key);
        }

        $this->assertProductionDisabled($archive);
        $this->assertFalse((bool) ($archive['reproducibility']['mutable_overwrite_allowed'] ?? true));
    }

    public function test_approval_and_archive_sha256sums_are_reproducible(): void
    {
        foreach ([self::APPROVAL_PATH, self::ARCHIVE_PATH] as $basePath) {
            $entries = file(base_path($basePath.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertIsArray($entries, $basePath);
            $this->assertNotSame([], $entries, $basePath);

            foreach ($entries as $entry) {
                $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
                [$expectedHash, $fileName] = explode('  ', $entry, 2);
                $path = base_path($basePath.'/'.$fileName);

                $this->assertFileExists($path);
                $this->assertSame($expectedHash, hash_file('sha256', $path), $fileName);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $document
     */
    private function assertProductionDisabled(array $document): void
    {
        $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
        $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        $this->assertFalse((bool) ($document['production_rollout_enabled'] ?? true));
    }

    /**
     * @return array<string,mixed>
     */
    private function archiveJson(string $fileName): array
    {
        $decoded = json_decode(
            (string) file_get_contents(base_path(self::ARCHIVE_PATH.'/'.$fileName)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
