<?php

declare(strict_types=1);

namespace App\Services\Career\PageAssemblyAssets;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerJobPageAssemblyAsset;
use App\Models\Occupation;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class CareerPageAssemblyImportService
{
    public const IMPORTER_VERSION = 'career_page_assembly_v1_staging_preview_importer_v0.1';

    public function __construct(
        private readonly CareerPageAssemblyPreviewService $previewService,
        private readonly CareerRuntimePublishProjectionVisibility $runtimeProjection,
        private readonly PublicCareerAuthorityResponseCache $authorityResponseCache,
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

        $sourceSha = hash_file('sha256', $file) ?: null;
        if ($expectedSha256 !== null && $this->normalizeSha256($expectedSha256) !== $sourceSha) {
            $errors[] = 'Source JSONL SHA-256 does not match --expected-sha256.';
        }

        [$rowsByKey, $slugsFromFile, $parseErrors, $duplicateKeys, $totalLines] = $this->readRowsByKey($file);
        foreach ($parseErrors as $parseError) {
            $errors[] = $parseError;
        }
        foreach ($duplicateKeys as $duplicateKey) {
            $errors[] = "Duplicate slug/locale row: {$duplicateKey}.";
        }

        $targetSlugs = $allSlugsFromFile
            ? array_values(array_unique($slugsFromFile))
            : $this->targetSlugs($requestedSlugs, $errors, $enforcePreviewAllowlist);

        $targetKeys = [];
        foreach ($targetSlugs as $slug) {
            $targetKeys[] = $slug.'|zh-CN';
            $targetKeys[] = $slug.'|en';
        }

        $validatedRows = [];
        foreach ($targetKeys as $targetKey) {
            $row = $rowsByKey[$targetKey] ?? null;
            if (! is_array($row)) {
                $errors[] = "{$targetKey}: required preview row missing.";

                continue;
            }

            [$slug, $locale] = explode('|', $targetKey, 2);
            foreach ($this->rowErrors($row, $slug, $locale) as $rowError) {
                $errors[] = "{$targetKey}: {$rowError}";
            }
            $validatedRows[] = $row;
        }

        $authorityReport = $this->careerJobBundleAuthorityReport($targetSlugs);
        foreach ($authorityReport['rows'] as $authorityRow) {
            foreach ((array) ($authorityRow['errors'] ?? []) as $authorityError) {
                $errors[] = ((string) ($authorityRow['slug'] ?? 'unknown')).': missing_career_job_bundle_authority: '.(string) $authorityError;
            }
        }

        $projectionSafety = $this->projectionSafetyReport($validatedRows);
        foreach ($projectionSafety['rows'] as $projectionRow) {
            foreach ((array) ($projectionRow['errors'] ?? []) as $projectionError) {
                $errors[] = ((string) ($projectionRow['slug'] ?? 'unknown')).'/'.((string) ($projectionRow['locale'] ?? 'unknown')).': reader_safe_projection: '.(string) $projectionError;
            }
        }

        return array_merge($report, [
            'mode' => 'dry_run',
            'decision' => $errors === [] ? 'pass' : 'fail',
            'source_file_sha256' => $sourceSha,
            'source_file_sha256_match' => $expectedSha256 === null || $this->normalizeSha256($expectedSha256) === $sourceSha,
            'target_slugs' => $targetSlugs,
            'target_slug_count' => count($targetSlugs),
            'total_jsonl_rows' => $totalLines,
            'validated_preview_rows' => count($validatedRows),
            'expected_preview_rows' => count($targetSlugs) * 2,
            'duplicate_key_count' => count($duplicateKeys),
            'career_job_bundle_authority' => $authorityReport,
            'reader_safe_projection' => $projectionSafety,
            'idempotency' => $this->idempotencyReport($sourceSha, $targetSlugs),
            'rollback_policy' => $this->rollbackPolicy(),
            'staging_write_performed' => false,
            'production_import_allowed' => false,
            'production_rows_touched' => 0,
            'runtime_modified' => false,
            'seo_runtime_modified' => false,
            'search_projection_activated' => false,
            'errors' => array_values(array_unique($errors)),
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
        string $status = CareerJobPageAssemblyAsset::STATUS_STAGING_PREVIEW,
    ): array {
        if ($status !== CareerJobPageAssemblyAsset::STATUS_STAGING_PREVIEW) {
            return [
                'mode' => 'invalid',
                'decision' => 'fail',
                'errors' => ['Only staging_preview status is supported by this command.'],
                'production_import_allowed' => false,
            ];
        }

        $validation = $this->validateFile(
            $file,
            $requestedSlugs,
            $expectedSha256,
            $allSlugsFromFile,
            ! $allSlugsFromFile,
        );
        if (($validation['decision'] ?? null) !== 'pass') {
            return array_merge($validation, [
                'mode' => 'write',
                'staging_write_performed' => false,
                'written_count' => 0,
            ]);
        }

        $sourceSha = (string) ($validation['source_file_sha256'] ?? '');
        $targetSlugs = (array) ($validation['target_slugs'] ?? []);
        $targetKeys = [];
        foreach ($targetSlugs as $slug) {
            $targetKeys[] = (string) $slug.'|zh-CN';
            $targetKeys[] = (string) $slug.'|en';
        }
        $rowsByKey = $this->targetRowsFromFile($file, $targetKeys);
        $importRunId = (string) Str::uuid();

        $writtenRows = DB::transaction(function () use ($rowsByKey, $sourceSha, $importRunId): array {
            $written = [];
            foreach ($rowsByKey as $key => $row) {
                $slug = $this->previewService->normalizeSlug((string) ($row['slug'] ?? ''));
                $locale = $this->previewService->normalizeLocale((string) ($row['locale'] ?? ''));
                $occupation = Occupation::query()->where('canonical_slug', $slug)->firstOrFail();
                $asset = CareerJobPageAssemblyAsset::query()->updateOrCreate(
                    [
                        'career_job_slug' => $slug,
                        'locale' => $locale,
                        'asset_version' => CareerJobPageAssemblyAsset::ASSET_VERSION_V1,
                    ],
                    [
                        'occupation_id' => $occupation->id,
                        'status' => CareerJobPageAssemblyAsset::STATUS_STAGING_PREVIEW,
                        'preview_allowlisted' => true,
                        'asset_payload_json' => $row,
                        'block_refs_json' => is_array($row['block_refs'] ?? null) ? $row['block_refs'] : null,
                        'audit_fields_json' => is_array($row['audit_fields'] ?? null) ? $row['audit_fields'] : null,
                        'asset_row_hash' => (string) data_get($row, 'audit_fields.row_hash'),
                        'source_artifact_sha256' => $sourceSha,
                        'import_run_id' => $importRunId,
                    ],
                );
                $written[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'row_id' => $asset->id,
                    'created' => $asset->wasRecentlyCreated,
                ];
            }

            return $written;
        });

        $expectedRows = (int) ($validation['expected_preview_rows'] ?? 0);
        $decision = count($writtenRows) === $expectedRows ? 'pass' : 'fail';

        return array_merge($validation, [
            'mode' => 'write',
            'decision' => $decision,
            'status' => CareerJobPageAssemblyAsset::STATUS_STAGING_PREVIEW,
            'staging_write_performed' => true,
            'production_import_allowed' => false,
            'production_rows_touched' => 0,
            'cms_rows_touched' => 0,
            'runtime_modified' => false,
            'seo_runtime_modified' => false,
            'search_projection_activated' => false,
            'import_run_id' => $importRunId,
            'written_count' => count($writtenRows),
            'expected_written_count' => $expectedRows,
            'created_count' => count(array_filter($writtenRows, static fn (array $row): bool => ($row['created'] ?? false) === true)),
            'updated_count' => count(array_filter($writtenRows, static fn (array $row): bool => ($row['created'] ?? false) !== true)),
            'written_rows' => $writtenRows,
            'errors' => $decision === 'pass' ? [] : ['Staging preview write count invariant failed.'],
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
        $baseReport = $this->baseReport($file, $requestedSlugs, $allSlugsFromFile, ! $allSlugsFromFile);
        if (! $confirmed) {
            return array_merge($baseReport, [
                'mode' => 'approved_transition',
                'status' => CareerJobPageAssemblyAsset::STATUS_APPROVED,
                'decision' => 'fail',
                'approved_transition_performed' => false,
                'production_import_allowed' => false,
                'production_import_performed' => false,
                'errors' => ['--confirm-approved-transition is required to mark page assembly assets as approved.'],
            ]);
        }

        $validation = $this->validateFile(
            $file,
            $requestedSlugs,
            $expectedSha256,
            $allSlugsFromFile,
            ! $allSlugsFromFile,
        );
        if (($validation['decision'] ?? null) !== 'pass') {
            return array_merge($validation, [
                'mode' => 'approved_transition',
                'status' => CareerJobPageAssemblyAsset::STATUS_APPROVED,
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

        $assetVersion = CareerJobPageAssemblyAsset::ASSET_VERSION_V1;
        $targetKeys = [];
        foreach ($targetSlugs as $slug) {
            foreach (['zh-CN', 'en'] as $locale) {
                $targetKeys[$slug.'|'.$locale] = ['slug' => $slug, 'locale' => $locale];
            }
        }

        $existingRows = CareerJobPageAssemblyAsset::query()
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
            CareerJobPageAssemblyAsset::STATUS_STAGING_PREVIEW,
            CareerJobPageAssemblyAsset::STATUS_EDITORIAL_REVIEW,
            CareerJobPageAssemblyAsset::STATUS_APPROVED,
        ];

        foreach ($targetKeys as $key => $target) {
            $asset = $existingByKey[$key] ?? null;
            if (! $asset instanceof CareerJobPageAssemblyAsset) {
                $errors[] = "{$target['slug']}/{$target['locale']}: approved transition requires an existing staging_preview or editorial_review row.";

                continue;
            }

            $previousStatus = (string) $asset->status;
            $previousStatusCounts[$previousStatus] = (int) ($previousStatusCounts[$previousStatus] ?? 0) + 1;
            if (! in_array($previousStatus, $approvableStatuses, true)) {
                $errors[] = "{$target['slug']}/{$target['locale']}: cannot approve from status {$previousStatus}.";
            }

            if ($previousStatus === CareerJobPageAssemblyAsset::STATUS_PRODUCTION_IMPORTED) {
                $errors[] = "{$target['slug']}/{$target['locale']}: production_imported rows are immutable in this command.";
            }

            if (is_string($asset->source_artifact_sha256) && $sourceFileSha256 !== null && $asset->source_artifact_sha256 !== $sourceFileSha256) {
                $errors[] = "{$target['slug']}/{$target['locale']}: existing source artifact SHA does not match the approved asset artifact SHA.";
            }

            $rollbackRows[] = [
                'slug' => $target['slug'],
                'locale' => $target['locale'],
                'previous_status' => $previousStatus,
                'new_status' => CareerJobPageAssemblyAsset::STATUS_APPROVED,
            ];
        }

        $productionRowsBefore = CareerJobPageAssemblyAsset::query()
            ->where('asset_version', $assetVersion)
            ->where('status', CareerJobPageAssemblyAsset::STATUS_PRODUCTION_IMPORTED)
            ->count();

        if ($errors !== []) {
            return array_merge($validation, [
                'mode' => 'approved_transition',
                'status' => CareerJobPageAssemblyAsset::STATUS_APPROVED,
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
            CareerJobPageAssemblyAsset::query()
                ->where('asset_version', $assetVersion)
                ->whereIn('career_job_slug', $targetSlugs)
                ->whereIn('locale', ['zh-CN', 'en'])
                ->whereIn('status', [
                    CareerJobPageAssemblyAsset::STATUS_STAGING_PREVIEW,
                    CareerJobPageAssemblyAsset::STATUS_EDITORIAL_REVIEW,
                    CareerJobPageAssemblyAsset::STATUS_APPROVED,
                ])
                ->update([
                    'status' => CareerJobPageAssemblyAsset::STATUS_APPROVED,
                    'preview_allowlisted' => true,
                    'import_run_id' => $importRunId,
                    'updated_at' => now(),
                ]);
        });

        $approvedCount = CareerJobPageAssemblyAsset::query()
            ->where('asset_version', $assetVersion)
            ->whereIn('career_job_slug', $targetSlugs)
            ->whereIn('locale', ['zh-CN', 'en'])
            ->where('status', CareerJobPageAssemblyAsset::STATUS_APPROVED)
            ->count();
        $productionRowsAfter = CareerJobPageAssemblyAsset::query()
            ->where('asset_version', $assetVersion)
            ->where('status', CareerJobPageAssemblyAsset::STATUS_PRODUCTION_IMPORTED)
            ->count();

        $productionRowsTouched = $productionRowsAfter - $productionRowsBefore;
        $decision = $approvedCount === $expectedRows && $productionRowsTouched === 0 ? 'pass' : 'fail';

        return array_merge($validation, [
            'mode' => 'approved_transition',
            'status' => CareerJobPageAssemblyAsset::STATUS_APPROVED,
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
    private function baseReport(string $file, ?array $requestedSlugs, bool $allSlugsFromFile, bool $enforcePreviewAllowlist): array
    {
        return [
            'importer_version' => self::IMPORTER_VERSION,
            'source_file' => $file,
            'requested_slugs' => $requestedSlugs,
            'all_slugs_from_file' => $allSlugsFromFile,
            'preview_allowlist_enforced' => $enforcePreviewAllowlist,
            'status_policy' => 'dry_run_staging_preview_or_approved_transition',
        ];
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: list<string>, 2: list<string>, 3: list<string>, 4: int}
     */
    private function readRowsByKey(string $file): array
    {
        $rowsByKey = [];
        $slugsFromFile = [];
        $parseErrors = [];
        $duplicateKeys = [];
        $totalLines = 0;
        $lineNumber = 0;
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return [$rowsByKey, $slugsFromFile, ['Source JSONL file could not be opened.'], $duplicateKeys, $totalLines];
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
            if ($slug === '' || ! in_array($locale, ['zh-CN', 'en'], true)) {
                $parseErrors[] = "Line {$lineNumber}: slug and locale are required.";

                continue;
            }
            $key = $slug.'|'.$locale;
            if (isset($rowsByKey[$key])) {
                $duplicateKeys[] = $key;

                continue;
            }
            $rowsByKey[$key] = $row;
            $slugsFromFile[] = $slug;
        }
        fclose($handle);

        return [$rowsByKey, array_values(array_unique($slugsFromFile)), $parseErrors, array_values(array_unique($duplicateKeys)), $totalLines];
    }

    /**
     * @param  list<string>|null  $requestedSlugs
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function targetSlugs(?array $requestedSlugs, array &$errors, bool $enforcePreviewAllowlist): array
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
                    $errors[] = "{$slug}: slug is not in the career content page assembly staging preview allowlist.";
                }
            }
        }

        return $target;
    }

    /**
     * @param  list<string>  $targetSlugs
     * @return array<string, mixed>
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
            $safePayload = $this->previewService->readerSafePayload($row);
            $safeText = json_encode($safePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
            foreach (['audit_fields', 'source_row_hash', 'row_hash', 'block_refs', 'search_projection', 'candidate_only', 'runtime seo', 'json-ld', 'canonical', 'noindex'] as $fragment) {
                if (str_contains(strtolower($safeText), strtolower($fragment))) {
                    $errors[] = "reader-safe projection must not leak {$fragment}.";
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
        if (($row['asset_version'] ?? null) !== CareerJobPageAssemblyAsset::ASSET_VERSION_V1) {
            $errors[] = 'asset_version must be career_page_assembly_v1.';
        }
        if (($row['block_type'] ?? null) !== 'career-page-assembly') {
            $errors[] = 'block_type must be career-page-assembly.';
        }
        if (($row['ledger_type'] ?? null) !== 'career-page-assembly') {
            $errors[] = 'ledger_type must be career-page-assembly.';
        }
        if ($this->previewService->normalizeSlug((string) ($row['slug'] ?? '')) !== $slug) {
            $errors[] = 'slug does not match target slug.';
        }
        if ($this->previewService->normalizeLocale((string) ($row['locale'] ?? '')) !== $locale) {
            $errors[] = 'locale does not match target locale.';
        }
        if (! is_array($row['occupation'] ?? null)) {
            $errors[] = 'occupation object is required.';
        }
        if (! is_array($row['section_order'] ?? null) || $row['section_order'] === []) {
            $errors[] = 'section_order must be a non-empty array.';
        }
        if (! is_array($row['page_sections'] ?? null) || $row['page_sections'] === []) {
            $errors[] = 'page_sections must be a non-empty array.';
        }
        if (! is_array($row['audit_fields'] ?? null) || ! $this->normalizeSha256((string) data_get($row, 'audit_fields.row_hash'))) {
            $errors[] = 'audit_fields.row_hash must be present.';
        }
        if (array_key_exists('search_projection', $row)) {
            $errors[] = 'search_projection must not be embedded in reader asset rows.';
        }

        return $errors;
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
            'approved_transition_supported_in_this_pr' => true,
            'production_import_supported_in_this_pr' => false,
            'production_import_requires_approved_status' => true,
            'rollback_boundary' => 'Rows written by staging_preview or marked approved are scoped by import_run_id and target key.',
        ];
    }

    /**
     * @param  list<string>  $targetKeys
     * @return array<string, array<string, mixed>>
     */
    private function targetRowsFromFile(string $file, array $targetKeys): array
    {
        [$rowsByKey] = $this->readRowsByKey($file);
        $targetKeyMap = array_fill_keys($targetKeys, true);

        return array_intersect_key($rowsByKey, $targetKeyMap);
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

        $allowedQaConclusion = ($manifest['qa_final_conclusion'] ?? null) === 'CAREER_CONTENT_1046_STAGING_EDITORIAL_QA_PASS';
        $allowedFinalConclusion = ($manifest['final_conclusion'] ?? null) === 'CAREER_CONTENT_1046_EDITORIAL_REVIEW_PASS';
        if (! $allowedQaConclusion && ! $allowedFinalConclusion) {
            $errors[] = 'Approval manifest must reference CAREER_CONTENT_1046_STAGING_EDITORIAL_QA_PASS or CAREER_CONTENT_1046_EDITORIAL_REVIEW_PASS.';
        }

        if ((bool) ($manifest['production_import_approved'] ?? false)) {
            $errors[] = 'Approval manifest must not approve production import.';
        }

        if ((bool) ($manifest['production_import_allowed'] ?? false)) {
            $errors[] = 'Approval manifest must not allow production import.';
        }

        if (($manifest['next_allowed_transition'] ?? CareerJobPageAssemblyAsset::STATUS_APPROVED) !== CareerJobPageAssemblyAsset::STATUS_APPROVED) {
            $errors[] = 'Approval manifest next_allowed_transition must be approved when present.';
        }

        $approvedRows = (int) (($manifest['approved_rows'] ?? $manifest['approved_count'] ?? -1));
        if ($approvedRows !== $expectedRows) {
            $errors[] = "Approval manifest approved rows must be {$expectedRows}.";
        }

        $rejectedRows = (int) (($manifest['rejected_rows'] ?? $manifest['rejected_count'] ?? -1));
        if ($rejectedRows !== 0) {
            $errors[] = 'Approval manifest rejected rows must be 0.';
        }

        $slugCount = (int) (($manifest['unique_slugs'] ?? $manifest['slug_count'] ?? -1));
        if ($slugCount !== $expectedSlugs) {
            $errors[] = "Approval manifest slug count must be {$expectedSlugs}.";
        }

        $manifestAssetSha = $this->normalizeSha256((string) ($manifest['final_repaired_asset_sha256'] ?? ''))
            ?? $this->normalizeSha256((string) (($manifest['required_for_approved_transition']['asset_sha256'] ?? '') ?: ''));
        if ($assetSha256 !== null && $manifestAssetSha === null) {
            $errors[] = 'Approval manifest asset SHA is required for approved transition.';
        }

        if ($assetSha256 !== null && $manifestAssetSha !== null && $manifestAssetSha !== $assetSha256) {
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

        if (($report['final_conclusion'] ?? null) !== 'CAREER_CONTENT_1046_EDITORIAL_REVIEW_PASS') {
            $errors[] = 'Editorial review final_conclusion must be CAREER_CONTENT_1046_EDITORIAL_REVIEW_PASS.';
        }

        $metrics = is_array($report['metrics'] ?? null) ? $report['metrics'] : [];
        $rowCount = (int) (($report['approved_rows'] ?? $metrics['asset_rows'] ?? $metrics['expected_locale_rows'] ?? -1));
        if ($rowCount !== $expectedRows) {
            $errors[] = "Editorial review approved or asset rows must be {$expectedRows}.";
        }

        $slugCount = (int) (($metrics['unique_slugs'] ?? $metrics['slug_count'] ?? -1));
        if ($slugCount !== $expectedSlugs) {
            $errors[] = "Editorial review unique slugs must be {$expectedSlugs}.";
        }

        $findings = (int) (($metrics['findings'] ?? $metrics['finding_count'] ?? 0));
        if ($findings !== 0) {
            $errors[] = 'Editorial review findings must be 0.';
        }

        $rejectedRows = (int) (($report['rejected'] ?? $metrics['rejected_rows'] ?? 0));
        if ($rejectedRows !== 0) {
            $errors[] = 'Editorial review rejected rows must be 0.';
        }

        $inputs = is_array($report['inputs'] ?? null) ? $report['inputs'] : [];
        $reportAssetSha = $this->normalizeSha256((string) (($inputs['final_repaired_asset_sha256'] ?? '') ?: ''));
        if ($assetSha256 !== null && $reportAssetSha === null) {
            $errors[] = 'Editorial review asset SHA is required for approved transition.';
        }

        if ($assetSha256 !== null && $reportAssetSha !== null && $reportAssetSha !== $assetSha256) {
            $errors[] = 'Editorial review asset SHA does not match the source JSONL SHA.';
        }

        if ((bool) ($report['production_import_approved'] ?? false)) {
            $errors[] = 'Editorial review must not approve production import.';
        }
    }

    private function normalizeSha256(?string $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{64}$/', $normalized) === 1 ? $normalized : null;
    }
}
