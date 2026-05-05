<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Validator;
use Tests\TestCase;

final class BigFiveResultPageV2CoreBodyPreviewTest extends TestCase
{
    private const FIXTURE_FILE = 'canonical_o59_core_body_preview.payload.json';

    private const CORE_BODY_FILE = 'content_assets/big5/result_page_v2/core_body/v0_1/canonical_o59_c32_e20_a55_n68.core_body.json';

    private const MODULE_MAPPING_FILE = 'content_assets/big5/result_page_v2/governance/big5_v2_module_to_section_mapping_v0_1.json';

    private const ANTI_TARGET_FILE = 'content_assets/big5/result_page_v2/governance/big5_v2_anti_target_render_terms_v0_1.json';

    private const REQUIRED_SECTIONS = [
        'hero_summary',
        'domains_overview',
        'domain_deep_dive',
        'facet_details',
        'core_portrait',
        'norms_comparison',
        'action_plan',
        'methodology_and_access',
    ];

    private const VISIBLE_FIELDS = [
        'title_zh',
        'subtitle_zh',
        'summary_zh',
        'body_zh',
        'bullets_zh',
        'table_zh',
        'action_zh',
        'cta_zh',
    ];

    public function test_preview_payload_and_o59_core_body_json_parse(): void
    {
        $this->assertIsArray($this->previewEnvelope());
        $this->assertIsArray($this->coreBody());
    }

    public function test_preview_payload_uses_big5_result_page_v2_contract(): void
    {
        $payload = $this->previewPayload();

        $this->assertSame(BigFiveResultPageV2Contract::SCHEMA_VERSION, $payload['schema_version'] ?? null);
        $this->assertSame(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload['payload_key'] ?? null);
        $this->assertSame(BigFiveResultPageV2Contract::SCALE_CODE, $payload['scale_code'] ?? null);
        $this->assertSame([], app(BigFiveResultPageV2Validator::class)->validateEnvelope($this->previewEnvelope()));
    }

    public function test_preview_payload_references_canonical_scores_and_profile_label(): void
    {
        $payload = $this->previewPayload();

        $this->assertSame(59, data_get($payload, 'projection_v2.domains.O.score'));
        $this->assertSame(32, data_get($payload, 'projection_v2.domains.C.score'));
        $this->assertSame(20, data_get($payload, 'projection_v2.domains.E.score'));
        $this->assertSame(55, data_get($payload, 'projection_v2.domains.A.score'));
        $this->assertSame(68, data_get($payload, 'projection_v2.domains.N.score'));
        $this->assertSame('敏锐的独立思考者', $payload['profile_label_zh'] ?? null);
        $this->assertSame('敏锐的独立思考者', data_get($payload, 'projection_v2.profile_signature.label_zh'));
    }

    public function test_all_8_source_sections_are_represented_with_mapping_trace(): void
    {
        $payload = $this->previewPayload();
        $sections = $this->sourceSectionsRepresentedByBlocks($payload);

        $this->assertSame(self::REQUIRED_SECTIONS, data_get($payload, 'b5_a_lite_section_trace.runtime_sections'));
        $this->assertSame($this->moduleMapping()['runtime_sections'], data_get($payload, 'b5_a_lite_section_trace.runtime_sections'));
        $this->assertSame(self::REQUIRED_SECTIONS, array_values(array_intersect(self::REQUIRED_SECTIONS, $sections)));
    }

    public function test_preview_payload_blocks_are_module_based_and_renderable(): void
    {
        $payload = $this->previewPayload();

        $this->assertSame(BigFiveResultPageV2Contract::MODULE_KEYS, array_map(
            static fn (array $module): string => (string) $module['module_key'],
            $payload['modules']
        ));

        foreach ($payload['modules'] as $module) {
            $moduleKey = (string) $module['module_key'];
            $this->assertNotEmpty($module['blocks'], $moduleKey);

            foreach ($module['blocks'] as $block) {
                $this->assertStringStartsWith($moduleKey.'.', (string) $block['block_key']);
                $this->assertSame($moduleKey, $block['module_key'] ?? null);
                $this->assertContains($block['block_kind'] ?? null, BigFiveResultPageV2Contract::BLOCK_KINDS);
                $this->assertIsArray($block['content'] ?? null);
                $this->assertNotEmpty($this->visibleText((array) $block['content']));
                $this->assertContains($block['source_section_key'] ?? null, self::REQUIRED_SECTIONS);
                $this->assertIsArray($block['source_modules'] ?? null);
            }
        }
    }

    public function test_visible_fields_do_not_contain_anti_target_terms_or_internal_notes(): void
    {
        $visibleText = $this->visibleText($this->previewPayload());

        foreach ($this->antiTargetTerms() as $term) {
            if ($term === 'all') {
                $this->assertDoesNotMatchRegularExpression('/(^|[\\s:：])all($|[\\s,，.。:：])/iu', $visibleText);

                continue;
            }

            $this->assertStringNotContainsString($term, $visibleText, $term);
        }

        foreach (['internal_metadata', 'selection_guidance', 'selector_basis', 'editor_notes', 'qa_notes'] as $internalLeak) {
            $this->assertStringNotContainsString($internalLeak, $visibleText, $internalLeak);
        }
    }

    public function test_preview_includes_chinese_hero_body_from_o59_core_body(): void
    {
        $heroBody = data_get($this->coreBody(), 'sections.0.blocks.0.body_zh');
        $this->assertIsString($heroBody);

        $this->assertStringContainsString($heroBody, $this->visibleText($this->previewPayload()));
    }

    public function test_facet_content_is_explanatory_not_pure_percentile_list(): void
    {
        $facetText = $this->visibleTextForSection('facet_details');

        $this->assertStringContainsString('解释性推断', $facetText);
        $this->assertStringContainsString('并非独立测量结论', $facetText);
        $this->assertStringNotContainsString('N1 百分位', $facetText);
        $this->assertDoesNotMatchRegularExpression('/^\\s*(N\\d|O\\d|C\\d|E\\d|A\\d)\\s*[:：]?\\s*\\d+\\s*$/um', $facetText);
    }

    public function test_action_content_covers_required_application_contexts(): void
    {
        $actionText = $this->visibleTextForSection('action_plan');

        foreach (['workplace', 'relationship', 'stress'] as $requiredScenario) {
            $this->assertStringContainsString($requiredScenario, $actionText);
        }
        $this->assertMatchesRegularExpression('/growth\\/action|成长行动/u', $actionText);
    }

    public function test_methodology_content_includes_privacy_and_data_use(): void
    {
        $methodologyText = $this->visibleTextForSection('methodology_and_access');

        foreach (['隐私', '数据使用', '删除个人测试结果', '4-8 周'] as $requiredPhrase) {
            $this->assertStringContainsString($requiredPhrase, $methodologyText);
        }
    }

    public function test_preview_payload_is_staging_only_and_not_production_allowed(): void
    {
        $payload = $this->previewPayload();

        $this->assertSame('staging_only', $payload['runtime_use'] ?? null);
        $this->assertFalse((bool) ($payload['production_use_allowed'] ?? true));
    }

    public function test_runtime_paths_have_no_uncommitted_diff(): void
    {
        $runtimePaths = [
            'backend/app',
            'backend/routes',
            'backend/database',
            'backend/content_packs',
            'frontend',
            'selector_ready_assets',
        ];

        $changed = $this->gitChangedFilesInBranchDiff($runtimePaths);

        $this->assertSame([], $changed);
    }

    public function test_runtime_freeze_classifier_ignores_career_only_artisan_command_changes(): void
    {
        $changed = [
            'backend/app/Console/Commands/CareerAlignD8AuthorityCrosswalks.php',
            'backend/app/Console/Kernel.php',
        ];
        $kernelChangedLines = [
            '+use App\\Console\\Commands\\CareerAlignD8AuthorityCrosswalks;',
            '+        CareerAlignD8AuthorityCrosswalks::class,',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', '', $kernelChangedLines));
    }

    public function test_runtime_freeze_classifier_ignores_career_display_import_service_changes(): void
    {
        $changed = [
            'backend/app/Services/Career/Import/CareerSelectedDisplayAssetMapper.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_display_surface_builder_changes(): void
    {
        $changed = [
            'backend/app/Services/Career/Bundles/CareerJobDisplaySurfaceBuilder.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_career_display_asset_backed_bundle_changes(): void
    {
        $changed = [
            'backend/app/Services/Career/Bundles/CareerJobDetailBundleBuilder.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_ignores_bigfive_v2_content_asset_loader_changes(): void
    {
        $changed = [
            'backend/app/Services/BigFive/ResultPageV2/ContentAssets/BigFiveV2AssetPackageLoader.php',
            'backend/app/Services/BigFive/ResultPageV2/RouteMatrix/BigFiveV2RouteMatrixParser.php',
        ];

        $this->assertSame([], $this->mbtiImpactingRuntimeChanges($changed, '', ''));
    }

    public function test_runtime_freeze_classifier_keeps_mbti_and_bigfive_runtime_changes_blocked(): void
    {
        $changed = [
            'backend/app/Services/BigFive/ResultPageV2/BigFiveResultPageV2Transformer.php',
            'backend/app/Console/Kernel.php',
        ];
        $kernelChangedLines = [
            '+use App\\Console\\Commands\\MbtiPrewarmCommand;',
            '+        MbtiPrewarmCommand::class,',
        ];

        $this->assertSame($changed, $this->mbtiImpactingRuntimeChanges($changed, '', '', $kernelChangedLines));
    }

    /**
     * @return array<string,mixed>
     */
    private function previewEnvelope(): array
    {
        return $this->decodeJsonFile('tests/Fixtures/big5_result_page_v2/'.self::FIXTURE_FILE);
    }

    /**
     * @return array<string,mixed>
     */
    private function previewPayload(): array
    {
        $payload = $this->previewEnvelope()[BigFiveResultPageV2Contract::PAYLOAD_KEY] ?? null;
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function coreBody(): array
    {
        return $this->decodeJsonFile(self::CORE_BODY_FILE);
    }

    /**
     * @return array<string,mixed>
     */
    private function moduleMapping(): array
    {
        return $this->decodeJsonFile(self::MODULE_MAPPING_FILE);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function sourceSectionsRepresentedByBlocks(array $payload): array
    {
        $sections = [];
        foreach ($payload['modules'] as $module) {
            foreach ($module['blocks'] as $block) {
                $sectionKey = (string) ($block['source_section_key'] ?? '');
                if ($sectionKey !== '' && ! in_array($sectionKey, $sections, true)) {
                    $sections[] = $sectionKey;
                }
            }
        }

        return $sections;
    }

    private function visibleTextForSection(string $sectionKey): string
    {
        $parts = [];
        foreach ($this->previewPayload()['modules'] as $module) {
            foreach ($module['blocks'] as $block) {
                if (($block['source_section_key'] ?? null) === $sectionKey) {
                    $parts[] = $this->visibleText((array) ($block['content'] ?? []));
                }
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @return list<string>
     */
    private function antiTargetTerms(): array
    {
        $decoded = $this->decodeJsonFile(self::ANTI_TARGET_FILE);

        return array_values(array_map(
            static fn (array $term): string => (string) $term['term'],
            (array) ($decoded['terms'] ?? []),
        ));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function visibleText(array $payload): string
    {
        $parts = [];
        $this->collectVisibleText($payload, $parts);

        return implode("\n", $parts);
    }

    /**
     * @param  array<int|string,mixed>  $payload
     * @param  list<string>  $parts
     */
    private function collectVisibleText(array $payload, array &$parts): void
    {
        foreach ($payload as $key => $value) {
            if (in_array((string) $key, self::VISIBLE_FIELDS, true)) {
                $parts[] = is_array($value)
                    ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                    : (string) $value;

                continue;
            }

            if (is_array($value)) {
                $this->collectVisibleText($value, $parts);
            }
        }
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function gitChangedFilesInBranchDiff(array $paths): array
    {
        $repoRoot = dirname(base_path());
        $baseRef = $this->mergeBaseWithMain($repoRoot);
        $this->assertNotSame('', $baseRef);

        $command = array_merge(['git', '-C', $repoRoot, 'diff', '--name-only', "{$baseRef}...HEAD", '--'], $paths);
        exec(implode(' ', array_map('escapeshellarg', $command)), $output, $exitCode);
        $this->assertSame(0, $exitCode);

        return $this->mbtiImpactingRuntimeChanges(array_values(array_unique(array_filter($output))), $repoRoot, $baseRef);
    }

    /**
     * @param  list<string>  $changed
     * @param  list<string>|null  $kernelChangedLines
     * @return list<string>
     */
    private function mbtiImpactingRuntimeChanges(
        array $changed,
        string $repoRoot,
        string $baseRef,
        ?array $kernelChangedLines = null,
    ): array {
        $impacting = [];

        foreach ($changed as $file) {
            if ($this->isCareerConsoleCommandFile($file)) {
                continue;
            }

            if ($this->isCareerDisplaySurfaceFile($file)) {
                continue;
            }

            if ($this->isBigFiveV2PilotSupportFile($file)) {
                continue;
            }

            if (
                $file === 'backend/app/Console/Kernel.php'
                && $this->kernelDiffIsCareerOnly($kernelChangedLines ?? $this->kernelChangedLines($repoRoot, $baseRef))
            ) {
                continue;
            }

            $impacting[] = $file;
        }

        return array_values(array_unique($impacting));
    }

    private function isCareerConsoleCommandFile(string $file): bool
    {
        return preg_match('#^backend/app/Console/Commands/Career[A-Za-z0-9_]*\.php$#', $file) === 1;
    }

    private function isCareerDisplaySurfaceFile(string $file): bool
    {
        return in_array($file, [
            'backend/app/Services/Career/Import/CareerSelectedDisplayAssetMapper.php',
            'backend/app/Services/Career/Bundles/CareerJobDetailBundleBuilder.php',
            'backend/app/Services/Career/Bundles/CareerJobDisplaySurfaceBuilder.php',
        ], true);
    }

    private function isBigFiveV2PilotSupportFile(string $file): bool
    {
        return preg_match('#^backend/app/Services/BigFive/ResultPageV2/(ContentAssets|RouteMatrix)/[A-Za-z0-9_]+\.php$#', $file) === 1;
    }

    /**
     * @param  list<string>  $changedLines
     */
    private function kernelDiffIsCareerOnly(array $changedLines): bool
    {
        if ($changedLines === []) {
            return false;
        }

        foreach ($changedLines as $line) {
            if (preg_match('/\b(MBTI|Mbti|BigFive|Big5|Prewarm|ResultPage|Report)\b/u', $line) === 1) {
                return false;
            }

            if (preg_match('/\bCareer[A-Za-z0-9_\\\\]*\b|career:/u', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function kernelChangedLines(string $repoRoot, string $baseRef): array
    {
        if ($repoRoot === '' || $baseRef === '') {
            return [];
        }

        $command = [
            'git',
            '-C',
            $repoRoot,
            'diff',
            '--unified=0',
            "{$baseRef}...HEAD",
            '--',
            'backend/app/Console/Kernel.php',
        ];
        exec(implode(' ', array_map('escapeshellarg', $command)), $output, $exitCode);
        $this->assertSame(0, $exitCode);

        return array_values(array_filter(array_map(
            static function (string $line): ?string {
                if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
                    return null;
                }

                if (! str_starts_with($line, '+') && ! str_starts_with($line, '-')) {
                    return null;
                }

                return substr($line, 1);
            },
            $output,
        )));
    }

    private function mergeBaseWithMain(string $repoRoot): string
    {
        $gitPrefix = 'git -C '.escapeshellarg($repoRoot).' ';
        $baseRef = trim((string) shell_exec($gitPrefix.'merge-base origin/main HEAD 2>/dev/null'));
        if ($baseRef !== '') {
            return $baseRef;
        }

        exec($gitPrefix.'fetch --no-tags --depth=1 origin main:refs/remotes/origin/main 2>/dev/null', output: $output, result_code: $exitCode);
        if ($exitCode === 0) {
            $baseRef = trim((string) shell_exec($gitPrefix.'merge-base origin/main HEAD 2>/dev/null'));
            if ($baseRef !== '') {
                return $baseRef;
            }
        }

        $isShallow = trim((string) shell_exec($gitPrefix.'rev-parse --is-shallow-repository 2>/dev/null')) === 'true';
        if ($isShallow) {
            exec($gitPrefix.'fetch --no-tags --unshallow origin 2>/dev/null', output: $unshallowOutput, result_code: $unshallowExitCode);
            if ($unshallowExitCode === 0) {
                exec($gitPrefix.'fetch --no-tags origin main:refs/remotes/origin/main 2>/dev/null');
            }
        }

        return trim((string) shell_exec($gitPrefix.'merge-base origin/main HEAD 2>/dev/null'));
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonFile(string $relativePath): array
    {
        $json = file_get_contents(base_path($relativePath));
        $this->assertIsString($json, "Missing JSON file: {$relativePath}");

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded, "Invalid JSON object: {$relativePath}");

        return $decoded;
    }
}
