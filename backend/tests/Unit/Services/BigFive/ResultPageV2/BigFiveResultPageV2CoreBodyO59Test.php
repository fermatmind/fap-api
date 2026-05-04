<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use Tests\TestCase;

final class BigFiveResultPageV2CoreBodyO59Test extends TestCase
{
    private const RELATIVE_DIR = 'content_assets/big5/result_page_v2/core_body/v0_1';

    private const CORE_BODY_FILE = 'canonical_o59_c32_e20_a55_n68.core_body.json';

    private const MANIFEST_FILE = 'canonical_o59_c32_e20_a55_n68.manifest.json';

    private const SOURCE_TRACE_FILE = 'canonical_o59_c32_e20_a55_n68.source_trace.json';

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
        'body_zh',
        'bullets_zh',
        'table_zh',
        'action_zh',
        'cta_zh',
    ];

    public function test_core_body_manifest_and_source_trace_json_parse(): void
    {
        $this->assertIsArray($this->coreBody());
        $this->assertIsArray($this->manifest());
        $this->assertIsArray($this->sourceTrace());
    }

    public function test_core_body_has_required_identity_scores_and_staging_flags(): void
    {
        $coreBody = $this->coreBody();

        $this->assertSame('staging_only', $coreBody['runtime_use'] ?? null);
        $this->assertFalse((bool) ($coreBody['production_use_allowed'] ?? true));
        $this->assertSame([
            'O' => 59,
            'C' => 32,
            'E' => 20,
            'A' => 55,
            'N' => 68,
        ], $coreBody['sample_scores'] ?? null);
        $this->assertSame('敏锐的独立思考者', $coreBody['profile_label'] ?? null);
        $this->assertSame('高敏感 × 中高开放 × 克制进入', $coreBody['profile_axis'] ?? null);
    }

    public function test_all_current_8_skeleton_sections_exist_with_blocks_and_quality(): void
    {
        $sections = $this->sectionsByKey();

        $this->assertSame(self::REQUIRED_SECTIONS, array_keys($sections));

        foreach ($sections as $sectionKey => $section) {
            $this->assertSame($sectionKey, $section['section_key'] ?? null);
            $this->assertNotEmpty($section['blocks'] ?? [], $sectionKey);
            $this->assertNotEmpty($section['body_quality'] ?? [], $sectionKey);
            $this->assertSame('staging_only', $section['runtime_use'] ?? null, $sectionKey);
            $this->assertFalse((bool) ($section['production_use_allowed'] ?? true), $sectionKey);
        }
    }

    public function test_source_modules_are_valid_module_00_to_10(): void
    {
        foreach ($this->sectionsByKey() as $section) {
            foreach ((array) ($section['source_modules'] ?? []) as $moduleKey) {
                $this->assertContains($moduleKey, BigFiveResultPageV2Contract::MODULE_KEYS);
            }
        }
    }

    public function test_visible_fields_do_not_contain_anti_target_terms(): void
    {
        $visibleText = $this->visibleText($this->coreBody());
        $terms = $this->antiTargetTerms();

        foreach ($terms as $term) {
            if ($term === 'all') {
                $this->assertDoesNotMatchRegularExpression('/(^|[\\s:：])all($|[\\s,，.。:：])/iu', $visibleText);
                continue;
            }

            $this->assertStringNotContainsString($term, $visibleText, $term);
        }
    }

    public function test_boundary_content_exists(): void
    {
        $visibleText = $this->visibleText($this->coreBody());

        foreach ([
            '不是固定类型',
            '不是医学或心理诊断',
            '不用于医学诊断',
            '不用于医学诊断、心理治疗、招聘筛选',
            '招聘筛选',
            '解释性推断',
        ] as $requiredPhrase) {
            $this->assertStringContainsString($requiredPhrase, $visibleText, $requiredPhrase);
        }
    }

    public function test_facet_action_method_sections_cover_required_body_points(): void
    {
        $sections = $this->sectionsByKey();
        $facetText = $this->visibleText($sections['facet_details']);
        $actionText = $this->visibleText($sections['action_plan']);
        $methodText = $this->visibleText($sections['methodology_and_access']);

        $this->assertStringContainsString('解释性推断', $facetText);
        $this->assertStringContainsString('并非独立测量结论', $facetText);

        foreach (['workplace', 'relationship', 'stress'] as $requiredScenario) {
            $this->assertStringContainsString($requiredScenario, $actionText);
        }
        $this->assertMatchesRegularExpression('/growth\\/action|成长行动/u', $actionText);

        foreach (['隐私', '数据使用', '4-8 周'] as $requiredPhrase) {
            $this->assertStringContainsString($requiredPhrase, $methodText);
        }
    }

    public function test_no_local_absolute_source_path_is_committed(): void
    {
        foreach ([
            self::CORE_BODY_FILE,
            self::MANIFEST_FILE,
            self::SOURCE_TRACE_FILE,
            'canonical_o59_c32_e20_a55_n68.review_notes.md',
        ] as $filename) {
            $contents = file_get_contents($this->path($filename));
            $this->assertIsString($contents, $filename);
            $this->assertStringNotContainsString('/Users/rainie/', $contents, $filename);
        }
    }

    public function test_source_trace_classifies_authoring_docs(): void
    {
        $documents = [];
        foreach ((array) ($this->sourceTrace()['source_documents'] ?? []) as $document) {
            $documents[(string) ($document['document_name'] ?? '')] = $document['classification'] ?? null;
        }

        $this->assertSame('module_master', $documents['FermatMind_BigFive_新版结果页_正式上线V2.0.docx'] ?? null);
        $this->assertSame('narrative / canonical body master', $documents['FermatMind_BigFive_正式上线结果页全文_两万字最终稿.docx'] ?? null);
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
    private function manifest(): array
    {
        return $this->decodeJsonFile(self::MANIFEST_FILE);
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceTrace(): array
    {
        return $this->decodeJsonFile(self::SOURCE_TRACE_FILE);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function sectionsByKey(): array
    {
        $sections = [];
        foreach ((array) ($this->coreBody()['sections'] ?? []) as $section) {
            $sectionKey = (string) ($section['section_key'] ?? '');
            $this->assertContains($sectionKey, self::REQUIRED_SECTIONS);
            $sections[$sectionKey] = $section;
        }

        return $sections;
    }

    /**
     * @return list<string>
     */
    private function antiTargetTerms(): array
    {
        $decoded = json_decode(
            file_get_contents(base_path('content_assets/big5/result_page_v2/governance/big5_v2_anti_target_render_terms_v0_1.json')) ?: '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($decoded);

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
     * @return array<string,mixed>
     */
    private function decodeJsonFile(string $filename): array
    {
        $decoded = json_decode(file_get_contents($this->path($filename)) ?: '', true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded, $filename);

        return $decoded;
    }

    private function path(string $filename): string
    {
        return base_path(self::RELATIVE_DIR.'/'.$filename);
    }
}
