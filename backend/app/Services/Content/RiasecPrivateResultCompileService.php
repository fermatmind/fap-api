<?php

declare(strict_types=1);

namespace App\Services\Content;

use JsonException;
use RuntimeException;

final class RiasecPrivateResultCompileService
{
    public const PACK_ID = 'RIASEC_PRIVATE_RESULT';

    public const PACK_VERSION = 'v1';

    public const AUTHORITY_ID = 'FERMATMIND_RIASEC_PRIVATE_RESULT_ZH_CN_CANONICAL';

    public const SCHEMA = 'fap.riasec.private_result.compiled.v1';

    public const MANIFEST_SCHEMA = 'fap.riasec.private_result.canonical_manifest.v1';

    public const COMPILER_SCHEMA = 'fap.riasec.private_result.compiler.v1';

    public const COMPILER_VERSION = '1.3.0';

    public const ENGLISH_DIMENSION_STATE_ASSET = '140q_task_environment_role_v1.en.jsonl';

    public const DIMENSION_STATE_READER_FIELDS = ['title', 'summary', 'cannot_claim', 'example_question', 'task_activity_card', 'environment_card', 'role_responsibility_card', 'layer_focus', 'science_boundary', 'observation_question'];

    public const ENGLISH_ACTIVITY_ASSET = 'activity_task_examples_v1.en.jsonl';

    public const ENGLISH_ACTIVITY_AUTHORITY_ID = 'FERMATMIND_RIASEC_ACTIVITY_EXAMPLES_EN';

    public const ARTIFACT_FILENAME = 'private_result.compiled.json';

    /** @var array<string,array{role:string,schema:string,surfaces:list<string>}> */
    public const SOURCE_CONTRACT = [
        self::ENGLISH_DIMENSION_STATE_ASSET => ['role' => 'English dimension-state task, environment, and role copy', 'schema' => 'riasec.140q_layer_copy.v1', 'surfaces' => ['result']],
        '140q_task_environment_role_v1.zh-CN.jsonl' => ['role' => '140q task, environment, and role projection copy', 'schema' => 'riasec.140q_layer_copy.v1', 'surfaces' => ['result', 'report', 'pdf', 'history']],
        self::ENGLISH_ACTIVITY_ASSET => ['role' => 'English activity and task examples', 'schema' => 'riasec.activity_task_example.v1', 'surfaces' => ['result', 'report']],
        'activity_task_examples_v1.zh-CN.jsonl' => ['role' => 'activity and task examples', 'schema' => 'riasec.activity_task_example.v1', 'surfaces' => ['result', 'report']],
        'aspirations_calibration_v1.zh-CN.jsonl' => ['role' => 'aspiration calibration copy', 'schema' => 'riasec.aspirations_calibration.v1', 'surfaces' => ['result', 'history']],
        'dimension_deep_copy_v1.en.json' => ['role' => 'selected English dimension band copy', 'schema' => 'riasec.deep_copy_slot_schema.v1_plus_medium_score_reading_candidate', 'surfaces' => ['result', 'report', 'pdf']],
        'dimension_deep_copy_v1.zh-CN.r3.json' => ['role' => 'six dimension band copy', 'schema' => 'riasec.deep_copy_slot_schema.v1_plus_medium_score_reading_candidate', 'surfaces' => ['result', 'report', 'pdf']],
        'disagree_path_v1.zh-CN.jsonl' => ['role' => 'result disagreement path copy', 'schema' => 'riasec.disagree_path.v1', 'surfaces' => ['result', 'history']],
        'faq_v1.en.json' => ['role' => 'English private result FAQ', 'schema' => 'riasec.faq.asset.v1', 'surfaces' => ['result', 'report']],
        'faq_v1.zh-CN.json' => ['role' => 'private result FAQ', 'schema' => 'riasec.faq.asset.v1', 'surfaces' => ['result', 'report']],
        'feedback_action_lab_v1.zh-CN.jsonl' => ['role' => 'feedback and action copy', 'schema' => 'riasec.feedback_action_lab.v1', 'surfaces' => ['result', 'history']],
        'low_quality_cautious_reading_v1.zh-CN.json' => ['role' => 'low-quality cautious reading copy', 'schema' => 'riasec.low_quality.asset.v1', 'surfaces' => ['result', 'report', 'share', 'pdf']],
        'near_tie_alternate_code_copy_v1.zh-CN.json' => ['role' => 'near-tie and alternate-code copy', 'schema' => 'riasec.near_tie.asset.v1', 'surfaces' => ['result', 'report']],
        'next_exploration_nodes_v1.zh-CN.jsonl' => ['role' => 'next exploration copy', 'schema' => 'riasec.next_exploration_node.v1', 'surfaces' => ['result', 'report']],
        'occupation_examples_boundary_v1.zh-CN.jsonl' => ['role' => 'bounded occupation examples', 'schema' => 'riasec.occupation_example_boundary.v1', 'surfaces' => ['result', 'report']],
        'pair_blend_15_pairs_v1.en.jsonl' => ['role' => 'three selected English pair blends', 'schema' => 'riasec.pair_blend_asset.v1', 'surfaces' => ['result', 'report', 'pdf']],
        'pair_blend_15_pairs_v1.zh-CN.jsonl' => ['role' => 'fifteen pair blend copy', 'schema' => 'riasec.pair_blend_asset.v1', 'surfaces' => ['result', 'report', 'pdf']],
        'professional_method_boundary_v1.en.json' => ['role' => 'English method and claim boundaries', 'schema' => 'riasec.professional_method_boundary.asset.v1', 'surfaces' => ['result', 'report', 'pdf']],
        'professional_method_boundary_v1.zh-CN.json' => ['role' => 'method and claim boundaries', 'schema' => 'riasec.professional_method_boundary.asset.v1', 'surfaces' => ['result', 'report', 'pdf']],
        'profile_shape_copy_v1.zh-CN.json' => ['role' => 'profile shape copy', 'schema' => 'riasec.profile_shape.asset.v1', 'surfaces' => ['result', 'report']],
        'runtime_surface_copy_v1.en.json' => ['role' => 'selected English ordered templates and generic context cards', 'schema' => 'riasec.runtime_surface_copy.v1', 'surfaces' => ['result', 'report']],
        'runtime_surface_copy_v1.zh-CN.json' => ['role' => 'runtime summary, quality, tie, and structural surface copy', 'schema' => 'riasec.runtime_surface_copy.v1', 'surfaces' => ['result', 'report', 'pdf', 'history', 'compare']],
        'share_pdf_history_v1.en.json' => ['role' => 'English secondary surface source copy', 'schema' => 'riasec.secondary_surfaces.asset.v1', 'surfaces' => ['share', 'pdf', 'print', 'history', 'compare']],
        'share_pdf_history_v1.zh-CN.json' => ['role' => 'share, PDF, print, history, and compare copy', 'schema' => 'riasec.secondary_surfaces.asset.v1', 'surfaces' => ['share', 'pdf', 'print', 'history', 'compare']],
        'technical_note_user_summary_v1.en.json' => ['role' => 'English private technical note with unknown data status', 'schema' => 'riasec.technical_note.asset.v1', 'surfaces' => ['result', 'report']],
        'technical_note_user_summary_v1.zh-CN.json' => ['role' => 'technical note user summary', 'schema' => 'riasec.technical_note.asset.v1', 'surfaces' => ['result', 'report', 'pdf']],
        'top3_code_chain_strategy_v1.en.jsonl' => ['role' => 'one selected English top-three group', 'schema' => 'riasec.top3_code_chain_strategy.v1', 'surfaces' => ['result', 'report', 'pdf']],
        'top3_code_chain_strategy_v1.zh-CN.jsonl' => ['role' => 'top-three code chain copy', 'schema' => 'riasec.top3_code_chain_strategy.v1', 'surfaces' => ['result', 'report', 'pdf']],
        'top_code_confidence_copy_v1.zh-CN.json' => ['role' => 'top-code confidence copy', 'schema' => 'riasec.top_code_confidence.asset.v1', 'surfaces' => ['result', 'report']],
    ];

    public function __construct(private readonly ?string $sourceRoot = null) {}

    /** @return array{payload:array<string,mixed>,bytes:string,manifest:array<string,mixed>,manifest_bytes:string,source_hash:string,compiled_hash:string} */
    public function compile(): array
    {
        $assets = [];
        $sourceFiles = [];
        $sourceHashInput = '';

        foreach (self::SOURCE_CONTRACT as $path => $contract) {
            $absolutePath = $this->root().'/'.$path;
            if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
                throw new RuntimeException("RIASEC canonical source is missing: {$path}");
            }

            $decoded = str_ends_with($path, '.jsonl')
                ? $this->decodeJsonLines($absolutePath, $path)
                : $this->decodeJson($absolutePath, $path);
            $this->validateSource($path, $decoded, $contract['schema'], str_ends_with($path, '.jsonl'));
            $canonicalBytes = $this->canonicalJson($decoded);
            $digest = hash('sha256', $canonicalBytes);
            $sourceFiles[] = [
                'path' => $path,
                'role' => $contract['role'],
                'schema' => $contract['schema'],
                'required' => true,
                'surfaces' => $contract['surfaces'],
                'sha256' => $digest,
            ];
            $sourceHashInput .= $path."\0".$digest."\n";
            $assets[$path] = $decoded;
        }

        $this->assertEnglishActivities($assets[self::ENGLISH_ACTIVITY_ASSET], $assets['activity_task_examples_v1.zh-CN.jsonl']);
        $this->assertSelectedEnglishSources($assets);
        $sourceHash = hash('sha256', $sourceHashInput);
        $coverage = $this->coverage($assets);
        $manifest = [
            'schema' => self::MANIFEST_SCHEMA,
            'authority_id' => self::AUTHORITY_ID,
            'authority_root' => 'backend/content_assets/riasec',
            'scale_code' => 'RIASEC',
            'locale' => 'zh-CN',
            'version' => self::PACK_VERSION,
            'editable_authority_count' => 1,
            'source_hash_rule' => 'sha256(concat(sorted path + NUL + sha256(canonical decoded JSON) + LF))',
            'compiler' => ['schema' => self::COMPILER_SCHEMA, 'version' => self::COMPILER_VERSION],
            'source_files' => $sourceFiles,
            'source_hash' => $sourceHash,
            'generated' => [
                'manifest' => 'compiled/manifest.json',
                'artifact' => 'compiled/'.self::ARTIFACT_FILENAME,
                'manual_edit_allowed' => false,
            ],
            'coverage' => $coverage,
        ];
        $unsignedPayload = [
            'schema' => self::SCHEMA,
            'authority_id' => self::AUTHORITY_ID,
            'scale_code' => 'RIASEC',
            'locale' => 'zh-CN',
            'version' => self::PACK_VERSION,
            'runtime_contract' => 'riasec.report.v1',
            'compiler' => $manifest['compiler'],
            'source_hash' => $sourceHash,
            'coverage' => $coverage,
            'assets' => $assets,
        ];
        $compiledHash = hash('sha256', $this->canonicalJson($unsignedPayload));
        $payload = $unsignedPayload + ['compiled_hash' => $compiledHash];
        $bytes = $this->canonicalJson($payload)."\n";
        $manifest['compiled_hash'] = $compiledHash;
        $manifest['content_hash'] = $sourceHash;
        $manifest['artifacts'] = [[
            'path' => self::ARTIFACT_FILENAME,
            'sha256' => hash('sha256', $bytes),
        ]];

        return [
            'payload' => $payload,
            'bytes' => $bytes,
            'manifest' => $manifest,
            'manifest_bytes' => $this->canonicalJson($manifest)."\n",
            'source_hash' => $sourceHash,
            'compiled_hash' => $compiledHash,
        ];
    }

    /** @return array{source_hash:string,compiled_hash:string,manifest_path:string,artifact_path:string} */
    public function materialize(): array
    {
        $compiled = $this->compile();
        $compiledDirectory = $this->root().'/compiled';
        if (! is_dir($compiledDirectory) && ! mkdir($compiledDirectory, 0775, true) && ! is_dir($compiledDirectory)) {
            throw new RuntimeException('Unable to create RIASEC canonical compiled directory.');
        }

        $manifestPath = $compiledDirectory.'/manifest.json';
        $artifactPath = $compiledDirectory.'/'.self::ARTIFACT_FILENAME;
        $this->atomicWrite($manifestPath, $compiled['manifest_bytes']);
        $this->atomicWrite($artifactPath, $compiled['bytes']);

        return [
            'source_hash' => $compiled['source_hash'],
            'compiled_hash' => $compiled['compiled_hash'],
            'manifest_path' => $manifestPath,
            'artifact_path' => $artifactPath,
        ];
    }

    /** @return array{ok:bool,pack_id:string,version:string,compiled_dir:string,source_hash:string,compiled_hash:string} */
    public function compileToPackDirectory(): array
    {
        $result = $this->materialize();

        return [
            'ok' => true,
            'pack_id' => self::PACK_ID,
            'version' => self::PACK_VERSION,
            'compiled_dir' => $this->root().'/compiled',
            'source_hash' => $result['source_hash'],
            'compiled_hash' => $result['compiled_hash'],
        ];
    }

    /** @param array<string,mixed> $assets @return array<string,mixed> */
    private function coverage(array $assets): array
    {
        $dimensionAsset = (array) $assets['dimension_deep_copy_v1.zh-CN.r3.json'];
        $dimensions = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => (string) ($row['dimension_code'] ?? $row['dimension'] ?? ''),
            (array) ($dimensionAsset['dimensions'] ?? []),
        ))));
        sort($dimensions, SORT_STRING);
        $pairs = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => (string) ($row['pair_key'] ?? ''),
            (array) $assets['pair_blend_15_pairs_v1.zh-CN.jsonl'],
        ))));
        sort($pairs, SORT_STRING);
        $top3Rows = (array) $assets['top3_code_chain_strategy_v1.zh-CN.jsonl'];

        if ($dimensions !== ['A', 'C', 'E', 'I', 'R', 'S'] || count($pairs) !== 15 || $top3Rows === []) {
            throw new RuntimeException('RIASEC canonical dimension, pair, or top-three coverage is incomplete.');
        }

        return [
            'dimensions' => ['R', 'I', 'A', 'S', 'E', 'C'],
            'dimension_bands' => ['low', 'medium', 'high'],
            'pair_count' => 15,
            'top3_chain_rows' => count($top3Rows),
            'forms' => ['riasec_60', 'riasec_140'],
            'quality_states' => ['normal', 'near_tie', 'low_quality'],
            'profile_shape' => true,
            'confidence' => true,
            'aspirations' => true,
            'disagreement' => true,
            'activity_occupation_task' => true,
            'feedback_action' => true,
            'secondary_surfaces' => ['faq', 'technical_note', 'pdf', 'print', 'history', 'compare', 'share', 'lifecycle'],
            'locale_modules' => ['en' => ['activity_explorer_v0_1', 'deep_content_slots_v1', 'lifecycle_copy_v1']],
            'english_selected_sources' => ['dimensions' => ['R', 'I', 'A', 'S', 'E', 'C'], 'pairs' => ['R_I', 'R_A', 'I_A'], 'top3_groups' => ['R_I_A'], 'ordered_codes' => ['RIA', 'RAI', 'IRA', 'IAR', 'ARI', 'AIR'], 'generic_context_cards' => 4, 'dimension_state_rows' => 126, 'faq_items' => 20, 'technical_sections' => 8, 'method_sections' => 8],
        ];
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $path, string $relativePath): array
    {
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("RIASEC canonical JSON is invalid: {$relativePath}", previous: $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException("RIASEC canonical JSON must decode to an object or array: {$relativePath}");
        }

        return $decoded;
    }

    /** @return list<array<string,mixed>> */
    private function decodeJsonLines(string $path, string $relativePath): array
    {
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $lineNumber => $line) {
            if (trim($line) === '') {
                continue;
            }
            try {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException("RIASEC canonical JSONL is invalid: {$relativePath}:".($lineNumber + 1), previous: $exception);
            }
            if (! is_array($row)) {
                throw new RuntimeException("RIASEC canonical JSONL row must be an object: {$relativePath}:".($lineNumber + 1));
            }
            $rows[] = $row;
        }
        if ($rows === []) {
            throw new RuntimeException("RIASEC canonical JSONL is empty: {$relativePath}");
        }

        return $rows;
    }

    private function root(): string
    {
        return rtrim($this->sourceRoot ?? base_path('content_assets/riasec'), '/');
    }

    /** @param array<string,mixed>|list<array<string,mixed>> $decoded */
    private function validateSource(string $path, array $decoded, string $schema, bool $jsonLines): void
    {
        $rows = $jsonLines ? $decoded : [$decoded];
        foreach ($rows as $index => $row) {
            $location = $jsonLines ? $path.':'.($index + 1) : $path;
            if (($row['frontend_fallback_allowed'] ?? true) !== false) {
                throw new RuntimeException("RIASEC canonical source permits frontend fallback: {$location}");
            }
            if (isset($row['schema_version']) && $row['schema_version'] !== $schema) {
                throw new RuntimeException("RIASEC canonical source schema mismatch: {$location}");
            }
            if (! isset($row['schema_version']) && (! isset($row['asset_id']) || trim((string) $row['asset_id']) === '')) {
                throw new RuntimeException("RIASEC canonical source lacks schema identity: {$location}");
            }
            if (! $jsonLines && ($row['locale'] ?? null) !== (str_contains($path, '.en.') ? 'en' : 'zh-CN')) {
                throw new RuntimeException("RIASEC canonical source locale mismatch: {$location}");
            }
        }
    }

    /** The original 1.0.0 contract remains readable for immutable releases and rollback. */
    public static function sourceContract(string $compilerVersion): array
    {
        return match ($compilerVersion) {
            '1.0.0' => array_filter(self::SOURCE_CONTRACT, static fn (string $path): bool => ! str_contains($path, '.en.'), ARRAY_FILTER_USE_KEY),
            '1.1.0' => array_filter(self::SOURCE_CONTRACT, static fn (string $path): bool => ! str_contains($path, '.en.') || $path === self::ENGLISH_ACTIVITY_ASSET, ARRAY_FILTER_USE_KEY),
            '1.2.0' => array_diff_key(self::SOURCE_CONTRACT, [self::ENGLISH_DIMENSION_STATE_ASSET => true]),
            self::COMPILER_VERSION => self::SOURCE_CONTRACT,
            default => throw new RuntimeException('RIASEC_PRIVATE_RESULT_COMPILER_UNSUPPORTED'),
        };
    }

    /** @param list<array<string,mixed>> $rows @param list<array<string,mixed>> $chineseRows */
    public function assertEnglishActivities(array $rows, array $chineseRows): void
    {
        if (count($rows) !== 18 || count($chineseRows) !== 18) {
            throw new RuntimeException('RIASEC English activity coverage is incomplete.');
        }
        $identities = [];
        foreach ($rows as $index => $row) {
            $key = $row['activity_key'] ?? null;
            if (! is_string($key) || isset($identities[$key])
                || $key !== ($chineseRows[$index]['activity_key'] ?? null)
                || ($row['dimensions'] ?? null) !== ($chineseRows[$index]['dimensions'] ?? null)
                || ($row['schema_version'] ?? null) !== 'riasec.activity_task_example.v1'
                || ($row['asset_version'] ?? null) !== 'riasec_activity_task_examples_v1.en'
                || ($row['locale'] ?? null) !== 'en'
                || ($row['source_status'] ?? null) !== 'content_example_not_registry_match'
                || ($row['frontend_fallback_allowed'] ?? true) !== false
                || ($row['not_a_recommendation'] ?? false) !== true
                || ! is_array($row['task_examples'] ?? null) || count($row['task_examples']) < 3
                || ! is_array($row['action_duration_options'] ?? null)) {
                throw new RuntimeException('RIASEC English activity identity or locale contract is invalid.');
            }
            $identities[$key] = true;
            $readerStrings = [($row['activity_label'] ?? null), ($row['low_risk_validation'] ?? null),
                ...$row['task_examples']];
            foreach (['15min', '30min', '2h', '1week'] as $duration) {
                $readerStrings[] = $row['action_duration_options'][$duration] ?? null;
            }
            foreach ($readerStrings as $text) {
                if (! is_string($text) || trim($text) === '' || preg_match('/[\x{3400}-\x{9fff}]/u', $text)) {
                    throw new RuntimeException('RIASEC English activity reader copy is invalid.');
                }
            }
        }
        foreach (['R', 'I', 'A', 'S', 'E', 'C'] as $dimension) {
            if (count(array_filter($rows, static fn (array $row): bool => $row['dimensions'] === [$dimension])) !== 3) {
                throw new RuntimeException('RIASEC English activity dimension coverage is incomplete.');
            }
        }
    }

    /** @param list<array<string,mixed>> $rows @param list<array<string,mixed>> $chineseRows */
    public function assertEnglishDimensionStates(array $rows, array $chineseRows): void
    {
        if (count($rows) !== 126 || count($chineseRows) !== 126) {
            throw new RuntimeException('RIASEC English dimension-state coverage is incomplete.');
        }
        // Provenance binds the frozen source bytes; semantic equality below protects every machine field.
        $sourceRawHash = 'e3461f36b5650d51c538dc6945beb62b653a5cec3a5886d519c1e72a3c4b336f';
        if (hash('sha256', $this->canonicalJson($chineseRows)) !== '7dccdf182291c068e2823463bfed3dd8a721f6f4f6f8d9b2072accbc69fa0e4e') {
            throw new RuntimeException('RIASEC English dimension-state source binding is stale.');
        }
        $identities = [];
        foreach ($rows as $index => $row) {
            $source = $chineseRows[$index];
            $identity = implode('.', [$row['dimension'] ?? '', $row['layer'] ?? '', $row['layer_state'] ?? '']);
            if (isset($identities[$identity])
                || ($row['locale'] ?? null) !== 'en'
                || ($row['asset_version'] ?? null) !== 'riasec_140q_task_environment_role_v1.en'
                || $this->canonicalJson($row['source_binding'] ?? null) !== $this->canonicalJson(['source_asset' => '140q_task_environment_role_v1.zh-CN.jsonl', 'raw_sha256' => $sourceRawHash, 'source_row' => $index + 1])) {
                throw new RuntimeException('RIASEC English dimension-state identity or provenance is invalid.');
            }
            $identities[$identity] = true;
            foreach (self::DIMENSION_STATE_READER_FIELDS as $field) {
                if (! is_string($row[$field] ?? null) || trim($row[$field]) === ''
                    || preg_match('/[\x{3400}-\x{9fff}]/u', $row[$field]) === 1) {
                    throw new RuntimeException('RIASEC English dimension-state reader copy is invalid.');
                }
                unset($row[$field], $source[$field]);
            }
            unset($row['locale'], $row['source_binding']);
            $row['asset_version'] = $source['asset_version'];
            if ($this->canonicalJson($row) !== $this->canonicalJson($source)) {
                throw new RuntimeException('RIASEC English dimension-state machine contract is invalid.');
            }
        }
    }

    public function assertSelectedEnglishSources(array $assets, string $compilerVersion = self::COMPILER_VERSION): void
    {
        foreach (self::sourceContract($compilerVersion) as $filename => $contract) {
            if (str_contains($filename, '.en.')) {
                $rows = str_ends_with($filename, '.jsonl') ? ($assets[$filename] ?? []) : [$assets[$filename] ?? []];
                foreach ($rows as $row) {
                    if (($row['locale'] ?? null) !== 'en') {
                        throw new RuntimeException('RIASEC selected English source locale is invalid.');
                    }
                }
            }
            if (str_contains($filename, '.en.') && preg_match('/[\x{3400}-\x{9fff}]/u', json_encode($assets[$filename] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === 1) {
                throw new RuntimeException('RIASEC selected English source contains Chinese text.');
            }
        }
        if (isset(self::sourceContract($compilerVersion)[self::ENGLISH_DIMENSION_STATE_ASSET])) {
            $this->assertEnglishDimensionStates($assets[self::ENGLISH_DIMENSION_STATE_ASSET] ?? [], $assets['140q_task_environment_role_v1.zh-CN.jsonl'] ?? []);
        }
        $dimensions = $assets['dimension_deep_copy_v1.en.json']['dimensions'] ?? [];
        $pairs = $assets['pair_blend_15_pairs_v1.en.jsonl'] ?? [];
        $top3 = $assets['top3_code_chain_strategy_v1.en.jsonl'] ?? [];
        $runtime = $assets['runtime_surface_copy_v1.en.json'] ?? [];
        if (array_column($dimensions, 'dimension_code') !== ['R', 'I', 'A', 'S', 'E', 'C']
            || array_column($pairs, 'pair_key') !== ['R_I', 'R_A', 'I_A']
            || array_column($top3, 'unordered_top3_key') !== ['R_I_A']
            || ($top3[0]['dimensions'] ?? null) !== ['R', 'I', 'A']
            || ($runtime['supported_unordered_key'] ?? null) !== 'R_I_A'
            || (count($runtime['dimension_reading_lines'] ?? []) !== 3 || array_diff(array_keys($runtime['dimension_reading_lines'] ?? []), ['R', 'I', 'A']) !== [])
            || count($runtime['top3_ordered_templates'] ?? []) !== 12
            || count($runtime['generic_slots']['layer_140q'] ?? []) !== 4
            || count($assets['faq_v1.en.json']['questions'] ?? []) !== 20
            || count($assets['technical_note_user_summary_v1.en.json']['summary_sections'] ?? []) !== 8
            || count($assets['technical_note_user_summary_v1.en.json']['runtime_method_boundaries'] ?? []) !== 8
            || count($assets['technical_note_user_summary_v1.en.json']['runtime_disclaimers'] ?? []) !== 6
            || count($assets['professional_method_boundary_v1.en.json']['sections'] ?? []) !== 8) {
            throw new RuntimeException('RIASEC selected English source coverage is invalid.');
        }
        foreach ($dimensions as $row) {
            if (($row['locale'] ?? null) !== 'en') {
                throw new RuntimeException('RIASEC selected English dimension locale is invalid.');
            }
            foreach (['work_activity_examples', 'interest_activity_focus', 'validation_questions', 'possible_drains', 'context_costs', 'misread_guardrails'] as $field) {
                if (! is_array($row[$field] ?? null) || $row[$field] === [] || count(array_filter($row[$field], static fn ($text): bool => is_string($text) && trim($text) !== '')) !== count($row[$field])) {
                    throw new RuntimeException('RIASEC selected English dimension list is incomplete.');
                }
            }
            foreach (['title', 'core_drive', 'positive_value', 'real_world_cost', 'high_score_reading', 'medium_score_reading', 'low_score_safe_reading', 'common_misread', 'action_advice', 'user_visible_boundary'] as $key) {
                if (! is_string($row[$key] ?? null) || trim($row[$key]) === '') {
                    throw new RuntimeException('RIASEC selected English dimension field is missing.');
                }
            }
        }
        foreach (['pair_blend_15_pairs_v1.en.jsonl' => 'riasec_pair_blend_15_pairs_v1.en', 'top3_code_chain_strategy_v1.en.jsonl' => 'riasec_top3_code_chain_strategy_v1.en'] as $filename => $version) {
            foreach ($assets[$filename] as $row) {
                if (($row['locale'] ?? null) !== 'en' || ($row['asset_version'] ?? null) !== $version || ($row['frontend_fallback_allowed'] ?? true) !== false || ($row['schema_version'] ?? null) !== self::SOURCE_CONTRACT[$filename]['schema']) {
                    throw new RuntimeException('RIASEC selected English row identity is invalid.');
                }
            }
        }
        foreach ($pairs as $row) {
            if (($row['dimensions'] ?? null) !== explode('_', $row['pair_key'])) {
                throw new RuntimeException('RIASEC selected English pair dimensions are invalid.');
            }
            foreach (['pair_label', 'short_label', 'chemistry', 'positive_value', 'pair_tension', 'real_world_cost', 'common_misread', 'micro_experiment', 'result_page_teaser', 'deep_report_extension_hint', 'user_visible_boundary'] as $field) {
                if (! is_string($row[$field] ?? null) || trim($row[$field]) === '') {
                    throw new RuntimeException('RIASEC selected English pair copy is incomplete.');
                }
            }
            foreach (['activities_to_validate', 'context_costs', 'validation_questions'] as $field) {
                if (! is_array($row[$field] ?? null) || $row[$field] === []) {
                    throw new RuntimeException('RIASEC selected English pair copy is incomplete.');
                }
            }
        }
        $generic = $runtime['generic_slots']['layer_140q'];
        if (array_diff(array_keys($generic), ['task_activity_card', 'environment_card', 'role_responsibility_card', '140q_cta']) !== []) {
            throw new RuntimeException('RIASEC selected English generic card identity is invalid.');
        }
        foreach ($generic as $key => $card) {
            $cta = $key === '140q_cta';
            if (($card['applicable_form_codes'] ?? null) !== ($cta ? ['riasec_60'] : ['riasec_140'])
                || ($card['layer_state'] ?? null) !== ($cta ? 'unavailable' : '140q_completed_state')
                || ($cta && (($card['contextual_detail_only'] ?? false) !== true || ($card['accuracy_upgrade_claim_allowed'] ?? true) !== false || ($card['overrides_60q_result'] ?? true) !== false))) {
                throw new RuntimeException('RIASEC selected English card applicability is invalid.');
            }
        }
        $templateKeys = ['strategy_label', 'activity_chain', 'core_reading', 'positive_value', 'first_experiment', 'ordered_code_handling', 'low_risk_validation', 'primary_activity_chain', 'secondary_support_line', 'tertiary_stabilizer', 'activity_sequence', 'free_page_teaser'];
        if (array_diff($templateKeys, array_keys($runtime['top3_ordered_templates'])) !== []) {
            throw new RuntimeException('RIASEC selected English template is missing.');
        }
        foreach (['faq_v1.en.json' => ['questions', ['q', 'a']], 'technical_note_user_summary_v1.en.json' => ['summary_sections', ['title', 'copy']], 'professional_method_boundary_v1.en.json' => ['sections', ['key', 'title', 'body']]] as $filename => [$collection, $fields]) {
            foreach ($assets[$filename][$collection] as $row) {
                foreach ($fields as $field) {
                    if (! is_string($row[$field] ?? null) || trim($row[$field]) === '') {
                        throw new RuntimeException('RIASEC selected English lifecycle copy is incomplete.');
                    }
                }
            }
        }
        foreach ($assets['technical_note_user_summary_v1.en.json']['runtime_method_boundaries'] as $row) {
            if (trim((string) ($row['label'] ?? '')) === '' || trim((string) ($row['copy'] ?? '')) === '') {
                throw new RuntimeException('RIASEC selected English method boundary is incomplete.');
            }
        }
        $tokens = ['code', 'labels', 'shorts'];
        foreach (['first', 'second', 'third'] as $position) {
            foreach (['label', 'short', 'focus', 'action'] as $field) {
                $tokens[] = $position.'_'.$field;
            }
        }
        foreach ($runtime['dimension_reading_lines'] as $line) {
            foreach (['label', 'short', 'focus', 'action'] as $field) {
                if (trim((string) ($line[$field] ?? '')) === '') {
                    throw new RuntimeException('RIASEC selected English reading line is missing.');
                }
            }
        }
        foreach ($runtime['top3_ordered_templates'] as $template) {
            foreach (is_array($template) ? $template : [$template] as $text) {
                if (! is_string($text) || trim($text) === '') {
                    throw new RuntimeException('RIASEC selected English template is missing.');
                }
                preg_match_all('/\{([a-z_]+)\}/', $text, $matches);
                if (array_diff($matches[1], $tokens) !== []) {
                    throw new RuntimeException('RIASEC selected English template token is unsupported.');
                }
            }
        }
        foreach ($assets['technical_note_user_summary_v1.en.json']['summary_sections'] as $section) {
            if (! array_key_exists('data_status', $section) || $section['data_status'] !== null) {
                throw new RuntimeException('RIASEC private English technical status must remain unknown.');
            }
        }
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_string($value) ? str_replace(["\r\n", "\r"], "\n", $value) : $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }

    private function atomicWrite(string $path, string $bytes): void
    {
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException("Unable to write RIASEC canonical artifact: {$path}");
        }
    }
}
