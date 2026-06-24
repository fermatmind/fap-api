<?php

declare(strict_types=1);

namespace App\Services\Career\AiImpactAssets;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerJobAiImpactAsset;
use App\Models\Occupation;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class CareerAiImpactAssetImportService
{
    public const IMPORTER_VERSION = 'career_ai_impact_asset_v5_staging_preview_importer_v0.1';

    public function __construct(
        private readonly CareerAiImpactAssetPreviewService $previewService,
        private readonly CareerRuntimePublishProjectionVisibility $runtimeProjection,
        private readonly PublicCareerAuthorityResponseCache $authorityResponseCache,
        private readonly CareerAiImpactAssetImportStateMachine $stateMachine,
    ) {}

    /**
     * @param  list<string>|null  $requestedSlugs
     * @return array<string, mixed>
     */
    public function validateFile(
        string $file,
        ?array $requestedSlugs = null,
        ?string $expectedSha256 = null,
        bool $allSlugsFromFile = false,
        bool $enforcePreviewAllowlist = true,
    ): array {
        $report = $this->baseReport($file, $requestedSlugs, $allSlugsFromFile, $enforcePreviewAllowlist);
        $errors = [];

        if (! is_file($file) || ! is_readable($file)) {
            return array_merge($report, [
                'decision' => 'fail',
                'errors' => ['Source JSONL file is missing or unreadable.'],
            ]);
        }

        $rowsByKey = [];
        $duplicateKeys = [];
        $parseErrors = [];
        $slugsFromFile = [];
        $totalLines = 0;
        $lineNumber = 0;

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return array_merge($report, [
                'decision' => 'fail',
                'errors' => ['Source JSONL file could not be opened.'],
            ]);
        }

        while (($line = fgets($handle)) !== false) {
            $lineNumber++;
            if (trim($line) === '') {
                continue;
            }

            $totalLines++;
            try {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $parseErrors[] = "Line {$lineNumber}: invalid JSON.";

                continue;
            }

            if (! is_array($row)) {
                $parseErrors[] = "Line {$lineNumber}: row must be an object.";

                continue;
            }

            $slug = $this->previewService->normalizeSlug((string) ($row['slug'] ?? ''));
            $locale = $this->previewService->normalizeLocale((string) ($row['locale'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $slugsFromFile[$slug] = true;
            $key = $slug.'|'.$locale;
            if (isset($rowsByKey[$key])) {
                $errors[] = "{$slug}/{$locale}: duplicate asset row.";
                $duplicateKeys[] = $key;

                continue;
            }

            $rowsByKey[$key] = [
                'line' => $lineNumber,
                'row' => $row,
            ];
        }

        fclose($handle);

        foreach ($parseErrors as $parseError) {
            $errors[] = $parseError;
        }

        $targetSlugs = $allSlugsFromFile
            ? array_values(array_keys($slugsFromFile))
            : $this->targetSlugs($requestedSlugs, $errors, $enforcePreviewAllowlist);

        $sourceFileSha256 = hash_file('sha256', $file) ?: null;
        $expectedSha256 = $this->normalizeSha256($expectedSha256);
        if ($expectedSha256 !== null && $sourceFileSha256 !== $expectedSha256) {
            $errors[] = 'Source JSONL SHA-256 does not match expected artifact SHA.';
        }

        $validatedRows = [];
        foreach ($targetSlugs as $slug) {
            foreach (['zh-CN', 'en'] as $locale) {
                $key = $slug.'|'.$locale;
                if (! isset($rowsByKey[$key])) {
                    $errors[] = "{$slug}/{$locale}: required preview row missing.";

                    continue;
                }

                $row = $rowsByKey[$key]['row'];
                foreach ($this->rowErrors($row, $slug, $locale) as $rowError) {
                    $errors[] = "{$slug}/{$locale}: {$rowError}";
                }

                $validatedRows[] = $row;
            }
        }

        $authorityReport = $this->careerJobBundleAuthorityReport($targetSlugs);
        foreach ($authorityReport['rows'] as $authorityRow) {
            $authorityErrors = is_array($authorityRow['errors'] ?? null) ? $authorityRow['errors'] : [];
            foreach ($authorityErrors as $authorityError) {
                $errors[] = ((string) ($authorityRow['slug'] ?? 'unknown')).': missing_career_job_bundle_authority: '.(string) $authorityError;
            }
        }

        $projectionSafetyReport = $this->projectionSafetyReport($validatedRows);
        foreach ($projectionSafetyReport['rows'] as $projectionRow) {
            $projectionErrors = is_array($projectionRow['errors'] ?? null) ? $projectionRow['errors'] : [];
            foreach ($projectionErrors as $projectionError) {
                $errors[] = ((string) ($projectionRow['slug'] ?? 'unknown')).'/'.((string) ($projectionRow['locale'] ?? 'unknown')).': ai_impact_projection_gate: '.(string) $projectionError;
            }
        }

        return array_merge($report, [
            'mode' => 'dry_run',
            'decision' => $errors === [] ? 'pass' : 'fail',
            'total_jsonl_lines' => $totalLines,
            'target_slug_count' => count($targetSlugs),
            'validated_preview_rows' => count($validatedRows),
            'expected_preview_rows' => count($targetSlugs) * 2,
            'source_file_sha256' => $sourceFileSha256,
            'expected_source_file_sha256' => $expectedSha256,
            'source_file_sha256_match' => $expectedSha256 === null || $sourceFileSha256 === $expectedSha256,
            'duplicate_key_count' => count(array_unique($duplicateKeys)),
            'duplicate_keys' => array_values(array_unique($duplicateKeys)),
            'idempotency' => $this->idempotencyReport($sourceFileSha256, $targetSlugs),
            'rollback_policy' => $this->rollbackPolicy(),
            'state_machine' => $this->stateMachine->report(),
            'target_slugs' => $targetSlugs,
            'all_slugs_from_file' => $allSlugsFromFile,
            'preview_allowlist_enforced' => $enforcePreviewAllowlist,
            'career_job_bundle_authority' => $authorityReport,
            'projection_safety_gate' => $projectionSafetyReport,
            'production_import_allowed' => false,
            'staging_write_performed' => false,
            'errors' => $errors,
        ]);
    }

    /**
     * @param  list<string>|null  $requestedSlugs
     * @return array<string, mixed>
     */
    public function importStagingPreview(
        string $file,
        ?array $requestedSlugs = null,
        ?string $expectedSha256 = null,
        bool $allSlugsFromFile = false,
        string $status = CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
    ): array {
        if ($status !== CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW) {
            return array_merge($this->baseReport($file, $requestedSlugs, $allSlugsFromFile), [
                'mode' => 'write',
                'decision' => 'fail',
                'status' => $status,
                'production_import_allowed' => false,
                'staging_write_performed' => false,
                'errors' => ['Only staging_preview status is supported by this command.'],
            ]);
        }

        $validation = $this->validateFile($file, $requestedSlugs, $expectedSha256, $allSlugsFromFile);
        if (($validation['decision'] ?? null) !== 'pass') {
            return array_merge($validation, [
                'mode' => 'write',
                'status' => $status,
                'staging_write_performed' => false,
                'write_skipped_reason' => 'validation_failed',
            ]);
        }

        $targetSlugs = array_values(array_map('strval', (array) ($validation['target_slugs'] ?? [])));
        $targetKeys = [];
        foreach ($targetSlugs as $slug) {
            foreach (['zh-CN', 'en'] as $locale) {
                $targetKeys[$slug.'|'.$locale] = true;
            }
        }

        $sourceFileSha256 = is_string($validation['source_file_sha256'] ?? null)
            ? (string) $validation['source_file_sha256']
            : null;
        $importRunId = (string) Str::uuid();
        $writtenCount = 0;
        $createdCount = 0;
        $updatedCount = 0;
        $writtenRows = [];

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return array_merge($validation, [
                'mode' => 'write',
                'status' => $status,
                'decision' => 'fail',
                'staging_write_performed' => false,
                'errors' => ['Source JSONL file could not be opened for staging write.'],
            ]);
        }

        while (($line = fgets($handle)) !== false) {
            if (trim($line) === '') {
                continue;
            }

            try {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }

            if (! is_array($row)) {
                continue;
            }

            $slug = $this->previewService->normalizeSlug((string) ($row['slug'] ?? ''));
            $locale = $this->previewService->normalizeLocale((string) ($row['locale'] ?? ''));
            if (! isset($targetKeys[$slug.'|'.$locale])) {
                continue;
            }

            $occupation = Occupation::query()->where('canonical_slug', $slug)->first();
            if (! $occupation instanceof Occupation) {
                continue;
            }

            $assetVersion = CareerJobAiImpactAsset::ASSET_VERSION_V5;
            $existing = CareerJobAiImpactAsset::query()
                ->where('career_job_slug', $slug)
                ->where('locale', $locale)
                ->where('asset_version', $assetVersion)
                ->first();

            $payload = [
                'occupation_id' => $occupation->id,
                'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
                'preview_allowlisted' => true,
                'asset_payload_json' => $row,
                'sources_json' => is_array($row['sources'] ?? null) ? $row['sources'] : null,
                'evidence_used_json' => is_array($row['evidence_used'] ?? null) ? $row['evidence_used'] : null,
                'derived_from_synthesis_json' => is_array($row['derived_from_synthesis'] ?? null) ? $row['derived_from_synthesis'] : null,
                'audit_fields_json' => is_array($row['audit_fields'] ?? null) ? $row['audit_fields'] : null,
                'asset_row_hash' => (string) (($row['audit_fields']['row_hash'] ?? '') ?: hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')),
                'source_artifact_sha256' => $sourceFileSha256,
                'evidence_artifact_sha256' => null,
                'synthesis_artifact_sha256' => null,
                'import_run_id' => $importRunId,
            ];

            CareerJobAiImpactAsset::query()->updateOrCreate(
                [
                    'career_job_slug' => $slug,
                    'locale' => $locale,
                    'asset_version' => $assetVersion,
                ],
                $payload,
            );

            $writtenCount++;
            if ($existing instanceof CareerJobAiImpactAsset) {
                $updatedCount++;
            } else {
                $createdCount++;
            }

            $writtenRows[] = [
                'slug' => $slug,
                'locale' => $locale,
                'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
                'operation' => $existing instanceof CareerJobAiImpactAsset ? 'updated' : 'created',
            ];
        }

        fclose($handle);

        $expectedWriteCount = (int) ($validation['expected_preview_rows'] ?? 0);
        if ($writtenCount !== $expectedWriteCount) {
            return array_merge($validation, [
                'mode' => 'write',
                'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
                'decision' => 'fail',
                'staging_write_performed' => true,
                'production_import_allowed' => false,
                'import_run_id' => $importRunId,
                'written_count' => $writtenCount,
                'created_count' => $createdCount,
                'updated_count' => $updatedCount,
                'written_rows' => $writtenRows,
                'errors' => ["Staging preview write count {$writtenCount} did not match expected row count {$expectedWriteCount}."],
            ]);
        }

        return array_merge($validation, [
            'mode' => 'write',
            'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
            'decision' => 'pass',
            'staging_write_performed' => true,
            'production_import_allowed' => false,
            'import_run_id' => $importRunId,
            'written_count' => $writtenCount,
            'created_count' => $createdCount,
            'updated_count' => $updatedCount,
            'written_rows' => $writtenRows,
            'errors' => [],
        ]);
    }

    /**
     * @param  list<string>|null  $requestedSlugs
     * @return array<string, mixed>
     */
    public function approveReviewedAssets(
        string $file,
        ?array $requestedSlugs = null,
        ?string $expectedSha256 = null,
        bool $allSlugsFromFile = false,
        string $approvalManifestFile = '',
        ?string $expectedApprovalManifestSha256 = null,
        string $editorialReviewReportFile = '',
        ?string $expectedEditorialReviewSha256 = null,
        bool $confirmed = false,
    ): array {
        $baseReport = $this->baseReport($file, $requestedSlugs, $allSlugsFromFile);
        if (! $confirmed) {
            return array_merge($baseReport, [
                'mode' => 'approved_transition',
                'status' => CareerJobAiImpactAsset::STATUS_APPROVED,
                'decision' => 'fail',
                'approved_transition_performed' => false,
                'production_import_allowed' => false,
                'production_import_performed' => false,
                'errors' => ['--confirm-approved-transition is required to mark AI impact assets as approved.'],
            ]);
        }

        $validation = $this->validateFile($file, $requestedSlugs, $expectedSha256, $allSlugsFromFile);
        if (($validation['decision'] ?? null) !== 'pass') {
            return array_merge($validation, [
                'mode' => 'approved_transition',
                'status' => CareerJobAiImpactAsset::STATUS_APPROVED,
                'approved_transition_performed' => false,
                'production_import_performed' => false,
                'write_skipped_reason' => 'validation_failed',
            ]);
        }

        $errors = [];
        $sourceFileSha256 = is_string($validation['source_file_sha256'] ?? null)
            ? (string) $validation['source_file_sha256']
            : null;
        $targetSlugs = array_values(array_map('strval', (array) ($validation['target_slugs'] ?? [])));
        $expectedRows = (int) ($validation['expected_preview_rows'] ?? (count($targetSlugs) * 2));
        $targetSlugCount = count($targetSlugs);

        $approvalArtifact = $this->readJsonArtifact($approvalManifestFile, $expectedApprovalManifestSha256, 'approval manifest', $errors);
        $editorialArtifact = $this->readJsonArtifact($editorialReviewReportFile, $expectedEditorialReviewSha256, 'editorial review report', $errors);
        $approvalManifest = is_array($approvalArtifact['payload'] ?? null) ? $approvalArtifact['payload'] : [];
        $editorialReview = is_array($editorialArtifact['payload'] ?? null) ? $editorialArtifact['payload'] : [];

        $this->validateApprovalManifest($approvalManifest, $sourceFileSha256, $expectedRows, $targetSlugCount, $errors);
        $this->validateEditorialReviewReport($editorialReview, $sourceFileSha256, $expectedRows, $targetSlugCount, $errors);

        $assetVersion = CareerJobAiImpactAsset::ASSET_VERSION_V5;
        $targetKeys = [];
        foreach ($targetSlugs as $slug) {
            foreach (['zh-CN', 'en'] as $locale) {
                $targetKeys[$slug.'|'.$locale] = ['slug' => $slug, 'locale' => $locale];
            }
        }

        $existingRows = CareerJobAiImpactAsset::query()
            ->where('asset_version', $assetVersion)
            ->whereIn('career_job_slug', $targetSlugs)
            ->whereIn('locale', ['zh-CN', 'en'])
            ->get();

        $existingByKey = [];
        foreach ($existingRows as $asset) {
            $existingByKey[$asset->career_job_slug.'|'.$asset->locale] = $asset;
        }

        $rollbackRows = [];
        $previousStatusCounts = [];
        $approvableStatuses = [
            CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
            CareerJobAiImpactAsset::STATUS_EDITORIAL_REVIEW,
            CareerJobAiImpactAsset::STATUS_APPROVED,
        ];

        foreach ($targetKeys as $key => $target) {
            $asset = $existingByKey[$key] ?? null;
            if (! $asset instanceof CareerJobAiImpactAsset) {
                $errors[] = "{$target['slug']}/{$target['locale']}: approved transition requires an existing staging_preview or editorial_review row.";

                continue;
            }

            $previousStatus = (string) $asset->status;
            $previousStatusCounts[$previousStatus] = (int) ($previousStatusCounts[$previousStatus] ?? 0) + 1;
            if (! in_array($previousStatus, $approvableStatuses, true)) {
                $errors[] = "{$target['slug']}/{$target['locale']}: cannot approve from status {$previousStatus}.";
            }

            if ($previousStatus === CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED) {
                $errors[] = "{$target['slug']}/{$target['locale']}: production_imported rows are immutable in this command.";
            }

            if (is_string($asset->source_artifact_sha256) && $sourceFileSha256 !== null && $asset->source_artifact_sha256 !== $sourceFileSha256) {
                $errors[] = "{$target['slug']}/{$target['locale']}: existing source artifact SHA does not match the approved asset artifact SHA.";
            }

            $rollbackRows[] = [
                'slug' => $target['slug'],
                'locale' => $target['locale'],
                'previous_status' => $previousStatus,
                'new_status' => CareerJobAiImpactAsset::STATUS_APPROVED,
            ];
        }

        $productionRowsBefore = CareerJobAiImpactAsset::query()
            ->where('asset_version', $assetVersion)
            ->where('status', CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED)
            ->count();

        if ($errors !== []) {
            return array_merge($validation, [
                'mode' => 'approved_transition',
                'status' => CareerJobAiImpactAsset::STATUS_APPROVED,
                'decision' => 'fail',
                'approved_transition_performed' => false,
                'production_import_allowed' => false,
                'production_import_performed' => false,
                'approval_manifest_sha256' => $approvalArtifact['sha256'] ?? null,
                'editorial_review_sha256' => $editorialArtifact['sha256'] ?? null,
                'production_rows_touched' => 0,
                'rollback_report' => [
                    'available' => true,
                    'target_key' => ['career_job_slug', 'locale', 'asset_version'],
                    'previous_status_counts' => $previousStatusCounts,
                    'rows' => $rollbackRows,
                ],
                'errors' => $errors,
            ]);
        }

        $importRunId = (string) Str::uuid();
        DB::transaction(function () use ($assetVersion, $targetSlugs, $importRunId): void {
            CareerJobAiImpactAsset::query()
                ->where('asset_version', $assetVersion)
                ->whereIn('career_job_slug', $targetSlugs)
                ->whereIn('locale', ['zh-CN', 'en'])
                ->whereIn('status', [
                    CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
                    CareerJobAiImpactAsset::STATUS_EDITORIAL_REVIEW,
                    CareerJobAiImpactAsset::STATUS_APPROVED,
                ])
                ->update([
                    'status' => CareerJobAiImpactAsset::STATUS_APPROVED,
                    'preview_allowlisted' => true,
                    'import_run_id' => $importRunId,
                    'updated_at' => now(),
                ]);
        });

        $approvedCount = CareerJobAiImpactAsset::query()
            ->where('asset_version', $assetVersion)
            ->whereIn('career_job_slug', $targetSlugs)
            ->whereIn('locale', ['zh-CN', 'en'])
            ->where('status', CareerJobAiImpactAsset::STATUS_APPROVED)
            ->count();
        $productionRowsAfter = CareerJobAiImpactAsset::query()
            ->where('asset_version', $assetVersion)
            ->where('status', CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED)
            ->count();

        $productionRowsTouched = $productionRowsAfter - $productionRowsBefore;
        $decision = $approvedCount === $expectedRows && $productionRowsTouched === 0 ? 'pass' : 'fail';

        return array_merge($validation, [
            'mode' => 'approved_transition',
            'status' => CareerJobAiImpactAsset::STATUS_APPROVED,
            'decision' => $decision,
            'approved_transition_performed' => true,
            'production_import_allowed' => false,
            'production_import_performed' => false,
            'staging_write_performed' => false,
            'import_run_id' => $importRunId,
            'approval_manifest_file' => $approvalManifestFile,
            'approval_manifest_sha256' => $approvalArtifact['sha256'] ?? null,
            'editorial_review_report_file' => $editorialReviewReportFile,
            'editorial_review_sha256' => $editorialArtifact['sha256'] ?? null,
            'approved_count' => $approvedCount,
            'expected_approved_count' => $expectedRows,
            'production_rows_touched' => $productionRowsTouched,
            'rollback_report' => [
                'available' => true,
                'target_key' => ['career_job_slug', 'locale', 'asset_version'],
                'previous_status_counts' => $previousStatusCounts,
                'rows' => $rollbackRows,
                'rollback_sql_intent' => 'Restore each row to previous_status by career_job_slug, locale, asset_version, and import_run_id if rollback is required before production import.',
            ],
            'errors' => $decision === 'pass' ? [] : ['Approved row count or production row touch invariant failed.'],
        ]);
    }

    /**
     * @param  list<string>|null  $requestedSlugs
     * @return array<string, mixed>
     */
    public function importApprovedAssetsToProduction(
        string $file,
        ?array $requestedSlugs = null,
        ?string $expectedSha256 = null,
        bool $allSlugsFromFile = false,
        string $approvalManifestFile = '',
        ?string $expectedApprovalManifestSha256 = null,
        string $editorialReviewReportFile = '',
        ?string $expectedEditorialReviewSha256 = null,
        bool $confirmed = false,
        bool $write = true,
    ): array {
        $baseReport = $this->baseReport($file, $requestedSlugs, $allSlugsFromFile);
        if (! $confirmed) {
            return array_merge($baseReport, [
                'mode' => $write ? 'production_import' : 'production_import_dry_run',
                'status' => CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
                'decision' => 'fail',
                'approved_transition_performed' => false,
                'production_import_allowed' => false,
                'production_import_performed' => false,
                'errors' => ['--confirm-production-import is required to import AI impact assets into production.'],
            ]);
        }

        if ($this->normalizeSha256($expectedSha256) === null) {
            return array_merge($baseReport, [
                'mode' => $write ? 'production_import' : 'production_import_dry_run',
                'status' => CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
                'decision' => 'fail',
                'approved_transition_performed' => false,
                'production_import_allowed' => false,
                'production_import_performed' => false,
                'errors' => ['--expected-sha256 with the approved asset artifact SHA is required for production import.'],
            ]);
        }

        $validation = $this->validateFile($file, $requestedSlugs, $expectedSha256, $allSlugsFromFile, false);
        if (($validation['decision'] ?? null) !== 'pass') {
            return array_merge($validation, [
                'mode' => $write ? 'production_import' : 'production_import_dry_run',
                'status' => CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
                'production_import_allowed' => false,
                'production_import_performed' => false,
                'write_skipped_reason' => 'validation_failed',
            ]);
        }

        $errors = [];
        $sourceFileSha256 = is_string($validation['source_file_sha256'] ?? null)
            ? (string) $validation['source_file_sha256']
            : null;
        $targetSlugs = array_values(array_map('strval', (array) ($validation['target_slugs'] ?? [])));
        $expectedRows = (int) ($validation['expected_preview_rows'] ?? (count($targetSlugs) * 2));
        $targetSlugCount = count($targetSlugs);

        $approvalArtifact = $this->readJsonArtifact($approvalManifestFile, $expectedApprovalManifestSha256, 'approval manifest', $errors);
        $editorialArtifact = $this->readJsonArtifact($editorialReviewReportFile, $expectedEditorialReviewSha256, 'editorial review report', $errors);
        $approvalManifest = is_array($approvalArtifact['payload'] ?? null) ? $approvalArtifact['payload'] : [];
        $editorialReview = is_array($editorialArtifact['payload'] ?? null) ? $editorialArtifact['payload'] : [];

        $this->validateApprovalManifest($approvalManifest, $sourceFileSha256, $expectedRows, $targetSlugCount, $errors);
        $this->validateEditorialReviewReport($editorialReview, $sourceFileSha256, $expectedRows, $targetSlugCount, $errors);

        $assetVersion = CareerJobAiImpactAsset::ASSET_VERSION_V5;
        $targetKeys = [];
        foreach ($targetSlugs as $slug) {
            foreach (['zh-CN', 'en'] as $locale) {
                $targetKeys[$slug.'|'.$locale] = ['slug' => $slug, 'locale' => $locale];
            }
        }

        $existingRows = CareerJobAiImpactAsset::query()
            ->where('asset_version', $assetVersion)
            ->whereIn('career_job_slug', $targetSlugs)
            ->whereIn('locale', ['zh-CN', 'en'])
            ->get();

        $existingByKey = [];
        foreach ($existingRows as $asset) {
            $existingByKey[$asset->career_job_slug.'|'.$asset->locale] = $asset;
        }

        $rollbackRows = [];
        $previousStatusCounts = [];
        foreach ($targetKeys as $key => $target) {
            $asset = $existingByKey[$key] ?? null;
            $previousStatus = $asset instanceof CareerJobAiImpactAsset ? (string) $asset->status : 'missing';
            $previousStatusCounts[$previousStatus] = (int) ($previousStatusCounts[$previousStatus] ?? 0) + 1;

            if ($asset instanceof CareerJobAiImpactAsset) {
                if (! in_array($previousStatus, [
                    CareerJobAiImpactAsset::STATUS_APPROVED,
                    CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
                ], true)) {
                    $errors[] = "{$target['slug']}/{$target['locale']}: production import requires approved source rows or an empty production target, found {$previousStatus}.";
                }

                if (is_string($asset->source_artifact_sha256) && $sourceFileSha256 !== null && $asset->source_artifact_sha256 !== $sourceFileSha256) {
                    $errors[] = "{$target['slug']}/{$target['locale']}: existing source artifact SHA does not match the approved production artifact SHA.";
                }
            }

            $rollbackRows[] = [
                'slug' => $target['slug'],
                'locale' => $target['locale'],
                'previous_status' => $previousStatus,
                'new_status' => CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
            ];
        }

        if ($errors !== []) {
            return array_merge($validation, [
                'mode' => $write ? 'production_import' : 'production_import_dry_run',
                'status' => CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
                'decision' => 'fail',
                'approved_transition_performed' => false,
                'production_import_allowed' => false,
                'production_import_performed' => false,
                'approval_manifest_sha256' => $approvalArtifact['sha256'] ?? null,
                'editorial_review_sha256' => $editorialArtifact['sha256'] ?? null,
                'production_rows_touched' => 0,
                'rollback_report' => [
                    'available' => true,
                    'target_key' => ['career_job_slug', 'locale', 'asset_version'],
                    'previous_status_counts' => $previousStatusCounts,
                    'rows' => $rollbackRows,
                ],
                'errors' => $errors,
            ]);
        }

        if (! $write) {
            return array_merge($validation, [
                'mode' => 'production_import_dry_run',
                'status' => CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
                'decision' => 'pass',
                'approved_transition_performed' => false,
                'production_import_allowed' => true,
                'production_import_performed' => false,
                'staging_write_performed' => false,
                'approval_manifest_file' => $approvalManifestFile,
                'approval_manifest_sha256' => $approvalArtifact['sha256'] ?? null,
                'editorial_review_report_file' => $editorialReviewReportFile,
                'editorial_review_sha256' => $editorialArtifact['sha256'] ?? null,
                'expected_written_count' => $expectedRows,
                'production_rows_touched' => 0,
                'rollback_report' => [
                    'available' => true,
                    'target_key' => ['career_job_slug', 'locale', 'asset_version'],
                    'previous_status_counts' => $previousStatusCounts,
                    'rows' => $rollbackRows,
                    'rollback_sql_intent' => 'No rows were changed during dry-run. If executed, rollback restores each row to previous_status, or deletes rows whose previous_status was missing, by career_job_slug, locale, asset_version, and import_run_id.',
                ],
                'errors' => [],
            ]);
        }

        $targetRows = $this->targetRowsFromFile($file, array_keys($targetKeys));
        $importRunId = (string) Str::uuid();
        $writtenCount = 0;
        $createdCount = 0;
        $updatedCount = 0;
        $writtenRows = [];

        DB::transaction(function () use (
            $targetRows,
            $assetVersion,
            $sourceFileSha256,
            $importRunId,
            &$writtenCount,
            &$createdCount,
            &$updatedCount,
            &$writtenRows
        ): void {
            foreach ($targetRows as $key => $row) {
                $slug = $this->previewService->normalizeSlug((string) ($row['slug'] ?? ''));
                $locale = $this->previewService->normalizeLocale((string) ($row['locale'] ?? ''));
                $occupation = Occupation::query()->where('canonical_slug', $slug)->first();
                if (! $occupation instanceof Occupation) {
                    continue;
                }

                $existing = CareerJobAiImpactAsset::query()
                    ->where('career_job_slug', $slug)
                    ->where('locale', $locale)
                    ->where('asset_version', $assetVersion)
                    ->first();

                CareerJobAiImpactAsset::query()->updateOrCreate(
                    [
                        'career_job_slug' => $slug,
                        'locale' => $locale,
                        'asset_version' => $assetVersion,
                    ],
                    [
                        'occupation_id' => $occupation->id,
                        'status' => CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
                        'preview_allowlisted' => false,
                        'asset_payload_json' => $row,
                        'sources_json' => is_array($row['sources'] ?? null) ? $row['sources'] : null,
                        'evidence_used_json' => is_array($row['evidence_used'] ?? null) ? $row['evidence_used'] : null,
                        'derived_from_synthesis_json' => is_array($row['derived_from_synthesis'] ?? null) ? $row['derived_from_synthesis'] : null,
                        'audit_fields_json' => is_array($row['audit_fields'] ?? null) ? $row['audit_fields'] : null,
                        'asset_row_hash' => (string) (($row['audit_fields']['row_hash'] ?? '') ?: hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')),
                        'source_artifact_sha256' => $sourceFileSha256,
                        'evidence_artifact_sha256' => null,
                        'synthesis_artifact_sha256' => null,
                        'import_run_id' => $importRunId,
                    ],
                );

                $writtenCount++;
                if ($existing instanceof CareerJobAiImpactAsset) {
                    $updatedCount++;
                } else {
                    $createdCount++;
                }

                $writtenRows[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'status' => CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
                    'operation' => $existing instanceof CareerJobAiImpactAsset ? 'updated' : 'created',
                    'key' => $key,
                ];
            }
        });

        $productionImportedCount = CareerJobAiImpactAsset::query()
            ->where('asset_version', $assetVersion)
            ->whereIn('career_job_slug', $targetSlugs)
            ->whereIn('locale', ['zh-CN', 'en'])
            ->where('status', CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED)
            ->count();
        $decision = $writtenCount === $expectedRows && $productionImportedCount === $expectedRows ? 'pass' : 'fail';

        return array_merge($validation, [
            'mode' => 'production_import',
            'status' => CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
            'decision' => $decision,
            'approved_transition_performed' => false,
            'production_import_allowed' => true,
            'production_import_performed' => true,
            'staging_write_performed' => false,
            'import_run_id' => $importRunId,
            'approval_manifest_file' => $approvalManifestFile,
            'approval_manifest_sha256' => $approvalArtifact['sha256'] ?? null,
            'editorial_review_report_file' => $editorialReviewReportFile,
            'editorial_review_sha256' => $editorialArtifact['sha256'] ?? null,
            'written_count' => $writtenCount,
            'created_count' => $createdCount,
            'updated_count' => $updatedCount,
            'expected_written_count' => $expectedRows,
            'production_imported_count' => $productionImportedCount,
            'production_rows_touched' => $writtenCount,
            'written_rows' => $writtenRows,
            'rollback_report' => [
                'available' => true,
                'target_key' => ['career_job_slug', 'locale', 'asset_version'],
                'previous_status_counts' => $previousStatusCounts,
                'rows' => $rollbackRows,
                'rollback_sql_intent' => 'Restore each row to previous_status, or delete rows whose previous_status was missing, by career_job_slug, locale, asset_version, and import_run_id if rollback is required.',
            ],
            'errors' => $decision === 'pass' ? [] : ['Production import row count invariant failed.'],
        ]);
    }

    /**
     * @param  list<string>  $targetSlugs
     * @return array{checked_slug_count: int, ready_slug_count: int, rows: list<array<string, mixed>>}
     */
    private function careerJobBundleAuthorityReport(array $targetSlugs): array
    {
        $rows = [];
        $readyCount = 0;

        foreach ($targetSlugs as $slug) {
            $occupationExists = Occupation::query()->where('canonical_slug', $slug)->exists();
            $enItem = $this->runtimeProjection->itemForSlug($slug, 'en');
            $zhItem = $this->runtimeProjection->itemForSlug($slug, 'zh-CN');
            $projectionItem = is_array($enItem) ? $enItem : (is_array($zhItem) ? $zhItem : null);
            $projectionExists = is_array($projectionItem);
            $publicResolutionType = $projectionExists ? (string) ($projectionItem['public_resolution_type'] ?? '') : null;
            $detailRouteEnabled = $projectionExists ? (($projectionItem['detail_route_enabled'] ?? false) === true) : false;
            $releaseGatePass = $projectionExists ? (($projectionItem['release_gate_pass'] ?? false) === true) : false;
            $detailApiZh = $this->authorityResponseCache->jobDetailPayload($slug, 'zh-CN') !== null;
            $detailApiEn = $this->authorityResponseCache->jobDetailPayload($slug, 'en') !== null;
            $errors = [];

            if (! $occupationExists) {
                $errors[] = 'occupation row is missing.';
            }

            if (! $projectionExists) {
                $errors[] = 'runtime publish projection item is missing.';
            }

            if ($projectionExists && $publicResolutionType !== CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB) {
                $errors[] = 'public_resolution_type must be public_canonical_job.';
            }

            if ($projectionExists && ! $detailRouteEnabled) {
                $errors[] = 'detail_route_enabled must be true.';
            }

            if ($projectionExists && ! $releaseGatePass) {
                $errors[] = 'release_gate_pass must be true.';
            }

            if (! $detailApiZh) {
                $errors[] = 'zh-CN career job detail API is not ready.';
            }

            if (! $detailApiEn) {
                $errors[] = 'en career job detail API is not ready.';
            }

            if ($errors === []) {
                $readyCount++;
            }

            $rows[] = [
                'slug' => $slug,
                'occupation_row_exists' => $occupationExists,
                'runtime_publish_projection_item_exists' => $projectionExists,
                'public_resolution_type' => $publicResolutionType,
                'public_resolution_type_pass' => $publicResolutionType === CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
                'detail_route_enabled' => $detailRouteEnabled,
                'release_gate_pass' => $releaseGatePass,
                'detail_api_zh_CN_200' => $detailApiZh,
                'detail_api_en_200' => $detailApiEn,
                'ready' => $errors === [],
                'errors' => $errors,
            ];
        }

        return [
            'checked_slug_count' => count($targetSlugs),
            'ready_slug_count' => $readyCount,
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{checked_row_count: int, ready_row_count: int, rows: list<array<string, mixed>>}
     */
    private function projectionSafetyReport(array $rows): array
    {
        $reportRows = [];
        $readyCount = 0;

        foreach ($rows as $row) {
            $errors = [];
            $readerText = $this->readerText($row);

            foreach (['evidence_id', 'source_id', 'row_hash', 'audit_fields', 'search_projection', 'candidate_only', 'not_runtime_seo'] as $fragment) {
                if (str_contains($readerText, $fragment)) {
                    $errors[] = "reader-facing text must not leak {$fragment}.";
                }
            }

            foreach ([
                'predicts job loss',
                'job-loss risk',
                'wage-loss risk',
                'career disappearance',
                'AI-proof',
                'will replace',
                '岗位会消失',
                '收入会下降',
                '不会被 AI 影响',
            ] as $fragment) {
                if (str_contains($readerText, $fragment)) {
                    $errors[] = "reader-facing text contains blocked AI outcome framing: {$fragment}.";
                }
            }

            if ($errors === []) {
                $readyCount++;
            }

            $reportRows[] = [
                'slug' => $this->previewService->normalizeSlug((string) ($row['slug'] ?? '')),
                'locale' => $this->previewService->normalizeLocale((string) ($row['locale'] ?? '')),
                'ready' => $errors === [],
                'errors' => $errors,
            ];
        }

        return [
            'checked_row_count' => count($rows),
            'ready_row_count' => $readyCount,
            'rows' => $reportRows,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function rowErrors(array $row, string $slug, string $locale): array
    {
        $errors = [];

        if (($row['ledger_type'] ?? null) !== 'career-risk-future_asset') {
            $errors[] = 'ledger_type must be career-risk-future_asset.';
        }

        if (($row['block_type'] ?? null) !== 'career-risk-future-ai-impact') {
            $errors[] = 'block_type must be career-risk-future-ai-impact.';
        }

        $assetVersion = (string) ($row['asset_version'] ?? '');
        if (! str_starts_with($assetVersion, 'career_risk_future_ai_impact_v5')) {
            $errors[] = 'asset_version must be an AI Impact v5 asset version.';
        }

        if ($this->previewService->normalizeSlug((string) ($row['slug'] ?? '')) !== $slug) {
            $errors[] = 'slug mismatch.';
        }

        if ($this->previewService->normalizeLocale((string) ($row['locale'] ?? '')) !== $locale) {
            $errors[] = 'locale mismatch.';
        }

        foreach (['occupation', 'ai_exposure_score', 'items', 'score_rationale', 'sources', 'evidence_used', 'derived_from_synthesis', 'audit_fields'] as $requiredKey) {
            if (! array_key_exists($requiredKey, $row)) {
                $errors[] = "{$requiredKey} is required.";
            }
        }

        if (array_key_exists('search_projection', $row)) {
            $errors[] = 'search_projection must remain in a separate candidate file and not be embedded in the reader asset.';
        }

        $score = is_array($row['ai_exposure_score'] ?? null) ? $row['ai_exposure_score'] : [];
        $scoreValue = $score['score_1_to_10'] ?? null;
        if (! is_int($scoreValue) || $scoreValue < 1 || $scoreValue > 10) {
            $errors[] = 'ai_exposure_score.score_1_to_10 must be an integer from 1 to 10.';
        }

        if (! in_array((string) ($score['exposure_type'] ?? ''), ['augmentation', 'automation', 'mixed', 'unknown'], true)) {
            $errors[] = 'ai_exposure_score.exposure_type is invalid.';
        }

        if (! in_array((string) ($score['confidence'] ?? ''), ['low', 'medium', 'high'], true)) {
            $errors[] = 'ai_exposure_score.confidence is invalid.';
        }

        $items = is_array($row['items'] ?? null) ? $row['items'] : [];
        foreach (['most_ai_exposed_workflows', 'human_accountability_anchors', 'how_to_prepare'] as $listKey) {
            if (! is_array($items[$listKey] ?? null) || count($items[$listKey]) < 1) {
                $errors[] = "items.{$listKey} must contain at least one reader-facing item.";
            }
        }

        if (! is_array($items['reader_boundary'] ?? null)) {
            $errors[] = 'items.reader_boundary must be present.';
        }

        $auditFields = is_array($row['audit_fields'] ?? null) ? $row['audit_fields'] : [];
        if (! is_string($auditFields['row_hash'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', (string) $auditFields['row_hash'])) {
            $errors[] = 'audit_fields.row_hash must be a SHA-256 string.';
        }

        return $errors;
    }

    /**
     * @param  list<string>|null  $requestedSlugs
     * @return array<string, mixed>
     */
    private function baseReport(
        string $file,
        ?array $requestedSlugs,
        bool $allSlugsFromFile,
        bool $enforcePreviewAllowlist = true,
    ): array {
        return [
            'importer_version' => self::IMPORTER_VERSION,
            'asset_version' => 'career_risk_future_ai_impact_v5',
            'status_policy' => 'dry_run_staging_preview_approved_transition_or_explicit_production_import',
            'production_import_allowed' => false,
            'production_import_gate' => [
                'allowed' => true,
                'required_from_status' => 'approved',
                'current_command_supports_production_import' => true,
                'requires_confirm_production_import' => true,
                'requires_exact_source_sha256' => true,
                'requires_editorial_approval_artifacts' => true,
            ],
            'staging_write_supported' => true,
            'source_file' => $file,
            'requested_slugs' => $requestedSlugs,
            'all_slugs_from_file' => $allSlugsFromFile,
            'preview_allowlist_enforced' => $enforcePreviewAllowlist,
        ];
    }

    /**
     * @param  list<string>  $errors
     * @return array{payload: array<string, mixed>|null, sha256: string|null}
     */
    private function readJsonArtifact(string $file, ?string $expectedSha256, string $label, array &$errors): array
    {
        $file = trim($file);
        if ($file === '') {
            $errors[] = ucfirst($label).' file is required.';

            return ['payload' => null, 'sha256' => null];
        }

        if (! is_file($file) || ! is_readable($file)) {
            $errors[] = ucfirst($label).' file is missing or unreadable.';

            return ['payload' => null, 'sha256' => null];
        }

        $sha256 = hash_file('sha256', $file) ?: null;
        $expectedSha256 = $this->normalizeSha256($expectedSha256);
        if ($expectedSha256 !== null && $sha256 !== $expectedSha256) {
            $errors[] = ucfirst($label).' SHA-256 does not match expected artifact SHA.';
        }

        try {
            $payload = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $errors[] = ucfirst($label).' file must be valid JSON.';

            return ['payload' => null, 'sha256' => $sha256];
        }

        if (! is_array($payload)) {
            $errors[] = ucfirst($label).' JSON must be an object.';

            return ['payload' => null, 'sha256' => $sha256];
        }

        return ['payload' => $payload, 'sha256' => $sha256];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $errors
     */
    private function validateApprovalManifest(array $manifest, ?string $assetSha256, int $expectedRows, int $expectedSlugs, array &$errors): void
    {
        if ($manifest === []) {
            return;
        }

        if (($manifest['final_conclusion'] ?? null) !== 'AI_IMPACT_V5_EDITORIAL_REVIEW_PASS') {
            $errors[] = 'Approval manifest final_conclusion must be AI_IMPACT_V5_EDITORIAL_REVIEW_PASS.';
        }

        if (($manifest['next_allowed_transition'] ?? null) !== CareerJobAiImpactAsset::STATUS_APPROVED) {
            $errors[] = 'Approval manifest next_allowed_transition must be approved.';
        }

        if ((bool) ($manifest['production_import_allowed'] ?? true)) {
            $errors[] = 'Approval manifest must not allow production import.';
        }

        if ((int) ($manifest['approved_rows'] ?? -1) !== $expectedRows) {
            $errors[] = "Approval manifest approved_rows must be {$expectedRows}.";
        }

        if ((int) ($manifest['rejected_rows'] ?? -1) !== 0) {
            $errors[] = 'Approval manifest rejected_rows must be 0.';
        }

        if ((int) ($manifest['unique_slugs'] ?? -1) !== $expectedSlugs) {
            $errors[] = "Approval manifest unique_slugs must be {$expectedSlugs}.";
        }

        $manifestAssetSha = $this->normalizeSha256((string) ($manifest['final_repaired_asset_sha256'] ?? ''))
            ?? $this->normalizeSha256((string) (($manifest['required_for_approved_transition']['asset_sha256'] ?? '') ?: ''));
        if ($assetSha256 !== null && $manifestAssetSha !== $assetSha256) {
            $errors[] = 'Approval manifest asset SHA does not match the source JSONL SHA.';
        }

        $required = is_array($manifest['required_for_approved_transition'] ?? null)
            ? $manifest['required_for_approved_transition']
            : [];
        if ($required !== []) {
            if ((int) ($required['row_count'] ?? -1) !== $expectedRows) {
                $errors[] = "Approval manifest required row_count must be {$expectedRows}.";
            }

            if ((int) ($required['slug_count'] ?? -1) !== $expectedSlugs) {
                $errors[] = "Approval manifest required slug_count must be {$expectedSlugs}.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<string>  $errors
     */
    private function validateEditorialReviewReport(array $report, ?string $assetSha256, int $expectedRows, int $expectedSlugs, array &$errors): void
    {
        if ($report === []) {
            return;
        }

        if (($report['final_conclusion'] ?? null) !== 'AI_IMPACT_V5_EDITORIAL_REVIEW_PASS') {
            $errors[] = 'Editorial review final_conclusion must be AI_IMPACT_V5_EDITORIAL_REVIEW_PASS.';
        }

        $metrics = is_array($report['metrics'] ?? null) ? $report['metrics'] : [];
        if ((int) ($metrics['asset_rows'] ?? -1) !== $expectedRows) {
            $errors[] = "Editorial review asset_rows must be {$expectedRows}.";
        }

        if ((int) ($metrics['unique_slugs'] ?? -1) !== $expectedSlugs) {
            $errors[] = "Editorial review unique_slugs must be {$expectedSlugs}.";
        }

        if ((int) ($metrics['findings'] ?? -1) !== 0) {
            $errors[] = 'Editorial review findings must be 0.';
        }

        if ((int) ($metrics['rejected_rows'] ?? -1) !== 0) {
            $errors[] = 'Editorial review rejected_rows must be 0.';
        }

        $inputs = is_array($report['inputs'] ?? null) ? $report['inputs'] : [];
        $reportAssetSha = $this->normalizeSha256((string) (($inputs['final_repaired_asset_sha256'] ?? '') ?: ''));
        if ($assetSha256 !== null && $reportAssetSha !== $assetSha256) {
            $errors[] = 'Editorial review asset SHA does not match the source JSONL SHA.';
        }

        $guarantees = is_array($report['guarantees'] ?? null) ? $report['guarantees'] : [];
        if ((bool) ($guarantees['no_production_import'] ?? false) !== true) {
            $errors[] = 'Editorial review must guarantee no production import.';
        }
    }

    /**
     * @param  list<string>|null  $requestedSlugs
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function targetSlugs(?array $requestedSlugs, array &$errors, bool $enforcePreviewAllowlist = true): array
    {
        $allowlist = $this->previewService->previewSlugs();
        $target = $requestedSlugs === null || $requestedSlugs === []
            ? $allowlist
            : array_values(array_unique(array_map(
                fn (string $slug): string => $this->previewService->normalizeSlug($slug),
                $requestedSlugs
            )));

        if ($enforcePreviewAllowlist) {
            foreach ($target as $slug) {
                if (! in_array($slug, $allowlist, true)) {
                    $errors[] = "{$slug}: slug is not in the AI Impact staging preview allowlist.";
                }
            }
        }

        return $target;
    }

    /**
     * @param  list<string>  $targetSlugs
     * @return array<string, mixed>
     */
    private function idempotencyReport(?string $sourceFileSha256, array $targetSlugs): array
    {
        return [
            'target_key' => ['career_job_slug', 'locale', 'asset_version'],
            'source_file_sha256' => $sourceFileSha256,
            'target_slug_count' => count($targetSlugs),
            'expected_row_count' => count($targetSlugs) * 2,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rollbackPolicy(): array
    {
        return [
            'dry_run_writes_database' => false,
            'staging_preview_write_supported_in_this_pr' => true,
            'production_import_requires_approved_status' => true,
            'production_import_requires_exact_artifact_sha' => true,
            'production_import_requires_explicit_confirmation' => true,
        ];
    }

    /**
     * @param  list<string>  $targetKeys
     * @return array<string, array<string, mixed>>
     */
    private function targetRowsFromFile(string $file, array $targetKeys): array
    {
        $targetKeyMap = array_fill_keys($targetKeys, true);
        $rows = [];
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return $rows;
        }

        while (($line = fgets($handle)) !== false) {
            if (trim($line) === '') {
                continue;
            }

            try {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }

            if (! is_array($row)) {
                continue;
            }

            $slug = $this->previewService->normalizeSlug((string) ($row['slug'] ?? ''));
            $locale = $this->previewService->normalizeLocale((string) ($row['locale'] ?? ''));
            $key = $slug.'|'.$locale;
            if (isset($targetKeyMap[$key])) {
                $rows[$key] = $row;
            }
        }

        fclose($handle);

        return $rows;
    }

    private function normalizeSha256(?string $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{64}$/', $normalized) === 1 ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function readerText(array $row): string
    {
        $scoreRationale = is_array($row['score_rationale'] ?? null) ? $row['score_rationale'] : null;
        if (is_array($scoreRationale)) {
            unset($scoreRationale['source_ids']);
            unset($scoreRationale['evidence_ids']);
        }

        $readerKeys = [
            'summary' => $row['summary'] ?? null,
            'items' => $row['items'] ?? null,
            'score_rationale' => $scoreRationale,
        ];

        return json_encode($readerKeys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
