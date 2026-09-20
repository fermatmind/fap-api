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

    public const COMPILER_VERSION = '1.3.0';

    private const MAX_SECTION_BIGRAM_SIMILARITY = 0.62;

    private const MAX_INTRA_TYPE_SECTION_BIGRAM_SIMILARITY = 0.68;

    private const MAX_ACTION_BIGRAM_SIMILARITY = 0.65;

    private const MAX_PAIR_BIGRAM_SIMILARITY = 0.70;

    public const ARTIFACT_FILENAME = 'private_result.compiled.json';

    /** @var array<string,array{registry_key:string,role:string,schema:string,surfaces:list<string>}> */
    public const SOURCE_CONTRACT = [
        'chapter_registry.json' => ['registry_key' => 'enneagram_chapter_registry', 'role' => 'seven-chapter candidate sections and growth actions', 'schema' => 'fap.enneagram.chapter_registry.v1', 'surfaces' => ['result', 'report', 'pdf', 'history']],
        'evidence_registry.json' => ['registry_key' => 'enneagram_evidence_registry', 'role' => 'non-rendered evidence and theory provenance', 'schema' => 'fap.enneagram.evidence_registry.v1', 'surfaces' => ['technical_note']],
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
                $this->validateEditorialHygiene($decoded, $locale, $relative);
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

    private function validateEditorialHygiene(mixed $value, string $locale, string $relative, string $path = '$'): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->validateEditorialHygiene($item, $locale, $relative, $path.'.'.(string) $key);
            }

            return;
        }

        if (! is_string($value)) {
            return;
        }

        $pattern = $locale === 'en'
            ? '/\b([a-z]{2,})\s+\1\b(?!-)/iu'
            : '/([\x{3400}-\x{9fff}]{2})\1/u';
        if (preg_match($pattern, $value) === 1) {
            throw new RuntimeException("Enneagram canonical editorial word duplication: {$relative}:{$path}");
        }
    }

    /** @param array<string,array<string,array<string,mixed>>> $localeAssets @return array<string,mixed> */
    private function coverage(array $localeAssets): array
    {
        foreach (['zh-CN', 'en'] as $locale) {
            $assets = $localeAssets[$locale] ?? [];
            $types = array_column((array) ($assets['type_registry.json']['entries'] ?? []), 'type_id');
            $chapterTypes = array_column((array) ($assets['chapter_registry.json']['entries'] ?? []), 'type_id');
            $pairs = array_column((array) ($assets['pair_registry.json']['entries'] ?? []), 'pair_key');
            sort($types, SORT_STRING);
            sort($chapterTypes, SORT_STRING);
            sort($pairs, SORT_STRING);
            if ($types !== ['1', '2', '3', '4', '5', '6', '7', '8', '9']
                || $chapterTypes !== ['1', '2', '3', '4', '5', '6', '7', '8', '9']
                || $pairs !== $this->allPairKeys()) {
                throw new RuntimeException("Enneagram canonical type or pair coverage is incomplete: {$locale}");
            }
            foreach ((array) ($assets['chapter_registry.json']['entries'] ?? []) as $entry) {
                $sectionIds = array_column((array) ($entry['sections'] ?? []), 'section_id');
                if ($sectionIds !== $this->requiredSectionIds() || count((array) ($entry['growth_actions'] ?? [])) !== 5) {
                    throw new RuntimeException("Enneagram canonical chapter coverage is incomplete: {$locale}:".(string) ($entry['type_id'] ?? 'unknown'));
                }
            }
            $this->validateChapterDifferentiation((array) ($assets['chapter_registry.json']['entries'] ?? []), $locale);
            $this->validatePairDifferentiation((array) ($assets['pair_registry.json']['entries'] ?? []), $locale);
            $this->validateEvidenceClaims($assets, $locale);
            foreach (['faq', 'technical_note', 'share', 'pdf', 'print', 'history', 'compare', 'secondary'] as $surface) {
                if (! is_array($assets['surface_registry.json']['entries'][$surface] ?? null)) {
                    throw new RuntimeException("Enneagram canonical secondary surface is incomplete: {$locale}:{$surface}");
                }
            }
        }

        return [
            'locales' => ['zh-CN', 'en'], 'types' => ['1', '2', '3', '4', '5', '6', '7', '8', '9'], 'pair_count' => 36,
            'type_section_count' => 20, 'total_section_count_per_locale' => 180, 'growth_action_count_per_type' => 5,
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

    /** @return list<string> */
    private function requiredSectionIds(): array
    {
        return [
            '2.1', '2.2', '2.3', '2.4', '2.5', '2.6',
            '3.1', '3.2', '3.3',
            '4.1', '4.2', '4.3',
            '5.1', '5.2', '5.3',
            '6.1', '6.2', '6.3', '6.4', '6.5',
        ];
    }

    /** @param list<array<string,mixed>> $entries */
    private function validateChapterDifferentiation(array $entries, string $locale): void
    {
        $bySection = [];
        $byType = [];
        $actions = [];
        foreach ($entries as $entry) {
            $typeId = (string) ($entry['type_id'] ?? '');
            foreach ((array) ($entry['sections'] ?? []) as $section) {
                if (! is_array($section)) {
                    continue;
                }
                $sectionId = (string) ($section['section_id'] ?? '');
                $reflectionQuestion = trim((string) ($section['reflection_question'] ?? ''));
                $reflectionLength = mb_strlen($reflectionQuestion);
                $reflectionBounds = $locale === 'en' ? [80, 420] : [24, 160];
                if (count((array) ($section['paragraphs'] ?? [])) < 2
                    || count((array) ($section['points'] ?? [])) < 2
                    || $reflectionLength < $reflectionBounds[0]
                    || $reflectionLength > $reflectionBounds[1]
                    || ! str_contains($reflectionQuestion, $locale === 'en' ? '?' : '？')) {
                    throw new RuntimeException("Enneagram canonical section semantic completeness invalid: {$locale}:{$typeId}:{$sectionId}");
                }
                if (in_array($sectionId, ['2.6', '6.4'], true)) {
                    continue;
                }
                $sentences = array_values(array_filter([
                    (string) ($section['lead'] ?? ''),
                    ...array_map('strval', (array) ($section['paragraphs'] ?? [])),
                    ...array_map('strval', (array) ($section['points'] ?? [])),
                    $reflectionQuestion,
                ], static fn (string $value): bool => trim($value) !== ''));
                $bySection[$sectionId][$typeId] = [
                    'text' => implode(' ', $sentences),
                    'sentences' => $sentences,
                ];
                $byType[$typeId][$sectionId] = [
                    'text' => implode(' ', $sentences),
                    'sentences' => $sentences,
                ];
            }
            foreach ((array) ($entry['growth_actions'] ?? []) as $action) {
                if (! is_array($action)) {
                    continue;
                }
                $actionId = (string) ($action['action_id'] ?? '');
                $title = trim((string) ($action['title'] ?? ''));
                $text = trim((string) ($action['instruction'] ?? '')).' '.trim((string) ($action['observable_outcome'] ?? ''));
                $normalized = str_ireplace($title, ' ', $text);
                $normalized = preg_replace('/(?:type\s*)?[1-9]|[1-9]号[\x{3400}-\x{9fff}]*型/iu', ' type ', $normalized) ?? $normalized;
                $actions[$actionId] = $this->normalizeDifferentiationText($normalized, $locale);
            }
        }

        foreach ($bySection as $sectionId => $types) {
            $typeIds = array_keys($types);
            for ($left = 0; $left < count($typeIds); $left++) {
                for ($right = $left + 1; $right < count($typeIds); $right++) {
                    $leftType = $typeIds[$left];
                    $rightType = $typeIds[$right];
                    $duplicates = array_intersect($types[$leftType]['sentences'], $types[$rightType]['sentences']);
                    if ($duplicates !== []) {
                        throw new RuntimeException("Enneagram canonical section sentence duplication: {$locale}:{$sectionId}:{$leftType}:{$rightType}");
                    }
                    $similarity = $this->bigramSimilarity(
                        $this->normalizeDifferentiationText($types[$leftType]['text'], $locale),
                        $this->normalizeDifferentiationText($types[$rightType]['text'], $locale),
                        $locale
                    );
                    if ($similarity > self::MAX_SECTION_BIGRAM_SIMILARITY) {
                        throw new RuntimeException(sprintf(
                            'Enneagram canonical section similarity exceeds %.2f: %s:%s:%s:%s:%.4f',
                            self::MAX_SECTION_BIGRAM_SIMILARITY,
                            $locale,
                            $sectionId,
                            $leftType,
                            $rightType,
                            $similarity
                        ));
                    }
                }
            }
        }

        foreach ($byType as $typeId => $sections) {
            $seenSentences = [];
            foreach ($sections as $sectionId => $section) {
                foreach ($section['sentences'] as $sentence) {
                    if (isset($seenSentences[$sentence])) {
                        throw new RuntimeException("Enneagram canonical intra-type sentence duplication: {$locale}:{$typeId}:{$sectionId}:{$seenSentences[$sentence]}");
                    }
                    $seenSentences[$sentence] = $sectionId;
                }
            }
            $sectionIds = array_keys($sections);
            for ($left = 0; $left < count($sectionIds); $left++) {
                for ($right = $left + 1; $right < count($sectionIds); $right++) {
                    $leftSection = $sectionIds[$left];
                    $rightSection = $sectionIds[$right];
                    $similarity = $this->bigramSimilarity(
                        $this->normalizeDifferentiationText($sections[$leftSection]['text'], $locale),
                        $this->normalizeDifferentiationText($sections[$rightSection]['text'], $locale),
                        $locale
                    );
                    if ($similarity > self::MAX_INTRA_TYPE_SECTION_BIGRAM_SIMILARITY) {
                        throw new RuntimeException(sprintf(
                            'Enneagram canonical intra-type section similarity exceeds %.2f: %s:%s:%s:%s:%.4f',
                            self::MAX_INTRA_TYPE_SECTION_BIGRAM_SIMILARITY,
                            $locale,
                            $typeId,
                            $leftSection,
                            $rightSection,
                            $similarity
                        ));
                    }
                }
            }
        }

        $actionIds = array_keys($actions);
        for ($left = 0; $left < count($actionIds); $left++) {
            for ($right = $left + 1; $right < count($actionIds); $right++) {
                $similarity = $this->bigramSimilarity($actions[$actionIds[$left]], $actions[$actionIds[$right]], $locale);
                if ($similarity > self::MAX_ACTION_BIGRAM_SIMILARITY) {
                    throw new RuntimeException(sprintf(
                        'Enneagram canonical action similarity exceeds %.2f: %s:%s:%s:%.4f',
                        self::MAX_ACTION_BIGRAM_SIMILARITY,
                        $locale,
                        $actionIds[$left],
                        $actionIds[$right],
                        $similarity
                    ));
                }
            }
        }
    }

    /** @param list<array<string,mixed>> $entries */
    private function validatePairDifferentiation(array $entries, string $locale): void
    {
        $signatures = [];
        $fieldValues = [];
        $comparisonSignatures = [];
        foreach ($entries as $entry) {
            $pairKey = (string) ($entry['pair_key'] ?? '');
            $typeA = (string) ($entry['type_a'] ?? '');
            $typeB = (string) ($entry['type_b'] ?? '');
            if ($pairKey !== $typeA.'_'.$typeB || ! preg_match('/^[1-8]$/', $typeA) || ! preg_match('/^[2-9]$/', $typeB) || (int) $typeA >= (int) $typeB) {
                throw new RuntimeException("Enneagram canonical pair identity invalid: {$locale}:{$pairKey}");
            }
            $parts = [];
            $motivationParts = [];
            foreach (['core_motivation_difference', 'fear_difference', 'stress_reaction_difference', 'relationship_difference', 'work_difference'] as $field) {
                $sides = is_array($entry[$field] ?? null) ? $entry[$field] : [];
                $left = trim((string) ($sides[$typeA] ?? ''));
                $right = trim((string) ($sides[$typeB] ?? ''));
                if ($left === '' || $right === '' || $left === $right) {
                    throw new RuntimeException("Enneagram canonical pair side content invalid: {$locale}:{$pairKey}:{$field}");
                }
                foreach ([$left, $right] as $value) {
                    if (isset($fieldValues[$field][$value])) {
                        throw new RuntimeException("Enneagram canonical pair side content reused: {$locale}:{$pairKey}:{$field}:{$fieldValues[$field][$value]}");
                    }
                    $fieldValues[$field][$value] = $pairKey;
                }
                $parts[] = $left;
                $parts[] = $right;
                if ($field === 'core_motivation_difference') {
                    $motivationParts[] = $left;
                    $motivationParts[] = $right;
                }
            }
            foreach (['shared_surface_similarity', 'seven_day_observation_question', 'resonance_feedback_prompt', 'short_compare_copy'] as $field) {
                $value = trim((string) ($entry[$field] ?? ''));
                if ($value === '') {
                    throw new RuntimeException("Enneagram canonical pair content missing: {$locale}:{$pairKey}:{$field}");
                }
                $parts[] = $value;
            }
            $signature = $this->normalizeDifferentiationText(implode(' ', $parts), $locale);
            $signature = preg_replace('/\b(?:type)?[1-9]\b/iu', ' type ', $signature) ?? $signature;
            if (isset($signatures[$signature])) {
                throw new RuntimeException("Enneagram canonical pair template duplication: {$locale}:{$pairKey}:{$signatures[$signature]}");
            }
            $signatures[$signature] = $pairKey;
            $comparisonSignatures[$pairKey] = $this->normalizeDifferentiationText(implode(' ', $motivationParts), $locale);
        }

        $pairKeys = array_keys($comparisonSignatures);
        for ($left = 0; $left < count($pairKeys); $left++) {
            for ($right = $left + 1; $right < count($pairKeys); $right++) {
                $similarity = $this->bigramSimilarity($comparisonSignatures[$pairKeys[$left]], $comparisonSignatures[$pairKeys[$right]], $locale);
                if ($similarity > self::MAX_PAIR_BIGRAM_SIMILARITY) {
                    throw new RuntimeException(sprintf(
                        'Enneagram canonical pair similarity exceeds %.2f: %s:%s:%s:%.4f',
                        self::MAX_PAIR_BIGRAM_SIMILARITY,
                        $locale,
                        $pairKeys[$left],
                        $pairKeys[$right],
                        $similarity
                    ));
                }
            }
        }
    }

    /** @param array<string,array<string,mixed>> $assets */
    private function validateEvidenceClaims(array $assets, string $locale): void
    {
        $entries = (array) ($assets['evidence_registry.json']['entries'] ?? []);
        $evidence = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException("Enneagram canonical evidence entry invalid: {$locale}");
            }
            $id = trim((string) ($entry['evidence_id'] ?? ''));
            if ($id === '' || isset($evidence[$id])) {
                throw new RuntimeException("Enneagram canonical evidence identity invalid: {$locale}:{$id}");
            }
            foreach (['source_kind', 'citation', 'url'] as $field) {
                if (trim((string) ($entry[$field] ?? '')) === '') {
                    throw new RuntimeException("Enneagram canonical evidence field missing: {$locale}:{$id}:{$field}");
                }
            }
            foreach (['supports', 'limitations'] as $field) {
                if (! is_array($entry[$field] ?? null) || count($entry[$field]) === 0) {
                    throw new RuntimeException("Enneagram canonical evidence field missing: {$locale}:{$id}:{$field}");
                }
            }
            $evidence[$id] = $entry;
        }

        $validateRefs = function (array $refs, string $path, array $required = [], array $forbiddenSourceKinds = []) use ($evidence, $locale): void {
            if ($refs === [] || count($refs) !== count(array_unique($refs))) {
                throw new RuntimeException("Enneagram canonical claim refs invalid: {$locale}:{$path}");
            }
            foreach ($refs as $ref) {
                if (! isset($evidence[$ref])) {
                    throw new RuntimeException("Enneagram canonical claim ref unresolved: {$locale}:{$path}:{$ref}");
                }
                if (in_array((string) ($evidence[$ref]['source_kind'] ?? ''), $forbiddenSourceKinds, true)) {
                    throw new RuntimeException("Enneagram canonical claim evidence conflict: {$locale}:{$path}:{$ref}");
                }
            }
            foreach ($required as $ref) {
                if (! in_array($ref, $refs, true)) {
                    throw new RuntimeException("Enneagram canonical claim ref required: {$locale}:{$path}:{$ref}");
                }
            }
        };

        foreach ((array) ($assets['chapter_registry.json']['entries'] ?? []) as $entry) {
            $typeId = (string) ($entry['type_id'] ?? '');
            foreach ((array) ($entry['sections'] ?? []) as $section) {
                if (($section['evidence_level'] ?? null) !== 'theory_based') {
                    throw new RuntimeException("Enneagram canonical section evidence level invalid: {$locale}:{$typeId}:".(string) ($section['section_id'] ?? ''));
                }
                $validateRefs(array_values(array_map('strval', (array) ($section['claim_refs'] ?? []))), 'type-'.$typeId.':section-'.(string) ($section['section_id'] ?? ''), ['enneagram-evidence-review-2021', 'enneagram-theory-riso-hudson']);
            }
            foreach ((array) ($entry['growth_actions'] ?? []) as $action) {
                if (($action['evidence_level'] ?? null) !== 'descriptive') {
                    throw new RuntimeException("Enneagram canonical action evidence level invalid: {$locale}:".(string) ($action['action_id'] ?? ''));
                }
                $validateRefs(array_values(array_map('strval', (array) ($action['claim_refs'] ?? []))), (string) ($action['action_id'] ?? ''), ['implementation-intentions-gollwitzer-1999', 'goal-monitoring-harkin-2016'], ['theory_source']);
            }
        }
        foreach ((array) ($assets['pair_registry.json']['entries'] ?? []) as $pair) {
            if (($pair['evidence_level'] ?? null) !== 'theory_based') {
                throw new RuntimeException("Enneagram canonical pair evidence level invalid: {$locale}:".(string) ($pair['pair_key'] ?? ''));
            }
            $validateRefs(array_values(array_map('strval', (array) ($pair['claim_refs'] ?? []))), 'pair-'.(string) ($pair['pair_key'] ?? ''), ['enneagram-evidence-review-2021', 'enneagram-theory-riso-hudson']);
        }
    }

    private function normalizeDifferentiationText(string $text, string $locale): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/type\s*[1-9](?:,?\s*the\s+[a-z-]+)?/iu', ' type ', $text) ?? $text;
        $text = preg_replace('/[1-9]号[\x{3400}-\x{9fff}]+型/u', ' 类型 ', $text) ?? $text;
        $text = preg_replace('/\b[1-9]\b/u', ' type ', $text) ?? $text;

        return trim($text);
    }

    private function bigramSimilarity(string $left, string $right, string $locale): float
    {
        $tokenize = static function (string $text) use ($locale): array {
            if ($locale === 'en') {
                preg_match_all('/[a-z]+/u', $text, $matches);
                $ignoredWords = array_fill_keys([
                    'a', 'an', 'and', 'are', 'as', 'at', 'be', 'been', 'being', 'but', 'by', 'candidate', 'does', 'for', 'from',
                    'had', 'has', 'have', 'how', 'if', 'in', 'into', 'is', 'it', 'its', 'may', 'more', 'not', 'of', 'on', 'or',
                    'same', 'setting', 'situation', 'than', 'that', 'the', 'their', 'them', 'then', 'there', 'these', 'they', 'this',
                    'those', 'to', 'type', 'under', 'when', 'where', 'whether', 'which', 'while', 'with', 'you', 'your',
                ], true);

                return array_values(array_filter($matches[0] ?? [], static fn (string $token): bool => ! isset($ignoredWords[$token])));
            }
            preg_match_all('/[\x{3400}-\x{9fff}]/u', $text, $matches);

            return $matches[0] ?? [];
        };
        $bigrams = static function (array $tokens) use ($locale): array {
            $ignored = $locale === 'en'
                ? []
                : array_fill_keys(['可能', '观察', '情境', '候选', '不是', '需要', '行为', '类型', '解释', '记录', '结果', '关系', '工作', '注意', '信息', '支持', '稳定', '压力', '具体', '实际', '影响', '判断', '不能', '仍然', '对方', '自己', '通过', '以及', '同时', '这个', '一种', '进行', '提示', '线索', '反例', '核对', '本节', '焦点', '辨认', '怎样', '第一', '出现', '变得', '哪些', '退到', '背景', '同样', '主要', '来自', '短期', '降低', '权重', '事实', '不同', '关于', '面对', '场景', '更可', '围绕', '组织', '行动', '守住', '首先', '改变', '标准', '边界', '责任', '一侧', '值得', '继续', '相关', '资源', '更有', '上升', '充分', '保护', '同一', '反应', '更好', '路径', '反复', '遇到', '表达', '在乎', '回应', '失望', '没有', '得到', '照顾', '处理', '通常', '描述', '推进', '顺序', '代表', '能力', '绩效', '岗位', '适配', '比较', '两者', '选择', '理想', '自我', '未来', '七天', '三次', '单次', '决定', '强制'], true);
            $result = [];
            for ($index = 0; $index + 1 < count($tokens); $index++) {
                $display = $tokens[$index].$tokens[$index + 1];
                if (isset($ignored[$display])) {
                    continue;
                }
                $result[$tokens[$index]."\0".$tokens[$index + 1]] = true;
            }

            return $result;
        };
        $leftBigrams = $bigrams($tokenize($left));
        $rightBigrams = $bigrams($tokenize($right));
        $denominator = count($leftBigrams) + count($rightBigrams);
        if ($denominator === 0) {
            return 0.0;
        }

        return (2 * count(array_intersect_key($leftBigrams, $rightBigrams))) / $denominator;
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
