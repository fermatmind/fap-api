<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\Result;
use App\Services\Content\RiasecPrivateResultCompileService as Compiler;
use App\Services\Content\RiasecPrivateResultPackLoader as Loader;
use App\Services\Riasec\RiasecDeepCopySlotRegistry;
use App\Services\Riasec\RiasecLifecycleCopyService;
use App\Services\Riasec\RiasecPublicProjectionService;
use RuntimeException;
use Tests\TestCase;

final class RiasecEnglishSelectedSourceReleaseTest extends TestCase
{
    public function test_selected_slots_preserve_source_fields_bands_and_actual_top2_pair_for_all_six_orders(): void
    {
        $assets = app(Compiler::class)->compile()['payload']['assets'];
        $dimensions = array_column($assets['dimension_deep_copy_v1.en.json']['dimensions'], null, 'dimension_code');
        $pairRows = array_column($assets['pair_blend_15_pairs_v1.en.jsonl'], null, 'pair_key');
        foreach (['RIA', 'RAI', 'IRA', 'IAR', 'ARI', 'AIR'] as $code) {
            $projection = $this->projection($code);
            $this->assertNull($projection['private_result_authority']);
            $slots = $projection['deep_content_slots_v1']['slots'];
            $this->assertCount(14, $slots);
            $actualTop2 = str_split(substr($code, 0, 2));
            usort($actualTop2, fn ($a, $b) => array_search($a, ['R', 'I', 'A', 'S', 'E', 'C']) <=> array_search($b, ['R', 'I', 'A', 'S', 'E', 'C']));
            foreach ($slots as $slot) {
                $this->assertSame('en', $slot['locale']);
                $this->assertFalse($slot['frontend_fallback_allowed']);
                $this->assertSame('draft', $slot['review_status']);
                $this->assertSame('docs_only_candidate', $slot['source_status']);
                $this->assertNotSame('expert_reviewed', $slot['evidence_level']);
                if ($slot['slot_key'] === 'dimension_deep_copy') {
                    $selection = $slot['selection_v1'];
                    $source = $dimensions[$selection['dimension_code']];
                    $this->assertSame($source['review_status'], $slot['source_review_status']);
                    $this->assertSame($source['source_binding']['source_evidence_level'], $slot['source_evidence_level']);
                    $this->assertSame($slot['source_evidence_level'], $slot['evidence_level']);
                    foreach ($slot['content'] as $field => $value) {
                        $this->assertSame($source[$field], $value, $code.':'.$field);
                    }
                    $this->assertSame($source[$selection['selected_detail_key']], $slot['content'][$selection['selected_detail_key']]);
                    $this->assertCount(1, array_intersect(['high_score_reading', 'medium_score_reading', 'low_score_safe_reading'], array_keys($slot['content'])));
                    $this->assertSame($selection['is_top_three'] ? 'visible' : 'collapsed', $slot['slot_visibility']);
                } elseif ($slot['slot_key'] === 'pair_blend_copy') {
                    $source = $pairRows[$slot['state']['pair_key']];
                    foreach ($slot['content'] as $field => $value) {
                        $this->assertSame($source[$field], $value);
                    }
                    $this->assertSame($source['pair_key'] === implode('_', $actualTop2), isset($slot['content']['result_page_teaser']));
                } elseif ($slot['slot_key'] === 'triad_blend_copy') {
                    $this->assertCount(17, $slot['content']);
                    $this->assertStringContainsString($code, $slot['content']['ordered_code_handling']);
                    $line = $assets['runtime_surface_copy_v1.en.json']['dimension_reading_lines'][$code[0]];
                    $this->assertStringContainsString($line['action'], $slot['content']['activity_sequence'][0]);
                    $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', json_encode($slot['content']));
                }
            }
            $this->assertDoesNotMatchRegularExpression('/[\x{3400}-\x{9fff}]/u', json_encode($slots, JSON_UNESCAPED_UNICODE));
        }
        $bands = array_column(array_column(array_filter($this->projection('RIA')['deep_content_slots_v1']['slots'], fn ($s) => $s['slot_key'] === 'dimension_deep_copy'), 'selection_v1'), 'score_band');
        $this->assertSame(['high', 'high', 'medium', 'medium', 'low', 'low'], $bands);
    }

    public function test_all_top3_groups_are_available_and_generic_context_selection_is_preserved(): void
    {
        $registry = app(RiasecDeepCopySlotRegistry::class);
        $this->assertSame('authored', $registry->resolveForLocale($registry->resolveTop3ChainSlot('SEC'), 'en')['content_status']);
        $this->assertSame('authored', $registry->resolveForLocale($registry->resolvePairBlendSlot('S_E'), 'en')['content_status']);
        $this->assertSame('authored', $registry->resolveForLocale($registry->resolve140qDimensionLayerSlot('R', 'task', 'agreement'), 'en')['content_status']);
        $slots = $this->projection('RIA', 'riasec_140')['deep_content_slots_v1']['slots'];
        $context = array_values(array_filter($slots, fn ($s) => $s['module_key'] === '140q_context_cards'));
        $this->assertCount(3, $context);
        foreach ($context as $slot) {
            $this->assertSame(['riasec_140'], $slot['applicability']['form_codes']);
            $this->assertSame('140q_completed_state', $slot['state']['layer_state']);
        }
        $this->assertSame([], array_values(array_filter($slots, fn ($s) => $s['module_key'] === '140q_cta')));
    }

    public function test_lifecycle_preserves_unknown_status_and_source_text_without_pdf_activation(): void
    {
        $assets = app(Compiler::class)->compile()['payload']['assets'];
        $copy = app(RiasecLifecycleCopyService::class)->runtimeLifecycleCopyContract(true, 'en');
        $this->assertSame('available', $copy['status']);
        $this->assertTrue($copy['snapshot_bound']);
        $this->assertCount(20, $copy['faq_items']);
        $this->assertSame($assets['faq_v1.en.json']['questions'], $copy['faq_items']);
        $this->assertCount(8, $copy['technical_note_summary_sections']);
        foreach ($copy['technical_note_summary_sections'] as $index => $section) {
            $this->assertNull($section['data_status']);
            $this->assertSame($assets['technical_note_user_summary_v1.en.json']['summary_sections'][$index]['copy'], $section['copy']);
        }
        $this->assertSame($assets['professional_method_boundary_v1.en.json']['sections'], $copy['professional_method_boundary_sections']);
        $this->assertSame($assets['technical_note_user_summary_v1.en.json']['runtime_method_boundaries'], $copy['method_boundaries']);
        $this->assertSame($assets['technical_note_user_summary_v1.en.json']['runtime_disclaimers'], $copy['disclaimers']);
        $this->assertCount(5, $copy['surfaces']);
        foreach ($copy['surfaces'] as $surface) {
            $this->assertStringStartsNotWith('pdf_', $surface['surface']);
            $this->assertFalse($surface['raw_scores_allowed']);
            $this->assertFalse($surface['raw_feedback_allowed']);
            $this->assertFalse($surface['pdf_default_visible']);
        }
    }

    public function test_missing_templates_wrong_identity_and_false_known_status_fail_closed(): void
    {
        $assets = app(Compiler::class)->compile()['payload']['assets'];
        foreach (['token', 'locale', 'version', 'status', 'applicability', 'pair', 'template', 'faq'] as $case) {
            $bad = $assets;
            match ($case) {
                'token' => $bad['runtime_surface_copy_v1.en.json']['top3_ordered_templates']['strategy_label'] = '{invented}',
                'locale' => $bad['pair_blend_15_pairs_v1.en.jsonl'][0]['locale'] = 'zh-CN',
                'version' => $bad['top3_code_chain_strategy_v1.en.jsonl'][0]['asset_version'] = 'unknown',
                'status' => $bad['technical_note_user_summary_v1.en.json']['summary_sections'][0]['data_status'] = 'measured',
                'applicability' => $bad['runtime_surface_copy_v1.en.json']['generic_slots']['layer_140q']['140q_cta']['applicable_form_codes'] = ['riasec_140'],
                'pair' => $bad['pair_blend_15_pairs_v1.en.jsonl'][0]['pair_tension'] = '',
                'template' => $bad['runtime_surface_copy_v1.en.json']['top3_ordered_templates'] = array_merge(array_diff_key($bad['runtime_surface_copy_v1.en.json']['top3_ordered_templates'], ['activity_chain' => true]), ['unknown' => 'unselected']),
                'faq' => $bad['faq_v1.en.json']['questions'][0]['a'] = '',
            };
            try {
                app(Compiler::class)->assertSelectedEnglishSources($bad);
                $this->fail('Invalid selected source accepted: '.$case);
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('English', $exception->getMessage());
            }
        }
    }

    public function test_dimension_source_chain_is_preserved_in_the_compiled_asset(): void
    {
        $compiled = app(Compiler::class)->compile();
        $asset = $compiled['payload']['assets']['dimension_deep_copy_v1.en.json'];
        $this->assertSame('94063d7e8ef0886bb1fe2882ab16b3a4c501d8d6', $asset['source_binding']['repository_commit']);
        $this->assertSame(hash_file('sha256', base_path('content_assets/riasec/dimension_deep_copy_v1.zh-CN.r3.json')), $asset['source_binding']['raw_sha256']);
        $this->assertSame('f222c798dd7d12c43bb003d2957e9bfb44a533bf485c2850cb0fc2be138cd3c7', $asset['source_binding']['manifest_source_hash']);
        foreach (['provenance', 'permission_scope', 'third_party_license'] as $field) {
            $this->assertNotEmpty($asset['source_binding'][$field]);
        }
        $this->assertArrayHasKey('source_embedded_historical_digest', $asset);
        $this->assertNotEmpty($asset['source_digest_note']);
    }

    public function test_loader_rejects_wrong_english_root_locales_even_after_rebinding_every_hash(): void
    {
        foreach (['faq_v1.en.json', 'technical_note_user_summary_v1.en.json', 'professional_method_boundary_v1.en.json', 'runtime_surface_copy_v1.en.json', 'share_pdf_history_v1.en.json'] as $filename) {
            $compiled = app(Compiler::class)->compile();
            $payload = $compiled['payload'];
            $manifest = $compiled['manifest'];
            $payload['assets'][$filename]['locale'] = 'zh-CN';
            $canonical = fn ($value) => (new \ReflectionMethod(Compiler::class, 'canonicalJson'))->invoke(app(Compiler::class), $value);
            foreach ($manifest['source_files'] as &$row) {
                $row['sha256'] = hash('sha256', $canonical($payload['assets'][$row['path']]));
            }
            unset($row);
            $sourceHash = hash('sha256', implode('', array_map(fn ($row) => $row['path']."\0".$row['sha256']."\n", $manifest['source_files'])));
            $payload['source_hash'] = $manifest['source_hash'] = $manifest['content_hash'] = $sourceHash;
            unset($payload['compiled_hash']);
            $payload['compiled_hash'] = hash('sha256', $canonical($payload));
            $manifest['compiled_hash'] = $payload['compiled_hash'];
            $bytes = $canonical($payload)."\n";
            $manifest['artifacts'][0]['sha256'] = hash('sha256', $bytes);
            try {
                app(Loader::class)->assertPackage($manifest, $payload, $bytes);
                $this->fail('Wrong English root locale accepted: '.$filename);
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('English source locale', $exception->getMessage());
            }
        }
    }

    private function projection(string $code, string $form = 'riasec_60'): array
    {
        $scores = ['S' => 40, 'E' => 25, 'C' => 10];
        foreach (str_split($code) as $index => $dimension) {
            $scores[$dimension] = [85, 70, 55][$index];
        }
        $result = new Result(['scale_code' => 'RIASEC', 'type_code' => $code, 'scores_pct' => $scores, 'result_json' => ['top_code' => $code, 'primary_type' => $code[0], 'secondary_type' => $code[1], 'tertiary_type' => $code[2], 'form_code' => $form, 'answer_count' => $form === 'riasec_60' ? 60 : 140, 'quality_grade' => 'A', 'quality_flags' => []]]);

        return app(RiasecPublicProjectionService::class)->buildV2FromResult($result, 'en', true);
    }
}
