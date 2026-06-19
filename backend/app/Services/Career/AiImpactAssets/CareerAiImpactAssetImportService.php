<?php

declare(strict_types=1);

namespace App\Services\Career\AiImpactAssets;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\Occupation;
use App\Services\Career\PublicCareerAuthorityResponseCache;
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
    ): array {
        $report = $this->baseReport($file, $requestedSlugs, $allSlugsFromFile);
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
            : $this->targetSlugs($requestedSlugs, $errors);

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
            'career_job_bundle_authority' => $authorityReport,
            'projection_safety_gate' => $projectionSafetyReport,
            'production_import_allowed' => false,
            'staging_write_performed' => false,
            'errors' => $errors,
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
    private function baseReport(string $file, ?array $requestedSlugs, bool $allSlugsFromFile): array
    {
        return [
            'importer_version' => self::IMPORTER_VERSION,
            'asset_version' => 'career_risk_future_ai_impact_v5',
            'status_policy' => 'dry_run_only_for_this_contract',
            'production_import_allowed' => false,
            'production_import_gate' => [
                'allowed' => false,
                'required_from_status' => 'approved',
                'current_command_supports_production_import' => false,
            ],
            'staging_write_supported' => false,
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
                $errors[] = "{$slug}: slug is not in the AI Impact staging preview allowlist.";
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
            'staging_preview_write_supported_in_this_pr' => false,
            'production_import_requires_approved_status' => true,
        ];
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
