<?php

declare(strict_types=1);

const IQ_SVG_PROVENANCE_SCHEMA_VERSION = 'fm.iq.svg_provenance_manifest.v1';
const IQ_SVG_PROVENANCE_GENERATOR_VERSION = '2026.05.12';
const IQ_LEGACY_PROTOTYPE_ZIP_PATH = '/Users/rainie/Desktop/iq_ui_prototype_30_svg_grid.zip';

/**
 * @return array<int, string>
 */
function iqSvgDefaultPackDirs(): array
{
    $repoRoot = iqSvgRepoRoot();

    return [
        $repoRoot.'/content_packages/default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO',
        $repoRoot.'/content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO',
    ];
}

function iqSvgRepoRoot(): string
{
    return dirname(__DIR__, 3);
}

function iqSvgRelativePath(string $path): string
{
    $repoRoot = iqSvgRepoRoot();
    $normalizedPath = str_replace('\\', '/', $path);
    $normalizedRoot = str_replace('\\', '/', $repoRoot);
    $prefix = rtrim($normalizedRoot, '/').'/';

    if (str_starts_with($normalizedPath, $prefix)) {
        return substr($normalizedPath, strlen($prefix));
    }

    return $normalizedPath;
}

/**
 * @return array<string, mixed>
 */
function iqSvgLoadJsonFile(string $path): array
{
    if (! is_file($path)) {
        throw new RuntimeException('missing json file: '.$path);
    }

    $json = file_get_contents($path);
    if (! is_string($json)) {
        throw new RuntimeException('failed to read json file: '.$path);
    }

    $decoded = json_decode($json, true);
    if (! is_array($decoded)) {
        throw new RuntimeException('invalid json file: '.$path);
    }

    return $decoded;
}

function iqSvgSha256File(string $path): string
{
    if (! is_file($path)) {
        throw new RuntimeException('missing file for sha256: '.$path);
    }

    $hash = hash_file('sha256', $path);
    if (! is_string($hash) || $hash === '') {
        throw new RuntimeException('failed to hash file: '.$path);
    }

    return 'sha256:'.$hash;
}

/**
 * @param  mixed  $value
 * @return mixed
 */
function iqSvgNormalizeValue($value)
{
    if (! is_array($value)) {
        return $value;
    }

    if (iqSvgIsAssoc($value)) {
        $normalized = [];
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $normalized[$key] = iqSvgNormalizeValue($value[$key]);
        }

        return $normalized;
    }

    return array_map(
        static fn ($item) => iqSvgNormalizeValue($item),
        $value
    );
}

function iqSvgStableJson(mixed $value): string
{
    $json = json_encode(
        iqSvgNormalizeValue($value),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if (! is_string($json)) {
        throw new RuntimeException('failed to encode stable json');
    }

    return $json;
}

function iqSvgPrettyJson(mixed $value): string
{
    $json = json_encode(
        iqSvgNormalizeValue($value),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if (! is_string($json)) {
        throw new RuntimeException('failed to encode pretty json');
    }

    return $json;
}

function iqSvgSha256Json(mixed $value): string
{
    return 'sha256:'.hash('sha256', iqSvgStableJson($value));
}

/**
 * @return array<string, mixed>
 */
function iqSvgBuildLegacyProvenanceManifest(string $packDir): array
{
    if (! is_dir($packDir)) {
        throw new RuntimeException('missing pack dir: '.$packDir);
    }

    $manifestPath = rtrim($packDir, '/').'/manifest.json';
    $landingPath = rtrim($packDir, '/').'/meta/landing.json';
    $questionsPath = rtrim($packDir, '/').'/questions.json';
    $scoringPath = rtrim($packDir, '/').'/scoring_spec.json';
    $versionPath = rtrim($packDir, '/').'/version.json';
    $builderScriptPath = iqSvgRepoRoot().'/backend/scripts/iq/build_iq30_questions_from_prototype.php';

    $manifest = iqSvgLoadJsonFile($manifestPath);
    $landing = iqSvgLoadJsonFile($landingPath);
    $questions = iqSvgLoadJsonFile($questionsPath);
    $scoring = iqSvgLoadJsonFile($scoringPath);
    $version = iqSvgLoadJsonFile($versionPath);

    $questionItems = $questions['items'] ?? null;
    if (! is_array($questionItems)) {
        throw new RuntimeException('questions.items missing: '.$questionsPath);
    }

    $lifecycle = is_array($manifest['lifecycle'] ?? null) ? $manifest['lifecycle'] : [];
    $landingLifecycle = is_array($landing['lifecycle'] ?? null) ? $landing['lifecycle'] : [];
    $packDirRelative = iqSvgRelativePath($packDir);

    $items = [];
    foreach (array_values($questionItems) as $itemIndex => $question) {
        if (! is_array($question)) {
            throw new RuntimeException('question payload invalid at index '.$itemIndex);
        }

        $stemSvg = is_array($question['stem']['svg'] ?? null) ? $question['stem']['svg'] : [];
        $options = is_array($question['options'] ?? null) ? $question['options'] : [];
        $optionAssets = [];
        foreach (array_values($options) as $optionIndex => $option) {
            if (! is_array($option)) {
                throw new RuntimeException('option payload invalid at index '.$itemIndex.':'.$optionIndex);
            }

            $optionSvg = is_array($option['svg'] ?? null) ? $option['svg'] : [];
            $optionAssets[] = [
                'code' => (string) ($option['code'] ?? ''),
                'kind' => 'inline_svg',
                'ref' => $packDirRelative.'/questions.json#items['.$itemIndex.'].options['.$optionIndex.'].svg',
                'view_box' => (string) ($optionSvg['view_box'] ?? ''),
                'path_count' => count(is_array($optionSvg['paths'] ?? null) ? $optionSvg['paths'] : []),
                'sha256' => iqSvgSha256Json($optionSvg),
            ];
        }

        $items[] = [
            'question_id' => (string) ($question['question_id'] ?? ''),
            'item_id' => null,
            'status' => 'legacy_demo',
            'order' => (int) ($question['order'] ?? ($itemIndex + 1)),
            'section_code' => (string) ($question['section_code'] ?? ''),
            'source_ref' => $packDirRelative.'/questions.json#items['.$itemIndex.']',
            'source_question_slug' => (string) ($question['meta']['source_q'] ?? ''),
            'source_html' => (string) ($question['meta']['source_html'] ?? ''),
            'question_sha256' => iqSvgSha256Json($question),
            'stem_asset' => [
                'kind' => 'inline_svg',
                'ref' => $packDirRelative.'/questions.json#items['.$itemIndex.'].stem.svg',
                'view_box' => (string) ($stemSvg['view_box'] ?? ''),
                'path_count' => count(is_array($stemSvg['paths'] ?? null) ? $stemSvg['paths'] : []),
                'sha256' => iqSvgSha256Json($stemSvg),
            ],
            'option_assets' => $optionAssets,
        ];
    }

    return [
        'schema_version' => IQ_SVG_PROVENANCE_SCHEMA_VERSION,
        'generator_version' => IQ_SVG_PROVENANCE_GENERATOR_VERSION,
        'scale_code' => (string) ($manifest['scale_code'] ?? ''),
        'pack_dir' => $packDirRelative,
        'dir_version' => (string) ($version['dir_version'] ?? basename($packDir)),
        'content_package_version' => (string) ($version['content_package_version'] ?? ($manifest['content_package_version'] ?? '')),
        'lifecycle' => [
            'status' => (string) ($lifecycle['status'] ?? ''),
            'identity_role' => (string) ($lifecycle['identity_role'] ?? ''),
            'legacy_scale_code' => $lifecycle['legacy_scale_code'] ?? null,
            'canonical_scale_code' => $landingLifecycle['canonical_scale_code'] ?? null,
            'legacy_demo_allowlisted' => ((string) ($lifecycle['status'] ?? '') === 'legacy_demo'),
        ],
        'runtime_policy' => [
            'production_ready' => false,
            'visual_output_changed' => false,
            'asset_kind' => 'inline_svg_in_questions_json',
            'legacy_identity_allowed' => ((string) ($lifecycle['status'] ?? '') === 'legacy_demo'),
            'prototype_source_tracked_in_repo' => false,
        ],
        'source_files' => [
            'questions_json' => iqSvgRelativePath($questionsPath),
            'scoring_spec_json' => iqSvgRelativePath($scoringPath),
            'manifest_json' => iqSvgRelativePath($manifestPath),
            'landing_json' => iqSvgRelativePath($landingPath),
            'version_json' => iqSvgRelativePath($versionPath),
            'builder_script' => iqSvgRelativePath($builderScriptPath),
        ],
        'source_hashes' => [
            'questions_json_sha256' => iqSvgSha256File($questionsPath),
            'scoring_spec_json_sha256' => iqSvgSha256File($scoringPath),
            'manifest_json_sha256' => iqSvgSha256File($manifestPath),
            'landing_json_sha256' => iqSvgSha256File($landingPath),
            'version_json_sha256' => iqSvgSha256File($versionPath),
            'builder_script_sha256' => iqSvgSha256File($builderScriptPath),
        ],
        'recorded_generator_source' => [
            'builder_script' => iqSvgRelativePath($builderScriptPath),
            'prototype_zip_path' => IQ_LEGACY_PROTOTYPE_ZIP_PATH,
            'prototype_zip_tracked_in_repo' => false,
            'params_recorded' => false,
            'seed_recorded' => false,
            'version_recorded' => false,
        ],
        'scoring_contract_snapshot' => [
            'scoring_mode' => (string) ($scoring['scoring_mode'] ?? ''),
            'answer_key_version' => (string) ($scoring['answer_key_version'] ?? ''),
            'item_bank_status' => (string) ($scoring['item_bank']['status'] ?? ''),
            'item_count' => count($items),
            'scored_items_declared' => count(is_array($scoring['items'] ?? null) ? $scoring['items'] : []),
        ],
        'item_count' => count($items),
        'items' => $items,
    ];
}

function iqSvgDefaultManifestPath(string $packDir): string
{
    return rtrim($packDir, '/').'/svg_provenance_manifest.json';
}

/**
 * @param  array<int|string, mixed>  $value
 */
function iqSvgIsAssoc(array $value): bool
{
    if ($value === []) {
        return false;
    }

    return array_keys($value) !== range(0, count($value) - 1);
}
