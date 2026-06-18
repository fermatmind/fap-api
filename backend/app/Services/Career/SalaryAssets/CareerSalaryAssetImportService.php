<?php

declare(strict_types=1);

namespace App\Services\Career\SalaryAssets;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerJobSalaryAsset;
use App\Models\Occupation;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class CareerSalaryAssetImportService
{
    public const IMPORTER_VERSION = 'career_salary_asset_v3_6_staging_preview_importer_v0.1';

    public function __construct(
        private readonly CareerSalaryAssetPreviewService $previewService,
        private readonly CareerRuntimePublishProjectionVisibility $runtimeProjection,
        private readonly PublicCareerAuthorityResponseCache $authorityResponseCache,
        private readonly CareerSalaryAssetImportStateMachine $stateMachine,
    ) {}

    /**
     * @param  list<string>|null  $requestedSlugs
     * @return array<string, mixed>
     */
    public function validateFile(string $file, ?array $requestedSlugs = null, ?string $expectedSha256 = null, bool $allSlugsFromFile = false): array
    {
        $report = $this->baseReport($file, $requestedSlugs, $allSlugsFromFile);
        $errors = [];

        if (! is_file($file) || ! is_readable($file)) {
            return array_merge($report, [
                'decision' => 'fail',
                'errors' => ['Source JSONL file is missing or unreadable.'],
            ]);
        }

        $targetSlugs = $allSlugsFromFile ? [] : $this->targetSlugs($requestedSlugs, $errors);
        $slugsInFileOrder = [];
        $rowsByKey = [];
        $duplicateKeys = [];
        $parseErrors = [];
        $rowValidationErrors = [];
        $editorialQualityRows = [];
        $editorialReadyCount = 0;
        $validatedRowCount = 0;
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
            } catch (Throwable $throwable) {
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

            if ($allSlugsFromFile) {
                $slugsInFileOrder[$slug] ??= true;
            } elseif (! in_array($slug, $targetSlugs, true)) {
                continue;
            }

            $key = $slug.'|'.$locale;
            if (isset($rowsByKey[$key])) {
                $errors[] = "{$slug}/{$locale}: duplicate asset row.";
                $duplicateKeys[] = $key;

                continue;
            }

            if ($allSlugsFromFile) {
                foreach ($this->rowErrors($row, $slug, $locale) as $rowError) {
                    $rowValidationErrors[] = "{$slug}/{$locale}: {$rowError}";
                }

                $editorialRow = $this->editorialQualityRowReport($row);
                if (($editorialRow['ready'] ?? false) === true) {
                    $editorialReadyCount++;
                }

                $editorialQualityRows[] = $editorialRow;
                $validatedRowCount++;

                $rowsByKey[$key] = [
                    'line' => $lineNumber,
                ];

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

        foreach ($rowValidationErrors as $rowValidationError) {
            $errors[] = $rowValidationError;
        }

        if ($allSlugsFromFile) {
            $targetSlugs = array_keys($slugsInFileOrder);
        }

        $sourceFileSha256 = hash_file('sha256', $file) ?: null;
        $expectedSha256 = $this->normalizeSha256($expectedSha256);
        if ($expectedSha256 !== null && $sourceFileSha256 !== $expectedSha256) {
            $errors[] = 'Source JSONL SHA-256 does not match expected artifact SHA.';
        }

        foreach ($targetSlugs as $slug) {
            foreach (['zh-CN', 'en'] as $locale) {
                $key = $slug.'|'.$locale;
                if (! isset($rowsByKey[$key])) {
                    $errors[] = "{$slug}/{$locale}: required preview row missing.";

                    continue;
                }

                if (! $allSlugsFromFile) {
                    foreach ($this->rowErrors($rowsByKey[$key]['row'], $slug, $locale) as $rowError) {
                        $errors[] = "{$slug}/{$locale}: {$rowError}";
                    }
                }
            }
        }

        $authorityReport = $this->careerJobBundleAuthorityReport($targetSlugs);
        foreach ($authorityReport['rows'] as $authorityRow) {
            $authorityErrors = is_array($authorityRow['errors'] ?? null) ? $authorityRow['errors'] : [];
            foreach ($authorityErrors as $authorityError) {
                $errors[] = ((string) ($authorityRow['slug'] ?? 'unknown')).': missing_career_job_bundle_authority: '.(string) $authorityError;
            }
        }

        $validatedRows = $allSlugsFromFile ? [] : array_values(array_map(
            static fn (array $entry): array => $entry['row'],
            $rowsByKey
        ));
        $editorialQualityReport = $allSlugsFromFile ? [
            'checked_row_count' => $validatedRowCount,
            'ready_row_count' => $editorialReadyCount,
            'rows' => $editorialQualityRows,
        ] : $this->editorialQualityReport($validatedRows);
        foreach ($editorialQualityReport['rows'] as $editorialRow) {
            $editorialErrors = is_array($editorialRow['errors'] ?? null) ? $editorialRow['errors'] : [];
            foreach ($editorialErrors as $editorialError) {
                $errors[] = ((string) ($editorialRow['slug'] ?? 'unknown')).'/'.((string) ($editorialRow['locale'] ?? 'unknown')).': salary_preview_editorial_gate: '.(string) $editorialError;
            }
        }

        return array_merge($report, [
            'mode' => 'dry_run',
            'decision' => $errors === [] ? 'pass' : 'fail',
            'total_jsonl_lines' => $totalLines,
            'target_slug_count' => count($targetSlugs),
            'validated_preview_rows' => $allSlugsFromFile ? $validatedRowCount : count($validatedRows),
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
            'career_job_bundle_authority' => $authorityReport,
            'editorial_quality_gate' => $editorialQualityReport,
            'errors' => $errors,
            'raw_rows_included' => ! $allSlugsFromFile,
            'rows' => $validatedRows,
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
    private function editorialQualityReport(array $rows): array
    {
        $reportRows = [];
        $readyCount = 0;

        foreach ($rows as $row) {
            $reportRow = $this->editorialQualityRowReport($row);
            if (($reportRow['ready'] ?? false) === true) {
                $readyCount++;
            }

            $reportRows[] = $reportRow;
        }

        return [
            'checked_row_count' => count($rows),
            'ready_row_count' => $readyCount,
            'rows' => $reportRows,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{slug: string, locale: string, ready: bool, errors: list<string>}
     */
    private function editorialQualityRowReport(array $row): array
    {
        $projectedRow = $this->previewService->readerSafePayload($row);
        $errors = $this->editorialQualityErrors($projectedRow);

        return [
            'slug' => $this->previewService->normalizeSlug((string) ($row['slug'] ?? '')),
            'locale' => $this->previewService->normalizeLocale((string) ($row['locale'] ?? '')),
            'ready' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function editorialQualityErrors(array $row): array
    {
        return array_values(array_merge(
            $this->sourceLabelErrors($row),
            $this->salaryDriverErrors($row),
            $this->readerGuidanceErrors($row),
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function sourceLabelErrors(array $row): array
    {
        $sources = is_array($row['sources'] ?? null) ? $row['sources'] : [];
        $errors = [];

        foreach ($sources as $index => $source) {
            if (! is_array($source)) {
                $errors[] = "sources[{$index}] must be an object.";

                continue;
            }

            $name = trim((string) ($source['name'] ?? ''), " \t\n\r\0\x0B/");
            if ($name === '') {
                $errors[] = "sources[{$index}].name must be a reader-safe source label.";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function salaryDriverErrors(array $row): array
    {
        $drivers = is_array($row['salary_drivers'] ?? null) ? $row['salary_drivers'] : [];
        $errors = [];

        if (count($drivers) < 5) {
            $errors[] = 'salary_drivers must contain at least five items.';

            return $errors;
        }

        $descriptions = [];
        foreach ($drivers as $driver) {
            if (! is_array($driver)) {
                continue;
            }

            $descriptions[] = trim((string) ($driver['description'] ?? ''));
        }

        $uniqueDescriptions = array_values(array_unique(array_filter($descriptions)));
        if (count($uniqueDescriptions) < 3) {
            $errors[] = 'salary_drivers must not reuse the same generic description across the row.';
        }

        if ($this->matchesKnownTemplate($descriptions, [
            '薪资会随具体岗位标题、职责范围和相邻岗位口径变化',
            '城市、机构类型、企业规模和预算来源会明显影响招聘报价',
            '初级、独立承担、带团队或负责关键结果时',
            '岗位相关证书、设备、软件、合规或客户责任',
            '排班、现场工作、旺季、风险和交付压力',
            'pay changes when the exact title, responsibility scope, or adjacent role cluster changes',
            'City, employer type, organization size, and budget source can materially change offers',
            'Entry, independent, senior, lead, or accountable roles are priced differently',
            'Relevant licenses, equipment, software, compliance, or client responsibility can change compensation',
            'Shift work, field work, seasonal pressure, risk, and delivery demands can affect bonuses or upper ranges',
        ], 4)) {
            $errors[] = 'salary_drivers still match the default template and need occupation-specific wording.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function readerGuidanceErrors(array $row): array
    {
        $guidance = is_array($row['reader_guidance'] ?? null) ? array_values(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $row['reader_guidance'],
        )) : [];
        $errors = [];

        if (count($guidance) < 4) {
            $errors[] = 'reader_guidance must contain at least four items.';

            return $errors;
        }

        if (count(array_values(array_unique(array_filter($guidance)))) < 3) {
            $errors[] = 'reader_guidance must not reuse the same generic sentence across the row.';
        }

        if ($this->matchesKnownTemplate($guidance, [
            '先确认你看的是否真的是',
            '中国薪资只读作招聘市场样本信号',
            '美国、英国和欧盟来源各有统计口径',
            '比较 offer 时同时看城市、经验、雇主类型',
            'First confirm whether the source is the exact',
            'Read China pay only as recruitment-market evidence',
            'US, UK, and EU references use different source boundaries',
            'Compare offers by location, experience, employer type',
        ], 3)) {
            $errors[] = 'reader_guidance still matches the default template and needs occupation-specific wording.';
        }

        return $errors;
    }

    /**
     * @param  list<string>  $values
     * @param  list<string>  $fragments
     */
    private function matchesKnownTemplate(array $values, array $fragments, int $threshold): bool
    {
        $matchCount = 0;
        foreach ($values as $value) {
            foreach ($fragments as $fragment) {
                if ($fragment !== '' && str_contains($value, $fragment)) {
                    $matchCount++;

                    break;
                }
            }
        }

        return $matchCount >= $threshold;
    }

    /**
     * @param  list<string>|null  $requestedSlugs
     * @return array<string, mixed>
     */
    public function importStagingPreview(string $file, ?array $requestedSlugs = null, ?string $expectedSha256 = null, bool $allSlugsFromFile = false): array
    {
        $report = $this->validateFile($file, $requestedSlugs, $expectedSha256, $allSlugsFromFile);
        if (($report['decision'] ?? null) !== 'pass') {
            return $report;
        }

        $importRunId = (string) Str::uuid();
        $sourceSha = is_string($report['source_file_sha256'] ?? null) ? $report['source_file_sha256'] : null;

        /** @var list<string> $targetSlugs */
        $targetSlugs = is_array($report['target_slugs'] ?? null) ? array_values(array_filter(
            array_map(fn (mixed $slug): string => $this->previewService->normalizeSlug((string) $slug), $report['target_slugs']),
            static fn (string $slug): bool => $slug !== ''
        )) : [];

        /** @var list<array<string, mixed>> $rows */
        $rows = $allSlugsFromFile
            ? $this->rowsForTargetSlugs($file, $targetSlugs)
            : (is_array($report['rows'] ?? null) ? $report['rows'] : []);

        $written = DB::transaction(function () use ($rows, $sourceSha, $importRunId): array {
            $written = [];

            foreach ($rows as $row) {
                $slug = $this->previewService->normalizeSlug((string) ($row['slug'] ?? ''));
                $locale = $this->previewService->normalizeLocale((string) ($row['locale'] ?? ''));
                $occupation = Occupation::query()->where('canonical_slug', $slug)->firstOrFail();
                $auditFields = $this->arrayValue($row, 'audit_fields');
                $existing = CareerJobSalaryAsset::query()
                    ->where('career_job_slug', $slug)
                    ->where('locale', $locale)
                    ->where('asset_version', CareerJobSalaryAsset::ASSET_VERSION_V3_6)
                    ->first();

                if (! $this->stateMachine->canWriteStagingPreviewFrom($existing?->status)) {
                    throw new \RuntimeException("{$slug}/{$locale}: cannot transition salary asset from {$existing?->status} to staging_preview.");
                }

                $asset = CareerJobSalaryAsset::query()->updateOrCreate(
                    [
                        'career_job_slug' => $slug,
                        'locale' => $locale,
                        'asset_version' => CareerJobSalaryAsset::ASSET_VERSION_V3_6,
                    ],
                    [
                        'occupation_id' => $occupation->id,
                        'status' => CareerJobSalaryAsset::STATUS_STAGING_PREVIEW,
                        'preview_allowlisted' => true,
                        'asset_payload_json' => $row,
                        'sources_json' => $this->arrayValue($row, 'sources'),
                        'evidence_used_json' => $this->arrayValue($row, 'evidence_used'),
                        'derived_from_estimate_json' => $this->arrayValue($row, 'derived_from_estimate'),
                        'audit_fields_json' => $auditFields,
                        'asset_row_hash' => (string) ($auditFields['row_hash'] ?? ''),
                        'source_artifact_sha256' => $sourceSha,
                        'evidence_artifact_sha256' => null,
                        'estimate_artifact_sha256' => null,
                        'import_run_id' => $importRunId,
                    ],
                );

                $written[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'row_id' => $asset->id,
                    'created' => $asset->wasRecentlyCreated,
                    'previous_status' => $existing?->status,
                    'new_status' => CareerJobSalaryAsset::STATUS_STAGING_PREVIEW,
                ];
            }

            return $written;
        });

        return array_merge($report, [
            'mode' => 'force_staging_preview',
            'decision' => 'pass',
            'did_write' => count($written) > 0,
            'written_count' => count($written),
            'full_staging_preview_confirmed' => $allSlugsFromFile,
            'import_run_id' => $importRunId,
            'rollback_policy' => $this->rollbackPolicy($importRunId),
            'written_assets' => $written,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function approveStagingPreview(string $approvalManifestFile, ?string $expectedApprovalManifestSha256 = null, bool $apply = false): array
    {
        $approvalManifestSha256 = is_file($approvalManifestFile) ? (hash_file('sha256', $approvalManifestFile) ?: null) : null;
        $rawExpectedApprovalManifestSha256 = trim((string) $expectedApprovalManifestSha256);
        $expectedApprovalManifestSha256 = $this->normalizeSha256($expectedApprovalManifestSha256);
        $errors = [];

        if ($rawExpectedApprovalManifestSha256 !== '' && $expectedApprovalManifestSha256 === null) {
            $errors[] = 'Expected approval manifest SHA-256 must be a 64-character hex digest.';
        }

        if (! is_file($approvalManifestFile) || ! is_readable($approvalManifestFile)) {
            return $this->approvalReport($approvalManifestFile, $approvalManifestSha256, $expectedApprovalManifestSha256, $apply, [
                'Approval manifest file is missing or unreadable.',
            ]);
        }

        if ($expectedApprovalManifestSha256 !== null && $approvalManifestSha256 !== $expectedApprovalManifestSha256) {
            $errors[] = 'Approval manifest SHA-256 does not match expected artifact SHA.';
        }

        try {
            $manifest = json_decode((string) file_get_contents($approvalManifestFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->approvalReport($approvalManifestFile, $approvalManifestSha256, $expectedApprovalManifestSha256, $apply, [
                'Approval manifest is not valid JSON.',
            ]);
        }

        if (! is_array($manifest)) {
            return $this->approvalReport($approvalManifestFile, $approvalManifestSha256, $expectedApprovalManifestSha256, $apply, [
                'Approval manifest root must be an object.',
            ]);
        }

        $sourceAsset = $this->arrayValue($manifest, 'source_asset');
        $sourceAudits = $this->arrayValue($manifest, 'source_audits');
        $gateResults = $this->arrayValue($manifest, 'gate_results');
        $editorialReview = $this->arrayValue($manifest, 'editorial_review');
        $approvedSlugs = is_array($manifest['approved_slugs'] ?? null) ? array_values(array_filter(
            array_map(fn (mixed $slug): string => $this->previewService->normalizeSlug((string) $slug), $manifest['approved_slugs']),
            static fn (string $slug): bool => $slug !== ''
        )) : [];
        $approvedSlugSet = array_values(array_unique($approvedSlugs));

        if (($manifest['artifact_type'] ?? null) !== 'career_salary_1046_editorial_review_approval_manifest') {
            $errors[] = 'approval_manifest.artifact_type mismatch.';
        }
        if (($sourceAsset['row_count'] ?? null) !== 2092) {
            $errors[] = 'approval_manifest.source_asset.row_count must be 2092.';
        }
        if (($sourceAsset['slug_count'] ?? null) !== 1046) {
            $errors[] = 'approval_manifest.source_asset.slug_count must be 1046.';
        }
        $localeCounts = is_array($sourceAsset['locale_counts'] ?? null) ? $sourceAsset['locale_counts'] : [];
        if (($localeCounts['zh-CN'] ?? null) !== 1046 || ($localeCounts['en'] ?? null) !== 1046) {
            $errors[] = 'approval_manifest.source_asset.locale_counts must contain zh-CN=1046 and en=1046.';
        }
        $sourceAssetSha256 = $this->normalizeSha256(is_string($sourceAsset['sha256'] ?? null) ? $sourceAsset['sha256'] : null);
        if ($sourceAssetSha256 === null) {
            $errors[] = 'approval_manifest.source_asset.sha256 must be a SHA-256 digest.';
        }
        foreach ([
            'independent_qa_sha256',
            'staging_api_smoke_sha256',
            'staging_summary_sha256',
        ] as $shaField) {
            if ($this->normalizeSha256(is_string($sourceAudits[$shaField] ?? null) ? $sourceAudits[$shaField] : null) === null) {
                $errors[] = "approval_manifest.source_audits.{$shaField} must be a SHA-256 digest.";
            }
        }
        if (($gateResults['independent_qa_conclusion'] ?? null) !== 'READY_FOR_EXPANDED_STAGING_PREVIEW') {
            $errors[] = 'approval_manifest.gate_results.independent_qa_conclusion must be READY_FOR_EXPANDED_STAGING_PREVIEW.';
        }
        if (($gateResults['known_good_10slug_pass'] ?? null) !== true) {
            $errors[] = 'approval_manifest.gate_results.known_good_10slug_pass must be true.';
        }
        if (($gateResults['projection_ready_rows'] ?? null) !== 2092 || ($gateResults['projection_blocked_rows'] ?? null) !== 0) {
            $errors[] = 'approval_manifest.gate_results projection counts must be ready=2092 and blocked=0.';
        }
        if (($gateResults['staging_api_smoke_status'] ?? null) !== 'pass'
            || ($gateResults['staging_api_ready_rows'] ?? null) !== 2092
            || ($gateResults['staging_api_failed_rows'] ?? null) !== 0) {
            $errors[] = 'approval_manifest.gate_results staging API smoke must pass 2092/2092.';
        }
        if (($editorialReview['status'] ?? null) !== 'editorial_review_pass') {
            $errors[] = 'approval_manifest.editorial_review.status must be editorial_review_pass.';
        }
        if (($editorialReview['approved_for_next_state'] ?? null) !== CareerJobSalaryAsset::STATUS_APPROVED) {
            $errors[] = 'approval_manifest.editorial_review.approved_for_next_state must be approved.';
        }
        if (($editorialReview['production_import_approved'] ?? null) !== false) {
            $errors[] = 'approval_manifest.editorial_review.production_import_approved must be false.';
        }
        if (($editorialReview['manual_approval_required_for_production_import'] ?? null) !== true) {
            $errors[] = 'approval_manifest.editorial_review.manual_approval_required_for_production_import must be true.';
        }
        if (($editorialReview['rejected_count'] ?? null) !== 0 || (is_array($editorialReview['rejected_slugs'] ?? null) && count($editorialReview['rejected_slugs']) > 0)) {
            $errors[] = 'approval_manifest.editorial_review must have rejected_count=0 and no rejected_slugs.';
        }
        if (count($approvedSlugSet) !== 1046 || count($approvedSlugs) !== 1046) {
            $errors[] = 'approval_manifest.approved_slugs must contain 1046 unique slugs.';
        }

        $dbReport = $sourceAssetSha256 === null ? $this->emptyApprovalDbReport() : $this->approvalDatabaseReport($approvedSlugSet, $sourceAssetSha256);
        foreach ($dbReport['errors'] as $dbError) {
            $errors[] = $dbError;
        }

        $approvalRunId = (string) Str::uuid();
        $updated = [];
        if ($errors === [] && $apply) {
            $updated = DB::transaction(function () use ($approvedSlugSet, $sourceAssetSha256, $approvalManifestSha256, $sourceAudits, $approvalRunId): array {
                $assets = CareerJobSalaryAsset::query()
                    ->where('asset_version', CareerJobSalaryAsset::ASSET_VERSION_V3_6)
                    ->whereIn('career_job_slug', $approvedSlugSet)
                    ->where('source_artifact_sha256', $sourceAssetSha256)
                    ->lockForUpdate()
                    ->get();
                $updated = [];

                foreach ($assets as $asset) {
                    if (! $this->stateMachine->canApproveFrom($asset->status)) {
                        throw new \RuntimeException("{$asset->career_job_slug}/{$asset->locale}: cannot transition salary asset from {$asset->status} to approved.");
                    }

                    $auditFields = is_array($asset->audit_fields_json) ? $asset->audit_fields_json : [];
                    $auditFields['approval_gate'] = [
                        'status' => CareerJobSalaryAsset::STATUS_APPROVED,
                        'approval_manifest_sha256' => $approvalManifestSha256,
                        'independent_qa_sha256' => $sourceAudits['independent_qa_sha256'] ?? null,
                        'staging_api_smoke_sha256' => $sourceAudits['staging_api_smoke_sha256'] ?? null,
                        'approved_run_id' => $approvalRunId,
                    ];
                    $previousStatus = $asset->status;
                    $asset->status = CareerJobSalaryAsset::STATUS_APPROVED;
                    $asset->audit_fields_json = $auditFields;
                    $asset->import_run_id = $approvalRunId;
                    $asset->save();

                    $updated[] = [
                        'slug' => $asset->career_job_slug,
                        'locale' => $asset->locale,
                        'row_id' => $asset->id,
                        'previous_status' => $previousStatus,
                        'new_status' => CareerJobSalaryAsset::STATUS_APPROVED,
                    ];
                }

                return $updated;
            });
        }

        return array_merge($this->approvalReport($approvalManifestFile, $approvalManifestSha256, $expectedApprovalManifestSha256, $apply, $errors), [
            'source_asset_sha256' => $sourceAssetSha256,
            'independent_qa_sha256' => $sourceAudits['independent_qa_sha256'] ?? null,
            'staging_api_smoke_sha256' => $sourceAudits['staging_api_smoke_sha256'] ?? null,
            'approved_slug_count' => count($approvedSlugSet),
            'expected_row_count' => 2092,
            'database_gate' => $dbReport,
            'did_write' => $apply && $errors === [] && count($updated) > 0,
            'updated_count' => count($updated),
            'approval_run_id' => $apply && $errors === [] ? $approvalRunId : null,
            'updated_assets' => $updated,
            'rollback_report' => [
                'approved_run_id' => $apply && $errors === [] ? $approvalRunId : null,
                'rollback_boundary' => 'Rows approved by this command are scoped by approval_run_id/import_run_id and must be moved back to editorial_review before any production import rollback is considered.',
                'production_rows_touched' => 0,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function productionImportApproved(string $approvalManifestFile, ?string $expectedApprovalManifestSha256 = null, string $operatorApproval = '', bool $apply = false): array
    {
        $approvalManifestSha256 = is_file($approvalManifestFile) ? (hash_file('sha256', $approvalManifestFile) ?: null) : null;
        $rawExpectedApprovalManifestSha256 = trim((string) $expectedApprovalManifestSha256);
        $expectedApprovalManifestSha256 = $this->normalizeSha256($expectedApprovalManifestSha256);
        $errors = [];

        if ($rawExpectedApprovalManifestSha256 !== '' && $expectedApprovalManifestSha256 === null) {
            $errors[] = 'Expected approval manifest SHA-256 must be a 64-character hex digest.';
        }

        if (! is_file($approvalManifestFile) || ! is_readable($approvalManifestFile)) {
            return $this->productionImportReport($approvalManifestFile, $approvalManifestSha256, $expectedApprovalManifestSha256, null, false, $apply, [
                'Approval manifest file is missing or unreadable.',
            ]);
        }

        if ($expectedApprovalManifestSha256 !== null && $approvalManifestSha256 !== $expectedApprovalManifestSha256) {
            $errors[] = 'Approval manifest SHA-256 does not match expected artifact SHA.';
        }

        try {
            $manifest = json_decode((string) file_get_contents($approvalManifestFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->productionImportReport($approvalManifestFile, $approvalManifestSha256, $expectedApprovalManifestSha256, null, false, $apply, [
                'Approval manifest is not valid JSON.',
            ]);
        }

        if (! is_array($manifest)) {
            return $this->productionImportReport($approvalManifestFile, $approvalManifestSha256, $expectedApprovalManifestSha256, null, false, $apply, [
                'Approval manifest root must be an object.',
            ]);
        }

        $sourceAsset = $this->arrayValue($manifest, 'source_asset');
        $sourceAudits = $this->arrayValue($manifest, 'source_audits');
        $gateResults = $this->arrayValue($manifest, 'gate_results');
        $editorialReview = $this->arrayValue($manifest, 'editorial_review');
        $approvedSlugs = is_array($manifest['approved_slugs'] ?? null) ? array_values(array_filter(
            array_map(fn (mixed $slug): string => $this->previewService->normalizeSlug((string) $slug), $manifest['approved_slugs']),
            static fn (string $slug): bool => $slug !== ''
        )) : [];
        $approvedSlugSet = array_values(array_unique($approvedSlugs));

        if (($manifest['artifact_type'] ?? null) !== 'career_salary_1046_editorial_review_approval_manifest') {
            $errors[] = 'approval_manifest.artifact_type mismatch.';
        }
        if (($sourceAsset['row_count'] ?? null) !== 2092) {
            $errors[] = 'approval_manifest.source_asset.row_count must be 2092.';
        }
        if (($sourceAsset['slug_count'] ?? null) !== 1046) {
            $errors[] = 'approval_manifest.source_asset.slug_count must be 1046.';
        }
        $localeCounts = is_array($sourceAsset['locale_counts'] ?? null) ? $sourceAsset['locale_counts'] : [];
        if (($localeCounts['zh-CN'] ?? null) !== 1046 || ($localeCounts['en'] ?? null) !== 1046) {
            $errors[] = 'approval_manifest.source_asset.locale_counts must contain zh-CN=1046 and en=1046.';
        }

        $sourceAssetSha256 = $this->normalizeSha256(is_string($sourceAsset['sha256'] ?? null) ? $sourceAsset['sha256'] : null);
        if ($sourceAssetSha256 === null) {
            $errors[] = 'approval_manifest.source_asset.sha256 must be a SHA-256 digest.';
        }
        foreach ([
            'independent_qa_sha256',
            'staging_api_smoke_sha256',
            'staging_summary_sha256',
        ] as $shaField) {
            if ($this->normalizeSha256(is_string($sourceAudits[$shaField] ?? null) ? $sourceAudits[$shaField] : null) === null) {
                $errors[] = "approval_manifest.source_audits.{$shaField} must be a SHA-256 digest.";
            }
        }
        if (($gateResults['independent_qa_conclusion'] ?? null) !== 'READY_FOR_EXPANDED_STAGING_PREVIEW') {
            $errors[] = 'approval_manifest.gate_results.independent_qa_conclusion must be READY_FOR_EXPANDED_STAGING_PREVIEW.';
        }
        if (($gateResults['known_good_10slug_pass'] ?? null) !== true) {
            $errors[] = 'approval_manifest.gate_results.known_good_10slug_pass must be true.';
        }
        if (($gateResults['projection_ready_rows'] ?? null) !== 2092 || ($gateResults['projection_blocked_rows'] ?? null) !== 0) {
            $errors[] = 'approval_manifest.gate_results projection counts must be ready=2092 and blocked=0.';
        }
        if (($gateResults['staging_api_smoke_status'] ?? null) !== 'pass'
            || ($gateResults['staging_api_ready_rows'] ?? null) !== 2092
            || ($gateResults['staging_api_failed_rows'] ?? null) !== 0) {
            $errors[] = 'approval_manifest.gate_results staging API smoke must pass 2092/2092.';
        }
        if (($editorialReview['status'] ?? null) !== 'editorial_review_pass') {
            $errors[] = 'approval_manifest.editorial_review.status must be editorial_review_pass.';
        }
        if (($editorialReview['approved_for_next_state'] ?? null) !== CareerJobSalaryAsset::STATUS_APPROVED) {
            $errors[] = 'approval_manifest.editorial_review.approved_for_next_state must be approved.';
        }
        if (($editorialReview['production_import_approved'] ?? null) !== false) {
            $errors[] = 'approval_manifest.editorial_review.production_import_approved must be false; production approval must come from the operator command text.';
        }
        if (($editorialReview['manual_approval_required_for_production_import'] ?? null) !== true) {
            $errors[] = 'approval_manifest.editorial_review.manual_approval_required_for_production_import must be true.';
        }
        if (($editorialReview['rejected_count'] ?? null) !== 0 || (is_array($editorialReview['rejected_slugs'] ?? null) && count($editorialReview['rejected_slugs']) > 0)) {
            $errors[] = 'approval_manifest.editorial_review must have rejected_count=0 and no rejected_slugs.';
        }
        if (count($approvedSlugSet) !== 1046 || count($approvedSlugs) !== 1046) {
            $errors[] = 'approval_manifest.approved_slugs must contain 1046 unique slugs.';
        }

        $requiredApprovalText = $sourceAssetSha256 === null ? null : $this->requiredProductionApprovalText($sourceAssetSha256);
        $operatorApprovalMatches = $requiredApprovalText !== null && trim($operatorApproval) === $requiredApprovalText;
        if (! $operatorApprovalMatches) {
            $errors[] = 'Operator approval text does not exactly match the required production import approval.';
        }

        $dbReport = $sourceAssetSha256 === null ? $this->emptyProductionDbReport() : $this->productionDatabaseReport($approvedSlugSet, $sourceAssetSha256);
        foreach ($dbReport['errors'] as $dbError) {
            $errors[] = $dbError;
        }

        $productionImportRunId = (string) Str::uuid();
        $updated = [];
        if ($errors === [] && $apply) {
            $updated = DB::transaction(function () use ($approvedSlugSet, $sourceAssetSha256, $approvalManifestSha256, $sourceAudits, $productionImportRunId, $operatorApproval): array {
                $assets = CareerJobSalaryAsset::query()
                    ->where('asset_version', CareerJobSalaryAsset::ASSET_VERSION_V3_6)
                    ->whereIn('career_job_slug', $approvedSlugSet)
                    ->where('source_artifact_sha256', $sourceAssetSha256)
                    ->where('status', CareerJobSalaryAsset::STATUS_APPROVED)
                    ->lockForUpdate()
                    ->get();
                $updated = [];

                foreach ($assets as $asset) {
                    if (! $this->stateMachine->canProductionImportFrom($asset->status)) {
                        throw new \RuntimeException("{$asset->career_job_slug}/{$asset->locale}: cannot transition salary asset from {$asset->status} to production_imported.");
                    }

                    $auditFields = is_array($asset->audit_fields_json) ? $asset->audit_fields_json : [];
                    $auditFields['production_import_gate'] = [
                        'status' => CareerJobSalaryAsset::STATUS_PRODUCTION_IMPORTED,
                        'approval_manifest_sha256' => $approvalManifestSha256,
                        'source_asset_sha256' => $sourceAssetSha256,
                        'independent_qa_sha256' => $sourceAudits['independent_qa_sha256'] ?? null,
                        'staging_api_smoke_sha256' => $sourceAudits['staging_api_smoke_sha256'] ?? null,
                        'operator_approval_sha256' => hash('sha256', trim($operatorApproval)),
                        'production_import_run_id' => $productionImportRunId,
                    ];
                    $previousStatus = $asset->status;
                    $asset->status = CareerJobSalaryAsset::STATUS_PRODUCTION_IMPORTED;
                    $asset->preview_allowlisted = false;
                    $asset->audit_fields_json = $auditFields;
                    $asset->import_run_id = $productionImportRunId;
                    $asset->save();

                    $updated[] = [
                        'slug' => $asset->career_job_slug,
                        'locale' => $asset->locale,
                        'row_id' => $asset->id,
                        'previous_status' => $previousStatus,
                        'new_status' => CareerJobSalaryAsset::STATUS_PRODUCTION_IMPORTED,
                    ];
                }

                return $updated;
            });
        }

        return array_merge($this->productionImportReport($approvalManifestFile, $approvalManifestSha256, $expectedApprovalManifestSha256, $requiredApprovalText, $operatorApprovalMatches, $apply, $errors), [
            'source_asset_sha256' => $sourceAssetSha256,
            'independent_qa_sha256' => $sourceAudits['independent_qa_sha256'] ?? null,
            'staging_api_smoke_sha256' => $sourceAudits['staging_api_smoke_sha256'] ?? null,
            'approved_slug_count' => count($approvedSlugSet),
            'expected_row_count' => 2092,
            'database_gate' => $dbReport,
            'did_write' => $apply && $errors === [] && count($updated) > 0,
            'updated_count' => count($updated),
            'production_import_run_id' => $apply && $errors === [] ? $productionImportRunId : null,
            'updated_assets' => $updated,
            'rollback_report' => [
                'production_import_run_id' => $apply && $errors === [] ? $productionImportRunId : null,
                'rollback_boundary' => 'Rows production-imported by this command are scoped by production_import_run_id/import_run_id and source_asset_sha256.',
                'restore_target_status' => CareerJobSalaryAsset::STATUS_APPROVED,
                'production_rows_touched' => $apply && $errors === [] ? count($updated) : 0,
            ],
        ]);
    }

    private function requiredProductionApprovalText(string $sourceAssetSha256): string
    {
        return "批准 production import 1046 salary assets, using SHA {$sourceAssetSha256}";
    }

    /**
     * @return array<string, mixed>
     */
    private function productionImportReport(string $approvalManifestFile, ?string $approvalManifestSha256, ?string $expectedApprovalManifestSha256, ?string $requiredApprovalText, bool $operatorApprovalMatches, bool $apply, array $errors): array
    {
        return [
            'importer_version' => self::IMPORTER_VERSION,
            'mode' => $apply ? 'production_import' : 'production_import_dry_run',
            'asset_version' => CareerJobSalaryAsset::ASSET_VERSION_V3_6,
            'decision' => $errors === [] ? 'pass' : 'fail',
            'status_policy' => CareerJobSalaryAsset::STATUS_PRODUCTION_IMPORTED,
            'production_import_allowed' => $errors === [],
            'production_rows_touched' => 0,
            'approval_manifest_file' => $approvalManifestFile,
            'approval_manifest_sha256' => $approvalManifestSha256,
            'expected_approval_manifest_sha256' => $expectedApprovalManifestSha256,
            'approval_manifest_sha256_match' => $expectedApprovalManifestSha256 === null || $approvalManifestSha256 === $expectedApprovalManifestSha256,
            'required_operator_approval' => $requiredApprovalText,
            'operator_approval_matches' => $operatorApprovalMatches,
            'state_machine' => $this->stateMachine->report(),
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function approvalReport(string $approvalManifestFile, ?string $approvalManifestSha256, ?string $expectedApprovalManifestSha256, bool $apply, array $errors): array
    {
        return [
            'importer_version' => self::IMPORTER_VERSION,
            'mode' => $apply ? 'approve_staging_preview' : 'approve_staging_preview_dry_run',
            'asset_version' => CareerJobSalaryAsset::ASSET_VERSION_V3_6,
            'decision' => $errors === [] ? 'pass' : 'fail',
            'status_policy' => CareerJobSalaryAsset::STATUS_APPROVED,
            'production_import_allowed' => false,
            'production_rows_touched' => 0,
            'approval_manifest_file' => $approvalManifestFile,
            'approval_manifest_sha256' => $approvalManifestSha256,
            'expected_approval_manifest_sha256' => $expectedApprovalManifestSha256,
            'approval_manifest_sha256_match' => $expectedApprovalManifestSha256 === null || $approvalManifestSha256 === $expectedApprovalManifestSha256,
            'state_machine' => $this->stateMachine->report(),
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<string>  $approvedSlugs
     * @return array<string, mixed>
     */
    private function approvalDatabaseReport(array $approvedSlugs, string $sourceAssetSha256): array
    {
        $assets = CareerJobSalaryAsset::query()
            ->where('asset_version', CareerJobSalaryAsset::ASSET_VERSION_V3_6)
            ->whereIn('career_job_slug', $approvedSlugs)
            ->where('source_artifact_sha256', $sourceAssetSha256)
            ->get(['career_job_slug', 'locale', 'status', 'source_artifact_sha256']);
        $rowsByKey = [];
        $statusCounts = [];
        $errors = [];

        foreach ($assets as $asset) {
            $rowsByKey[$asset->career_job_slug.'|'.$asset->locale] = true;
            $statusCounts[$asset->status] = ($statusCounts[$asset->status] ?? 0) + 1;
            if (! $this->stateMachine->canApproveFrom($asset->status)) {
                $errors[] = "{$asset->career_job_slug}/{$asset->locale}: cannot transition from {$asset->status} to approved.";
            }
        }

        foreach ($approvedSlugs as $slug) {
            foreach (['zh-CN', 'en'] as $locale) {
                if (! isset($rowsByKey[$slug.'|'.$locale])) {
                    $errors[] = "{$slug}/{$locale}: approved staging row with matching source asset SHA is missing.";
                }
            }
        }

        return [
            'checked_slug_count' => count($approvedSlugs),
            'expected_row_count' => count($approvedSlugs) * 2,
            'matching_row_count' => count($rowsByKey),
            'status_counts' => $statusCounts,
            'ready_for_approval' => $errors === [] && count($rowsByKey) === count($approvedSlugs) * 2,
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<string>  $approvedSlugs
     * @return array<string, mixed>
     */
    private function productionDatabaseReport(array $approvedSlugs, string $sourceAssetSha256): array
    {
        $assets = CareerJobSalaryAsset::query()
            ->where('asset_version', CareerJobSalaryAsset::ASSET_VERSION_V3_6)
            ->whereIn('career_job_slug', $approvedSlugs)
            ->where('source_artifact_sha256', $sourceAssetSha256)
            ->get(['career_job_slug', 'locale', 'status', 'source_artifact_sha256']);
        $rowsByKey = [];
        $statusCounts = [];
        $errors = [];

        foreach ($assets as $asset) {
            $rowsByKey[$asset->career_job_slug.'|'.$asset->locale] = true;
            $statusCounts[$asset->status] = ($statusCounts[$asset->status] ?? 0) + 1;
            if ($asset->status === CareerJobSalaryAsset::STATUS_PRODUCTION_IMPORTED) {
                continue;
            }

            if (! $this->stateMachine->canProductionImportFrom($asset->status)) {
                $errors[] = "{$asset->career_job_slug}/{$asset->locale}: cannot transition from {$asset->status} to production_imported.";
            }
        }

        foreach ($approvedSlugs as $slug) {
            foreach (['zh-CN', 'en'] as $locale) {
                if (! isset($rowsByKey[$slug.'|'.$locale])) {
                    $errors[] = "{$slug}/{$locale}: approved row with matching source asset SHA is missing.";
                }
            }
        }

        $approvedCount = (int) ($statusCounts[CareerJobSalaryAsset::STATUS_APPROVED] ?? 0);
        $alreadyImportedCount = (int) ($statusCounts[CareerJobSalaryAsset::STATUS_PRODUCTION_IMPORTED] ?? 0);
        $expectedRowCount = count($approvedSlugs) * 2;

        return [
            'checked_slug_count' => count($approvedSlugs),
            'expected_row_count' => $expectedRowCount,
            'matching_row_count' => count($rowsByKey),
            'status_counts' => $statusCounts,
            'approved_source_row_count' => $approvedCount,
            'already_production_imported_row_count' => $alreadyImportedCount,
            'ready_for_production_import' => $errors === [] && count($rowsByKey) === $expectedRowCount && ($approvedCount + $alreadyImportedCount) === $expectedRowCount,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyApprovalDbReport(): array
    {
        return [
            'checked_slug_count' => 0,
            'expected_row_count' => 0,
            'matching_row_count' => 0,
            'status_counts' => [],
            'ready_for_approval' => false,
            'errors' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyProductionDbReport(): array
    {
        return [
            'checked_slug_count' => 0,
            'expected_row_count' => 0,
            'matching_row_count' => 0,
            'status_counts' => [],
            'approved_source_row_count' => 0,
            'already_production_imported_row_count' => 0,
            'ready_for_production_import' => false,
            'errors' => [],
        ];
    }

    /**
     * @param  list<string>  $targetSlugs
     * @return list<array<string, mixed>>
     */
    private function rowsForTargetSlugs(string $file, array $targetSlugs): array
    {
        $targetLookup = array_fill_keys($targetSlugs, true);
        $rows = [];
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Source JSONL file could not be opened for staging_preview write.');
        }

        while (($line = fgets($handle)) !== false) {
            if (trim($line) === '') {
                continue;
            }

            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($row)) {
                continue;
            }

            $slug = $this->previewService->normalizeSlug((string) ($row['slug'] ?? ''));
            if (isset($targetLookup[$slug])) {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  list<string>|null  $requestedSlugs
     * @return array<string, mixed>
     */
    private function baseReport(string $file, ?array $requestedSlugs, bool $allSlugsFromFile = false): array
    {
        return [
            'importer_version' => self::IMPORTER_VERSION,
            'asset_version' => CareerJobSalaryAsset::ASSET_VERSION_V3_6,
            'status_policy' => CareerJobSalaryAsset::STATUS_STAGING_PREVIEW,
            'production_import_allowed' => false,
            'production_import_gate' => [
                'allowed' => false,
                'required_from_status' => CareerJobSalaryAsset::STATUS_APPROVED,
                'current_command_supports_production_import' => false,
            ],
            'source_file' => $file,
            'requested_slugs' => $requestedSlugs,
            'all_slugs_from_file' => $allSlugsFromFile,
        ];
    }

    /**
     * @param  list<string>|null  $requestedSlugs
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function targetSlugs(?array $requestedSlugs, array &$errors): array
    {
        $allowlist = $this->previewService->previewSlugs();
        $target = $requestedSlugs === null || $requestedSlugs === []
            ? $allowlist
            : array_values(array_unique(array_map(
                fn (string $slug): string => $this->previewService->normalizeSlug($slug),
                $requestedSlugs
            )));

        foreach ($target as $slug) {
            if (! in_array($slug, $allowlist, true)) {
                $errors[] = "{$slug}: slug is not in the staging preview allowlist.";
            }
        }

        return $target;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function rowErrors(array $row, string $slug, string $locale): array
    {
        $errors = [];
        if (($row['asset_type'] ?? null) !== 'career_job_salary_asset') {
            $errors[] = 'asset_type must be career_job_salary_asset.';
        }

        if (($row['asset_version'] ?? null) !== CareerJobSalaryAsset::ASSET_VERSION_V3_6) {
            $errors[] = 'asset_version must be career_job_salary_asset_v3_6.';
        }

        if ($this->previewService->normalizeSlug((string) ($row['slug'] ?? '')) !== $slug) {
            $errors[] = 'slug mismatch.';
        }

        if ($this->previewService->normalizeLocale((string) ($row['locale'] ?? '')) !== $locale) {
            $errors[] = 'locale mismatch.';
        }

        $auditFields = $this->arrayValue($row, 'audit_fields');
        if (($auditFields['schema_version'] ?? null) !== 'career_job_salary_asset_v3_6') {
            $errors[] = 'audit_fields.schema_version mismatch.';
        }

        if (($auditFields['ready_for_codex_audit'] ?? null) !== true) {
            $errors[] = 'audit_fields.ready_for_codex_audit must be true.';
        }

        $rowHash = (string) ($auditFields['row_hash'] ?? '');
        if (! preg_match('/^[a-f0-9]{64}$/', $rowHash)) {
            $errors[] = 'audit_fields.row_hash must be a SHA-256 hex digest.';
        }

        $cn = $this->arrayValue($row, 'china_recruitment_reference');
        $facts = $this->arrayValue($cn, 'facts');
        foreach (['monthly_cny_p25', 'monthly_cny_median', 'monthly_cny_p75'] as $field) {
            if (($facts[$field] ?? null) !== null) {
                $errors[] = "China {$field} must stay null for v3.6 preview import.";
            }
        }

        $boundary = strtolower((string) ($cn['data_boundary'] ?? ''));
        $body = (string) ($cn['body'] ?? '');
        if (! str_contains($boundary, 'not an official chinese single-occupation median wage')
            && ! str_contains($body, '不是官方职业中位薪资')) {
            $errors[] = 'China recruitment boundary must explicitly say it is not official wage.';
        }

        if (! is_array($row['sources'] ?? null) || count((array) $row['sources']) === 0) {
            $errors[] = 'sources must be a non-empty array.';
        }

        $derived = $this->arrayValue($row, 'derived_from_estimate');
        if (! preg_match('/^[a-f0-9]{64}$/', (string) ($derived['estimate_row_hash'] ?? ''))) {
            $errors[] = 'derived_from_estimate.estimate_row_hash must be present.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function arrayValue(array $row, string $key): array
    {
        return is_array($row[$key] ?? null) ? $row[$key] : [];
    }

    private function normalizeSha256(?string $sha256): ?string
    {
        $normalized = strtolower(trim((string) $sha256));
        if ($normalized === '') {
            return null;
        }

        return preg_match('/^[a-f0-9]{64}$/', $normalized) === 1 ? $normalized : null;
    }

    /**
     * @param  list<string>  $targetSlugs
     * @return array<string, mixed>
     */
    private function idempotencyReport(?string $sourceFileSha256, array $targetSlugs): array
    {
        return [
            'idempotency_key' => hash('sha256', implode('|', [
                CareerJobSalaryAsset::ASSET_VERSION_V3_6,
                $sourceFileSha256 ?? 'unknown_source_sha',
                implode(',', $targetSlugs),
            ])),
            'target_key' => ['career_job_slug', 'locale', 'asset_version'],
            'write_strategy' => 'update_or_create_by_target_key',
            'duplicate_rows_allowed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rollbackPolicy(?string $importRunId = null): array
    {
        return [
            'production_rollback_supported_by_this_command' => false,
            'staging_preview_import_run_id' => $importRunId,
            'staging_preview_rollback_boundary' => 'Rows written by a staging_preview import are scoped by import_run_id and must be rolled back before approval or production import.',
            'production_import_requires_approved_status' => true,
        ];
    }
}
