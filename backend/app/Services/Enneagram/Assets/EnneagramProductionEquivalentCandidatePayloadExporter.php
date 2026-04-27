<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets;

use RuntimeException;

final class EnneagramProductionEquivalentCandidatePayloadExporter
{
    private const EXPECTED_MANIFEST_SHA256 = '87f7eb874eb162ff158b5d3ac5e4393218d045054b2f0e3e0eddc09c6c3ea556';

    private const EXPECTED_RUNTIME_REGISTRY_MANIFEST_SHA256 = 'ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f';

    private const DEEP_CORE_CATEGORIES = [
        'core_motivation',
        'core_fear',
        'core_desire',
        'self_image',
        'attention_pattern',
        'strength',
        'blindspot',
        'stress_pattern',
        'relationship_pattern',
        'work_pattern',
        'growth_direction',
        'daily_observation',
        'boundary',
    ];

    private const FC144_BOUNDARY_UNSAFE_PHRASES = [
        'FC144 更准确',
        '更准确',
        '最终判型',
        '终极判型',
        '第二套结果页',
        '第二套产品',
        'E105 和 FC144 分数可比较',
        '分数可比较',
        '直接比较分数',
        '重新判型',
        '确认最终类型',
        '诊断',
        '招聘',
        '准确率',
    ];

    public function __construct(
        private readonly EnneagramAssetItemStreamLoader $loader,
        private readonly EnneagramAssetMergeResolver $mergeResolver,
        private readonly EnneagramAssetPreviewPayloadBuilder $payloadBuilder,
        private readonly EnneagramAssetPublicPayloadSanitizer $sanitizer,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function export(string $candidateDir, string $outputDir): array
    {
        $candidateDir = rtrim(trim($candidateDir), DIRECTORY_SEPARATOR);
        $outputDir = rtrim(trim($outputDir), DIRECTORY_SEPARATOR);

        if ($candidateDir === '' || ! is_dir($candidateDir)) {
            throw new RuntimeException('Candidate directory does not exist: '.$candidateDir);
        }

        $manifestPath = $candidateDir.DIRECTORY_SEPARATOR.'candidate_manifest.json';
        $hashesPath = $candidateDir.DIRECTORY_SEPARATOR.'candidate_hashes.json';
        $rollbackPlanPath = $candidateDir.DIRECTORY_SEPARATOR.'rollback_plan.md';

        if (! is_file($manifestPath) || ! is_file($hashesPath) || ! is_file($rollbackPlanPath)) {
            throw new RuntimeException('Candidate directory is missing required Phase 8-B artifacts.');
        }

        $manifest = $this->decodeJsonFile($manifestPath);
        $hashes = $this->decodeJsonFile($hashesPath);

        $manifestHashActual = hash_file('sha256', $manifestPath) ?: '';
        if ($manifestHashActual !== self::EXPECTED_MANIFEST_SHA256) {
            throw new RuntimeException('Candidate manifest hash mismatch: '.$manifestHashActual);
        }

        if ((string) ($hashes['candidate_manifest_sha256'] ?? '') !== self::EXPECTED_MANIFEST_SHA256) {
            throw new RuntimeException('candidate_hashes.json candidate_manifest_sha256 mismatch.');
        }

        if ((string) ($hashes['runtime_registry_manifest_sha256'] ?? '') !== self::EXPECTED_RUNTIME_REGISTRY_MANIFEST_SHA256) {
            throw new RuntimeException('candidate_hashes.json runtime_registry_manifest_sha256 mismatch.');
        }

        $assetPaths = $this->resolveAssetPaths();
        $streams = [];
        foreach ($assetPaths as $path) {
            $streams[] = $this->loader->load($path);
        }

        $merged = $this->mergeResolver->resolveStreams(...$streams);

        $payloadSets = [
            'baseline' => $this->payloadBuilder->buildAll($merged),
            'low_resonance' => $this->payloadBuilder->buildLowResonanceObjectionMatrix($merged),
            'partial_resonance' => $this->payloadBuilder->buildPartialResonanceMatrix($merged),
            'diffuse_convergence' => $this->payloadBuilder->buildDiffuseConvergenceMatrix($merged),
            'close_call_pair' => $this->payloadBuilder->buildCloseCallPairMatrix($merged),
            'scene_localization' => $this->payloadBuilder->buildSceneLocalizationMatrix($merged),
            'fc144_recommendation' => $this->payloadBuilder->buildFc144RecommendationMatrix($merged),
        ];

        $payloadCounts = array_map(static fn (array $payloads): int => count($payloads), $payloadSets);
        $expectedCounts = [
            'baseline' => 36,
            'low_resonance' => 108,
            'partial_resonance' => 90,
            'diffuse_convergence' => 108,
            'close_call_pair' => 36,
            'scene_localization' => 162,
            'fc144_recommendation' => 90,
        ];

        foreach ($expectedCounts as $matrix => $expectedCount) {
            if (($payloadCounts[$matrix] ?? -1) !== $expectedCount) {
                throw new RuntimeException(sprintf(
                    'Unexpected payload count for %s: expected %d got %d',
                    $matrix,
                    $expectedCount,
                    (int) ($payloadCounts[$matrix] ?? -1)
                ));
            }
        }

        $payloadDir = $candidateDir.DIRECTORY_SEPARATOR.'candidate_payloads';
        $this->resetDirectory($payloadDir);
        $this->ensureDirectory($outputDir);

        $payloadFiles = [];
        foreach ($payloadSets as $matrix => $payloads) {
            foreach ($payloads as $index => $payload) {
                $fileName = $this->fileNameForPayload($matrix, $payload, $index);
                $filePath = $payloadDir.DIRECTORY_SEPARATOR.$fileName;
                file_put_contents($filePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $payloadFiles[] = $filePath;
            }
        }

        sort($payloadFiles, SORT_STRING);

        $sourceMapping = $this->buildSourceMapping($payloadSets);
        $metadataLeakCount = $this->metadataLeakCount($payloadSets);
        $legacyResidualCount = $this->legacyResidualCount($payloadSets);
        $fc144BoundaryViolationCount = $this->fc144BoundaryViolationCount($payloadSets);

        $payloadManifest = [
            'schema_version' => 'enneagram.production_equivalent_candidate_payloads.v1',
            'generated_at' => now()->toIso8601String(),
            'candidate_dir' => $candidateDir,
            'candidate_manifest_path' => $manifestPath,
            'candidate_manifest_sha256' => $manifestHashActual,
            'runtime_registry_manifest_sha256' => (string) ($hashes['runtime_registry_manifest_sha256'] ?? ''),
            'payload_dir' => $payloadDir,
            'payload_counts' => $payloadCounts,
            'total_payload_count' => count($payloadFiles),
            'out_of_launch_scope' => $manifest['out_of_launch_scope'] ?? ['1R-I', '1R-J'],
            'source_assets' => $manifest['source_assets'] ?? [],
        ];

        $payloadFileHashes = [];
        foreach ($payloadFiles as $payloadFile) {
            $payloadFileHashes[basename($payloadFile)] = hash_file('sha256', $payloadFile) ?: '';
        }
        ksort($payloadFileHashes);

        $payloadHashes = [
            'candidate_payloads_manifest_sha256' => hash('sha256', json_encode($payloadManifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'payload_file_sha256' => $payloadFileHashes,
        ];

        $payloadSourceMapping = [
            'source_mapping_failure_count' => $sourceMapping['source_mapping_failure_count'],
            'missing_count' => $sourceMapping['missing_count'],
            'fallback_count' => $sourceMapping['fallback_count'],
            'blocked_count' => $sourceMapping['blocked_count'],
            'duplicate_selection_count' => $sourceMapping['duplicate_selection_count'],
            'branch_provenance_mismatch_count' => $sourceMapping['branch_provenance_mismatch_count'],
            'branch_payload_counts' => $sourceMapping['branch_payload_counts'],
        ];

        file_put_contents(
            $candidateDir.DIRECTORY_SEPARATOR.'candidate_payloads_manifest.json',
            json_encode($payloadManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        file_put_contents(
            $candidateDir.DIRECTORY_SEPARATOR.'candidate_payload_hashes.json',
            json_encode($payloadHashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        file_put_contents(
            $candidateDir.DIRECTORY_SEPARATOR.'candidate_payload_source_mapping.json',
            json_encode($payloadSourceMapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $summary = [
            'verdict' => $this->summaryVerdict(
                $sourceMapping['source_mapping_failure_count'],
                $metadataLeakCount,
                $legacyResidualCount,
                $fc144BoundaryViolationCount
            ),
            'candidate_source_directory' => $candidateDir,
            'candidate_payload_output_directory' => $payloadDir,
            'payload_count_by_matrix' => $payloadCounts,
            'total_payload_count' => count($payloadFiles),
            'candidate_manifest_hash_expected' => self::EXPECTED_MANIFEST_SHA256,
            'candidate_manifest_hash_actual' => $manifestHashActual,
            'runtime_registry_manifest_hash_expected' => self::EXPECTED_RUNTIME_REGISTRY_MANIFEST_SHA256,
            'runtime_registry_manifest_hash_recorded' => (string) ($hashes['runtime_registry_manifest_sha256'] ?? ''),
            'source_mapping_result' => $sourceMapping,
            'legacy_residual_result' => [
                'legacy_deep_core_residual_count' => $legacyResidualCount,
            ],
            'metadata_sanitizer_result' => [
                'metadata_leak_count' => $metadataLeakCount,
            ],
            'fc144_boundary_result' => [
                'violation_count' => $fc144BoundaryViolationCount,
            ],
            'production_import_happened' => false,
            'full_replacement_happened' => false,
        ];

        $this->writeMarkdownReport($outputDir.DIRECTORY_SEPARATOR.'Phase8B1_CandidatePayloadCoverage.md', [
            '# Phase8B1 Candidate Payload Coverage',
            '- payload_dir: `'.$payloadDir.'`',
            '- total_payload_count: '.count($payloadFiles),
            '- baseline: '.$payloadCounts['baseline'],
            '- low_resonance: '.$payloadCounts['low_resonance'],
            '- partial_resonance: '.$payloadCounts['partial_resonance'],
            '- diffuse_convergence: '.$payloadCounts['diffuse_convergence'],
            '- close_call_pair: '.$payloadCounts['close_call_pair'],
            '- scene_localization: '.$payloadCounts['scene_localization'],
            '- fc144_recommendation: '.$payloadCounts['fc144_recommendation'],
        ]);

        $this->writeMarkdownReport($outputDir.DIRECTORY_SEPARATOR.'Phase8B1_SourceMapping.md', [
            '# Phase8B1 Source Mapping',
            '- source_mapping_failure_count: '.$sourceMapping['source_mapping_failure_count'],
            '- missing_count: '.$sourceMapping['missing_count'],
            '- fallback_count: '.$sourceMapping['fallback_count'],
            '- blocked_count: '.$sourceMapping['blocked_count'],
            '- duplicate_selection_count: '.$sourceMapping['duplicate_selection_count'],
            '- branch_provenance_mismatch_count: '.$sourceMapping['branch_provenance_mismatch_count'],
        ]);

        $this->writeMarkdownReport($outputDir.DIRECTORY_SEPARATOR.'Phase8B1_MetadataLeakageQA.md', [
            '# Phase8B1 Metadata Leakage QA',
            '- metadata_leak_count: '.$metadataLeakCount,
        ]);

        $this->writeMarkdownReport($outputDir.DIRECTORY_SEPARATOR.'Phase8B1_FC144BoundaryQA.md', [
            '# Phase8B1 FC144 Boundary QA',
            '- fc144_boundary_violation_count: '.$fc144BoundaryViolationCount,
        ]);

        $this->writeMarkdownReport($outputDir.DIRECTORY_SEPARATOR.'Phase8B1_GoNoGo.md', [
            '# Phase8B1 Go / No-Go',
            '- verdict: '.$summary['verdict'],
            '- no production import happened',
            '- no full replacement happened',
            '- candidate payload source generated under `'.$payloadDir.'`',
        ]);

        file_put_contents(
            $outputDir.DIRECTORY_SEPARATOR.'phase8b1_summary.json',
            json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $summary;
    }

    /**
     * @return array<string,string>
     */
    private function resolveAssetPaths(): array
    {
        return [
            '1R-A' => $this->resolveExternalAssetPath(
                '/Users/rainie/Desktop/FermatMind_Enneagram_Content_Expansion_Batch_1R_B_v3/FermatMind_Enneagram_Content_Expansion_Batch_1R_A_Assets_v6_final.json',
                '/Users/rainie/Desktop/九型/FermatMind_Enneagram_Content_Expansion_Batch_1R_B_v3/FermatMind_Enneagram_Content_Expansion_Batch_1R_A_Assets_v6_final.json',
            ),
            '1R-B' => $this->resolveExternalAssetPath(
                '/Users/rainie/Desktop/FermatMind_Enneagram_Content_Expansion_Batch_1R_B_v3/FermatMind_Enneagram_Content_Expansion_Batch_1R_B_Legacy_Core_Rewrite_v3_Assets.json',
                '/Users/rainie/Desktop/九型/FermatMind_Enneagram_Content_Expansion_Batch_1R_B_v3/FermatMind_Enneagram_Content_Expansion_Batch_1R_B_Legacy_Core_Rewrite_v3_Assets.json',
            ),
            '1R-C' => $this->resolveExternalAssetPath(
                '/Users/rainie/Desktop/FermatMind_Enneagram_Content_Expansion_Batch_1R_C_Low_Resonance_Objection_Handling/FermatMind_Enneagram_Content_Expansion_Batch_1R_C_Low_Resonance_Objection_Handling_Assets.json',
                '/Users/rainie/Desktop/九型/FermatMind_Enneagram_Content_Expansion_Batch_1R_C_Low_Resonance_Objection_Handling/FermatMind_Enneagram_Content_Expansion_Batch_1R_C_Low_Resonance_Objection_Handling_Assets.json',
            ),
            '1R-D' => $this->resolveExternalAssetPath(
                '/Users/rainie/Desktop/FermatMind_Enneagram_Content_Expansion_Batch_1R_D_Partial_Resonance_Deep_Branch/FermatMind_Enneagram_Content_Expansion_Batch_1R_D_Partial_Resonance_Deep_Branch_Assets.json',
                '/Users/rainie/Desktop/九型/FermatMind_Enneagram_Content_Expansion_Batch_1R_D_Partial_Resonance_Deep_Branch/FermatMind_Enneagram_Content_Expansion_Batch_1R_D_Partial_Resonance_Deep_Branch_Assets.json',
            ),
            '1R-E' => $this->resolveExternalAssetPath(
                '/Users/rainie/Desktop/FermatMind_Enneagram_Content_Expansion_Batch_1R_E_Diffuse_Top3_Convergence/FermatMind_Enneagram_Content_Expansion_Batch_1R_E_Diffuse_Top3_Convergence_Assets.json',
                '/Users/rainie/Desktop/九型/FermatMind_Enneagram_Content_Expansion_Batch_1R_E_Diffuse_Top3_Convergence/FermatMind_Enneagram_Content_Expansion_Batch_1R_E_Diffuse_Top3_Convergence_Assets.json',
            ),
            '1R-F' => $this->resolveExternalAssetPath(
                '/Users/rainie/Desktop/FermatMind_Enneagram_Content_Expansion_Batch_1R_F_Close_Call_36_Pair_Completion/FermatMind_Enneagram_Content_Expansion_Batch_1R_F_Close_Call_36_Pair_Completion_Assets.json',
                '/Users/rainie/Desktop/九型/FermatMind_Enneagram_Content_Expansion_Batch_1R_F_Close_Call_36_Pair_Completion/FermatMind_Enneagram_Content_Expansion_Batch_1R_F_Close_Call_36_Pair_Completion_Assets.json',
            ),
            '1R-G' => $this->resolveExternalAssetPath(
                '/Users/rainie/Desktop/FermatMind_Enneagram_Content_Expansion_Batch_1R_G_Scene_Localization/FermatMind_Enneagram_Content_Expansion_Batch_1R_G_Scene_Localization_Assets.json',
                '/Users/rainie/Desktop/九型/FermatMind_Enneagram_Content_Expansion_Batch_1R_G_Scene_Localization/FermatMind_Enneagram_Content_Expansion_Batch_1R_G_Scene_Localization_Assets.json',
            ),
            '1R-H' => $this->resolveExternalAssetPath(
                '/Users/rainie/Desktop/FermatMind_Enneagram_Content_Expansion_Batch_1R_H_FC144_Recommendation_Pack/FermatMind_Enneagram_Content_Expansion_Batch_1R_H_FC144_Recommendation_Pack_Assets.json',
                '/Users/rainie/Desktop/九型/FermatMind_Enneagram_Content_Expansion_Batch_1R_H_FC144_Recommendation_Pack/FermatMind_Enneagram_Content_Expansion_Batch_1R_H_FC144_Recommendation_Pack_Assets.json',
                '/mnt/data/FermatMind_Enneagram_Content_Expansion_Batch_1R_H_FC144_Recommendation_Pack/FermatMind_Enneagram_Content_Expansion_Batch_1R_H_FC144_Recommendation_Pack_Assets.json',
            ),
        ];
    }

    private function resolveExternalAssetPath(string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('External ENNEAGRAM asset file is missing: '.($candidates[0] ?? 'unknown'));
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid JSON file: '.$path);
        }

        return $decoded;
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException('Unable to create directory: '.$path);
        }
    }

    private function resetDirectory(string $path): void
    {
        if (is_dir($path)) {
            foreach ((array) glob($path.DIRECTORY_SEPARATOR.'*.json') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            return;
        }

        $this->ensureDirectory($path);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function fileNameForPayload(string $matrix, array $payload, int $index): string
    {
        $context = (array) ($payload['preview_context'] ?? []);

        return match ($matrix) {
            'baseline' => sprintf(
                'baseline_t%s_%s.json',
                $this->slug((string) ($context['type_id'] ?? 'unknown')),
                $this->slug((string) ($context['interpretation_scope'] ?? 'unknown'))
            ),
            'low_resonance' => sprintf(
                'low_resonance_t%s_%s.json',
                $this->slug((string) ($context['type_id'] ?? 'unknown')),
                $this->slug((string) ($context['objection_axis'] ?? 'unknown'))
            ),
            'partial_resonance' => sprintf(
                'partial_resonance_t%s_%s.json',
                $this->slug((string) ($context['type_id'] ?? 'unknown')),
                $this->slug((string) ($context['partial_axis'] ?? 'unknown'))
            ),
            'diffuse_convergence' => sprintf(
                'diffuse_convergence_t%s_%s.json',
                $this->slug((string) ($context['type_id'] ?? 'unknown')),
                $this->slug((string) ($context['diffuse_axis'] ?? 'unknown'))
            ),
            'close_call_pair' => sprintf(
                'close_call_pair_%s.json',
                $this->slug((string) ($context['pair_key'] ?? 'unknown'))
            ),
            'scene_localization' => sprintf(
                'scene_localization_t%s_%s.json',
                $this->slug((string) ($context['type_id'] ?? 'unknown')),
                $this->slug((string) ($context['scene_axis'] ?? 'unknown'))
            ),
            'fc144_recommendation' => sprintf(
                'fc144_recommendation_t%s_%s.json',
                $this->slug((string) ($context['type_id'] ?? 'unknown')),
                $this->slug((string) ($context['fc144_recommendation_context'] ?? 'unknown'))
            ),
            default => sprintf('%s_%04d.json', $this->slug($matrix), $index),
        };
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/i', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        return $slug !== '' ? $slug : 'unknown';
    }

    /**
     * @param  array<string,list<array<string,mixed>>>  $payloadSets
     * @return array<string,mixed>
     */
    private function buildSourceMapping(array $payloadSets): array
    {
        $matrixTargets = [
            'low_resonance' => ['category' => 'low_resonance_response', 'marker' => '1R_C'],
            'partial_resonance' => ['category' => 'partial_resonance_response', 'marker' => '1R_D'],
            'diffuse_convergence' => ['category' => 'diffuse_convergence_response', 'marker' => '1R_E'],
            'close_call_pair' => ['category' => 'close_call_pair', 'marker' => '1R_F'],
            'scene_localization' => ['category' => 'scene_localization_response', 'marker' => '1R_G'],
            'fc144_recommendation' => ['category' => 'fc144_recommendation_response', 'marker' => '1R_H'],
        ];

        $branchPayloadCounts = [
            'low_resonance_response' => 0,
            'partial_resonance_response' => 0,
            'diffuse_convergence_response' => 0,
            'close_call_pair' => 0,
            'scene_localization_response' => 0,
            'fc144_recommendation_response' => 0,
        ];
        $branchProvenanceMismatchCount = 0;
        $missingCount = 0;
        $fallbackCount = 0;
        $blockedCount = 0;
        $duplicateSelectionCount = 0;

        foreach ($payloadSets as $matrix => $payloads) {
            foreach ($payloads as $payload) {
                $modules = array_values(array_filter(
                    (array) ($payload['modules'] ?? []),
                    static fn ($module): bool => is_array($module)
                ));

                if ((array) ($payload['blocked_reasons'] ?? []) !== []) {
                    $blockedCount++;
                }

                $target = $matrixTargets[$matrix] ?? null;
                if ($target !== null) {
                    $category = $target['category'];
                    $expectedMarker = $target['marker'];
                    $categoryModules = array_values(array_filter(
                        $modules,
                        static fn (array $module): bool => (string) data_get($module, 'content.category') === $category
                    ));

                    if ($categoryModules === []) {
                        $missingCount++;
                    } else {
                        $branchPayloadCounts[$category]++;

                        if (count($categoryModules) !== 1) {
                            $duplicateSelectionCount++;
                        }

                        $assetKey = (string) data_get($categoryModules[0], 'content.asset_key', '');
                        $version = (string) data_get($categoryModules[0], 'content.version', '');
                        $haystack = strtoupper($assetKey.' '.$version);
                        if (! str_contains($haystack, strtoupper($expectedMarker))) {
                            $branchProvenanceMismatchCount++;
                        }
                    }
                }

                foreach ($modules as $module) {
                    $content = (array) ($module['content'] ?? []);
                    if (((string) ($content['body_zh'] ?? '')) === '') {
                        $missingCount++;
                    }
                    if ((bool) ($content['fallback'] ?? false) === true) {
                        $fallbackCount++;
                    }
                }
            }
        }

        return [
            'source_mapping_failure_count' => $branchProvenanceMismatchCount + $missingCount + $fallbackCount + $blockedCount + $duplicateSelectionCount,
            'missing_count' => $missingCount,
            'fallback_count' => $fallbackCount,
            'blocked_count' => $blockedCount,
            'duplicate_selection_count' => $duplicateSelectionCount,
            'branch_provenance_mismatch_count' => $branchProvenanceMismatchCount,
            'branch_payload_counts' => $branchPayloadCounts,
        ];
    }

    /**
     * @param  array<string,list<array<string,mixed>>>  $payloadSets
     */
    private function metadataLeakCount(array $payloadSets): int
    {
        $count = 0;
        foreach ($payloadSets as $payloads) {
            foreach ($payloads as $payload) {
                $count += count($this->sanitizer->internalMetadataLeaks($payload));
            }
        }

        return $count;
    }

    /**
     * @param  array<string,list<array<string,mixed>>>  $payloadSets
     */
    private function legacyResidualCount(array $payloadSets): int
    {
        $count = 0;
        foreach ($payloadSets as $payloads) {
            foreach ($payloads as $payload) {
                foreach ((array) ($payload['modules'] ?? []) as $module) {
                    if (! is_array($module)) {
                        continue;
                    }

                    $content = (array) ($module['content'] ?? []);
                    $category = (string) ($content['category'] ?? '');
                    if (! in_array($category, self::DEEP_CORE_CATEGORIES, true)) {
                        continue;
                    }

                    $assetKey = strtoupper((string) ($content['asset_key'] ?? ''));
                    $version = strtoupper((string) ($content['version'] ?? ''));
                    if (! str_contains($assetKey.' '.$version, '1R_B')) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * @param  array<string,list<array<string,mixed>>>  $payloadSets
     */
    private function fc144BoundaryViolationCount(array $payloadSets): int
    {
        $count = 0;
        foreach ($payloadSets as $payloads) {
            foreach ($payloads as $payload) {
                foreach ((array) ($payload['modules'] ?? []) as $module) {
                    if (! is_array($module) || (string) data_get($module, 'content.category') !== 'fc144_recommendation_response') {
                        continue;
                    }

                    $content = (array) ($module['content'] ?? []);
                    $haystack = implode("\n", array_filter([
                        (string) ($content['body_zh'] ?? ''),
                        (string) ($content['short_body_zh'] ?? ''),
                        (string) ($content['cta_zh'] ?? ''),
                    ]));

                    foreach (self::FC144_BOUNDARY_UNSAFE_PHRASES as $phrase) {
                        if (str_contains($haystack, $phrase)) {
                            $count++;
                        }
                    }
                }
            }
        }

        return $count;
    }

    private function summaryVerdict(
        int $sourceMappingFailureCount,
        int $metadataLeakCount,
        int $legacyResidualCount,
        int $fc144BoundaryViolationCount,
    ): string {
        if ($sourceMappingFailureCount > 0) {
            return 'BLOCKED_BY_SOURCE_MAPPING_FAILURE';
        }
        if ($metadataLeakCount > 0) {
            return 'BLOCKED_BY_METADATA_LEAK';
        }
        if ($legacyResidualCount > 0) {
            return 'BLOCKED_BY_LEGACY_RESIDUAL';
        }
        if ($fc144BoundaryViolationCount > 0) {
            return 'BLOCKED_BY_FC144_BOUNDARY';
        }

        return 'PASS_WITH_PAYLOAD_SOURCE_GENERATED_BUT_FRONTEND_RETRY_NOT_RUN';
    }

    /**
     * @param  list<string>  $lines
     */
    private function writeMarkdownReport(string $path, array $lines): void
    {
        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);
    }
}
