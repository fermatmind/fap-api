<?php

declare(strict_types=1);

namespace App\Services\Content;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class BigFivePrivateResultCompileService
{
    public const SCHEMA = 'fap.big5.private_result.compiled.v1';

    public const COMPILER_SCHEMA = 'fap.big5.private_result.compiler.v1';

    public const COMPILER_VERSION = '1.0.0';

    public function __construct(
        private readonly ?string $registryPath = null,
    ) {}

    /**
     * @return array{payload:array<string,mixed>,bytes:string,manifest:array<string,mixed>,source_hash:string,compiled_hash:string}
     */
    public function compile(): array
    {
        $files = $this->sourceFiles();
        $assets = [];
        $sourceFiles = [];
        $sourceHashInput = '';

        foreach ($files as $relativePath => $absolutePath) {
            $decoded = json_decode((string) file_get_contents($absolutePath), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new RuntimeException("Big Five private result source must be a JSON object or array: {$relativePath}");
            }

            $normalized = $this->canonicalJson($decoded);
            $fileHash = hash('sha256', $normalized);
            $sourceFiles[] = [
                'path' => $relativePath,
                'sha256' => $fileHash,
            ];
            $sourceHashInput .= $relativePath."\0".$fileHash."\n";
            $assets[$relativePath] = $decoded;
        }

        $sourceHash = hash('sha256', $sourceHashInput);
        $coverage = $this->coverage($assets);
        $manifest = [
            'schema' => 'fap.big5.report_registry_manifest.v1',
            'registry_id' => 'BIG5_OCEAN_private_result_canonical',
            'scale_code' => 'BIG5_OCEAN',
            'locale' => 'zh-CN',
            'version' => 'v2',
            'scope' => 'Canonical Chinese private Big Five result authority',
            'runtime_contract' => 'fap.big5.report.v1',
            'section_skeleton' => [
                'hero_summary',
                'domains_overview',
                'domain_deep_dive',
                'facet_details',
                'core_portrait',
                'norms_comparison',
                'action_plan',
                'methodology_and_access',
            ],
            'traits_in_scope' => ['O', 'C', 'E', 'A', 'N'],
            'compiler' => [
                'schema' => self::COMPILER_SCHEMA,
                'version' => self::COMPILER_VERSION,
            ],
            'source_files' => $sourceFiles,
            'source_hash' => $sourceHash,
            'coverage' => $coverage,
            'fixtures' => ['fixtures/canonical_n_slice_sensitive_independent.context.json'],
        ];

        $unsignedPayload = [
            'schema' => self::SCHEMA,
            'scale_code' => 'BIG5_OCEAN',
            'locale' => 'zh-CN',
            'version' => 'v2',
            'runtime_contract' => 'fap.big5.report.v1',
            'compiler' => $manifest['compiler'],
            'source_hash' => $sourceHash,
            'coverage' => $coverage,
            'assets' => $assets,
        ];
        $compiledHash = hash('sha256', $this->canonicalJson($unsignedPayload));
        $payload = $unsignedPayload;
        $payload['compiled_hash'] = $compiledHash;
        $bytes = $this->canonicalJson($payload)."\n";

        return [
            'payload' => $payload,
            'bytes' => $bytes,
            'manifest' => $manifest,
            'source_hash' => $sourceHash,
            'compiled_hash' => $compiledHash,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function sourceFiles(): array
    {
        $root = $this->registryPath ?? base_path('content_packs/BIG5_OCEAN/v2/registry');
        if (! is_dir($root)) {
            throw new RuntimeException("Big Five private result registry is missing: {$root}");
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'json') {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($relativePath === 'manifest.json'
                || str_starts_with($relativePath, 'en/')
                || str_starts_with($relativePath, 'fixtures/')
                || str_contains($relativePath, '/fixtures/')) {
                continue;
            }

            $files[$relativePath] = $file->getPathname();
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param  array<string,mixed>  $assets
     * @return array<string,mixed>
     */
    private function coverage(array $assets): array
    {
        $facetCount = 0;
        $facetPrecisionRuleCount = 0;
        foreach (['O', 'C', 'E', 'A', 'N'] as $trait) {
            $bands = array_keys((array) ($assets["atomic/{$trait}.json"]['bands'] ?? []));
            if ($bands !== ['low', 'mid', 'high']) {
                throw new RuntimeException("Big Five private result atomic coverage is incomplete for {$trait}");
            }
            if (count((array) ($assets["modifiers/{$trait}.json"]['gradients'] ?? [])) !== 5) {
                throw new RuntimeException("Big Five private result modifier coverage is incomplete for {$trait}");
            }
            $facetCount += count((array) ($assets["facet_glossary/{$trait}.json"]['facets'] ?? []));
            $facetPrecisionRuleCount += count((array) ($assets["facet_precision/{$trait}.json"]['rules'] ?? []));
        }

        $synergyCount = count(array_filter(array_keys($assets), static fn (string $path): bool => str_starts_with($path, 'synergies/')));
        $actionRuleCounts = [];
        foreach (['workplace', 'relationships', 'stress_recovery', 'personal_growth'] as $scenario) {
            $actionRuleCounts[$scenario] = count((array) ($assets["action_rules/{$scenario}.json"]['rules'] ?? []));
        }

        $qualityAssets = array_keys((array) ($assets['surfaces/quality_states.json']['assets'] ?? []));
        $accessLevels = array_keys((array) ($assets['surfaces/secondary.json']['access_levels'] ?? []));
        $requiredSurfaces = [
            'surfaces/faq.json',
            'surfaces/lifecycle.json',
            'surfaces/share.json',
            'surfaces/pdf_print.json',
            'surfaces/history_compare.json',
            'surfaces/secondary.json',
        ];
        if ($facetCount !== 30
            || $facetPrecisionRuleCount !== 22
            || $synergyCount !== 5
            || $actionRuleCounts !== ['workplace' => 8, 'relationships' => 8, 'stress_recovery' => 6, 'personal_growth' => 6]
            || ! in_array('score.near_boundary', $qualityAssets, true)
            || ! in_array('quality.low_quality', $qualityAssets, true)
            || ! in_array('norm.unavailable', $qualityAssets, true)
            || $accessLevels !== ['free', 'full']
            || array_diff($requiredSurfaces, array_keys($assets)) !== []) {
            throw new RuntimeException('Big Five private result canonical coverage is incomplete');
        }

        return [
            'atomic_traits' => ['O', 'C', 'E', 'A', 'N'],
            'atomic_bands_per_trait' => ['low', 'mid', 'high'],
            'traits' => ['O', 'C', 'E', 'A', 'N'],
            'bands' => ['low', 'mid', 'high'],
            'facet_count' => $facetCount,
            'modifier_traits' => ['O', 'C', 'E', 'A', 'N'],
            'modifier_gradients_per_trait' => 5,
            'near_boundary' => true,
            'synergies' => ['n_high_x_e_low', 'o_high_x_c_low', 'o_high_x_n_high', 'c_high_x_n_high', 'e_high_x_a_low'],
            'synergy_count' => $synergyCount,
            'facet_precision_traits' => ['O', 'C', 'E', 'A', 'N'],
            'action_rule_scope' => 'scenario-bound action matrix',
            'action_rule_scenarios' => ['workplace', 'relationships', 'stress_recovery', 'personal_growth'],
            'action_scenarios' => ['workplace', 'relationships', 'stress_recovery', 'personal_growth'],
            'action_rule_count' => 28,
            'action_rule_counts_by_scenario' => $actionRuleCounts,
            'action_matrix_caps' => [
                'per_scenario_per_bucket' => 1,
                'per_scenario' => 4,
                'per_report' => 12,
            ],
            'facet_glossary_traits' => ['O', 'C', 'E', 'A', 'N'],
            'facet_glossary_entries' => $facetCount,
            'facet_precision_rules' => $facetPrecisionRuleCount,
            'facet_precision_caps' => [
                'per_domain' => 2,
                'per_report' => 6,
                'standout_render_cards' => 3,
            ],
            'quality_states' => ['valid', 'low_quality', 'norm_unavailable'],
            'access_levels' => ['free', 'full'],
            'secondary_surfaces' => ['faq', 'lifecycle', 'share', 'pdf', 'print', 'history', 'compare'],
        ];
    }

    private function canonicalJson(mixed $value): string
    {
        $normalized = $this->normalize($value);
        $encoded = json_encode(
            $normalized,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );

        return $encoded;
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
