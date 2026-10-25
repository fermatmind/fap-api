<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\Result;
use App\Services\Content\RiasecPrivateResultCompileService as Compiler;
use App\Services\Content\RiasecPrivateResultPackLoader as Loader;
use App\Services\Riasec\RiasecDeepCopySlotRegistry as Registry;
use App\Services\Riasec\RiasecPrivateResultSourceRepository;
use App\Services\Riasec\RiasecPublicProjectionService;
use RuntimeException;
use Tests\TestCase;

final class RiasecEnglishTop3ReleaseTest extends TestCase
{
    public function test_all_120_orders_in_both_forms_use_positioned_english_and_group_specific_source(): void
    {
        $compiled = app(Compiler::class)->compile();
        app(Loader::class)->assertPackage($compiled['manifest'], $compiled['payload'], $compiled['bytes']);
        $assets = $compiled['payload']['assets'];
        $runtime = $assets['runtime_surface_copy_v1.en.json'];
        $sources = array_column($assets['top3_code_chain_strategy_v1.en.jsonl'], null, 'unordered_top3_key');
        $codes = Compiler::englishOrderedCodeCoverage('1.5.0');
        $this->assertCount(120, array_unique($codes));
        $this->assertCount(20, $sources);
        $projector = app(RiasecPublicProjectionService::class);
        foreach ($codes as $code) {
            $letters = str_split($code);
            $canonical = $letters;
            usort($canonical, fn ($a, $b) => array_search($a, ['R', 'I', 'A', 'S', 'E', 'C']) <=> array_search($b, ['R', 'I', 'A', 'S', 'E', 'C']));
            $source = $sources[implode('_', $canonical)];
            $lines = array_map(fn ($dimension) => $runtime['dimension_reading_lines'][$dimension], $letters);
            $tokens = ['{code}' => $code, '{labels}' => implode(', ', array_column($lines, 'label')), '{shorts}' => implode('-', array_column($lines, 'short'))];
            foreach (['first', 'second', 'third'] as $index => $position) {
                foreach (['label', 'short', 'focus', 'action'] as $field) {
                    $tokens['{'.$position.'_'.$field.'}'] = $lines[$index][$field];
                }
            }
            foreach (['riasec_60', 'riasec_140'] as $form) {
                $projection = $projector->buildV2FromResult($this->resultFixture($code, $form), 'en', true);
                $this->assertNull($projection['private_result_authority']);
                $slots = $this->triads($projection);
                $this->assertCount(1, $slots, $code.':'.$form);
                $slot = $slots[0];
                $this->assertSame('en', $slot['locale']);
                $this->assertSame('draft', $slot['review_status']);
                $this->assertSame('contract_only', $slot['evidence_level']);
                $this->assertSame($sources['R_I_A']['user_visible_boundary'], $slot['boundaries']['user_visible_boundary']);
                foreach ($runtime['top3_ordered_templates'] as $field => $template) {
                    $expected = is_array($template) ? array_map(fn ($value) => strtr($value, $tokens), $template) : strtr($template, $tokens);
                    $this->assertSame($expected, $slot['content'][$field], $code.':'.$field);
                }
                foreach (['real_world_cost', 'likely_tension', 'when_to_use_140q', 'when_not_to_overread', 'deep_report_extension'] as $field) {
                    $this->assertSame($source[$field], $slot['content'][$field]);
                }
                $serialized = json_encode($slot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $this->assertDoesNotMatchRegularExpression('/[\x{3400}-\x{9fff}]/u', $serialized);
                $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $serialized);
            }
        }
    }

    public function test_quality_and_profile_policies_still_hide_or_collapse_the_new_groups(): void
    {
        $projector = app(RiasecPublicProjectionService::class);
        $low = $projector->buildV2FromResult($this->resultFixture('SEC', 'riasec_140', ['attention_133_failed', 'attention_137_failed']), 'en', true);
        $this->assertSame([], $this->triads($low));
        $broad = $projector->buildV2FromResult($this->resultFixture('SEC', 'riasec_140', scores: ['R' => 74, 'I' => 72, 'A' => 70, 'S' => 80, 'E' => 78, 'C' => 76]), 'en', true);
        $this->assertSame('broad_profile', $broad['interpretation_state']['profile_shape']);
        $this->assertSame([], $this->triads($broad));
        $near = $projector->buildV2FromResult($this->resultFixture('SEC', 'riasec_140', scores: ['R' => 20, 'I' => 20, 'A' => 20, 'S' => 85, 'E' => 84, 'C' => 60]), 'en', true);
        $this->assertSame('collapsed', $this->triads($near)[0]['slot_visibility']);
    }

    public function test_partial_or_drifted_sources_reading_lines_and_templates_fail_closed(): void
    {
        $assets = app(Compiler::class)->compile()['payload']['assets'];
        foreach (['missing', 'duplicate', 'dimensions', 'locale', 'version', 'empty', 'sequence', 'cjk', 'fallback', 'source', 'evidence', 'missing_boundary', 'empty_boundary', 'cjk_boundary', 'empty_override', 'reading_missing', 'reading_empty', 'reading_cjk', 'token', 'support', 'scope'] as $case) {
            $bad = $assets;
            $rows = &$bad['top3_code_chain_strategy_v1.en.jsonl'];
            $runtime = &$bad['runtime_surface_copy_v1.en.json'];
            match ($case) {
                'missing' => array_pop($rows), 'duplicate' => $rows[2] = $rows[1],
                'dimensions' => $rows[1]['dimensions'] = ['R', 'I', 'A'], 'locale' => $rows[1]['locale'] = 'zh-CN',
                'version' => $rows[1]['asset_version'] = 'unknown', 'empty' => $rows[1]['likely_tension'] = '',
                'sequence' => $rows[1]['activity_sequence'][0] = '', 'cjk' => $rows[1]['core_reading'] = '中文',
                'fallback' => $rows[1]['frontend_fallback_allowed'] = true,
                'source' => $bad['top3_code_chain_strategy_v1.zh-CN.jsonl'][1]['dimensions'] = ['R', 'I', 'A'],
                'evidence' => $rows[1]['review_status'] = 'expert_reviewed',
                'missing_boundary' => $rows[0] = array_diff_key($rows[0], ['user_visible_boundary' => true]),
                'empty_boundary' => $rows[0]['user_visible_boundary'] = '', 'cjk_boundary' => $rows[0]['user_visible_boundary'] = '中文',
                'empty_override' => $rows[1]['user_visible_boundary'] = '',
                'reading_missing' => $runtime['dimension_reading_lines'] = array_diff_key($runtime['dimension_reading_lines'], ['S' => true]),
                'reading_empty' => $runtime['dimension_reading_lines']['E']['focus'] = '', 'reading_cjk' => $runtime['dimension_reading_lines']['C']['action'] = '中文',
                'token' => $runtime['top3_ordered_templates']['strategy_label'] = '{unknown}',
                'support' => array_pop($runtime['supported_unordered_keys']), 'scope' => $runtime['scope'] = 'full_report',
            };
            unset($rows, $runtime);
            try {
                app(Compiler::class)->assertSelectedEnglishSources($bad);
                $this->fail('Invalid English Top3 source accepted: '.$case);
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('English', $exception->getMessage());
            }
        }
    }

    public function test_original_1_4_package_bytes_and_six_ria_outputs_are_unchanged(): void
    {
        $this->assertSame('eb59709ef2aca0ee5ff4a95b53e86ef9b2db72f09b37f4aeec4f76c97d091eb2', hash('sha256', file(base_path('content_assets/riasec/top3_code_chain_strategy_v1.en.jsonl'))[0]));
        $compiled = app(Compiler::class)->compile();
        $payload = $compiled['payload'];
        $manifest = $compiled['manifest'];
        $payload['compiler']['version'] = '1.4.0';
        $payload['assets']['top3_code_chain_strategy_v1.en.jsonl'] = array_slice($payload['assets']['top3_code_chain_strategy_v1.en.jsonl'], 0, 1);
        $runtime = &$payload['assets']['runtime_surface_copy_v1.en.json'];
        $runtime['dimension_reading_lines'] = array_intersect_key($runtime['dimension_reading_lines'], array_fill_keys(['R', 'I', 'A'], true));
        unset($runtime['supported_unordered_keys']);
        $runtime['supported_unordered_key'] = 'R_I_A';
        $runtime['scope'] = 'Only R_I_A and its six orders; no new 20-group or 120-code editorial expansion.';
        unset($runtime);
        $payload['coverage']['english_selected_sources']['top3_groups'] = ['R_I_A'];
        $payload['coverage']['english_selected_sources']['ordered_codes'] = ['RIA', 'RAI', 'IRA', 'IAR', 'ARI', 'AIR'];
        $manifest['compiler'] = $payload['compiler'];
        $manifest['coverage'] = $payload['coverage'];
        $canonical = fn ($value) => (new \ReflectionMethod(Compiler::class, 'canonicalJson'))->invoke(app(Compiler::class), $value);
        foreach ($manifest['source_files'] as &$row) {
            $row = array_merge($row, Compiler::sourceContract('1.4.0')[$row['path']]);
            $row['sha256'] = hash('sha256', $canonical($payload['assets'][$row['path']]));
        }
        unset($row);
        $payload['source_hash'] = $manifest['source_hash'] = $manifest['content_hash'] = hash('sha256', implode('', array_map(fn ($row) => $row['path']."\0".$row['sha256']."\n", $manifest['source_files'])));
        unset($payload['compiled_hash']);
        $payload['compiled_hash'] = $manifest['compiled_hash'] = hash('sha256', $canonical($payload));
        $bytes = $canonical($payload)."\n";
        $manifest['artifacts'][0]['sha256'] = hash('sha256', $bytes);
        $this->assertSame('b87c251323a18207a8f4cc98672a5ec77ae1e1e6deac5c59be379b3351cf4578', $payload['source_hash']);
        $this->assertSame('f653cbab814881363b7a2c6a4805a3defaa934c0f00a693588507b222fcd04f2', $payload['compiled_hash']);
        $this->assertSame('cdd3c3a773a43a7ecb940e9681a3e20ee54afaea4d7287e4ab31570d0f47752e', hash('sha256', $bytes));
        app(Loader::class)->assertPackage($manifest, $payload, $bytes);
        $source = new RiasecPrivateResultSourceRepository;
        (new \ReflectionProperty($source, 'loaded'))->setValue($source, ['en' => Loader::localeProjection($payload, 'en'), 'zh-CN' => Loader::localeProjection($payload, 'zh-CN')]);
        $legacy = new Registry(privateResultSource: $source);
        $current = app(Registry::class);
        foreach (Compiler::englishOrderedCodeCoverage('1.4.0') as $code) {
            $this->assertSame($legacy->resolveForLocale($legacy->resolveTop3ChainSlot($code), 'en'), $current->resolveForLocale($current->resolveTop3ChainSlot($code), 'en'));
        }
        foreach (array_slice(Compiler::ENGLISH_TOP3_KEYS, 1) as $key) {
            $this->assertSame('unavailable', $legacy->resolveForLocale($legacy->resolveTop3ChainSlot(str_replace('_', '', $key)), 'en')['content_status']);
        }
        $badLegacyAssets = $payload['assets'];
        $badLegacyAssets['pair_blend_15_pairs_v1.en.jsonl'][3]['context_costs'][0] = '';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('English pair reader text');
        app(Compiler::class)->assertSelectedEnglishSources($badLegacyAssets, '1.4.0');
    }

    private function triads(array $projection): array
    {
        return array_values(array_filter($projection['deep_content_slots_v1']['slots'], fn ($slot) => $slot['slot_key'] === 'triad_blend_copy'));
    }

    private function resultFixture(string $code, string $form, array $flags = [], ?array $scores = null): Result
    {
        if ($scores === null) {
            $scores = array_fill_keys(['R', 'I', 'A', 'S', 'E', 'C'], 30);
            foreach (str_split($code) as $index => $letter) {
                $scores[$letter] = [85, 70, 55][$index];
            }
        }

        return new Result(['scale_code' => 'RIASEC', 'type_code' => $code, 'scores_pct' => $scores, 'result_json' => ['top_code' => $code, 'form_code' => $form, 'answer_count' => $form === 'riasec_60' ? 60 : 140, 'quality_flags' => $flags]]);
    }
}
