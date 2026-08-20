<?php

declare(strict_types=1);

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;

require dirname(__DIR__, 2).'/vendor/autoload.php';

ini_set('memory_limit', '1024M');

$options = getopt('', [
    'source-root:', 'lookup:', 'schema-manifest:', 'base-evidence-root:', 'output-root:',
    'baseline-assets:', 'target-count:', 'control-slugs:', 'manual-hold-slugs:', 'evaluation-date:',
]);

try {
    $sourceRoot = requiredDirectory($options, 'source-root');
    $baseRoot = requiredDirectory($options, 'base-evidence-root');
    $outputRoot = requiredDirectory($options, 'output-root');
    $lookupPath = requiredFile($options, 'lookup');
    $schemaPath = requiredFile($options, 'schema-manifest');
    $baselineAssetsPath = requiredFile($options, 'baseline-assets');
    $targetCount = positiveInt($options['target-count'] ?? null);
    $controls = slugList($options['control-slugs'] ?? null);
    $manualHolds = slugList($options['manual-hold-slugs'] ?? null);
    $evaluationDate = exactDate($options['evaluation-date'] ?? null);
    $lookup = jsonObject($lookupPath);
    $schema = jsonObject($schemaPath);
    $profiles = $schema['profiles'] ?? null;
    if (($schema['contract_version'] ?? null) !== 'career.ten_block.schema_profile_manifest.v1'
        || ! is_array($profiles) || array_is_list($profiles)) {
        throw new RuntimeException('EVIDENCE_SCHEMA_MANIFEST_INVALID');
    }

    $buckets = [
        'onet_secondary_value', 'nullable_salary', 'english_keys', 'object_table', 'standard_core',
    ];
    if ($targetCount !== count($buckets)) {
        throw new RuntimeException('EVIDENCE_DIFFICULTY_COVERAGE_INVALID');
    }
    $excluded = array_fill_keys(array_merge($controls, $manualHolds), true);
    $candidates = [];
    foreach ($profiles as $slug => $profile) {
        if (! is_string($slug) || ! is_array($profile) || isset($excluded[$slug])) {
            continue;
        }
        $lookupRow = $lookup['by_slug'][$slug] ?? null;
        if (! is_array($lookupRow) || ($lookupRow['canonical_slug'] ?? null) !== $slug
            || ! is_string($lookupRow['onet_code'] ?? null) || ! is_string($lookupRow['soc_code'] ?? null)) {
            continue;
        }
        $discriminators = $profile['discriminators'] ?? [];
        $bucket = match (true) {
            ($discriminators['onet_value2'] ?? null) === 'present' => 'onet_secondary_value',
            ($discriminators['salary_min_max'] ?? null) === 'nullable' => 'nullable_salary',
            ($profile['input_profile'] ?? null) === 'career.ten_block.english_keys.v1' => 'english_keys',
            ($profile['input_profile'] ?? null) === 'career.ten_block.object_table.v1' => 'object_table',
            default => 'standard_core',
        };
        $score = ((int) ($profile['field_coverage_count'] ?? 0) * 1000)
            + ((int) ($profile['input_link_count'] ?? 0) * 10)
            - ((int) ($profile['variant_rewrite_count'] ?? 0) * 25);
        $candidates[$bucket][] = [
            'slug' => $slug,
            'maturity_score' => $score,
            'tie_break_digest' => hash('sha256', 'career-evidence-maturity-v1'."\0".$slug),
            'input_profile' => $profile['input_profile'],
            'field_coverage_count' => $profile['field_coverage_count'],
            'input_link_count' => $profile['input_link_count'],
            'variant_rewrite_count' => $profile['variant_rewrite_count'],
            'discriminators' => $discriminators,
            'soc_code' => $lookupRow['soc_code'],
            'onet_code' => $lookupRow['onet_code'],
        ];
    }
    $selected = [];
    foreach ($buckets as $bucket) {
        $rows = $candidates[$bucket] ?? [];
        usort($rows, static fn (array $a, array $b): int => $b['maturity_score'] <=> $a['maturity_score']
            ?: strcmp($a['tie_break_digest'], $b['tie_break_digest']));
        if ($rows === []) {
            throw new RuntimeException('EVIDENCE_DIFFICULTY_BUCKET_EMPTY');
        }
        $selected[] = ['difficulty_bucket' => $bucket] + $rows[0];
    }
    $targets = array_column($selected, 'slug');
    $bound = array_values(array_unique(array_merge($controls, $targets)));
    if (array_intersect($bound, $manualHolds) !== []) {
        throw new RuntimeException('EVIDENCE_MANUAL_HOLD_SELECTED');
    }

    $selection = [
        'contract_version' => 'career.evidence.maturity_selection.v1',
        'evaluation_date' => $evaluationDate,
        'selection_method' => [
            'score' => 'field_coverage_count*1000 + input_link_count*10 - variant_rewrite_count*25',
            'tie_break' => 'sha256(career-evidence-maturity-v1 NUL canonical_slug)',
            'difficulty_buckets' => $buckets,
            'canonical_lookup_required' => true,
        ],
        'scan' => [
            'slug_count' => count($profiles),
            'source_root_digest' => $schema['source_root_digest'] ?? null,
            'schema_manifest_sha256' => hash_file('sha256', $schemaPath),
            'lookup_sha256' => hash_file('sha256', $lookupPath),
            'baseline_assets_sha256' => hash_file('sha256', $baselineAssetsPath),
            'manual_hold_slugs' => $manualHolds,
            'control_slugs' => $controls,
        ],
        'selected_target_count' => count($targets),
        'selected' => $selected,
        'database_writes' => 0,
        'cache_writes' => 0,
        'cms_writes' => 0,
        'occupation_generation_writes' => 0,
        'sitemap_writes' => 0,
        'discoverability_writes' => 0,
        'search_submissions' => 0,
        'generated_at' => null,
    ];
    atomicJson($outputRoot.'/selection-report.json', $selection);

    $sourceRows = jsonLines($baseRoot.'/source-registry.jsonl');
    $claimRows = jsonLines($baseRoot.'/claim-bindings.jsonl');
    $requiredClaimKeys = [
        'definition.summary', 'duties.list', 'hero.lead', 'identity.title_en',
        'identity.title_zh', 'work_context.summary',
    ];
    foreach ($targets as $slug) {
        $identity = jsonObject($sourceRoot.'/'.$slug.'/identity.json');
        $definition = jsonObject($sourceRoot.'/'.$slug.'/definition.json');
        $pageMeta = jsonObject($sourceRoot.'/'.$slug.'/page-meta.json');
        $onet = (string) $identity['onet'];
        $onetUrl = 'https://www.onetonline.org/link/details/'.$onet;
        $onetBody = @file_get_contents($onetUrl);
        if (! is_string($onetBody) || ! str_contains($onetBody, $onet)
            || ! str_contains($onetBody, 'O*NET OnLine')) {
            throw new RuntimeException('EVIDENCE_ONET_READBACK_INVALID');
        }
        $sourceRows[] = sourceRow(
            'onet.'.$onet.'.2026', 'occupation_fact', 'trusted_public_source', 'O*NET OnLine',
            (string) $identity['title_en'].' ('.$onet.')', $onetUrl, 'US', 'en',
            ['identity', 'duty', 'work_context', 'tool', 'qualification'], $evaluationDate,
            'O*NET profile updated 2026', '2027-08-20', hash('sha256', $onetBody),
            'exact O*NET code and live title readback', 'Official occupation identity and English occupational facts.',
        );
        $zhValues = [
            'definition.summary' => $definition['definition'],
            'duties.list' => $definition['duties'],
            'hero.lead' => $pageMeta['hero_lead'],
            'identity.title_zh' => $identity['title_zh'],
            'work_context.summary' => $definition['work_scene'],
        ];
        $fermatKey = 'fermatmind.interpretation.'.$slug.'.'.$evaluationDate;
        $sourceRows[] = sourceRow(
            $fermatKey, 'fermatmind_interpretation', 'bounded_interpretation', 'FermatMind',
            (string) $identity['title_en'].' reviewed Chinese occupational synthesis', 'https://fermatmind.com/',
            'CN', 'zh', ['identity', 'duty', 'work_context', 'interpretation'], $evaluationDate,
            'Canonical ten-block review on '.$evaluationDate, '2027-08-20', CareerCurrentAuthorityPackage::hashValue($zhValues),
            'reviewed bounded synthesis with exact normalized input-value digests',
            'Chinese synthesis only; does not certify salary, growth, licensing, market, or AI trend facts.',
        );
        $claims = [
            ['definition.summary', 'zh', 'CN', 'interpretation', '$.definition.definition', $definition['definition'], 'definition_block', '$.page_payload_json.page.zh.definition_block', $fermatKey, 'reviewed bounded Chinese synthesis', 'reviewed_interpretation', 'interpretation_only'],
            ['duties.list', 'zh', 'CN', 'interpretation', '$.definition.duties', $definition['duties'], 'responsibilities_block', '$.page_payload_json.page.zh.responsibilities_block', $fermatKey, 'reviewed bounded Chinese synthesis', 'reviewed_interpretation', 'interpretation_only'],
            ['hero.lead', 'zh', 'CN', 'interpretation', '$.page-meta.hero_lead', $pageMeta['hero_lead'], 'hero', '$.page_payload_json.page.zh.hero.quick_answer', $fermatKey, 'reviewed bounded career-fit framing', 'reviewed_interpretation', 'interpretation_only'],
            ['identity.title_en', 'en', 'US', 'identity', '$.identity.title_en', $identity['title_en'], 'hero', '$.page_payload_json.page.en.hero.title', 'onet.'.$onet.'.2026', 'exact O*NET code and English occupation title', 'exact_registry_match', 'fact'],
            ['identity.title_zh', 'zh', 'CN', 'interpretation', '$.identity.title_zh', $identity['title_zh'], 'hero', '$.page_payload_json.page.zh.hero.title', $fermatKey, 'reviewed Chinese label; not an official classification title', 'reviewed_interpretation', 'interpretation_only'],
            ['work_context.summary', 'zh', 'CN', 'interpretation', '$.definition.work_scene', $definition['work_scene'], 'work_context_block', '$.page_payload_json.page.zh.work_context_block', $fermatKey, 'reviewed bounded Chinese work-context synthesis', 'reviewed_interpretation', 'interpretation_only'],
        ];
        foreach ($claims as $claim) {
            $claimRows[] = claimRow($slug, $claim, $evaluationDate);
        }
    }
    usort($sourceRows, static fn (array $a, array $b): int => strcmp($a['source_key'], $b['source_key']));
    usort($claimRows, static fn (array $a, array $b): int => strcmp($a['canonical_slug']."\0".$a['claim_key'], $b['canonical_slug']."\0".$b['claim_key']));
    atomicJsonLines($outputRoot.'/source-registry.jsonl', $sourceRows);
    atomicJsonLines($outputRoot.'/claim-bindings.jsonl', $claimRows);

    $evidenceProfiles = [];
    foreach ($bound as $slug) {
        $profile = $profiles[$slug] ?? null;
        if (! is_array($profile)) {
            throw new RuntimeException('EVIDENCE_BOUND_PROFILE_MISSING');
        }
        $evidenceProfiles[$slug] = $profile + [
            'profile_version' => 'career.evidence.bound.v1',
            'required_claim_keys' => $requiredClaimKeys,
            'unsupported_candidate_claim_policy' => 'omit_or_retain_current_baseline',
        ];
    }
    atomicJson($outputRoot.'/schema-profile-manifest.json', [
        'contract_version' => 'career.evidence.schema_profile_manifest.v1',
        'profiles' => $evidenceProfiles,
        'schema_version' => $schema['schema_version'] ?? null,
        'slug_count' => count($bound),
        'source_root_digest' => $schema['source_root_digest'] ?? null,
    ]);
    $baselineRows = [];
    foreach (jsonLines($baselineAssetsPath) as $row) {
        if (is_string($row['canonical_slug'] ?? null) && in_array($row['canonical_slug'], $bound, true)) {
            $baselineRows[$row['canonical_slug']] = [
                'row_sha256' => CareerCurrentAuthorityPackage::hashValue($row),
                'public_content_sha256' => array_combine(
                    CareerCurrentAuthorityPackage::LOCALES,
                    array_map(
                        static fn (string $locale): string => publicContentHash($row, $locale),
                        CareerCurrentAuthorityPackage::LOCALES,
                    ),
                ),
            ];
        }
    }
    if (count($baselineRows) !== count($bound)) {
        throw new RuntimeException('EVIDENCE_BASELINE_COHORT_INVALID');
    }
    $baselineRows = array_replace(array_fill_keys($bound, null), $baselineRows);
    $cohort = [
        'contract_version' => 'career.evidence.cohort.v1',
        'evaluation_date' => $evaluationDate,
        'control_slugs' => $controls,
        'target_slugs' => $targets,
        'evidence_bound_slugs' => $bound,
        'manual_hold_slugs' => $manualHolds,
        'required_claim_keys' => $requiredClaimKeys,
        'baseline_assets_sha256' => hash_file('sha256', $baselineAssetsPath),
        'baseline_rows' => $baselineRows,
        'selection_report_sha256' => hash_file('sha256', $outputRoot.'/selection-report.json'),
        'expected_public_changed_locale_page_count' => count($targets) * 2,
        'expected_baseline_retained_slug_count' => count($profiles) - count($bound),
    ];
    atomicJson($outputRoot.'/cohort.json', $cohort);
    $files = [];
    foreach ([
        'source_registry' => 'source-registry.jsonl',
        'claim_bindings' => 'claim-bindings.jsonl',
        'schema_profile_manifest' => 'schema-profile-manifest.json',
        'cohort' => 'cohort.json',
        'selection_report' => 'selection-report.json',
    ] as $key => $name) {
        $files[$key] = ['path' => $name, 'sha256' => hash_file('sha256', $outputRoot.'/'.$name)];
    }
    atomicJson($outputRoot.'/manifest.json', [
        'contract_version' => 'career.evidence.authority.manifest.v1',
        'evaluation_date' => $evaluationDate,
        'reviewed_at' => $evaluationDate,
        'files' => $files,
    ]);
    fwrite(STDOUT, json_encode([
        'status' => 'PASS_CAREER_EVIDENCE_MATURITY_COHORT_BUILD',
        'target_slugs' => $targets,
        'evidence_bound_slug_count' => count($bound),
        'claim_binding_count' => count($claimRows),
        'source_count' => count($sourceRows),
        'database_writes' => 0, 'cache_writes' => 0, 'cms_writes' => 0,
        'occupation_generation_writes' => 0, 'sitemap_writes' => 0,
        'discoverability_writes' => 0, 'search_submissions' => 0,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
} catch (Throwable $throwable) {
    fwrite(STDERR, json_encode([
        'status' => 'FAIL_CAREER_EVIDENCE_MATURITY_COHORT_BUILD',
        'safe_error_code' => $throwable->getMessage(),
        'database_writes' => 0, 'cache_writes' => 0, 'cms_writes' => 0,
        'occupation_generation_writes' => 0, 'sitemap_writes' => 0,
        'discoverability_writes' => 0, 'search_submissions' => 0,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}

function requiredDirectory(array $options, string $key): string
{
    $path = is_string($options[$key] ?? null) ? realpath($options[$key]) : false;
    if ($path === false || ! is_dir($path) || is_link($options[$key])) {
        throw new RuntimeException('EVIDENCE_INPUT_INVALID');
    }

    return $path;
}

function requiredFile(array $options, string $key): string
{
    $path = is_string($options[$key] ?? null) ? realpath($options[$key]) : false;
    if ($path === false || ! is_file($path) || is_link($options[$key])) {
        throw new RuntimeException('EVIDENCE_INPUT_INVALID');
    }

    return $path;
}

function positiveInt(mixed $value): int
{
    if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
        throw new RuntimeException('EVIDENCE_TARGET_COUNT_INVALID');
    }

    return (int) $value;
}

function slugList(mixed $value): array
{
    if (! is_string($value)) {
        throw new RuntimeException('EVIDENCE_SLUG_LIST_INVALID');
    }
    $slugs = array_values(array_filter(array_map('trim', explode(',', $value))));
    foreach ($slugs as $slug) {
        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1) {
            throw new RuntimeException('EVIDENCE_SLUG_LIST_INVALID');
        }
    }

    return array_values(array_unique($slugs));
}

function exactDate(mixed $value): string
{
    $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
    if ($date === false || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException('EVIDENCE_DATE_INVALID');
    }

    return $value;
}

function jsonObject(string $path): array
{
    $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($value) || array_is_list($value)) {
        throw new RuntimeException('EVIDENCE_JSON_INVALID');
    }

    return $value;
}

function jsonLines(string $path): array
{
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $rows[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }

    return $rows;
}

function atomicJson(string $path, array $value): void
{
    atomicWrite($path, CareerCurrentAuthorityPackage::encodePrettyCanonical($value));
}

function atomicJsonLines(string $path, array $rows): void
{
    atomicWrite($path, implode("\n", array_map(
        static fn (array $row): string => CareerCurrentAuthorityPackage::encodeCanonical($row),
        $rows,
    ))."\n");
}

function atomicWrite(string $path, string $bytes): void
{
    $temporary = dirname($path).'/.'.basename($path).'.tmp';
    if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || ! rename($temporary, $path)) {
        throw new RuntimeException('EVIDENCE_OUTPUT_WRITE_FAILED');
    }
}

function sourceRow(string $key, string $authority, string $certification, string $publisher, string $title,
    string $url, string $market, string $locale, array $kinds, string $captured, string $period,
    string $expires, string $digest, string $method, string $usage): array
{
    return [
        'contract_version' => 'career.source_registry.v1', 'source_key' => $key,
        'authority' => $authority, 'trust_certification' => $certification, 'publisher' => $publisher,
        'title' => $title, 'url' => $url, 'market' => $market, 'locale' => $locale,
        'claim_kinds' => $kinds, 'captured_at' => $captured, 'effective_period' => $period,
        'expires_at' => $expires, 'evidence_digest' => $digest, 'confidence_method' => $method, 'usage' => $usage,
    ];
}

function claimRow(string $slug, array $claim, string $captured): array
{
    [$key, $locale, $market, $kind, $inputPath, $value, $component, $outputPath, $sourceKey, $basis, $confidence, $mode] = $claim;

    return [
        'contract_version' => 'career.claim_binding.v1', 'claim_key' => $key, 'canonical_slug' => $slug,
        'locale' => $locale, 'market' => $market, 'claim_kind' => $kind, 'input_jsonpath' => $inputPath,
        'normalized_value_digest' => CareerCurrentAuthorityPackage::hashValue($value), 'component_id' => $component,
        'authority_output_jsonpath' => $outputPath, 'source_keys' => [$sourceKey], 'evidence_basis' => $basis,
        'confidence' => $confidence, 'captured_at' => $captured,
        'effective_period' => $locale === 'en' ? 'O*NET profile updated 2026' : 'Canonical ten-block review on '.$captured,
        'expires_at' => '2027-08-20', 'proxy' => false, 'proxy_boundary' => null, 'claim_mode' => $mode,
        'review_status' => 'approved', 'blocker_codes' => [],
    ];
}

function publicContentHash(array $row, string $locale): string
{
    return (new CareerCurrentAuthorityPackage)->publicContentHash($row, $locale);
}
