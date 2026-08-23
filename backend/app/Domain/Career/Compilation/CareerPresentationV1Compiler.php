<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerPresentationV1Contract;
use App\Domain\Career\Display\CareerSupportingEvidenceV1Contract;
use JsonException;

final class CareerPresentationV1Compiler
{
    public const VERSION = 'career.presentation_v1.compiler.v4';

    /** @var array<string,array{label:string,source_field:string}> */
    private const BLS_STATS = [
        '中位年薪' => ['label' => '美国年薪中位数', 'source_field' => 'salary.bls_table.中位年薪'],
        '就业增长' => ['label' => '美国就业增长', 'source_field' => 'salary.bls_table.就业增长'],
    ];

    private const STAT_KEYS = [
        '中位年薪' => 'us_median_pay',
        '就业增长' => 'us_growth',
    ];

    public function __construct(
        private readonly CareerCurrentZhBatchPreparer $sourceInspector,
        private readonly CareerCurrentAuthorityPackage $package,
        private readonly CareerPresentationSourceRegistry $sourceRegistry,
    ) {}

    /** @var array{document:array<string,mixed>,onet:array<string,array<string,mixed>>,bls:array<string,array<string,mixed>>}|null */
    private ?array $registry = null;

    /** @return array{assets_bytes:string,manifest_template:array<string,mixed>,receipt:array<string,mixed>,field_coverage:array<string,mixed>,package_diff:array<string,mixed>} */
    public function compile(string $sourceRoot, string $designAuthorityPath, string $backendRoot): array
    {
        $sourceBefore = $this->sourceInspector->inspectSource($sourceRoot);
        if (($sourceBefore['career_count'] ?? null) !== 1046
            || ($sourceBefore['file_count'] ?? null) !== 10460
            || ! hash_equals(
                CareerCurrentZhBatchMaterializer::EXPECTED_SOURCE_AGGREGATE_SHA256,
                (string) ($sourceBefore['aggregate_sha256'] ?? ''),
            )) {
            throw new CareerTenBlockCompileFailure('PRESENTATION_V1_SOURCE_AUTHORITY_INVALID');
        }
        $designPath = realpath($designAuthorityPath);
        if ($designPath === false || ! is_file($designPath) || is_link($designPath)
            || ! hash_equals(CareerPresentationV1Contract::DESIGN_AUTHORITY_SHA256, (string) hash_file('sha256', $designPath))) {
            throw new CareerTenBlockCompileFailure('PRESENTATION_V1_DESIGN_AUTHORITY_INVALID');
        }

        $baseline = $this->package->load($backendRoot);
        $this->registry = $this->sourceRegistry->load($backendRoot, $baseline['manifest']);
        $supportingRegistry = $this->supportingRegistry($backendRoot, $baseline['slugs']);
        if ($sourceBefore['slugs'] !== $baseline['slugs']) {
            throw new CareerTenBlockCompileFailure('PRESENTATION_V1_SOURCE_SLUG_SET_MISMATCH');
        }

        $sourceBlocks = [];
        $titleZhBySlug = [];
        foreach ($baseline['slugs'] as $slug) {
            $blocks = $this->readBlocks($sourceRoot, $slug);
            $titleZh = trim((string) ($blocks['identity']['title_zh'] ?? ''));
            if (($blocks['identity']['slug'] ?? null) !== $slug
                || $titleZh === '' || preg_match('/[\x{3400}-\x{9fff}]/u', $titleZh) !== 1) {
                throw new CareerTenBlockCompileFailure('PRESENTATION_V1_SOURCE_IDENTITY_INVALID');
            }
            $sourceBlocks[$slug] = $blocks;
            $titleZhBySlug[$slug] = $titleZh;
        }

        $coverage = $this->newCoverage();
        $missingFields = [];
        $candidateRows = [];
        $beforeEnHashes = [];
        $afterEnHashes = [];
        $beforeZhNonRelatedHashes = [];
        $afterZhNonRelatedHashes = [];
        $presentationAdditions = 0;
        $presentationChanges = 0;
        $sourceReferenceChanges = 0;
        $supportingEvidenceChanges = 0;
        $relatedNextPageChanges = 0;
        foreach ($baseline['slugs'] as $slug) {
            $row = $baseline['rows'][$slug];
            $beforePresentation = $row['metadata_json']['presentation_v1']['zh'] ?? null;
            $blocks = $sourceBlocks[$slug];
            $presentation = $this->project($slug, $blocks, $row, $coverage, $missingFields);
            CareerPresentationV1Contract::assert($presentation);
            $beforeEnHashes[] = CareerCurrentAuthorityPackage::hashValue($this->englishContentProjection($row));
            $beforeZhNonRelatedHashes[] = CareerCurrentAuthorityPackage::hashValue($this->zhPageWithoutRelatedLinks($row));
            $row = $this->normalizeMultipleOnetReferences($slug, $row, $sourceReferenceChanges);
            $row = $this->compileRelatedNextPages($slug, $row, $titleZhBySlug, $relatedNextPageChanges);
            $row['metadata_json']['presentation_v1'] = ['zh' => $presentation];
            $beforeSupporting = $row['metadata_json']['supporting_evidence_v1']['zh'] ?? null;
            $supportingItem = $supportingRegistry['items'][$slug] ?? null;
            if (is_array($supportingItem)) {
                $row = $this->applySupportingEvidence($row, $supportingItem, $sourceReferenceChanges);
                $supporting = $row['metadata_json']['supporting_evidence_v1']['zh'];
                if (! is_array($beforeSupporting) || ! hash_equals(
                    CareerCurrentAuthorityPackage::hashValue($beforeSupporting),
                    CareerCurrentAuthorityPackage::hashValue($supporting),
                )) {
                    $supportingEvidenceChanges++;
                }
            } else {
                unset($row['metadata_json']['supporting_evidence_v1']);
                if (is_array($beforeSupporting)) {
                    $supportingEvidenceChanges++;
                }
            }
            if ($beforePresentation === null) {
                $presentationAdditions++;
                $presentationChanges++;
            } elseif (! hash_equals(
                CareerCurrentAuthorityPackage::hashValue($beforePresentation),
                CareerCurrentAuthorityPackage::hashValue($presentation),
            )) {
                $presentationChanges++;
            }
            $afterEnHashes[] = CareerCurrentAuthorityPackage::hashValue($this->englishContentProjection($row));
            $afterZhNonRelatedHashes[] = CareerCurrentAuthorityPackage::hashValue($this->zhPageWithoutRelatedLinks($row));
            $candidateRows[$slug] = $row;
        }

        if ($beforeEnHashes !== $afterEnHashes || $beforeZhNonRelatedHashes !== $afterZhNonRelatedHashes) {
            throw new CareerTenBlockCompileFailure('PRESENTATION_V1_EXISTING_PROJECTION_DRIFT');
        }
        $sourceAfter = $this->sourceInspector->inspectSource($sourceRoot);
        if ($sourceBefore !== $sourceAfter) {
            throw new CareerTenBlockCompileFailure('PRESENTATION_V1_SOURCE_BYTES_CHANGED');
        }

        $assetsBytes = implode("\n", array_map(
            static fn (array $row): string => CareerCurrentAuthorityPackage::encodeCanonical($row),
            $candidateRows,
        ))."\n";
        $manifest = $baseline['manifest'];
        $manifest['presentation_v1'] = [
            'compiler_version' => self::VERSION,
            'contract_version' => CareerPresentationV1Contract::CONTRACT_VERSION,
            'design_authority' => [
                'id' => CareerPresentationV1Contract::DESIGN_AUTHORITY_ID,
                'sha256' => CareerPresentationV1Contract::DESIGN_AUTHORITY_SHA256,
            ],
            'source_aggregate_sha256' => $sourceBefore['aggregate_sha256'],
            'field_coverage_sha256' => CareerCurrentAuthorityPackage::hashValue($coverage),
            'source_registry' => $baseline['manifest']['presentation_v1']['source_registry'],
            'zh_presentation_count' => count($candidateRows),
        ];
        $manifest['supporting_evidence_v1'] = [
            'contract_version' => CareerSupportingEvidenceV1Contract::CONTRACT_VERSION,
            'registry_contract_version' => $supportingRegistry['contract_version'],
            'registry_path' => 'supporting-evidence-v1.json',
            'registry_sha256' => $supportingRegistry['sha256'],
            'zh_supporting_evidence_count' => count($supportingRegistry['items']),
        ];

        return [
            'assets_bytes' => $assetsBytes,
            'manifest_template' => $manifest,
            'receipt' => [
                'contract_version' => 'career.presentation_v1.compile_receipt.v1',
                'compiler_version' => self::VERSION,
                'source_aggregate_sha256' => $sourceBefore['aggregate_sha256'],
                'design_authority_sha256' => CareerPresentationV1Contract::DESIGN_AUTHORITY_SHA256,
                'career_count' => count($candidateRows),
                'locale_page_count' => count($candidateRows) * 2,
                'components_per_page' => 26,
                'zh_presentation_count' => count($candidateRows),
                'missing_field_count' => count($missingFields),
                'database_writes' => 0,
                'cache_writes' => 0,
                'cms_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
                'onet_multiple_occupation_records' => count($this->registry['onet']),
                'bls_projection_records' => count($this->registry['bls']),
                'source_reference_changes' => $sourceReferenceChanges,
                'zh_supporting_evidence_count' => count($supportingRegistry['items']),
                'generated_at' => null,
            ],
            'field_coverage' => [
                'contract_version' => 'career.presentation_v1.field_coverage.v1',
                'career_count' => count($candidateRows),
                'fields' => array_values($coverage),
                'missing_fields' => $missingFields,
            ],
            'package_diff' => [
                'contract_version' => 'career.presentation_v1.package_diff.v1',
                'source_bytes_changed' => 0,
                'existing_zh_content_fields_changed' => $relatedNextPageChanges,
                'en_locale_pages_changed' => 0,
                'shared_source_reference_rows_changed' => $sourceReferenceChanges,
                'zh_supporting_evidence_changes' => $supportingEvidenceChanges,
                'zh_related_next_page_changes' => $relatedNextPageChanges,
                'zh_presentation_additions' => $presentationAdditions,
                'zh_presentation_changes' => $presentationChanges,
                'changed_row_count' => $presentationChanges + $sourceReferenceChanges + $supportingEvidenceChanges + $relatedNextPageChanges,
                'slug_count' => count($candidateRows),
                'locale_page_count' => count($candidateRows) * 2,
                'components_per_page' => 26,
                'software_developers_included' => false,
                'canonical_route_inventory_changed' => false,
                'discoverability_surface_changed' => false,
            ],
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $blocks
     * @param  array<string,mixed>  $row
     * @param  array<string,array<string,mixed>>  $coverage
     * @param  list<array{slug:string,field:string,reason:string}>  $missingFields
     * @return array<string,mixed>
     */
    public function project(string $slug, array $blocks, array $row, array &$coverage, array &$missingFields): array
    {
        $identity = $blocks['identity'] ?? [];
        $definition = $blocks['definition'] ?? [];
        $risk = $blocks['risk'] ?? [];
        $pageMeta = $blocks['page-meta'] ?? [];
        $salary = $blocks['salary'] ?? [];
        $page = $this->zhPage($row);

        $titleZh = $this->stringValue($identity, 'title_zh', 'title_zh', $slug, $coverage, $missingFields);
        $titleEn = $this->stringValue($identity, 'title_en', 'title_en', $slug, $coverage, $missingFields);
        $soc = $this->codeValue($identity, 'soc', 'soc_code', '/\A[0-9]{2}-[0-9]{4}\z/', $slug, $coverage, $missingFields);
        $onet = $this->onetCode($identity, $slug, $coverage, $missingFields);
        $interest = $this->stringValue($identity, 'riasec_short', 'interest_badge', $slug, $coverage, $missingFields);
        $scene = $this->stringValue($definition, 'scene', 'scene_badge', $slug, $coverage, $missingFields);
        $riskBadge = $this->stringValue($risk, 'risk_badge', 'risk_badge', $slug, $coverage, $missingFields);
        $lead = $this->stringValue($pageMeta, 'hero_lead', 'hero_lead', $slug, $coverage, $missingFields);
        $note = $this->stringValue($pageMeta, 'gauge_note', 'ai_note', $slug, $coverage, $missingFields);
        $snapshot = $this->stringValue($pageMeta, 'snapshot_callout', 'snapshot_callout', $slug, $coverage, $missingFields);
        $aiScore = $this->aiScore($identity['ai_score'] ?? null, $slug, $coverage, $missingFields);

        $stats = [];
        foreach (self::BLS_STATS as $indicator => $config) {
            $stat = $this->blsStat($salary['bls_table'] ?? null, $indicator, self::STAT_KEYS[$indicator], $config, $slug, $coverage, $missingFields);
            if ($stat !== null) {
                $stats[] = $stat;
            }
        }
        if ($aiScore !== null) {
            $stats[] = [
                'key' => 'ai_exposure',
                'value' => $aiScore.'/10',
                'label' => 'AI 曝光评分',
                'source_label' => 'FermatMind 内部 rubric',
                'source_keys' => ['identity.ai_score'],
                'availability' => 'published',
            ];
        }

        $cta = $this->cta($page, $slug, $coverage, $missingFields);
        $salaryBoundary = $this->nestedString(
            $page,
            ['career_snapshot_primary_locale', 'salary', 'china_salary_note'],
            'salary_boundary',
            $slug,
            $coverage,
            $missingFields,
        );
        $usageBoundary = $this->stringList($page['boundary_notice'] ?? null, 'usage_boundary', $slug, $coverage, $missingFields);

        return [
            'contract_version' => CareerPresentationV1Contract::CONTRACT_VERSION,
            'design_authority' => [
                'id' => CareerPresentationV1Contract::DESIGN_AUTHORITY_ID,
                'sha256' => CareerPresentationV1Contract::DESIGN_AUTHORITY_SHA256,
            ],
            'hero' => [
                'title_zh' => $titleZh,
                'title_en' => $titleEn,
                'soc_code' => $soc,
                'onet_code' => $onet,
                'badges' => [
                    ['key' => 'interest', 'text' => $interest, 'availability' => $this->availability($interest)],
                    ['key' => 'scene', 'text' => $scene, 'availability' => $this->availability($scene)],
                    ['key' => 'risk', 'text' => $riskBadge, 'availability' => $this->availability($riskBadge)],
                ],
                'lead' => $lead,
                'ai_exposure' => [
                    'value' => $aiScore,
                    'scale' => 10,
                    'display_value' => $aiScore === null ? null : $aiScore.'/10',
                    'label' => 'AI 曝光评分',
                    'note' => $note,
                    'metric_kind' => 'fermatmind_internal_rubric',
                    'source_label' => 'FermatMind 内部 rubric',
                    'availability' => $this->availability($aiScore),
                ],
                'stats' => $stats,
                'cta' => $cta,
            ],
            'notices' => [
                'snapshot_callout' => $snapshot,
                'salary_boundary' => $salaryBoundary,
                'usage_boundary' => $usageBoundary,
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function readBlocks(string $sourceRoot, string $slug): array
    {
        $blocks = [];
        foreach (['identity', 'definition', 'risk', 'page-meta', 'salary'] as $block) {
            $path = rtrim($sourceRoot, '/').'/'.$slug.'/'.$block.'.json';
            if (! is_file($path) || is_link($path)) {
                throw new CareerTenBlockCompileFailure('PRESENTATION_V1_SOURCE_BLOCK_MISSING');
            }
            try {
                $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new CareerTenBlockCompileFailure('PRESENTATION_V1_SOURCE_BLOCK_INVALID');
            }
            if (! is_array($value) || array_is_list($value)) {
                throw new CareerTenBlockCompileFailure('PRESENTATION_V1_SOURCE_BLOCK_INVALID');
            }
            $blocks[$block] = $value;
        }

        return $blocks;
    }

    /** @return array<string,array<string,mixed>> */
    public function newCoverage(): array
    {
        $sources = [
            'title_zh' => 'identity.title_zh',
            'title_en' => 'identity.title_en',
            'soc_code' => 'identity.soc',
            'onet_code' => 'identity.onet',
            'interest_badge' => 'identity.riasec_short',
            'scene_badge' => 'definition.scene',
            'risk_badge' => 'risk.risk_badge',
            'hero_lead' => 'page-meta.hero_lead',
            'ai_exposure' => 'identity.ai_score',
            'ai_note' => 'page-meta.gauge_note',
            'snapshot_callout' => 'page-meta.snapshot_callout',
            'us_median_pay' => 'salary.bls_table[指标=中位年薪]',
            'us_growth' => 'salary.bls_table[指标=就业增长]',
            'cta' => 'display_surface_v1.page.content.primary_cta|final_cta',
            'salary_boundary' => 'display_surface_v1.page.content.career_snapshot_primary_locale.salary.china_salary_note',
            'usage_boundary' => 'display_surface_v1.page.content.boundary_notice',
        ];
        $coverage = [];
        foreach ($sources as $slot => $source) {
            $coverage[$slot] = [
                'slot' => $slot,
                'source_field' => $source,
                'present' => 0,
                'missing' => 0,
                'invalid' => 0,
                'not_applicable_single_code_multiple_official_occupations' => 0,
            ];
        }

        return $coverage;
    }

    /** @param array<string,mixed> $source @param array<string,array<string,mixed>> $coverage @param list<array{slug:string,field:string,reason:string}> $missingFields */
    private function stringValue(array $source, string $key, string $slot, string $slug, array &$coverage, array &$missingFields): ?string
    {
        $value = $source[$key] ?? null;
        if ($value === null) {
            $this->record($slot, 'missing', $slug, 'source_field_missing', $coverage, $missingFields);

            return null;
        }
        if (! is_string($value) || trim($value) === '') {
            $this->record($slot, 'invalid', $slug, 'source_field_invalid', $coverage, $missingFields);

            return null;
        }
        $coverage[$slot]['present']++;

        return $value;
    }

    /** @param array<string,mixed> $source @param array<string,array<string,mixed>> $coverage @param list<array{slug:string,field:string,reason:string}> $missingFields */
    private function codeValue(array $source, string $key, string $slot, string $pattern, string $slug, array &$coverage, array &$missingFields): ?string
    {
        $value = $this->stringValueWithoutRecord($source[$key] ?? null);
        if (($source[$key] ?? null) === null) {
            $this->record($slot, 'missing', $slug, 'source_field_missing', $coverage, $missingFields);

            return null;
        }
        if ($value === null || preg_match($pattern, $value) !== 1) {
            $this->record($slot, 'invalid', $slug, 'source_field_invalid', $coverage, $missingFields);

            return null;
        }
        $coverage[$slot]['present']++;

        return $value;
    }

    /** @param array<string,mixed> $source @param array<string,array<string,mixed>> $coverage @param list<array{slug:string,field:string,reason:string}> $missingFields */
    private function onetCode(array $source, string $slug, array &$coverage, array &$missingFields): ?string
    {
        $value = $this->stringValueWithoutRecord($source['onet'] ?? null);
        if ($value !== null) {
            if (preg_match('/\A[0-9]{2}-[0-9]{4}\.[0-9]{2}\z/', $value) !== 1) {
                $this->record('onet_code', 'invalid', $slug, 'source_field_invalid', $coverage, $missingFields);

                return null;
            }
            $coverage['onet_code']['present']++;

            return $value;
        }
        $record = $this->registry()['onet'][$slug] ?? null;
        if (is_array($record) && ($record['summary_soc'] ?? null) === ($source['soc'] ?? null)) {
            $coverage['onet_code']['not_applicable_single_code_multiple_official_occupations']++;

            return null;
        }
        $this->record('onet_code', ($source['onet'] ?? null) === null ? 'missing' : 'invalid', $slug, 'source_field_missing', $coverage, $missingFields);

        return null;
    }

    /** @param array<string,array<string,mixed>> $coverage @param list<array{slug:string,field:string,reason:string}> $missingFields */
    private function aiScore(mixed $value, string $slug, array &$coverage, array &$missingFields): ?int
    {
        if ($value === null) {
            $this->record('ai_exposure', 'missing', $slug, 'source_field_missing', $coverage, $missingFields);

            return null;
        }
        if (! is_int($value) || $value < 0 || $value > 10) {
            $this->record('ai_exposure', 'invalid', $slug, 'source_field_invalid', $coverage, $missingFields);

            return null;
        }
        $coverage['ai_exposure']['present']++;

        return $value;
    }

    /** @param array{label:string,source_field:string} $config @param array<string,array<string,mixed>> $coverage @param list<array{slug:string,field:string,reason:string}> $missingFields @return array<string,mixed>|null */
    private function blsStat(mixed $table, string $indicator, string $key, array $config, string $slug, array &$coverage, array &$missingFields): ?array
    {
        $rows = [];
        $variantSchema = false;
        if (is_array($table) && array_is_list($table)) {
            foreach ($table as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rowIndicator = $this->canonicalBlsIndicator($row['指标'] ?? $row['label'] ?? null);
                if ($rowIndicator === $indicator) {
                    $rows[] = $row;
                    $variantSchema = array_key_exists('label', $row);
                }
            }
        }
        if ($rows === []) {
            $this->record($key, 'missing', $slug, 'structured_metric_missing', $coverage, $missingFields);

            return null;
        }
        $value = count($rows) === 1 ? $this->blsValue($rows[0]) : null;
        if ($value === null) {
            $this->record($key, 'invalid', $slug, 'structured_metric_invalid', $coverage, $missingFields);

            return null;
        }
        $registry = $this->registry()['bls'][$slug] ?? null;
        if ($variantSchema) {
            if (! is_array($registry) || ($registry['metrics'][$indicator] ?? null) !== $value) {
                throw new CareerTenBlockCompileFailure('PRESENTATION_V1_BLS_SOURCE_VALUE_CONFLICT');
            }
        }
        $coverage[$key]['present']++;

        return [
            'key' => $key,
            'value' => $value,
            'label' => $config['label'],
            'source_label' => is_array($registry)
                ? $this->blsSourceLabel($registry)
                : $this->stringValueWithoutRecord($rows[0]['说明'] ?? null),
            'source_keys' => [is_array($registry) ? $registry['source_key'] : $config['source_field']],
            'availability' => 'published',
        ];
    }

    private function canonicalBlsIndicator(mixed $value): ?string
    {
        return match ($this->stringValueWithoutRecord($value)) {
            '中位年薪', '美国中位数年薪' => '中位年薪',
            '就业增长', '2024–2034 就业增长' => '就业增长',
            '在岗人数' => '在岗人数',
            '年均职位空缺' => '年均职位空缺',
            default => null,
        };
    }

    /** @param array<string,mixed> $row */
    private function blsValue(array $row): ?string
    {
        $number = $this->stringValueWithoutRecord($row['数值'] ?? null);
        $value = $this->stringValueWithoutRecord($row['value'] ?? null);
        if ($number !== null && $value !== null && $number !== $value) {
            throw new CareerTenBlockCompileFailure('PRESENTATION_V1_BLS_SOURCE_VALUE_CONFLICT');
        }

        return $number ?? $value;
    }

    /** @param array<string,mixed> $record */
    private function blsSourceLabel(array $record): string
    {
        $scope = match ($record['source_scope']) {
            'exact' => '精确职业',
            'combined_official' => '官方组合口径',
            'parent_occupation_proxy' => '上级职业代理：'.$record['title'],
        };

        return 'BLS '.$record['data_year'].' · '.$scope;
    }

    /** @param array<string,mixed> $page @param array<string,array<string,mixed>> $coverage @param list<array{slug:string,field:string,reason:string}> $missingFields @return array<string,mixed> */
    private function cta(array $page, string $slug, array &$coverage, array &$missingFields): array
    {
        $cta = is_array($page['primary_cta'] ?? null) ? $page['primary_cta']
            : (is_array($page['final_cta'] ?? null) ? $page['final_cta'] : []);
        $label = $this->stringValueWithoutRecord($cta['label'] ?? null);
        $href = $this->localizedHref($cta['href'] ?? null);
        if ($label === null || $href === null) {
            $this->record('cta', ($cta === [] ? 'missing' : 'invalid'), $slug, 'published_cta_unavailable', $coverage, $missingFields);

            return ['label' => null, 'href' => null, 'availability' => 'missing'];
        }
        $coverage['cta']['present']++;

        return ['label' => $label, 'href' => $href, 'availability' => 'published'];
    }

    private function localizedHref(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $candidates = preg_split('/\s*\|\s*/', trim($value)) ?: [];
        foreach ($candidates as $candidate) {
            if (str_starts_with($candidate, '/zh/')) {
                return $candidate;
            }
        }
        $first = $candidates[0] ?? null;
        if (! is_string($first) || trim($first) === '') {
            return null;
        }

        return str_starts_with($first, '/en/') ? '/zh/'.substr($first, 4) : $first;
    }

    /** @param array<string,mixed> $source @param list<string> $path @param array<string,array<string,mixed>> $coverage @param list<array{slug:string,field:string,reason:string}> $missingFields */
    private function nestedString(array $source, array $path, string $slot, string $slug, array &$coverage, array &$missingFields): ?string
    {
        $value = $source;
        foreach ($path as $segment) {
            $value = is_array($value) ? ($value[$segment] ?? null) : null;
        }
        $normalized = $this->stringValueWithoutRecord($value);
        if ($normalized === null) {
            $this->record($slot, $value === null ? 'missing' : 'invalid', $slug, 'published_field_unavailable', $coverage, $missingFields);

            return null;
        }
        $coverage[$slot]['present']++;

        return $normalized;
    }

    /** @param array<string,array<string,mixed>> $coverage @param list<array{slug:string,field:string,reason:string}> $missingFields @return list<string> */
    private function stringList(mixed $value, string $slot, string $slug, array &$coverage, array &$missingFields): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            $this->record($slot, $value === null ? 'missing' : 'invalid', $slug, 'published_field_unavailable', $coverage, $missingFields);

            return [];
        }
        $items = [];
        foreach ($value as $item) {
            $normalized = $this->stringValueWithoutRecord($item);
            if ($normalized === null) {
                $this->record($slot, 'invalid', $slug, 'published_field_invalid', $coverage, $missingFields);

                return [];
            }
            $items[] = $normalized;
        }
        $coverage[$slot]['present']++;

        return $items;
    }

    /** @param array<string,array<string,mixed>> $coverage @param list<array{slug:string,field:string,reason:string}> $missingFields */
    private function record(string $slot, string $state, string $slug, string $reason, array &$coverage, array &$missingFields): void
    {
        $coverage[$slot][$state]++;
        $missingFields[] = ['slug' => $slug, 'field' => $slot, 'reason' => $reason];
    }

    private function stringValueWithoutRecord(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function availability(mixed $value): string
    {
        return $value === null ? 'missing' : 'published';
    }

    /** @return array{document:array<string,mixed>,onet:array<string,array<string,mixed>>,bls:array<string,array<string,mixed>>} */
    private function registry(): array
    {
        if ($this->registry === null) {
            $authority = $this->package->load(base_path());
            $this->registry = $this->sourceRegistry->load(base_path(), $authority['manifest']);
        }

        return $this->registry;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeMultipleOnetReferences(string $slug, array $row, int &$changes): array
    {
        $record = $this->registry()['onet'][$slug] ?? null;
        if (! is_array($record)) {
            return $row;
        }
        $sources = is_array($row['sources_json'] ?? null) ? $row['sources_json'] : [];
        $references = is_array($sources['references'] ?? null) && array_is_list($sources['references'])
            ? $sources['references'] : [];
        $filtered = [];
        $insertAt = null;
        foreach ($references as $reference) {
            if (! is_array($reference)) {
                $filtered[] = $reference;

                continue;
            }
            $url = (string) ($reference['url'] ?? '');
            $label = (string) ($reference['label'] ?? '');
            $isOnet = str_contains($url, 'onetonline.org/link/details/') || str_contains($label, 'O*NET');
            $isSupersededElectrical = str_contains($url, 'oes173012') || str_contains($label, '17-3012');
            if ($isOnet || $isSupersededElectrical) {
                $insertAt ??= count($filtered);

                continue;
            }
            $filtered[] = $reference;
        }
        $replacement = array_map(static fn (array $child): array => [
            'label' => 'O*NET OnLine: '.$child['title'].' '.$child['code'],
            'source_type' => 'official',
            'url' => $child['official_url'],
            'usage' => 'Official child occupation identity for this multiple-occupation summary; not a fabricated single O*NET code.',
        ], $record['child_occupations']);
        array_splice($filtered, $insertAt ?? count($filtered), 0, $replacement);
        if (CareerCurrentAuthorityPackage::hashValue($references) !== CareerCurrentAuthorityPackage::hashValue($filtered)) {
            $changes++;
        }
        $sources['references'] = $filtered;
        $row['sources_json'] = $sources;

        return $row;
    }

    /**
     * @param  list<string>  $canonicalSlugs
     * @return array{contract_version:string,reviewed_at:string,items:array<string,array<string,mixed>>,sha256:string}
     */
    private function supportingRegistry(string $backendRoot, array $canonicalSlugs): array
    {
        $path = rtrim($backendRoot, '/').'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/supporting-evidence-v1.json';
        if (! is_file($path) || is_link($path)) {
            throw new CareerTenBlockCompileFailure('SUPPORTING_EVIDENCE_V1_REGISTRY_MISSING');
        }
        try {
            $registry = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerTenBlockCompileFailure('SUPPORTING_EVIDENCE_V1_REGISTRY_INVALID');
        }
        if (! is_array($registry) || array_keys($registry) !== ['contract_version', 'reviewed_at', 'items']
            || ($registry['contract_version'] ?? null) !== 'career.supporting_evidence.registry.v1'
            || ! is_string($registry['reviewed_at'] ?? null)
            || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $registry['reviewed_at']) !== 1
            || ! is_array($registry['items'] ?? null) || array_is_list($registry['items'])) {
            throw new CareerTenBlockCompileFailure('SUPPORTING_EVIDENCE_V1_REGISTRY_INVALID');
        }
        $allowedSlugs = array_fill_keys($canonicalSlugs, true);
        foreach ($registry['items'] as $slug => $item) {
            if (! is_string($slug) || ! isset($allowedSlugs[$slug]) || ! is_array($item)
                || array_keys($item) !== ['sources', 'evidence']
                || ! is_array($item['sources']) || ! array_is_list($item['sources']) || $item['sources'] === []
                || ! is_array($item['evidence'])) {
                throw new CareerTenBlockCompileFailure('SUPPORTING_EVIDENCE_V1_REGISTRY_INVALID');
            }
            $sourceKeys = [];
            foreach ($item['sources'] as $source) {
                if (! is_array($source)) {
                    throw new CareerTenBlockCompileFailure('SUPPORTING_EVIDENCE_V1_REGISTRY_INVALID');
                }
                $key = $this->stringValueWithoutRecord($source['source_key'] ?? null);
                $url = $this->stringValueWithoutRecord($source['url'] ?? null);
                if ($key === null || isset($sourceKeys[$key]) || $url === null
                    || filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with($url, 'https://')) {
                    throw new CareerTenBlockCompileFailure('SUPPORTING_EVIDENCE_V1_REGISTRY_INVALID');
                }
                $sourceKeys[$key] = true;
            }
        }

        return [
            'contract_version' => $registry['contract_version'],
            'reviewed_at' => $registry['reviewed_at'],
            'items' => $registry['items'],
            'sha256' => (string) hash_file('sha256', $path),
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $item @return array<string,mixed> */
    private function applySupportingEvidence(array $row, array $item, int &$sourceReferenceChanges): array
    {
        $sources = is_array($row['sources_json'] ?? null) ? $row['sources_json'] : [];
        $references = is_array($sources['references'] ?? null) && array_is_list($sources['references'])
            ? $sources['references'] : [];
        $before = CareerCurrentAuthorityPackage::hashValue($references);
        $positions = [];
        foreach ($references as $index => $reference) {
            if (! is_array($reference)) {
                continue;
            }
            $key = $this->stringValueWithoutRecord($reference['source_key'] ?? $reference['label'] ?? null);
            if ($key !== null && ! isset($positions[$key])) {
                $positions[$key] = $index;
            }
        }
        foreach ($item['sources'] as $source) {
            $key = (string) $source['source_key'];
            if (isset($positions[$key])) {
                $references[$positions[$key]] = $source;
            } else {
                $positions[$key] = count($references);
                $references[] = $source;
            }
        }
        if (! hash_equals($before, CareerCurrentAuthorityPackage::hashValue($references))) {
            $sourceReferenceChanges++;
        }
        $sources['references'] = $references;
        $row['sources_json'] = $sources;
        CareerSupportingEvidenceV1Contract::assert($item['evidence'], $references);
        $row['metadata_json']['supporting_evidence_v1'] = ['zh' => $item['evidence']];

        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function englishContentProjection(array $row): array
    {
        $projection = $this->package->publicProjection($row, 'en');
        unset($projection['sources']);

        return $projection;
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,string>  $titleZhBySlug
     * @return array<string,mixed>
     */
    private function compileRelatedNextPages(string $currentSlug, array $row, array $titleZhBySlug, int &$changes): array
    {
        $page = $this->zhPage($row);
        $related = $page['related_next_pages'] ?? null;
        if (! is_array($related)) {
            return $row;
        }
        $links = is_array($related['links'] ?? null) && array_is_list($related['links']) ? $related['links'] : [];
        $candidates = [];
        $seenSlugs = [];
        foreach ($links as $index => $link) {
            if (! is_array($link)) {
                continue;
            }
            $slug = strtolower(trim((string) ($link['slug'] ?? '')));
            $source = (string) ($link['source'] ?? '');
            $titleEn = trim((string) ($link['title_en'] ?? ''));
            if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
                || $slug === $currentSlug || $slug === 'software-developers' || isset($seenSlugs[$slug])
                || ! isset($titleZhBySlug[$slug]) || ! in_array($source, ['self_pick', 'lookup'], true)
                || $titleEn === '' || ! is_bool($link['nofollow'] ?? null)) {
                continue;
            }
            $seenSlugs[$slug] = true;
            $candidates[] = [
                'index' => $index,
                'slug' => $slug,
                'title_en' => $titleEn,
                'title_zh' => $titleZhBySlug[$slug],
                'source' => $source,
                'nofollow' => $link['nofollow'],
            ];
        }
        usort($candidates, static fn (array $left, array $right): int => [
            $left['source'] === 'self_pick' ? 0 : 1, $left['index'],
        ] <=> [
            $right['source'] === 'self_pick' ? 0 : 1, $right['index'],
        ]);
        $normalized = [];
        $seenTitles = [];
        foreach ($candidates as $candidate) {
            $titleKey = mb_strtolower($candidate['title_zh'], 'UTF-8');
            if (isset($seenTitles[$titleKey])) {
                continue;
            }
            $seenTitles[$titleKey] = true;
            unset($candidate['index']);
            $normalized[] = $candidate;
            if (count($normalized) === 12) {
                break;
            }
        }
        if (! hash_equals(
            CareerCurrentAuthorityPackage::hashValue($links),
            CareerCurrentAuthorityPackage::hashValue($normalized),
        )) {
            $changes++;
        }
        $related['links'] = $normalized;
        $page['related_next_pages'] = $related;
        $payload = is_array($row['page_payload_json'] ?? null) ? $row['page_payload_json'] : [];
        if (is_array($payload['page'] ?? null)) {
            $row['page_payload_json']['page']['zh'] = $page;
        } else {
            $row['page_payload_json']['zh'] = $page;
        }

        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function zhPageWithoutRelatedLinks(array $row): array
    {
        $page = $this->zhPage($row);
        if (is_array($page['related_next_pages'] ?? null)) {
            unset($page['related_next_pages']['links']);
        }

        return $page;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function zhPage(array $row): array
    {
        $payload = is_array($row['page_payload_json'] ?? null) ? $row['page_payload_json'] : [];
        $pages = is_array($payload['page'] ?? null) ? $payload['page'] : $payload;

        return is_array($pages['zh'] ?? null) ? $pages['zh'] : [];
    }
}
