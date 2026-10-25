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

    public const COMPILER_VERSION = '1.7.0';

    public const ENGLISH_ASPIRATIONS_CARDS = ['intro', 'input_boundary', 'no_score_mutation_boundary'];

    public const ENGLISH_DISAGREE_CARDS = ['user_not_wrong_message', 'feedback_no_mutation_boundary', 'next_step'];

    public const ENGLISH_TOP3_KEYS = ['R_I_A', 'R_I_S', 'R_I_E', 'R_I_C', 'R_A_S', 'R_A_E', 'R_A_C', 'R_S_E', 'R_S_C', 'R_E_C', 'I_A_S', 'I_A_E', 'I_A_C', 'I_S_E', 'I_S_C', 'I_E_C', 'A_S_E', 'A_S_C', 'A_E_C', 'S_E_C'];

    public const ENGLISH_TOP3_READER_FIELDS = ['strategy_label', 'activity_chain', 'core_reading', 'positive_value', 'real_world_cost', 'first_experiment', 'ordered_code_handling', 'low_risk_validation', 'primary_activity_chain', 'secondary_support_line', 'tertiary_stabilizer', 'likely_tension', 'activity_sequence', 'when_to_use_140q', 'when_not_to_overread', 'free_page_teaser', 'deep_report_extension'];

    public const ENGLISH_PAIR_KEYS = ['R_I', 'R_A', 'R_S', 'R_E', 'R_C', 'I_A', 'I_S', 'I_E', 'I_C', 'A_S', 'A_E', 'A_C', 'S_E', 'S_C', 'E_C'];

    public const ENGLISH_PAIR_READER_FIELDS = ['pair_label', 'short_label', 'chemistry', 'positive_value', 'real_world_cost', 'common_misread', 'activities_to_validate', 'micro_experiment', 'user_visible_boundary', 'result_page_teaser', 'deep_report_extension_hint', 'pair_tension', 'context_costs', 'validation_questions'];

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
        'pair_blend_15_pairs_v1.en.jsonl' => ['role' => 'English pair blend copy for all fifteen identities', 'schema' => 'riasec.pair_blend_asset.v1', 'surfaces' => ['result', 'report', 'pdf']],
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
        'top3_code_chain_strategy_v1.en.jsonl' => ['role' => 'English top-three group copy for all twenty identities', 'schema' => 'riasec.top3_code_chain_strategy.v1', 'surfaces' => ['result', 'report', 'pdf']],
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
            'english_selected_sources' => ['dimensions' => ['R', 'I', 'A', 'S', 'E', 'C'], 'pairs' => self::englishPairCoverage(self::COMPILER_VERSION), 'top3_groups' => self::englishTop3Coverage(self::COMPILER_VERSION), 'ordered_codes' => self::englishOrderedCodeCoverage(self::COMPILER_VERSION), 'generic_context_cards' => 4, 'dimension_state_rows' => 126, 'faq_items' => 20, 'technical_sections' => 8, 'method_sections' => 8, 'generic_disagree_cards' => 3, 'generic_aspirations_cards' => 3],
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
            '1.2.0' => array_diff_key(self::legacySelectedSourceContract(), [self::ENGLISH_DIMENSION_STATE_ASSET => true]),
            '1.3.0' => self::legacySelectedSourceContract(),
            '1.4.0' => self::legacySelectedSourceContract(completePairs: true),
            '1.5.0', '1.6.0', self::COMPILER_VERSION => self::SOURCE_CONTRACT,
            default => throw new RuntimeException('RIASEC_PRIVATE_RESULT_COMPILER_UNSUPPORTED'),
        };
    }

    /** Keep immutable selected-module manifest metadata as published. */
    private static function legacySelectedSourceContract(bool $completePairs = false): array
    {
        $contract = self::SOURCE_CONTRACT;
        if (! $completePairs) {
            $contract['pair_blend_15_pairs_v1.en.jsonl']['role'] = 'three selected English pair blends';
        }
        $contract['top3_code_chain_strategy_v1.en.jsonl']['role'] = 'one selected English top-three group';

        return $contract;
    }

    public static function englishPairCoverage(string $compilerVersion): array
    {
        return match ($compilerVersion) {
            '1.2.0', '1.3.0' => ['R_I', 'R_A', 'I_A'],
            '1.4.0', '1.5.0', '1.6.0', self::COMPILER_VERSION => self::ENGLISH_PAIR_KEYS,
            default => throw new RuntimeException('RIASEC_PRIVATE_RESULT_COMPILER_UNSUPPORTED'),
        };
    }

    public static function englishTop3Coverage(string $compilerVersion): array
    {
        return match ($compilerVersion) {
            '1.2.0', '1.3.0', '1.4.0' => ['R_I_A'],
            '1.5.0', '1.6.0', self::COMPILER_VERSION => self::ENGLISH_TOP3_KEYS,
            default => throw new RuntimeException('RIASEC_PRIVATE_RESULT_COMPILER_UNSUPPORTED'),
        };
    }

    public static function englishOrderedCodeCoverage(string $compilerVersion): array
    {
        $codes = [];
        foreach (self::englishTop3Coverage($compilerVersion) as $key) {
            [$a, $b, $c] = explode('_', $key);
            array_push($codes, $a.$b.$c, $a.$c.$b, $b.$a.$c, $b.$c.$a, $c.$a.$b, $c.$b.$a);
        }

        return $codes;
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

    /** @param list<array<string,mixed>> $rows @param list<array<string,mixed>> $chineseRows */
    public function assertEnglishPairSources(array $rows, array $chineseRows): void
    {
        $sourceHash = 'd406bf9b4913c8b6db6a08ab4a7358d8b4b7cdb335730a20642392e753e4cd4a';
        if (count($rows) !== 15 || count($chineseRows) !== 15
            || hash('sha256', $this->canonicalJson($chineseRows)) !== $sourceHash) {
            throw new RuntimeException('RIASEC English pair coverage or source binding is invalid.');
        }
        $sourceByKey = array_column($chineseRows, null, 'pair_key');
        $identities = [];
        foreach ($rows as $row) {
            $key = $row['pair_key'] ?? '';
            $source = $sourceByKey[$key] ?? null;
            if (! is_array($source) || isset($identities[$key])
                || ($row['locale'] ?? null) !== 'en'
                || ($row['schema_version'] ?? null) !== 'riasec.pair_blend_asset.v1'
                || ($row['asset_version'] ?? null) !== 'riasec_pair_blend_15_pairs_v1.en'
                || ($row['frontend_fallback_allowed'] ?? true) !== false
                || ($row['review_status'] ?? null) !== 'draft_human_review_required'
                || isset($row['evidence_level'])
                || ($row['source_review_status'] ?? null) !== $source['review_status']
                || ($row['source_line'] ?? null) !== array_search($key, array_column($chineseRows, 'pair_key'), true) + 1) {
                throw new RuntimeException('RIASEC English pair identity or locale is invalid.');
            }
            $identities[$key] = true;
            foreach (['dimensions', 'required_boundaries', 'forbidden_claims', 'applicable_form_codes', 'applicable_profile_shapes', 'applicable_quality_states'] as $field) {
                if (($row[$field] ?? null) !== $source[$field]) {
                    throw new RuntimeException('RIASEC English pair machine boundary is invalid.');
                }
            }
            if (! in_array($key, ['R_I', 'R_A', 'I_A'], true)
                && $this->canonicalJson($row['source_binding'] ?? null) !== $this->canonicalJson([
                    'source_asset' => 'pair_blend_15_pairs_v1.zh-CN.jsonl',
                    'raw_sha256' => 'f75848ed7e21c80402fa21666703c99f0ae00448039c3a4b3ca93bb707110f9b',
                    'source_row' => $row['source_line'],
                ])) {
                throw new RuntimeException('RIASEC English pair provenance is invalid.');
            }
            foreach (self::ENGLISH_PAIR_READER_FIELDS as $field) {
                $value = $row[$field] ?? null;
                if (is_array($source[$field])) {
                    if (! is_array($value) || ! array_is_list($value) || count($value) !== count($source[$field])) {
                        throw new RuntimeException('RIASEC English pair reader list is incomplete.');
                    }
                } elseif (! is_string($value)) {
                    throw new RuntimeException('RIASEC English pair reader text is invalid.');
                }
                foreach (is_array($value) ? $value : [$value] as $text) {
                    if (! is_string($text) || trim($text) === '' || preg_match('/[\x{3400}-\x{9fff}]/u', $text) === 1) {
                        throw new RuntimeException('RIASEC English pair reader text is invalid.');
                    }
                }
            }
        }
    }

    /** @param list<array<string,mixed>> $rows @param list<array<string,mixed>> $chineseRows */
    public function assertEnglishTop3Sources(array $rows, array $chineseRows): void
    {
        if (count($rows) !== 20 || count($chineseRows) !== 20
            || array_column($rows, 'unordered_top3_key') !== self::ENGLISH_TOP3_KEYS
            || hash('sha256', $this->canonicalJson($chineseRows)) !== '1fbda7c7396793b6df28f9335bf2123a52eda798247a88e879f84d136ec9cf90') {
            throw new RuntimeException('RIASEC English Top3 coverage or source binding is invalid.');
        }
        $boundary = $rows[0]['user_visible_boundary'] ?? null;
        if (! is_string($boundary) || trim($boundary) === '' || preg_match('/[\x{3400}-\x{9fff}]/u', $boundary) === 1) {
            throw new RuntimeException('RIASEC English Top3 boundary is incomplete.');
        }
        foreach ($rows as $index => $row) {
            $source = $chineseRows[$index];
            if (($row['locale'] ?? null) !== 'en'
                || ($row['schema_version'] ?? null) !== 'riasec.top3_code_chain_strategy.v1'
                || ($row['asset_version'] ?? null) !== 'riasec_top3_code_chain_strategy_v1.en'
                || ($row['frontend_fallback_allowed'] ?? true) !== false
                || ($row['fallback_behavior'] ?? 'omit_module') !== 'omit_module'
                || array_intersect(array_keys($row), ['asset_status', 'review_status', 'source_status', 'evidence_level', 'target_review_status_after_owner_signoff']) !== []) {
                throw new RuntimeException('RIASEC English Top3 identity or evidence metadata is invalid.');
            }
            foreach (['dimensions', 'required_boundaries', 'forbidden_claims'] as $field) {
                if (($row[$field] ?? null) !== $source[$field]) {
                    throw new RuntimeException('RIASEC English Top3 machine boundary is invalid.');
                }
            }
            if (array_key_exists('user_visible_boundary', $row)
                && (! is_string($row['user_visible_boundary']) || trim($row['user_visible_boundary']) === '' || preg_match('/[\x{3400}-\x{9fff}]/u', $row['user_visible_boundary']) === 1)) {
                throw new RuntimeException('RIASEC English Top3 boundary is incomplete.');
            }
            foreach (self::ENGLISH_TOP3_READER_FIELDS as $field) {
                $value = $row[$field] ?? null;
                if ($field === 'activity_sequence') {
                    if (! is_array($value) || ! array_is_list($value) || count($value) !== count($source[$field])) {
                        throw new RuntimeException('RIASEC English Top3 activity sequence is incomplete.');
                    }
                } elseif (! is_string($value)) {
                    throw new RuntimeException('RIASEC English Top3 reader text is invalid.');
                }
                foreach (is_array($value) ? $value : [$value] as $text) {
                    if (! is_string($text) || trim($text) === '' || preg_match('/[\x{3400}-\x{9fff}]/u', $text) === 1) {
                        throw new RuntimeException('RIASEC English Top3 reader text is invalid.');
                    }
                }
            }
        }
    }

    /** The seven new reader leaves are bound to the unchanged Chinese generic cards. */
    public function assertEnglishDisagreeSources(array $runtime, array $chineseRuntime, string $compilerVersion = self::COMPILER_VERSION): void
    {
        $source = ['cards' => $chineseRuntime['generic_slots']['disagree'] ?? null, 'boundary' => $chineseRuntime['boundaries']['disagree'] ?? null];
        if (hash('sha256', $this->canonicalJson($source)) !== '0ec7e564b9a14e957914654b87539402a63735052a22dcf58b42da8bd1d26734') {
            throw new RuntimeException('RIASEC English disagreement source binding is invalid.');
        }
        $cards = $runtime['generic_slots']['disagree'] ?? [];
        $keys = is_array($cards) ? array_keys($cards) : [];
        $expected = self::ENGLISH_DISAGREE_CARDS;
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        $boundaries = $runtime['boundaries'] ?? [];
        $boundaryKeys = is_array($boundaries) ? array_keys($boundaries) : [];
        sort($boundaryKeys, SORT_STRING);
        $expectedBoundaries = $compilerVersion === '1.6.0' ? ['disagree'] : ['aspirations', 'disagree'];
        if ($keys !== $expected || $boundaryKeys !== $expectedBoundaries) {
            throw new RuntimeException('RIASEC English disagreement coverage is incomplete.');
        }
        foreach ($cards as $name => $card) {
            $fields = is_array($card) ? array_keys($card) : [];
            sort($fields, SORT_STRING);
            if ($fields !== ['disagree_state', 'summary', 'title']
                || ($card['disagree_state'] ?? null) !== $source['cards'][$name]['disagree_state']) {
                throw new RuntimeException('RIASEC English disagreement identity is invalid.');
            }
            foreach (['title', 'summary'] as $field) {
                if (! is_string($card[$field]) || trim($card[$field]) === '' || preg_match('/\p{Han}/u', $card[$field]) === 1) {
                    throw new RuntimeException('RIASEC English disagreement reader text is invalid.');
                }
            }
        }
        $boundary = $runtime['boundaries']['disagree'];
        if (! is_string($boundary) || trim($boundary) === '' || preg_match('/\p{Han}/u', $boundary) === 1) {
            throw new RuntimeException('RIASEC English disagreement boundary is invalid.');
        }
    }

    /** Only the three projection-selected aspiration cards are admitted. */
    public function assertEnglishAspirationsSources(array $runtime, array $chineseRuntime): void
    {
        $source = ['cards' => array_intersect_key((array) ($chineseRuntime['generic_slots']['aspirations'] ?? []), array_fill_keys(self::ENGLISH_ASPIRATIONS_CARDS, true)), 'boundary' => $chineseRuntime['boundaries']['aspirations'] ?? null];
        if (hash('sha256', $this->canonicalJson($source)) !== 'fa38f29304387db6537980528c58c7c9284a9f8fa9560c99552d186be97c78c3') {
            throw new RuntimeException('RIASEC English aspirations source binding is invalid.');
        }
        $cards = $runtime['generic_slots']['aspirations'] ?? [];
        $keys = is_array($cards) ? array_keys($cards) : [];
        $expected = self::ENGLISH_ASPIRATIONS_CARDS;
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new RuntimeException('RIASEC English aspirations coverage is incomplete.');
        }
        foreach ($cards as $name => $card) {
            $fields = is_array($card) ? array_keys($card) : [];
            sort($fields, SORT_STRING);
            if ($fields !== ['aspirations_state', 'summary', 'title']
                || ($card['aspirations_state'] ?? null) !== $source['cards'][$name]['aspirations_state']) {
                throw new RuntimeException('RIASEC English aspirations identity is invalid.');
            }
            foreach (['title', 'summary'] as $field) {
                if (! is_string($card[$field]) || trim($card[$field]) === '' || preg_match('/\p{Han}/u', $card[$field]) === 1) {
                    throw new RuntimeException('RIASEC English aspirations reader text is invalid.');
                }
            }
        }
        $boundary = $runtime['boundaries']['aspirations'] ?? null;
        if (! is_string($boundary) || trim($boundary) === '' || preg_match('/\p{Han}/u', $boundary) === 1) {
            throw new RuntimeException('RIASEC English aspirations boundary is invalid.');
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
        $pairKeys = array_column($pairs, 'pair_key');
        $expectedPairKeys = self::englishPairCoverage($compilerVersion);
        if (in_array($compilerVersion, ['1.4.0', '1.5.0', '1.6.0', self::COMPILER_VERSION], true)) {
            sort($pairKeys, SORT_STRING);
            sort($expectedPairKeys, SORT_STRING);
            $this->assertEnglishPairSources($pairs, $assets['pair_blend_15_pairs_v1.zh-CN.jsonl'] ?? []);
        }
        $top3 = $assets['top3_code_chain_strategy_v1.en.jsonl'] ?? [];
        $runtime = $assets['runtime_surface_copy_v1.en.json'] ?? [];
        $completeTop3 = in_array($compilerVersion, ['1.5.0', '1.6.0', self::COMPILER_VERSION], true);
        if (in_array($compilerVersion, ['1.6.0', self::COMPILER_VERSION], true)) {
            $this->assertEnglishDisagreeSources($runtime, $assets['runtime_surface_copy_v1.zh-CN.json'] ?? [], $compilerVersion);
        }
        if ($compilerVersion === self::COMPILER_VERSION) {
            $this->assertEnglishAspirationsSources($runtime, $assets['runtime_surface_copy_v1.zh-CN.json'] ?? []);
        }
        if ($completeTop3) {
            $this->assertEnglishTop3Sources($top3, $assets['top3_code_chain_strategy_v1.zh-CN.jsonl'] ?? []);
        }
        $expectedReadingDimensions = $completeTop3 ? ['R', 'I', 'A', 'S', 'E', 'C'] : ['R', 'I', 'A'];
        $readingDimensions = array_keys($runtime['dimension_reading_lines'] ?? []);
        sort($expectedReadingDimensions, SORT_STRING);
        sort($readingDimensions, SORT_STRING);
        $supportedGroupsValid = $completeTop3
            ? ($runtime['supported_unordered_keys'] ?? null) === self::ENGLISH_TOP3_KEYS && ! isset($runtime['supported_unordered_key']) && ($runtime['scope'] ?? null) === 'selected_modules'
            : ($runtime['supported_unordered_key'] ?? null) === 'R_I_A';

        if (array_column($dimensions, 'dimension_code') !== ['R', 'I', 'A', 'S', 'E', 'C']
            || $pairKeys !== $expectedPairKeys
            || array_column($top3, 'unordered_top3_key') !== self::englishTop3Coverage($compilerVersion)
            || ($top3[0]['dimensions'] ?? null) !== ['R', 'I', 'A']
            || ! $supportedGroupsValid
            || $readingDimensions !== $expectedReadingDimensions
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
                if (! is_string($line[$field] ?? null) || trim($line[$field]) === '') {
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
