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
    ) {}

    /**
     * @param  list<string>|null  $requestedSlugs
     * @return array<string, mixed>
     */
    public function validateFile(string $file, ?array $requestedSlugs = null): array
    {
        $report = $this->baseReport($file, $requestedSlugs);
        $errors = [];

        if (! is_file($file) || ! is_readable($file)) {
            return array_merge($report, [
                'decision' => 'fail',
                'errors' => ['Source JSONL file is missing or unreadable.'],
            ]);
        }

        $targetSlugs = $this->targetSlugs($requestedSlugs, $errors);
        $rowsByKey = [];
        $parseErrors = [];
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
            if ($slug === '' || ! in_array($slug, $targetSlugs, true)) {
                continue;
            }

            $key = $slug.'|'.$locale;
            if (isset($rowsByKey[$key])) {
                $errors[] = "{$slug}/{$locale}: duplicate asset row.";

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

        foreach ($targetSlugs as $slug) {
            foreach (['zh-CN', 'en'] as $locale) {
                $key = $slug.'|'.$locale;
                if (! isset($rowsByKey[$key])) {
                    $errors[] = "{$slug}/{$locale}: required preview row missing.";

                    continue;
                }

                foreach ($this->rowErrors($rowsByKey[$key]['row'], $slug, $locale) as $rowError) {
                    $errors[] = "{$slug}/{$locale}: {$rowError}";
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

        $validatedRows = array_values(array_map(
            static fn (array $entry): array => $entry['row'],
            $rowsByKey
        ));

        return array_merge($report, [
            'mode' => 'dry_run',
            'decision' => $errors === [] ? 'pass' : 'fail',
            'total_jsonl_lines' => $totalLines,
            'target_slug_count' => count($targetSlugs),
            'validated_preview_rows' => count($validatedRows),
            'expected_preview_rows' => count($targetSlugs) * 2,
            'source_file_sha256' => hash_file('sha256', $file) ?: null,
            'target_slugs' => $targetSlugs,
            'career_job_bundle_authority' => $authorityReport,
            'errors' => $errors,
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
     * @param  list<string>|null  $requestedSlugs
     * @return array<string, mixed>
     */
    public function importStagingPreview(string $file, ?array $requestedSlugs = null): array
    {
        $report = $this->validateFile($file, $requestedSlugs);
        if (($report['decision'] ?? null) !== 'pass') {
            return $report;
        }

        $importRunId = (string) Str::uuid();
        $sourceSha = is_string($report['source_file_sha256'] ?? null) ? $report['source_file_sha256'] : null;

        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
        $written = DB::transaction(function () use ($rows, $sourceSha, $importRunId): array {
            $written = [];

            foreach ($rows as $row) {
                $slug = $this->previewService->normalizeSlug((string) ($row['slug'] ?? ''));
                $locale = $this->previewService->normalizeLocale((string) ($row['locale'] ?? ''));
                $occupation = Occupation::query()->where('canonical_slug', $slug)->firstOrFail();
                $auditFields = $this->arrayValue($row, 'audit_fields');

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
                ];
            }

            return $written;
        });

        return array_merge($report, [
            'mode' => 'force_staging_preview',
            'decision' => 'pass',
            'did_write' => count($written) > 0,
            'written_count' => count($written),
            'import_run_id' => $importRunId,
            'written_assets' => $written,
        ]);
    }

    /**
     * @param  list<string>|null  $requestedSlugs
     * @return array<string, mixed>
     */
    private function baseReport(string $file, ?array $requestedSlugs): array
    {
        return [
            'importer_version' => self::IMPORTER_VERSION,
            'asset_version' => CareerJobSalaryAsset::ASSET_VERSION_V3_6,
            'status_policy' => CareerJobSalaryAsset::STATUS_STAGING_PREVIEW,
            'production_import_allowed' => false,
            'source_file' => $file,
            'requested_slugs' => $requestedSlugs,
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
}
