<?php

declare(strict_types=1);

use App\Domain\Career\Compilation\CareerEvidenceAuthorityLoader;
use App\Domain\Career\Compilation\CareerTenBlockCompileFailure;
use App\Domain\Career\Compilation\CareerTenBlockSchemaDetector;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use Illuminate\Contracts\Console\Kernel;

const ADAPTER_VERSION = 'career.research.compiler_evidence_adapter.v1';
const REQUIRED_CLAIM_KEYS = [
    'definition.summary',
    'duties.list',
    'faq.items',
    'hero.lead',
    'identity.title_en',
    'identity.title_zh',
    'seo.description',
    'seo.title',
    'work_context.summary',
];
const CLAIM_CONTRACT = [
    'definition.summary' => ['$.definition.definition', 'definition_block', '$.page_payload_json.page.zh.definition_block'],
    'duties.list' => ['$.definition.duties', 'responsibilities_block', '$.page_payload_json.page.zh.responsibilities_block'],
    'faq.items' => ['$.faq.faq', 'faq_block', '$.structured_data_json.faq_page.zh'],
    'hero.lead' => ['$.page-meta.hero_lead', 'hero', '$.page_payload_json.page.zh.hero.quick_answer'],
    'identity.title_en' => ['$.identity.title_en', 'hero', '$.page_payload_json.page.en.hero.title'],
    'identity.title_zh' => ['$.identity.title_zh', 'hero', '$.page_payload_json.page.zh.hero.title'],
    'seo.description' => ['$.page-meta.meta_description', 'seo', '$.seo_payload_json.zh.description'],
    'seo.title' => ['$.page-meta.meta_title', 'seo', '$.seo_payload_json.zh.title'],
    'work_context.summary' => ['$.definition.work_scene', 'work_context_block', '$.page_payload_json.page.zh.work_context_block'],
];

final class AdapterFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

/** @return never */
function failAdapter(string $safeCode): void
{
    throw new AdapterFailure($safeCode);
}

/** @return array<string,mixed> */
function jsonObject(string $path, string $safeCode): array
{
    if (! is_file($path) || is_link($path)) {
        failAdapter($safeCode);
    }
    try {
        $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        failAdapter($safeCode);
    }
    if (! is_array($value) || array_is_list($value)) {
        failAdapter($safeCode);
    }

    return $value;
}

/** @return list<array<string,mixed>> */
function jsonLines(string $path, string $safeCode): array
{
    if (! is_file($path) || is_link($path)) {
        failAdapter($safeCode);
    }
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if ($line === '') {
            failAdapter($safeCode);
        }
        try {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            failAdapter($safeCode);
        }
        if (! is_array($row) || array_is_list($row)) {
            failAdapter($safeCode);
        }
        $rows[] = $row;
    }

    return $rows;
}

function isoDate(mixed $value, string $safeCode): string
{
    if (! is_string($value)) {
        failAdapter($safeCode);
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($date === false || $date->format('Y-m-d') !== $value) {
        failAdapter($safeCode);
    }

    return $value;
}

function localeForCompiler(mixed $locale): string
{
    return match ($locale) {
        'zh-CN' => 'zh',
        'en' => 'en',
        default => failAdapter('ADAPTER_LOCALE_UNSUPPORTED'),
    };
}

function resolvedDirectory(string $path, string $safeCode): string
{
    if (is_link($path)) {
        failAdapter($safeCode);
    }
    $resolved = realpath($path);
    if ($resolved === false || ! is_dir($resolved)) {
        failAdapter($safeCode);
    }

    return $resolved;
}

function assertOutputBoundary(string $root, string $repoRoot): string
{
    $resolved = resolvedDirectory($root, 'ADAPTER_OUTPUT_ROOT_FORBIDDEN');
    $tempRoots = array_values(array_filter([
        realpath(sys_get_temp_dir()),
        realpath('/tmp'),
    ], 'is_string'));
    $insideTemp = false;
    foreach ($tempRoots as $tempRoot) {
        if (str_starts_with($resolved.'/', rtrim($tempRoot, '/').'/')) {
            $insideTemp = true;
        }
    }
    $forbiddenParts = ['current', 'career-en-translation', 'zh-master', 'zh_master', '中文母版'];
    $parts = array_map('strtolower', preg_split('~/+~', $resolved, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    if (! $insideTemp
        || str_starts_with($resolved.'/', rtrim($repoRoot, '/').'/')
        || array_intersect($parts, $forbiddenParts) !== []) {
        failAdapter('ADAPTER_OUTPUT_ROOT_FORBIDDEN');
    }
    foreach (scandir($resolved) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_link($resolved.'/'.$entry)) {
            failAdapter('ADAPTER_OUTPUT_ROOT_FORBIDDEN');
        }
        if (! in_array($entry, [
            'manifest.json', 'source-registry.jsonl', 'claim-bindings.jsonl',
            'schema-profile-manifest.json', 'cohort.json', 'selection-report.json', 'adapter-receipt.json',
        ], true)) {
            failAdapter('ADAPTER_OUTPUT_ROOT_NOT_EMPTY');
        }
    }

    return $resolved;
}

/** @return array<string,mixed> */
function runResearchValidator(string $repoRoot, string $packageRoot): array
{
    $validator = $repoRoot.'/.agents/skills/fap-api-career-content-research-producer/scripts/validate_research_package.py';
    $pipes = [];
    $process = proc_open(['python3', $validator, $packageRoot], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $repoRoot);
    if (! is_resource($process)) {
        failAdapter('ADAPTER_RESEARCH_VALIDATOR_FAILED');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    try {
        $report = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        failAdapter('ADAPTER_RESEARCH_VALIDATOR_FAILED');
    }
    if ($status !== 0 || ! is_array($report) || ($report['ok'] ?? false) !== true || $stderr !== '') {
        failAdapter('ADAPTER_RESEARCH_PACKAGE_INVALID');
    }

    return $report;
}

function jsonPointerValue(array $document, string $pointer): mixed
{
    if ($pointer === '') {
        return $document;
    }
    if (! str_starts_with($pointer, '/')) {
        failAdapter('ADAPTER_RESEARCH_POINTER_INVALID');
    }
    $value = $document;
    foreach (explode('/', substr($pointer, 1)) as $rawSegment) {
        $segment = str_replace(['~1', '~0'], ['/', '~'], $rawSegment);
        if (! is_array($value) || ! array_key_exists($segment, $value)) {
            failAdapter('ADAPTER_RESEARCH_POINTER_INVALID');
        }
        $value = $value[$segment];
    }

    return $value;
}

function compilerInputValue(array $blocks, string $path): mixed
{
    if (preg_match('/\A\$\.([a-z-]+)((?:\.[a-z0-9_]+)+)\z/', $path, $matches) !== 1) {
        failAdapter('ADAPTER_COMPILER_JSONPATH_INVALID');
    }
    $value = $blocks[$matches[1].'.json'] ?? null;
    foreach (array_filter(explode('.', $matches[2])) as $segment) {
        if (! is_array($value) || ! array_key_exists($segment, $value)) {
            failAdapter('ADAPTER_COMPILER_JSONPATH_INVALID');
        }
        $value = $value[$segment];
    }

    return $value;
}

/** @return array<string,array<string,mixed>> */
function baselineRows(string $path, array $slugs, CareerCurrentAuthorityPackage $package): array
{
    if (! is_file($path) || is_link($path)) {
        failAdapter('ADAPTER_BASELINE_INVALID');
    }
    $wanted = array_fill_keys($slugs, true);
    $rows = [];
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        failAdapter('ADAPTER_BASELINE_INVALID');
    }
    while (($line = fgets($handle)) !== false) {
        if (trim($line) === '') {
            continue;
        }
        try {
            $row = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            fclose($handle);
            failAdapter('ADAPTER_BASELINE_INVALID');
        }
        $slug = is_array($row) ? ($row['canonical_slug'] ?? null) : null;
        if (is_string($slug) && isset($wanted[$slug])) {
            if (isset($rows[$slug])) {
                failAdapter('ADAPTER_BASELINE_DUPLICATE_SLUG');
            }
            $rows[$slug] = [
                'public_content_sha256' => [
                    'en' => $package->publicContentHash($row, 'en'),
                    'zh-CN' => $package->publicContentHash($row, 'zh-CN'),
                ],
                'row_sha256' => CareerCurrentAuthorityPackage::hashValue($row),
            ];
        }
    }
    fclose($handle);
    if (array_diff($slugs, array_keys($rows)) !== []) {
        failAdapter('ADAPTER_BASELINE_SLUG_MISSING');
    }
    $ordered = [];
    foreach ($slugs as $slug) {
        $ordered[$slug] = $rows[$slug];
    }

    return $ordered;
}

function pretty(array $value): string
{
    return CareerCurrentAuthorityPackage::encodePrettyCanonical($value)."\n";
}

/** @param list<array<string,mixed>> $rows */
function jsonl(array $rows): string
{
    return implode('', array_map(
        static fn (array $row): string => CareerCurrentAuthorityPackage::encodeCanonical($row)."\n",
        $rows,
    ));
}

function stageFile(string $root, string $name, string $bytes): void
{
    $temporary = $root.'/.'.$name.'.tmp';
    if (file_put_contents($temporary, $bytes, LOCK_EX) === false || ! rename($temporary, $root.'/'.$name)) {
        failAdapter('ADAPTER_OUTPUT_WRITE_FAILED');
    }
}

function removeTree(string $root): void
{
    if (! is_dir($root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}

/** @return array<string,string> */
function options(): array
{
    $parsed = getopt('', [
        'research-package:', 'source-root:', 'lookup:', 'baseline-assets:',
        'control-slug:', 'target-slug:', 'evaluation-date:', 'output-root:',
    ]);
    if (! is_array($parsed)) {
        failAdapter('ADAPTER_ARGUMENTS_INVALID');
    }
    $required = [
        'research-package', 'source-root', 'lookup', 'baseline-assets',
        'control-slug', 'target-slug', 'evaluation-date', 'output-root',
    ];
    foreach ($required as $key) {
        if (! isset($parsed[$key]) || ! is_string($parsed[$key]) || trim($parsed[$key]) === '') {
            failAdapter('ADAPTER_ARGUMENTS_INVALID');
        }
    }

    return array_map('trim', $parsed);
}

/** @return array<string,mixed> */
function adapt(array $options, string $repoRoot): array
{
    $researchRoot = resolvedDirectory($options['research-package'], 'ADAPTER_RESEARCH_PACKAGE_INVALID');
    $sourceRoot = resolvedDirectory($options['source-root'], 'ADAPTER_SOURCE_ROOT_INVALID');
    $outputRoot = assertOutputBoundary($options['output-root'], $repoRoot);
    $evaluationDate = isoDate($options['evaluation-date'], 'ADAPTER_EVALUATION_DATE_INVALID');
    $control = $options['control-slug'];
    $target = $options['target-slug'];
    if ($control === $target
        || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $control) !== 1
        || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $target) !== 1) {
        failAdapter('ADAPTER_COHORT_INVALID');
    }
    $slugs = [$control, $target];
    $researchValidation = runResearchValidator($repoRoot, $researchRoot);
    $researchReceipt = jsonObject($researchRoot.'/research-receipt.json', 'ADAPTER_RESEARCH_PACKAGE_INVALID');
    if (($researchReceipt['schema_version'] ?? null) !== 'career.research-receipt.v1'
        || array_diff($slugs, $researchReceipt['slugs'] ?? []) !== []) {
        failAdapter('ADAPTER_RESEARCH_COHORT_MISMATCH');
    }
    $lookup = jsonObject($options['lookup'], 'ADAPTER_LOOKUP_INVALID');
    $lookupRows = $lookup['by_slug'] ?? null;
    if (! is_array($lookupRows) || array_is_list($lookupRows)) {
        failAdapter('ADAPTER_LOOKUP_INVALID');
    }

    /** @var CareerTenBlockSchemaDetector $detector */
    $detector = app(CareerTenBlockSchemaDetector::class);
    /** @var CareerCurrentAuthorityPackage $package */
    $package = app(CareerCurrentAuthorityPackage::class);
    $detectedBySlug = [];
    $profiles = [];
    $inputDigests = [];
    foreach ($slugs as $slug) {
        try {
            $detected = $detector->detect($sourceRoot, $slug);
        } catch (CareerTenBlockCompileFailure $failure) {
            failAdapter($failure->safeCode);
        }
        $identity = $detected['blocks']['identity.json'];
        $lookupRow = $lookupRows[$slug] ?? null;
        if (! is_array($lookupRow)
            || ($lookupRow['canonical_slug'] ?? null) !== ($identity['slug'] ?? null)
            || ($lookupRow['soc_code'] ?? null) !== ($identity['soc'] ?? null)
            || ($lookupRow['onet_code'] ?? null) !== ($identity['onet'] ?? null)
            || ($lookupRow['ai_score'] ?? null) !== ($identity['ai_score'] ?? null)) {
            failAdapter('ADAPTER_LOOKUP_MISMATCH');
        }
        $detectedBySlug[$slug] = $detected;
        $inputDigests[$slug] = $detected['input_digest'];
        $profiles[$slug] = [
            'input_digest' => $detected['input_digest'],
            'input_profile' => $detected['profile'],
            'profile_version' => 'career.evidence.bound.v1',
            'required_claim_keys' => REQUIRED_CLAIM_KEYS,
            'unsupported_candidate_claim_policy' => 'explicit_not_compiler_mapped',
        ];
    }

    $researchSources = jsonLines($researchRoot.'/source-registry.jsonl', 'ADAPTER_RESEARCH_PACKAGE_INVALID');
    $sourcesByKey = [];
    foreach ($researchSources as $source) {
        $key = $source['source_key'] ?? null;
        if (! is_string($key) || isset($sourcesByKey[$key])) {
            failAdapter('ADAPTER_SOURCE_KEY_DUPLICATE');
        }
        $sourcesByKey[$key] = $source;
    }

    $compilerSources = [];
    $compilerSourcesByKey = [];
    $compilerClaims = [];
    $claimKeysBySlug = array_fill_keys($slugs, []);
    $unmapped = [];
    foreach (jsonLines($researchRoot.'/claim-bindings.jsonl', 'ADAPTER_RESEARCH_PACKAGE_INVALID') as $index => $claim) {
        $slug = $claim['slug'] ?? null;
        if (! is_string($slug) || ! isset($detectedBySlug[$slug])) {
            continue;
        }
        $disposition = $claim['compiler_disposition'] ?? null;
        if ($disposition === 'not_compiler_mapped') {
            if (! is_string($claim['compiler_unmapped_reason'] ?? null) || $claim['compiler_unmapped_reason'] === '') {
                failAdapter('ADAPTER_UNMAPPED_REASON_MISSING');
            }
            $unmapped[] = [
                'claim_index' => $index + 1,
                'json_pointer' => $claim['json_pointer'] ?? null,
                'module' => $claim['module'] ?? null,
                'reason' => $claim['compiler_unmapped_reason'],
                'slug' => $slug,
                'status' => 'not_compiler_mapped',
            ];

            continue;
        }
        if ($disposition !== 'mapped' || ! is_array($claim['compiler_mapping'] ?? null)) {
            failAdapter('ADAPTER_COMPILER_MAPPING_MISSING');
        }
        $mapping = $claim['compiler_mapping'];
        $requiredMapping = [
            'compiler_claim_key', 'compiler_claim_kind', 'input_jsonpath', 'component_id',
            'authority_output_jsonpath', 'claim_mode', 'confidence', 'evidence_basis',
            'proxy', 'proxy_boundary', 'expires_at',
        ];
        foreach ($requiredMapping as $field) {
            if (! array_key_exists($field, $mapping)) {
                failAdapter('ADAPTER_COMPILER_MAPPING_MISSING');
            }
        }
        $claimKey = $mapping['compiler_claim_key'];
        if (! is_string($claimKey) || ! isset(CLAIM_CONTRACT[$claimKey])) {
            failAdapter('ADAPTER_COMPILER_CLAIM_UNSUPPORTED');
        }
        if ([$mapping['input_jsonpath'], $mapping['component_id'], $mapping['authority_output_jsonpath']] !== CLAIM_CONTRACT[$claimKey]) {
            failAdapter('ADAPTER_COMPILER_MAPPING_CONFLICT');
        }
        if (isset($claimKeysBySlug[$slug][$claimKey])) {
            failAdapter('ADAPTER_CLAIM_KEY_DUPLICATE');
        }
        $sourceKeys = $claim['source_keys'] ?? null;
        if (! is_array($sourceKeys) || $sourceKeys === []) {
            failAdapter('ADAPTER_CLAIM_SOURCE_MISSING');
        }
        $claimLocale = localeForCompiler($claim['locale'] ?? null);
        $claimExpiry = isoDate($mapping['expires_at'], 'ADAPTER_CLAIM_DATE_INVALID');
        if ($claimExpiry < $evaluationDate) {
            failAdapter('ADAPTER_CLAIM_EXPIRED');
        }
        $sourceTuple = null;
        foreach ($sourceKeys as $sourceKey) {
            $source = is_string($sourceKey) ? ($sourcesByKey[$sourceKey] ?? null) : null;
            $compiler = is_array($source) ? ($source['compiler_metadata'] ?? null) : null;
            if (! is_array($source) || ! is_array($compiler)) {
                failAdapter('ADAPTER_SOURCE_COMPILER_METADATA_MISSING');
            }
            $requiredSource = [
                'authority', 'trust_certification', 'market', 'locale', 'claim_kinds',
                'captured_at', 'effective_period', 'confidence_method', 'usage', 'expires_at',
            ];
            foreach ($requiredSource as $field) {
                if (! array_key_exists($field, $compiler)) {
                    failAdapter('ADAPTER_SOURCE_COMPILER_METADATA_MISSING');
                }
            }
            $sourceLocale = localeForCompiler($compiler['locale']);
            $capturedAt = isoDate($compiler['captured_at'], 'ADAPTER_SOURCE_DATE_INVALID');
            $sourceExpiry = isoDate($compiler['expires_at'], 'ADAPTER_SOURCE_DATE_INVALID');
            if ($capturedAt > $evaluationDate
                || $sourceExpiry < $evaluationDate
                || $sourceExpiry !== $claimExpiry) {
                failAdapter('ADAPTER_SOURCE_EXPIRED_OR_EXPIRY_MISMATCH');
            }
            $tuple = [$compiler['market'], $sourceLocale, $capturedAt, $compiler['effective_period'], $sourceExpiry];
            if ($sourceTuple !== null && $sourceTuple !== $tuple) {
                failAdapter('ADAPTER_CLAIM_SOURCE_MISMATCH');
            }
            $sourceTuple = $tuple;
            if ($claimLocale !== $sourceLocale || ($claim['jurisdiction'] ?? null) !== $compiler['market']) {
                failAdapter('ADAPTER_CLAIM_SOURCE_MISMATCH');
            }
            if (! isset($compilerSourcesByKey[$sourceKey])) {
                $compilerRow = [
                    'authority' => $compiler['authority'],
                    'captured_at' => $capturedAt,
                    'claim_kinds' => $compiler['claim_kinds'],
                    'confidence_method' => $compiler['confidence_method'],
                    'contract_version' => 'career.source_registry.v1',
                    'effective_period' => $compiler['effective_period'],
                    'evidence_digest' => $source['content_sha256'],
                    'expires_at' => $sourceExpiry,
                    'locale' => $sourceLocale,
                    'market' => $compiler['market'],
                    'publisher' => $source['publisher'],
                    'source_key' => $sourceKey,
                    'title' => $source['title'],
                    'trust_certification' => $compiler['trust_certification'],
                    'url' => $source['url'],
                    'usage' => $compiler['usage'],
                ];
                $compilerSourcesByKey[$sourceKey] = $compilerRow;
                $compilerSources[] = $compilerRow;
            }
        }
        if ($sourceTuple === null) {
            failAdapter('ADAPTER_CLAIM_SOURCE_MISSING');
        }
        $module = $claim['module'] ?? null;
        $pointer = $claim['json_pointer'] ?? null;
        if (! is_string($module) || ! is_string($pointer)) {
            failAdapter('ADAPTER_RESEARCH_POINTER_INVALID');
        }
        $researchModule = jsonObject(
            $researchRoot.'/careers/'.$slug.'/'.$module.'.json',
            'ADAPTER_RESEARCH_POINTER_INVALID',
        );
        $researchValue = jsonPointerValue($researchModule, $pointer);
        $compilerValue = compilerInputValue($detectedBySlug[$slug]['blocks'], (string) $mapping['input_jsonpath']);
        if (! hash_equals(
            CareerCurrentAuthorityPackage::hashValue($researchValue),
            CareerCurrentAuthorityPackage::hashValue($compilerValue),
        )) {
            failAdapter('ADAPTER_RESEARCH_COMPILER_VALUE_MISMATCH');
        }
        [$market, , $capturedAt, $effectivePeriod] = $sourceTuple;
        $compilerClaims[] = [
            'authority_output_jsonpath' => $mapping['authority_output_jsonpath'],
            'blocker_codes' => [],
            'canonical_slug' => $slug,
            'captured_at' => $capturedAt,
            'claim_key' => $claimKey,
            'claim_kind' => $mapping['compiler_claim_kind'],
            'claim_mode' => $mapping['claim_mode'],
            'component_id' => $mapping['component_id'],
            'confidence' => $mapping['confidence'],
            'contract_version' => 'career.claim_binding.v1',
            'effective_period' => $effectivePeriod,
            'evidence_basis' => $mapping['evidence_basis'],
            'expires_at' => $claimExpiry,
            'input_jsonpath' => $mapping['input_jsonpath'],
            'locale' => $claimLocale,
            'market' => $market,
            'normalized_value_digest' => CareerCurrentAuthorityPackage::hashValue($compilerValue),
            'proxy' => $mapping['proxy'],
            'proxy_boundary' => $mapping['proxy_boundary'],
            'review_status' => 'approved',
            'source_keys' => array_values($sourceKeys),
        ];
        $claimKeysBySlug[$slug][$claimKey] = true;
    }
    foreach ($slugs as $slug) {
        $keys = array_keys($claimKeysBySlug[$slug]);
        sort($keys, SORT_STRING);
        if ($keys !== REQUIRED_CLAIM_KEYS) {
            failAdapter('ADAPTER_REQUIRED_CLAIM_MISSING');
        }
    }
    usort($compilerSources, static fn (array $a, array $b): int => strcmp($a['source_key'], $b['source_key']));
    usort($compilerClaims, static fn (array $a, array $b): int => [$a['canonical_slug'], $a['claim_key']] <=> [$b['canonical_slug'], $b['claim_key']]);
    usort($unmapped, static fn (array $a, array $b): int => [$a['slug'], $a['module'], $a['json_pointer']] <=> [$b['slug'], $b['module'], $b['json_pointer']]);

    $baseline = baselineRows($options['baseline-assets'], $slugs, $package);
    ksort($profiles, SORT_STRING);
    ksort($inputDigests, SORT_STRING);
    $schemaProfileManifest = [
        'contract_version' => 'career.evidence.schema_profile_manifest.v1',
        'profiles' => $profiles,
        'schema_version' => $detectedBySlug[$control]['schema_version'],
        'slug_count' => count($slugs),
        'source_root_digest' => CareerCurrentAuthorityPackage::hashValue($inputDigests),
    ];
    $selection = [
        'cache_writes' => 0,
        'cms_writes' => 0,
        'contract_version' => 'career.evidence.maturity_selection.v1',
        'database_writes' => 0,
        'discoverability_writes' => 0,
        'evaluation_date' => $evaluationDate,
        'generated_at' => null,
        'occupation_generation_writes' => 0,
        'search_submissions' => 0,
        'selected' => [
            ['role' => 'control', 'slug' => $control],
            ['role' => 'target', 'slug' => $target],
        ],
        'selection_method' => 'explicit_contract_compatibility_test',
        'sitemap_writes' => 0,
    ];

    $stage = $outputRoot.'/.adapter-stage-'.bin2hex(random_bytes(8));
    if (! mkdir($stage, 0700)) {
        failAdapter('ADAPTER_OUTPUT_WRITE_FAILED');
    }
    try {
        stageFile($stage, 'source-registry.jsonl', jsonl($compilerSources));
        stageFile($stage, 'claim-bindings.jsonl', jsonl($compilerClaims));
        stageFile($stage, 'schema-profile-manifest.json', pretty($schemaProfileManifest));
        stageFile($stage, 'selection-report.json', pretty($selection));
        $cohort = [
            'baseline_assets_sha256' => hash_file('sha256', $options['baseline-assets']),
            'baseline_rows' => $baseline,
            'contract_version' => 'career.evidence.cohort.v1',
            'control_slugs' => [$control],
            'evaluation_date' => $evaluationDate,
            'evidence_bound_slugs' => $slugs,
            'manual_hold_slugs' => ['software-developers'],
            'required_claim_keys' => REQUIRED_CLAIM_KEYS,
            'selection_report_sha256' => hash_file('sha256', $stage.'/selection-report.json'),
            'target_slugs' => [$target],
        ];
        stageFile($stage, 'cohort.json', pretty($cohort));
        $files = [];
        foreach ([
            'claim_bindings' => 'claim-bindings.jsonl',
            'cohort' => 'cohort.json',
            'schema_profile_manifest' => 'schema-profile-manifest.json',
            'selection_report' => 'selection-report.json',
            'source_registry' => 'source-registry.jsonl',
        ] as $key => $name) {
            $files[$key] = ['path' => $name, 'sha256' => hash_file('sha256', $stage.'/'.$name)];
        }
        $manifest = [
            'contract_version' => 'career.evidence.authority.manifest.v1',
            'evaluation_date' => $evaluationDate,
            'files' => $files,
            'reviewed_at' => $evaluationDate,
        ];
        stageFile($stage, 'manifest.json', pretty($manifest));

        /** @var CareerEvidenceAuthorityLoader $loader */
        $loader = app(CareerEvidenceAuthorityLoader::class);
        try {
            $loader->cohort($stage);
            foreach ($slugs as $slug) {
                $loaded = $loader->load($stage, $slug, $detectedBySlug[$slug]['blocks']);
                if (($loaded['blockers'] ?? null) !== []) {
                    failAdapter('ADAPTER_LOADER_BLOCKED');
                }
            }
        } catch (CareerTenBlockCompileFailure $failure) {
            failAdapter($failure->safeCode);
        }

        $outputHashes = [];
        foreach (['manifest.json', 'source-registry.jsonl', 'claim-bindings.jsonl', 'schema-profile-manifest.json', 'cohort.json', 'selection-report.json'] as $name) {
            $outputHashes[$name] = hash_file('sha256', $stage.'/'.$name);
        }
        $receipt = [
            'adapter_version' => ADAPTER_VERSION,
            'claim_count' => count($compilerClaims),
            'contract_version' => 'career.research.compiler_evidence_adapter_receipt.v1',
            'control_slug' => $control,
            'deterministic_output_hash' => CareerCurrentAuthorityPackage::hashValue($outputHashes),
            'evaluation_date' => $evaluationDate,
            'generated_files' => array_keys($outputHashes),
            'loader_cohort_validation' => 'passed',
            'loader_single_slug_validation' => array_fill_keys($slugs, 'passed'),
            'locale_mapping' => ['en' => 'en', 'zh-CN' => 'zh'],
            'non_target_writes' => [
                'cache' => 0, 'cms' => 0, 'current_package' => 0, 'database' => 0,
                'discoverability' => 0, 'english_assets' => 0, 'publisher' => 0,
                'runtime_api' => 0, 'search' => 0, 'sitemap' => 0, 'zh_master' => 0,
            ],
            'output_hashes' => $outputHashes,
            'research_contract_version' => $researchReceipt['schema_version'],
            'research_validation' => $researchValidation['ok'],
            'source_count' => count($compilerSources),
            'status' => 'PASS_RESEARCH_COMPILER_EVIDENCE_ADAPTER',
            'target_slug' => $target,
            'unmapped_claim_count' => count($unmapped),
            'unmapped_claims' => $unmapped,
        ];
        stageFile($stage, 'adapter-receipt.json', pretty($receipt));

        @unlink($outputRoot.'/adapter-receipt.json');
        foreach (['manifest.json', 'source-registry.jsonl', 'claim-bindings.jsonl', 'schema-profile-manifest.json', 'cohort.json', 'selection-report.json', 'adapter-receipt.json'] as $name) {
            if (! rename($stage.'/'.$name, $outputRoot.'/'.$name)) {
                failAdapter('ADAPTER_OUTPUT_WRITE_FAILED');
            }
        }

        return $receipt;
    } finally {
        removeTree($stage);
    }
}

$repoRoot = dirname(__DIR__, 4);
require $repoRoot.'/backend/vendor/autoload.php';
$app = require $repoRoot.'/backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $receipt = adapt(options(), $repoRoot);
    fwrite(STDOUT, CareerCurrentAuthorityPackage::encodeCanonical($receipt)."\n");
    exit(0);
} catch (Throwable $throwable) {
    $safeCode = $throwable instanceof AdapterFailure
        ? $throwable->safeCode
        : 'ADAPTER_UNEXPECTED_FAILURE';
    fwrite(STDOUT, CareerCurrentAuthorityPackage::encodeCanonical([
        'cache_writes' => 0,
        'cms_writes' => 0,
        'database_writes' => 0,
        'discoverability_writes' => 0,
        'safe_error_code' => $safeCode,
        'search_submissions' => 0,
        'status' => 'FAIL_RESEARCH_COMPILER_EVIDENCE_ADAPTER',
    ])."\n");
    exit(1);
}
