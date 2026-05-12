<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class CareerBaselineMetadataInventoryAuditor
{
    /**
     * The zh baseline is the display source for these fields in AUDIT-4.
     *
     * @var list<string>
     */
    private const DEFAULT_REQUIRED_DISPLAY_FIELDS = [
        'title',
        'subtitle',
        'excerpt',
        'body_md',
        'seo_meta',
    ];

    /**
     * @param  list<string>|null  $manifestPaths
     * @param  list<string>|null  $requiredDisplayFields
     */
    public function __construct(
        private readonly ?string $zhBaselinePath = null,
        private readonly ?string $enBaselinePath = null,
        private readonly ?array $manifestPaths = null,
        private readonly ?array $requiredDisplayFields = null,
    ) {}

    public function auditPlan(CareerPublicResolutionPlan $plan): CareerBaselineMetadataInventoryResult
    {
        return $this->auditRows($plan->rows);
    }

    /**
     * @param  list<CareerPublicResolutionPlanRow>  $planRows
     */
    public function auditRows(array $planRows): CareerBaselineMetadataInventoryResult
    {
        $sources = $this->loadSources();
        $rows = [];

        foreach ($planRows as $planRow) {
            $canonicalSlug = $this->normalizeSlug($planRow->canonicalSlug);
            if ($canonicalSlug === null) {
                continue;
            }

            $rows[] = $this->buildRow(
                canonicalSlug: $canonicalSlug,
                sourceScope: $planRow->batchId ?? 'plan',
                zhRow: $sources['zh_rows'][$canonicalSlug] ?? null,
                enRow: $sources['en_rows'][$canonicalSlug] ?? null,
                manifestRow: $sources['manifest_rows'][$canonicalSlug] ?? null
            );
        }

        return CareerBaselineMetadataInventoryResult::build(
            sourcePaths: $this->sourcePaths(),
            rows: $rows,
            issues: $sources['issues']
        );
    }

    /**
     * @return array{zh_rows: array<string, array<string, mixed>>, en_rows: array<string, array<string, mixed>>, manifest_rows: array<string, array<string, mixed>>, issues: list<CareerBaselineMetadataInventoryIssue>}
     */
    private function loadSources(): array
    {
        $issues = [];
        [$zhRows, $zhIssue] = $this->loadSlugRowsFromJson($this->resolvedZhBaselinePath(), 'zh_baseline');
        [$enRows, $enIssue] = $this->loadSlugRowsFromJson($this->resolvedEnBaselinePath(), 'en_baseline');

        if ($zhIssue !== null) {
            $issues[] = $zhIssue;
        }

        if ($enIssue !== null) {
            $issues[] = $enIssue;
        }

        $manifestRows = [];
        foreach ($this->resolvedManifestPaths() as $manifestPath) {
            [$rows, $issue] = $this->loadSlugRowsFromJson($manifestPath, 'batch_manifest');
            if ($issue !== null) {
                $issues[] = $issue;

                continue;
            }

            $manifestRows = array_replace($manifestRows, $rows);
        }

        return [
            'zh_rows' => $zhRows,
            'en_rows' => $enRows,
            'manifest_rows' => $manifestRows,
            'issues' => $issues,
        ];
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: CareerBaselineMetadataInventoryIssue|null}
     */
    private function loadSlugRowsFromJson(string $path, string $source): array
    {
        if (! is_file($path)) {
            return [
                [],
                new CareerBaselineMetadataInventoryIssue(
                    reason: CareerBaselineMetadataInventoryIssue::BASELINE_SOURCE_MISSING,
                    message: sprintf('Career baseline metadata source [%s] was not found.', $source),
                    severity: CareerCanonicalEligibilitySeverity::HIGH,
                    sourcePath: $path,
                    evidence: [['source' => $source, 'path' => $path]]
                ),
            ];
        }

        $contents = file_get_contents($path);
        $payload = is_string($contents) ? json_decode($contents, true) : null;
        if (! is_array($payload)) {
            return [
                [],
                new CareerBaselineMetadataInventoryIssue(
                    reason: CareerBaselineMetadataInventoryIssue::BASELINE_JSON_INVALID,
                    message: sprintf('Career baseline metadata source [%s] is not valid JSON.', $source),
                    severity: CareerCanonicalEligibilitySeverity::HIGH,
                    sourcePath: $path,
                    evidence: [['source' => $source, 'json_error' => json_last_error_msg()]]
                ),
            ];
        }

        return [$this->rowsBySlug($payload), null];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array<string, mixed>>
     */
    private function rowsBySlug(array $payload): array
    {
        $rows = $this->extractRows($payload);
        $bySlug = [];

        foreach ($rows as $key => $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = $this->normalizeSlug($row['canonical_slug'] ?? null)
                ?? $this->normalizeSlug($row['slug'] ?? null)
                ?? (is_string($key) ? $this->normalizeSlug($key) : null);

            if ($slug !== null) {
                $bySlug[$slug] = $row;
            }
        }

        return $bySlug;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<mixed>|array<string, mixed>
     */
    private function extractRows(array $payload): array
    {
        foreach (['jobs', 'members', 'occupations', 'rows', 'assets'] as $key) {
            if (array_key_exists($key, $payload) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        $workbook = $payload['workbook'] ?? null;
        if (is_array($workbook) && isset($workbook['rows']) && is_array($workbook['rows'])) {
            return $workbook['rows'];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>|null  $zhRow
     * @param  array<string, mixed>|null  $enRow
     * @param  array<string, mixed>|null  $manifestRow
     */
    private function buildRow(
        string $canonicalSlug,
        string $sourceScope,
        ?array $zhRow,
        ?array $enRow,
        ?array $manifestRow,
    ): CareerBaselineMetadataInventoryRow {
        $issues = [];
        $missingDisplayFields = $this->missingDisplayFields($zhRow);
        $titleZh = $this->titleForLocale($zhRow, 'zh');
        [$titleEn, $titleEnSource] = $this->resolveTitleEn($canonicalSlug, $enRow, $manifestRow);

        if ($zhRow === null) {
            $issues[] = new CareerBaselineMetadataInventoryIssue(
                reason: CareerBaselineMetadataInventoryIssue::ZH_BASELINE_MISSING,
                message: 'Career zh-CN baseline row was not found for canonical slug.',
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                canonicalSlug: $canonicalSlug,
                evidence: [['canonical_slug' => $canonicalSlug]]
            );
        }

        foreach ($missingDisplayFields as $field) {
            $issues[] = new CareerBaselineMetadataInventoryIssue(
                reason: CareerBaselineMetadataInventoryIssue::REQUIRED_DISPLAY_FIELD_MISSING,
                message: 'Career zh-CN baseline row is missing a required display field.',
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                canonicalSlug: $canonicalSlug,
                field: $field,
                evidence: [['field' => $field]]
            );
        }

        if ($titleEnSource === CareerBaselineMetadataInventoryRow::TITLE_EN_SOURCE_CANONICAL_SLUG_DERIVED) {
            $issues[] = new CareerBaselineMetadataInventoryIssue(
                reason: CareerBaselineMetadataInventoryIssue::EN_TITLE_DERIVATION_REQUIRED,
                message: 'Career English title was derived from canonical_slug because no baseline or manifest title was available.',
                severity: CareerCanonicalEligibilitySeverity::LOW,
                canonicalSlug: $canonicalSlug,
                evidence: [['canonical_slug' => $canonicalSlug, 'title_en' => $titleEn]]
            );
        }

        if ($titleEnSource === CareerBaselineMetadataInventoryRow::TITLE_EN_SOURCE_MISSING) {
            $issues[] = new CareerBaselineMetadataInventoryIssue(
                reason: CareerBaselineMetadataInventoryIssue::EN_TITLE_MISSING,
                message: 'Career English title is missing and could not be derived from canonical_slug.',
                severity: CareerCanonicalEligibilitySeverity::MEDIUM,
                canonicalSlug: $canonicalSlug,
                evidence: [['canonical_slug' => $canonicalSlug]]
            );
        }

        $evidence = [
            [
                'canonical_slug' => $canonicalSlug,
                'zh_baseline_exists' => $zhRow !== null,
                'title_en_source' => $titleEnSource,
            ],
        ];

        if ($titleZh !== null) {
            $evidence[] = ['title_zh' => $titleZh];
        }

        if ($titleEn !== null) {
            $evidence[] = ['title_en' => $titleEn];
        }

        return new CareerBaselineMetadataInventoryRow(
            canonicalSlug: $canonicalSlug,
            zhBaselineExists: $zhRow !== null,
            titleZh: $titleZh,
            titleEn: $titleEn,
            titleEnSource: $titleEnSource,
            baselineStatus: $this->baselineLayerStatus($issues, $evidence),
            missingDisplayFields: $missingDisplayFields,
            sourceScope: $sourceScope,
            evidence: $evidence,
            issues: $issues
        );
    }

    /**
     * @param  list<CareerBaselineMetadataInventoryIssue>  $issues
     * @param  list<mixed>  $evidence
     */
    private function baselineLayerStatus(array $issues, array $evidence): CareerCanonicalEligibilityLayerStatus
    {
        $reasons = array_values(array_unique(array_map(
            static fn (CareerBaselineMetadataInventoryIssue $issue): string => $issue->reason,
            $issues
        )));

        $status = CareerCanonicalEligibilityStatus::PASS;
        foreach ($issues as $issue) {
            if ($issue->reason === CareerBaselineMetadataInventoryIssue::EN_TITLE_DERIVATION_REQUIRED) {
                $status = $status === CareerCanonicalEligibilityStatus::PASS
                    ? CareerCanonicalEligibilityStatus::WARNING
                    : $status;

                continue;
            }

            $status = CareerCanonicalEligibilityStatus::BLOCKED;
            break;
        }

        return new CareerCanonicalEligibilityLayerStatus(
            layer: CareerCanonicalEligibilityLayer::BASELINE,
            status: $status,
            reasons: $reasons,
            evidence: $evidence,
            source: 'career_baselines'
        );
    }

    /**
     * @param  array<string, mixed>|null  $row
     * @return list<string>
     */
    private function missingDisplayFields(?array $row): array
    {
        if ($row === null) {
            return $this->requiredDisplayFields();
        }

        $missing = [];
        foreach ($this->requiredDisplayFields() as $field) {
            if ($this->fieldValue($row, $field) === null) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>|null  $enRow
     * @param  array<string, mixed>|null  $manifestRow
     * @return array{0: string|null, 1: string}
     */
    private function resolveTitleEn(string $canonicalSlug, ?array $enRow, ?array $manifestRow): array
    {
        $title = $this->titleForLocale($enRow, 'en');
        if ($title !== null) {
            return [$title, CareerBaselineMetadataInventoryRow::TITLE_EN_SOURCE_EN_BASELINE];
        }

        $title = $this->titleForLocale($manifestRow, 'en');
        if ($title !== null) {
            return [$title, CareerBaselineMetadataInventoryRow::TITLE_EN_SOURCE_BATCH_MANIFEST];
        }

        $derived = $this->deriveTitleEn($canonicalSlug);

        return $derived === null
            ? [null, CareerBaselineMetadataInventoryRow::TITLE_EN_SOURCE_MISSING]
            : [$derived, CareerBaselineMetadataInventoryRow::TITLE_EN_SOURCE_CANONICAL_SLUG_DERIVED];
    }

    /**
     * @param  array<string, mixed>|null  $row
     */
    private function titleForLocale(?array $row, string $locale): ?string
    {
        if ($row === null) {
            return null;
        }

        if ($locale === 'en') {
            return $this->normalizeString($row['title_en'] ?? null)
                ?? $this->normalizeString($row['canonical_title_en'] ?? null)
                ?? $this->normalizeString($row['title'] ?? null);
        }

        return $this->normalizeString($row['title_zh'] ?? null)
            ?? $this->normalizeString($row['canonical_title_zh'] ?? null)
            ?? $this->normalizeString($row['title'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function fieldValue(array $row, string $field): ?string
    {
        if (array_key_exists($field, $row)) {
            return $this->normalizeString($row[$field]);
        }

        if ($field === 'title_zh') {
            return $this->titleForLocale($row, 'zh');
        }

        return null;
    }

    private function deriveTitleEn(string $canonicalSlug): ?string
    {
        $words = preg_split('/[-_]+/', $canonicalSlug);
        if (! is_array($words) || $words === []) {
            return null;
        }

        $title = trim(implode(' ', array_map(
            static fn (string $word): string => ucfirst(strtolower($word)),
            array_filter($words, static fn (string $word): bool => $word !== '')
        )));

        return $title === '' ? null : $title;
    }

    /**
     * @return list<string>
     */
    private function requiredDisplayFields(): array
    {
        return $this->requiredDisplayFields ?? self::DEFAULT_REQUIRED_DISPLAY_FIELDS;
    }

    private function resolvedZhBaselinePath(): string
    {
        return $this->zhBaselinePath ?? $this->repoRoot().'/content_baselines/career_jobs/career_jobs.zh-CN.json';
    }

    private function resolvedEnBaselinePath(): string
    {
        return $this->enBaselinePath ?? $this->repoRoot().'/content_baselines/career_jobs/career_jobs.en.json';
    }

    /**
     * @return list<string>
     */
    private function resolvedManifestPaths(): array
    {
        return $this->manifestPaths ?? [
            $this->repoRoot().'/backend/docs/career/first_wave_manifest.json',
            $this->repoRoot().'/backend/docs/career/batches/batch_2_manifest.json',
            $this->repoRoot().'/backend/docs/career/batches/batch_3_manifest.json',
            $this->repoRoot().'/backend/docs/career/batches/batch_4_manifest.json',
        ];
    }

    /**
     * @return array{zh_baseline: string|null, en_baseline: string|null, manifests: list<string>}
     */
    private function sourcePaths(): array
    {
        return [
            'zh_baseline' => $this->resolvedZhBaselinePath(),
            'en_baseline' => $this->resolvedEnBaselinePath(),
            'manifests' => $this->resolvedManifestPaths(),
        ];
    }

    private function repoRoot(): string
    {
        $root = realpath(dirname(__DIR__, 5));

        return is_string($root) && $root !== '' ? $root : dirname(__DIR__, 5);
    }

    private function normalizeSlug(mixed $value): ?string
    {
        $normalized = $this->normalizeString($value);

        return $normalized === null ? null : strtolower($normalized);
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
