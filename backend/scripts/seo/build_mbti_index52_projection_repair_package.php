<?php

declare(strict_types=1);

const API_BASE = 'https://api.fermatmind.com/api/v0.5/personality/comparisons/';
const QUERY = '?locale=zh-CN&org_id=0&scale_code=MBTI';
const PACKAGE_ID = 'mbti-index52-comparison-projection-repair-2026-07-27-r1';
const ORIGINAL_CROSS = ['intj-vs-intp', 'entj-vs-intj', 'infj-vs-infp', 'istj-vs-isfj'];
const RELEASED_CROSS = ['enfp-vs-entp', 'estj-vs-entj', 'isfp-vs-infp'];
const BASE_TYPES = ['intj', 'intp', 'entj', 'entp', 'infj', 'infp', 'enfj', 'enfp', 'istj', 'isfj', 'estj', 'esfj', 'istp', 'isfp', 'estp', 'esfp'];

$backend = dirname(__DIR__, 2);
$repo = dirname($backend);
$output = $backend.'/content_assets/personality_public/mbti-index52-comparison-projection-repair-2026-07-27.json';
$authorizationOutput = $backend.'/content_assets/personality_public/mbti-index52-comparison-projection-repair-operator-authorization-2026-07-27.json';
$atSourceDir = $backend.'/docs/seo/import-packages/mbti-comparison-content-assets-draft-20260702/comparisons';
$approvedCross = jsonFile($backend.'/content_assets/personality_public/mbti-cross-approval-48-package-2026-07-23.json');
$approvedCrossBySlug = [];
foreach ((array) ($approvedCross['records'] ?? []) as $record) {
    if (is_array($record)) {
        $approvedCrossBySlug[(string) ($record['slug'] ?? '')] = (array) ($record['candidate_payload'] ?? []);
    }
}

$records = [];
foreach (BASE_TYPES as $baseType) {
    $slug = $baseType.'-a-vs-'.$baseType.'-t';
    $live = liveComparison($slug);
    $projection = (array) ($live['comparison_public_projection_v1'] ?? []);
    $sourceName = 'FermatMind_'.strtoupper($baseType).'-A_vs_'.strtoupper($baseType).'-T_CMS_READY.json';
    $source = jsonFile($atSourceDir.'/'.$sourceName);
    $sections = array_values((array) ($projection['sections'] ?? []));
    if (count($sections) !== 9) {
        throw new RuntimeException("{$slug} must expose exactly nine live projection sections.");
    }
    $claimBoundary = trim((string) ($source['claim_boundary'] ?? ''));
    if ($claimBoundary === '') {
        throw new RuntimeException("{$slug} approved claim boundary is missing.");
    }
    $records[] = [
        'slug' => $slug,
        'locale' => 'zh-CN',
        'record_kind' => 'at_comparison',
        'source_authority' => 'personality_profile_sections.mbti64_comparison_a_vs_t',
        'source_revision_sha256' => (string) ($projection['overlay_source']['payload_sha256'] ?? hashJson($projection)),
        'expected_runtime_sections_count' => 9,
        'expected_runtime_sections' => $sections,
        'expected_runtime_sections_sha256' => hashJson($sections),
        'patch' => [
            'claim_boundary' => $claimBoundary,
        ],
    ];
}

foreach ([...ORIGINAL_CROSS, ...RELEASED_CROSS] as $slug) {
    $live = liveComparison($slug);
    $projection = (array) ($live['comparison_public_projection_v1'] ?? []);
    $sourceLinks = in_array($slug, RELEASED_CROSS, true)
        ? (array) ($approvedCrossBySlug[$slug]['internal_links'] ?? [])
        : (array) ($projection['internal_links'] ?? []);
    $links = normalizeLinks($sourceLinks);
    $expectedLinkCount = in_array($slug, RELEASED_CROSS, true) ? 7 : 5;
    if (count($links) !== $expectedLinkCount) {
        throw new RuntimeException("{$slug} internal-link count mismatch.");
    }
    $sections = array_values((array) ($projection['sections'] ?? []));
    $faq = array_values((array) ($projection['faq'] ?? []));
    $records[] = [
        'slug' => $slug,
        'locale' => 'zh-CN',
        'record_kind' => 'cross_type_comparison',
        'source_authority' => 'mbti_cross_type_comparison_authorities',
        'source_revision_sha256' => (string) ($projection['source_sha256'] ?? ''),
        'expected_runtime_sections_count' => count($sections),
        'expected_runtime_sections' => $sections,
        'expected_runtime_sections_sha256' => hashJson($sections),
        'english_alternate_authority_gap' => [
            'status' => 'held_missing_en_backend_record',
            'expected_en_canonical' => 'https://fermatmind.com/en/personality/'.$slug,
            'production_write_authorized' => false,
        ],
        'patch' => [
            'internal_links' => $links,
            'answer_surface_v1' => answerSurface($projection, $sections, $faq, $links),
        ],
    ];
}

$package = [
    'schema_version' => 'mbti.index52.comparison_projection_repair.v1',
    'package_id' => PACKAGE_ID,
    'generated_at' => '2026-07-27T00:00:00Z',
    'scope' => [
        'locale' => 'zh-CN',
        'record_count' => 23,
        'at_comparison_count' => 16,
        'cross_type_comparison_count' => 7,
        'exact_slugs' => array_column($records, 'slug'),
        'allowed_patch_fields' => ['runtime_sections', 'claim_boundary', 'internal_links', 'answer_surface_v1'],
        'held_gap_fields' => ['alternates.en'],
    ],
    'records' => $records,
    'rollback_contract' => [
        'contract' => 'mbti.index52.comparison_projection_repair.rollback.v1',
        'capture_exact_prewrite_payloads' => true,
        'atomic_restore_required' => true,
        'preserve_non_target_rows' => true,
        'automatic_rollback' => false,
    ],
    'readback_contract' => [
        'contract' => 'mbti.index52.comparison_projection_repair.readback.v1',
        'exact_record_count' => 23,
        'require_at_runtime_section' => 'mbti64_comparison_a_vs_t',
        'require_claim_boundary' => true,
        'require_cross_internal_links' => true,
        'require_cross_answer_surface_v1' => true,
        'require_cross_english_alternate' => false,
        'require_english_alternate_hold_without_en_backend_record' => true,
        'preserve_publication_and_indexability' => true,
    ],
    'safety_boundary' => [
        'body_or_faq_mutation_authorized' => false,
        'publication_or_indexability_mutation_authorized' => false,
        'sitemap_or_llms_mutation_authorized' => false,
        'search_submission_authorized' => false,
        'production_write_authorized' => false,
    ],
];
if (count($records) !== 23) {
    throw new RuntimeException('Exact 23-record package is required.');
}
$package['package_sha256'] = hashJson($package);

$authorization = [
    'schema_version' => 'mbti.index52.comparison_projection_repair.authorization.v1',
    'package_id' => PACKAGE_ID,
    'approved_package_sha256' => $package['package_sha256'],
    'decision' => 'APPROVED_EXACT_23_PROJECTION_REPAIR_FOR_PREFLIGHT_AND_DRY_RUN_ONLY',
    'exact_slugs' => array_column($records, 'slug'),
    'record_count' => 23,
    'production_write_authorized' => false,
    'publication_or_indexability_mutation_authorized' => false,
    'sitemap_or_llms_mutation_authorized' => false,
    'search_submission_authorized' => false,
];
$authorization['authorization_sha256'] = hashJson($authorization);

writeJson($output, $package);
writeJson($authorizationOutput, $authorization);
fwrite(STDOUT, json_encode([
    'ok' => true,
    'package_path' => substr($output, strlen($repo) + 1),
    'package_sha256' => $package['package_sha256'],
    'authorization_path' => substr($authorizationOutput, strlen($repo) + 1),
    'authorization_sha256' => $authorization['authorization_sha256'],
    'record_count' => count($records),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);

/** @return array<string,mixed> */
function liveComparison(string $slug): array
{
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'timeout' => 45,
        'header' => "Accept: application/json\r\nUser-Agent: FermatMind-MBTI-INDEX52-Package-Builder/1.0\r\n",
        'ignore_errors' => false,
    ]]);
    $body = file_get_contents(API_BASE.rawurlencode($slug).QUERY, false, $context);
    if (! is_string($body)) {
        throw new RuntimeException("Failed to read public authority for {$slug}.");
    }
    $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
        throw new RuntimeException("Invalid public authority response for {$slug}.");
    }

    return $decoded;
}

/** @return array<string,mixed> */
function jsonFile(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new RuntimeException("JSON object required: {$path}");
    }

    return $decoded;
}

/** @param list<mixed> $source @return list<array<string,string>> */
function normalizeLinks(array $source): array
{
    $links = [];
    foreach ($source as $link) {
        if (! is_array($link)) {
            continue;
        }
        $label = trim((string) ($link['label'] ?? $link['anchor_text'] ?? ''));
        $href = trim((string) ($link['href'] ?? ''));
        if ($label === '' || preg_match('~^/zh/(?:personality|tests)(?:/|$)~', $href) !== 1) {
            throw new RuntimeException('Every internal link must be a bounded public zh route.');
        }
        $normalized = ['label' => $label, 'href' => $href];
        $reason = trim((string) ($link['reason'] ?? ''));
        if ($reason !== '') {
            $normalized['reason'] = $reason;
        }
        $links[] = $normalized;
    }

    return $links;
}

/** @param array<string,mixed> $projection @param list<mixed> $sections @param list<mixed> $faq @param list<array<string,string>> $links @return array<string,mixed> */
function answerSurface(array $projection, array $sections, array $faq, array $links): array
{
    $compareBlocks = [];
    foreach ($sections as $section) {
        if (! is_array($section)) {
            continue;
        }
        $bodySource = $section['body'] ?? [];
        $bodies = is_array($bodySource) ? $bodySource : [$bodySource];
        $body = trim((string) (array_values(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            $bodies,
        )))[0] ?? ''));
        if ($body !== '') {
            $compareBlocks[] = [
                'key' => (string) ($section['id'] ?? $section['key'] ?? 'comparison'),
                'title' => (string) ($section['title'] ?? ''),
                'body' => $body,
                'kind' => 'backend_authoritative_comparison_section',
            ];
        }
    }
    if ($compareBlocks === []) {
        throw new RuntimeException('Answer surface requires at least one visible comparison block.');
    }

    return [
        'answer_contract_version' => 'mbti.comparison.answer_surface.v1',
        'summary_blocks' => [[
            'key' => 'comparison_summary',
            'title' => (string) ($projection['title'] ?? ''),
            'body' => (string) ($projection['summary'] ?? $projection['description'] ?? ''),
            'kind' => 'answer_first',
        ]],
        'faq_blocks' => $faq,
        'compare_blocks' => $compareBlocks,
        'next_step_blocks' => array_map(static fn (array $link, int $index): array => [
            'key' => 'internal_link_'.($index + 1),
            'title' => $link['label'],
            'body' => $link['reason'] ?? '',
            'href' => $link['href'],
            'kind' => 'backend_authoritative_internal_link',
        ], $links, array_keys($links)),
    ];
}

function hashJson(mixed $value): string
{
    return hash('sha256', json_encode(stable($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function stable(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('stable', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = stable($item);
    }

    return $value;
}

function writeJson(string $path, array $value): void
{
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
}
