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
    public function validateFile(string $file, ?array $requestedSlugs = null, ?string $expectedSha256 = null): array
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
        $duplicateKeys = [];
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
        $editorialQualityReport = $this->editorialQualityReport($validatedRows);
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
            'career_job_bundle_authority' => $authorityReport,
            'editorial_quality_gate' => $editorialQualityReport,
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
     * @param  list<array<string, mixed>>  $rows
     * @return array{checked_row_count: int, ready_row_count: int, rows: list<array<string, mixed>>}
     */
    private function editorialQualityReport(array $rows): array
    {
        $reportRows = [];
        $readyCount = 0;

        foreach ($rows as $row) {
            $errors = $this->editorialQualityErrors($row);
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
    public function importStagingPreview(string $file, ?array $requestedSlugs = null, ?string $expectedSha256 = null): array
    {
        $report = $this->validateFile($file, $requestedSlugs, $expectedSha256);
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
            'import_run_id' => $importRunId,
            'rollback_policy' => $this->rollbackPolicy($importRunId),
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
            'production_import_gate' => [
                'allowed' => false,
                'required_from_status' => CareerJobSalaryAsset::STATUS_APPROVED,
                'current_command_supports_production_import' => false,
            ],
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

    private function normalizeSha256(?string $sha256): ?string
    {
        $normalized = strtolower(trim((string) $sha256));
        if ($normalized === '') {
            return null;
        }

        return $normalized;
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
