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

    public const COMPILER_VERSION = '1.0.0';

    public const ARTIFACT_FILENAME = 'private_result.compiled.json';

    /** @var array<string,array{role:string,schema:string,surfaces:list<string>}> */
    public const SOURCE_CONTRACT = [
        '140q_task_environment_role_v1.zh-CN.jsonl' => ['role' => '140q task, environment, and role projection copy', 'schema' => 'riasec.140q_layer_copy.v1', 'surfaces' => ['result', 'report', 'pdf', 'history']],
        'activity_task_examples_v1.zh-CN.jsonl' => ['role' => 'activity and task examples', 'schema' => 'riasec.activity_task_example.v1', 'surfaces' => ['result', 'report']],
        'aspirations_calibration_v1.zh-CN.jsonl' => ['role' => 'aspiration calibration copy', 'schema' => 'riasec.aspirations_calibration.v1', 'surfaces' => ['result', 'history']],
        'dimension_deep_copy_v1.zh-CN.r3.json' => ['role' => 'six dimension band copy', 'schema' => 'riasec.deep_copy_slot_schema.v1_plus_medium_score_reading_candidate', 'surfaces' => ['result', 'report', 'pdf']],
        'disagree_path_v1.zh-CN.jsonl' => ['role' => 'result disagreement path copy', 'schema' => 'riasec.disagree_path.v1', 'surfaces' => ['result', 'history']],
        'faq_v1.zh-CN.json' => ['role' => 'private result FAQ', 'schema' => 'riasec.faq.asset.v1', 'surfaces' => ['result', 'report']],
        'feedback_action_lab_v1.zh-CN.jsonl' => ['role' => 'feedback and action copy', 'schema' => 'riasec.feedback_action_lab.v1', 'surfaces' => ['result', 'history']],
        'low_quality_cautious_reading_v1.zh-CN.json' => ['role' => 'low-quality cautious reading copy', 'schema' => 'riasec.low_quality.asset.v1', 'surfaces' => ['result', 'report', 'share', 'pdf']],
        'near_tie_alternate_code_copy_v1.zh-CN.json' => ['role' => 'near-tie and alternate-code copy', 'schema' => 'riasec.near_tie.asset.v1', 'surfaces' => ['result', 'report']],
        'next_exploration_nodes_v1.zh-CN.jsonl' => ['role' => 'next exploration copy', 'schema' => 'riasec.next_exploration_node.v1', 'surfaces' => ['result', 'report']],
        'occupation_examples_boundary_v1.zh-CN.jsonl' => ['role' => 'bounded occupation examples', 'schema' => 'riasec.occupation_example_boundary.v1', 'surfaces' => ['result', 'report']],
        'pair_blend_15_pairs_v1.zh-CN.jsonl' => ['role' => 'fifteen pair blend copy', 'schema' => 'riasec.pair_blend_asset.v1', 'surfaces' => ['result', 'report', 'pdf']],
        'professional_method_boundary_v1.zh-CN.json' => ['role' => 'method and claim boundaries', 'schema' => 'riasec.professional_method_boundary.asset.v1', 'surfaces' => ['result', 'report', 'pdf']],
        'profile_shape_copy_v1.zh-CN.json' => ['role' => 'profile shape copy', 'schema' => 'riasec.profile_shape.asset.v1', 'surfaces' => ['result', 'report']],
        'runtime_surface_copy_v1.zh-CN.json' => ['role' => 'runtime summary, quality, tie, and structural surface copy', 'schema' => 'riasec.runtime_surface_copy.v1', 'surfaces' => ['result', 'report', 'pdf', 'history', 'compare']],
        'share_pdf_history_v1.zh-CN.json' => ['role' => 'share, PDF, print, history, and compare copy', 'schema' => 'riasec.secondary_surfaces.asset.v1', 'surfaces' => ['share', 'pdf', 'print', 'history', 'compare']],
        'technical_note_user_summary_v1.zh-CN.json' => ['role' => 'technical note user summary', 'schema' => 'riasec.technical_note.asset.v1', 'surfaces' => ['result', 'report', 'pdf']],
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
            if (! $jsonLines && ($row['locale'] ?? null) !== 'zh-CN') {
                throw new RuntimeException("RIASEC canonical source locale mismatch: {$location}");
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
