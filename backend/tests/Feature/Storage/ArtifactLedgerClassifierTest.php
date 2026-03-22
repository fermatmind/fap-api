<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\ArtifactLedgerClassifier;
use Tests\TestCase;

final class ArtifactLedgerClassifierTest extends TestCase
{
    /**
     * @return array<string,array{0:array<string,mixed>,1:string,2:list<string>,3:bool,4:bool}>
     */
    private static function classificationCases(): array
    {
        $artifactsRoot = '/tmp/fap-artifact-ledger-tests/app/private/artifacts';
        $contentReleasesRoot = '/tmp/fap-artifact-ledger-tests/app/private/content_releases';

        return [
            'matched_db_and_file' => [
                [
                    'artifact_kind' => 'report_json',
                    'source_path' => 'artifacts/reports/MBTI/attempt-1/report.json',
                    'relative_path' => 'reports/MBTI/attempt-1/report.json',
                    'source_root' => $artifactsRoot,
                    'attempt_id' => 'attempt-1',
                    'scale_code' => 'MBTI',
                    'slot_code' => 'report_json_full',
                    'has_db_row' => true,
                    'has_file' => true,
                ],
                'matched_db_and_file',
                ['db_row_present', 'file_present'],
                false,
                false,
            ],
            'alias_or_legacy_path_and_nohash' => [
                [
                    'artifact_kind' => 'report_pdf',
                    'source_path' => 'private/reports/BIG5/attempt-2/nohash/report_free.pdf',
                    'relative_path' => 'private/reports/BIG5/attempt-2/nohash/report_free.pdf',
                    'source_root' => storage_path('app/private/reports'),
                    'attempt_id' => 'attempt-2',
                    'scale_code' => 'BIG5',
                    'slot_code' => 'report_pdf_free',
                    'manifest_hash' => 'nohash',
                    'has_db_row' => true,
                    'has_file' => true,
                ],
                'alias_or_legacy_path',
                ['legacy_path', 'scale_alias:BIG5', 'manifest_nohash', 'db_row_present', 'file_present'],
                true,
                false,
            ],
            'manual_or_test_owned' => [
                [
                    'artifact_kind' => 'report_json',
                    'source_path' => '/content_releases/release-1/source_pack/report.json',
                    'relative_path' => 'content_releases/release-1/source_pack/report.json',
                    'source_root' => $contentReleasesRoot,
                    'attempt_id' => 'attempt-3',
                    'scale_code' => 'MBTI',
                    'slot_code' => 'report_json_full',
                    'has_db_row' => true,
                    'has_file' => true,
                ],
                'manual_or_test_owned',
                ['manual_or_test_owned', 'db_row_present', 'file_present'],
                false,
                true,
            ],
            'file_only' => [
                [
                    'artifact_kind' => 'report_pdf',
                    'source_path' => 'artifacts/pdf/EQ60/attempt-4/hash123/report_full.pdf',
                    'relative_path' => 'pdf/EQ60/attempt-4/hash123/report_full.pdf',
                    'source_root' => $artifactsRoot,
                    'attempt_id' => 'attempt-4',
                    'scale_code' => 'EQ60',
                    'slot_code' => 'report_pdf_full',
                    'manifest_hash' => 'hash123',
                    'has_file' => true,
                ],
                'file_only',
                ['file_present'],
                false,
                false,
            ],
            'db_only' => [
                [
                    'artifact_kind' => 'report_json',
                    'source_path' => 'artifacts/reports/MBTI/attempt-5/report.json',
                    'relative_path' => 'reports/MBTI/attempt-5/report.json',
                    'source_root' => $artifactsRoot,
                    'attempt_id' => 'attempt-5',
                    'scale_code' => 'MBTI',
                    'slot_code' => 'report_json_free',
                    'has_db_row' => true,
                ],
                'db_only',
                ['db_row_present'],
                false,
                false,
            ],
            'archive_proof_only' => [
                [
                    'artifact_kind' => 'report_pdf',
                    'source_path' => 'artifacts/pdf/MBTI/attempt-6/hash123/report_full.pdf',
                    'relative_path' => 'pdf/MBTI/attempt-6/hash123/report_full.pdf',
                    'source_root' => $artifactsRoot,
                    'attempt_id' => 'attempt-6',
                    'scale_code' => 'MBTI',
                    'slot_code' => 'report_pdf_full',
                    'has_archive_proof' => true,
                ],
                'archive_proof_only',
                ['archive_proof_present'],
                false,
                false,
            ],
        ];
    }

    public function test_classifier_surfaces_alias_nohash_and_ownership_buckets(): void
    {
        foreach (self::classificationCases() as $caseName => [$candidate, $expectedBucket, $expectedReasons, $expectedLegacyAlias, $expectedManualOrTestOwned]) {
            $classified = app(ArtifactLedgerClassifier::class)->classify($candidate);

            $this->assertSame($expectedBucket, $classified['bucket'], $caseName);
            $this->assertSame($expectedLegacyAlias, $classified['legacy_alias'], $caseName);
            $this->assertSame($expectedManualOrTestOwned, $classified['manual_or_test_owned'], $caseName);

            foreach ($expectedReasons as $reason) {
                $this->assertContains($reason, $classified['reasons'], $caseName);
            }
        }
    }
}
