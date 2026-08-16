<?php

declare(strict_types=1);

namespace App\Services\Content;

use RuntimeException;

final class EnneagramPrivateResultCompileService
{
    public const PACK_ID = 'ENNEAGRAM_PRIVATE_RESULT';

    public const PACK_VERSION = 'v2';

    public const AUTHORITY_ID = 'FERMATMIND_ENNEAGRAM_PRIVATE_RESULT_CANONICAL';

    public const SCHEMA = 'fap.enneagram.private_result.compiled.v1';

    public const MANIFEST_SCHEMA = 'fap.enneagram.private_result.canonical_manifest.v1';

    public const COMPILER_SCHEMA = 'fap.enneagram.private_result.compiler.v1';

    public const COMPILER_VERSION = '1.0.0';

    public const ARTIFACT_FILENAME = 'private_result.compiled.json';

    /** @var array<string,array{registry_key:string,role:string,schema:string,surfaces:list<string>}> */
    public const SOURCE_CONTRACT = [
        'group_registry.json' => ['registry_key' => 'enneagram_group_registry', 'role' => 'centers, stances, and harmonics', 'schema' => 'fap.enneagram.group_registry.v1', 'surfaces' => ['result', 'report']],
        'method_registry.json' => ['registry_key' => 'enneagram_method_registry', 'role' => 'E105 and FC144 method boundaries', 'schema' => 'fap.enneagram.method_registry.v1', 'surfaces' => ['result', 'report', 'technical_note', 'compare']],
        'observation_registry.json' => ['registry_key' => 'enneagram_observation_registry', 'role' => 'observation workflow copy', 'schema' => 'fap.enneagram.observation_registry.v1', 'surfaces' => ['result', 'history']],
        'pair_registry.json' => ['registry_key' => 'enneagram_pair_registry', 'role' => 'all 36 close-call comparisons', 'schema' => 'fap.enneagram.pair_registry.v1', 'surfaces' => ['result', 'report', 'pdf', 'history', 'compare', 'share']],
        'sample_report_registry.json' => ['registry_key' => 'enneagram_sample_report_registry', 'role' => 'canonical sample reports', 'schema' => 'fap.enneagram.sample_report_registry.v1', 'surfaces' => ['result', 'report']],
        'scenario_registry.json' => ['registry_key' => 'enneagram_scenario_registry', 'role' => 'work, relationship, growth, and scenario copy', 'schema' => 'fap.enneagram.scenario_registry.v1', 'surfaces' => ['result', 'report']],
        'state_registry.json' => ['registry_key' => 'enneagram_state_registry', 'role' => 'state spectrum copy', 'schema' => 'fap.enneagram.state_registry.v1', 'surfaces' => ['result', 'report']],
        'surface_registry.json' => ['registry_key' => 'enneagram_surface_registry', 'role' => 'page, FAQ, share, PDF, print, history, compare, and secondary surface copy', 'schema' => 'fap.enneagram.surface_registry.v1', 'surfaces' => ['result', 'report', 'faq', 'technical_note', 'share', 'pdf', 'print', 'history', 'compare']],
        'technical_note_registry.json' => ['registry_key' => 'enneagram_technical_note_registry', 'role' => 'technical note sections', 'schema' => 'fap.enneagram.technical_note_registry.v1', 'surfaces' => ['technical_note', 'result', 'report', 'pdf']],
        'theory_hint_registry.json' => ['registry_key' => 'enneagram_theory_hint_registry', 'role' => 'bounded theory hints', 'schema' => 'fap.enneagram.theory_hint_registry.v1', 'surfaces' => ['result', 'report']],
        'type_registry.json' => ['registry_key' => 'enneagram_type_registry', 'role' => 'nine type private-result bodies', 'schema' => 'fap.enneagram.type_registry.v1', 'surfaces' => ['result', 'report', 'pdf', 'history', 'share']],
        'ui_copy_registry.json' => ['registry_key' => 'enneagram_ui_copy_registry', 'role' => 'result states and form labels', 'schema' => 'fap.enneagram.ui_copy_registry.v1', 'surfaces' => ['result', 'report']],
    ];

    public function __construct(private readonly ?string $registryPath = null) {}

    /** @return array{payload:array<string,mixed>,bytes:string,manifest:array<string,mixed>,english_manifest:array<string,mixed>,manifest_bytes:string,english_manifest_bytes:string,source_hash:string,compiled_hash:string} */
    public function compile(): array
    {
        $localeAssets = [];
        $localeFiles = [];
        $localeHashes = [];
        $packageHashInput = '';

        foreach (['zh-CN' => '', 'en' => 'en/'] as $locale => $prefix) {
            $assets = [];
            $files = [];
            $localeHashInput = '';
            foreach (self::SOURCE_CONTRACT as $filename => $contract) {
                $relative = $prefix.$filename;
                $path = $this->root().'/'.$relative;
                $decoded = $this->decode($path, $relative);
                $this->validateSource($decoded, $contract, $locale, $relative);
                $digest = hash('sha256', $this->canonicalJson($decoded));
                $files[] = [
                    'path' => $relative,
                    'registry_key' => $contract['registry_key'],
                    'role' => $contract['role'],
                    'locale' => $locale,
                    'schema' => $contract['schema'],
                    'required_fields' => ['schema_version', 'registry_key', 'locale', 'entries'],
                    'consumer_surfaces' => $contract['surfaces'],
                    'sha256' => $digest,
                ];
                $localeHashInput .= $filename."\0".$digest."\n";
                $packageHashInput .= $locale.'/'.$filename."\0".$digest."\n";
                $assets[$filename] = $decoded;
            }
            $localeAssets[$locale] = $assets;
            $localeFiles[$locale] = $files;
            $localeHashes[$locale] = hash('sha256', $localeHashInput);
        }

        $sourceHash = hash('sha256', $packageHashInput);
        $coverage = $this->coverage($localeAssets);
        $compiler = ['schema' => self::COMPILER_SCHEMA, 'version' => self::COMPILER_VERSION];
        $manifest = $this->manifest('zh-CN', $localeFiles['zh-CN'], $localeFiles, $localeHashes, $sourceHash, $coverage, $compiler);
        $englishManifest = $this->manifest('en', $localeFiles['en'], $localeFiles, $localeHashes, $sourceHash, $coverage, $compiler);
        $unsigned = [
            'schema' => self::SCHEMA,
            'authority_id' => self::AUTHORITY_ID,
            'authority_root' => 'backend/content_packs/ENNEAGRAM/v2/registry',
            'scale_code' => 'ENNEAGRAM',
            'version' => self::PACK_VERSION,
            'runtime_contract' => 'enneagram.report.v2',
            'compiler' => $compiler,
            'source_hash' => $sourceHash,
            'locale_source_hashes' => $localeHashes,
            'coverage' => $coverage,
            'form_projections' => [
                'e105' => ['form_code' => 'enneagram_likert_105', 'methodology_variant' => 'e105_standard', 'source_hash' => $sourceHash],
                'fc144' => ['form_code' => 'enneagram_forced_choice_144', 'methodology_variant' => 'fc144_forced_choice', 'source_hash' => $sourceHash],
            ],
            'locale_assets' => $localeAssets,
        ];
        $compiledHash = hash('sha256', $this->canonicalJson($unsigned));
        $payload = $unsigned + ['compiled_hash' => $compiledHash];
        $bytes = $this->canonicalJson($payload)."\n";
        $localeManifests = [&$manifest, &$englishManifest];
        foreach ($localeManifests as &$localeManifest) {
            $localeManifest['compiled_hash_rule'] = 'sha256(canonical JSON of compiled payload excluding compiled_hash)';
            $localeManifest['compiled_hash'] = $compiledHash;
            $localeManifest['content_hash'] = $sourceHash;
            $localeManifest['artifacts'] = [['path' => self::ARTIFACT_FILENAME, 'sha256' => hash('sha256', $bytes)]];
        }
        unset($localeManifest);

        return [
            'payload' => $payload,
            'bytes' => $bytes,
            'manifest' => $manifest,
            'english_manifest' => $englishManifest,
            'manifest_bytes' => $this->prettyJson($manifest),
            'english_manifest_bytes' => $this->prettyJson($englishManifest),
            'source_hash' => $sourceHash,
            'compiled_hash' => $compiledHash,
        ];
    }

    /** @return array{ok:bool,pack_id:string,version:string,compiled_dir:string,source_hash:string,compiled_hash:string} */
    public function compileToPackDirectory(): array
    {
        $compiled = $this->compile();
        $directory = dirname($this->root()).'/compiled';
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create Enneagram canonical compiled directory.');
        }
        $this->atomicWrite($directory.'/'.self::ARTIFACT_FILENAME, $compiled['bytes']);
        $this->atomicWrite($directory.'/manifest.json', $compiled['manifest_bytes']);
        $this->atomicWrite($this->root().'/manifest.json', $compiled['manifest_bytes']);
        $this->atomicWrite($this->root().'/en/manifest.json', $compiled['english_manifest_bytes']);

        return ['ok' => true, 'pack_id' => self::PACK_ID, 'version' => self::PACK_VERSION, 'compiled_dir' => $directory, 'source_hash' => $compiled['source_hash'], 'compiled_hash' => $compiled['compiled_hash']];
    }

    /** @param array<string,mixed> $decoded @param array{registry_key:string,role:string,schema:string,surfaces:list<string>} $contract */
    private function validateSource(array $decoded, array $contract, string $locale, string $relative): void
    {
        if (($decoded['schema_version'] ?? null) !== $contract['schema'] || ($decoded['registry_key'] ?? null) !== $contract['registry_key'] || ($decoded['locale'] ?? null) !== $locale || ! is_array($decoded['entries'] ?? null)) {
            throw new RuntimeException("Enneagram canonical source contract mismatch: {$relative}");
        }
    }

    /** @param array<string,array<string,array<string,mixed>>> $localeAssets @return array<string,mixed> */
    private function coverage(array $localeAssets): array
    {
        foreach (['zh-CN', 'en'] as $locale) {
            $assets = $localeAssets[$locale] ?? [];
            $types = array_column((array) ($assets['type_registry.json']['entries'] ?? []), 'type_id');
            $pairs = array_column((array) ($assets['pair_registry.json']['entries'] ?? []), 'pair_key');
            sort($types, SORT_STRING);
            sort($pairs, SORT_STRING);
            if ($types !== ['1', '2', '3', '4', '5', '6', '7', '8', '9'] || $pairs !== $this->allPairKeys()) {
                throw new RuntimeException("Enneagram canonical type or pair coverage is incomplete: {$locale}");
            }
            foreach (['faq', 'technical_note', 'share', 'pdf', 'print', 'history', 'compare', 'secondary'] as $surface) {
                if (! is_array($assets['surface_registry.json']['entries'][$surface] ?? null)) {
                    throw new RuntimeException("Enneagram canonical secondary surface is incomplete: {$locale}:{$surface}");
                }
            }
        }

        return [
            'locales' => ['zh-CN', 'en'], 'types' => ['1', '2', '3', '4', '5', '6', '7', '8', '9'], 'pair_count' => 36,
            'groups' => ['centers', 'stances', 'harmonics'], 'interpretation_states' => ['clear', 'close_call', 'diffuse', 'low_quality'],
            'scenarios' => ['work', 'relationship', 'growth', 'state', 'observation'], 'forms' => ['e105', 'fc144'],
            'secondary_surfaces' => ['faq', 'technical_note', 'share', 'pdf', 'print', 'history', 'compare', 'secondary'],
        ];
    }

    /** @return list<string> */
    private function allPairKeys(): array
    {
        $keys = [];
        for ($a = 1; $a <= 8; $a++) {
            for ($b = $a + 1; $b <= 9; $b++) {
                $keys[] = $a.'_'.$b;
            }
        }

        return $keys;
    }

    /** @param list<array<string,mixed>> $sourceFiles @param array<string,list<array<string,mixed>>> $localeFiles @param array<string,string> $localeHashes @param array<string,mixed> $coverage @param array<string,string> $compiler @return array<string,mixed> */
    private function manifest(string $locale, array $sourceFiles, array $localeFiles, array $localeHashes, string $sourceHash, array $coverage, array $compiler): array
    {
        return [
            'schema_version' => self::MANIFEST_SCHEMA, 'authority_id' => self::AUTHORITY_ID, 'authority_root' => 'backend/content_packs/ENNEAGRAM/v2/registry',
            'editable_authority_count' => 1, 'scale_code' => 'ENNEAGRAM', 'registry_version' => 'enneagram_registry.v1', 'release_id' => 'enneagram_registry_canonical_v2',
            'locale' => $locale, 'locales' => $locale === 'en' ? ['en'] : ['zh-CN', 'en'], 'supported_form_variants' => ['all', 'e105', 'fc144'],
            'supported_context_modes' => ['individual', 'workplace', 'team'], 'content_maturity_values' => ['scaffold', 'p0_placeholder', 'p0_ready', 'p1_expanded', 'experimental', 'deprecated'],
            'evidence_level_values' => ['descriptive', 'theory_based', 'data_supported', 'validated_internal', 'validated_external'],
            'registries' => array_map(static fn (array $row): array => ['registry_key' => $row['registry_key'], 'file' => basename((string) $row['path'])], $sourceFiles),
            'source_files' => $sourceFiles, 'locale_source_files' => $localeFiles, 'source_hash_rule' => 'sha256(concat(sorted locale/path + NUL + sha256(canonical decoded JSON) + LF))',
            'source_hash' => $sourceHash, 'locale_source_hashes' => $localeHashes, 'compiler' => $compiler, 'coverage' => $coverage,
            'migration_inventory' => [
                ['source' => 'result_page/1R-A..1R-H', 'disposition' => 'missing canonical fields migrated; receipts, QA, and runner metadata excluded'],
                ['source' => 'W5 English parity candidate', 'disposition' => 'missing English pair and secondary-surface fields migrated; candidate envelope excluded'],
                ['source' => 'EnneagramReportComposer and EnneagramTechnicalNoteService', 'disposition' => 'page specifications, boundary copy, and technical-note disclaimers migrated'],
                ['source' => 'fap-web local editorial copy', 'disposition' => 'consumer migration assigned to serial card 3'],
                ['source' => 'preview/generated fixtures', 'disposition' => 'not canonical source; deterministic compiler projections replace editable fixtures'],
            ],
            'generated' => ['manifest' => 'compiled/manifest.json', 'artifact' => 'compiled/'.self::ARTIFACT_FILENAME, 'manual_edit_allowed' => false],
        ];
    }

    /** @return array<string,mixed> */
    private function decode(string $path, string $relative): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Enneagram canonical source is missing: {$relative}");
        }
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException("Enneagram canonical source is invalid: {$relative}");
        }

        return $decoded;
    }

    private function root(): string
    {
        return rtrim($this->registryPath ?? base_path('content_packs/ENNEAGRAM/v2/registry'), '/');
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function prettyJson(array $value): string
    {
        return json_encode($this->normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
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
            throw new RuntimeException("Unable to write Enneagram canonical artifact: {$path}");
        }
    }
}
