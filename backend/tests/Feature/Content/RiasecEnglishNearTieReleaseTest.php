<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\Result;
use App\Services\Content\RiasecPrivateResultCompileService as Compiler;
use App\Services\Content\RiasecPrivateResultPackLoader as Loader;
use App\Services\Riasec\RiasecDeepCopySlotRegistry as Registry;
use App\Services\Riasec\RiasecPrivateResultSourceRepository as Source;
use App\Services\Riasec\RiasecPublicProjectionService as Projection;
use RuntimeException;
use Tests\TestCase;

final class RiasecEnglishNearTieReleaseTest extends TestCase
{
    public function test_selected_near_tie_states_reach_both_forms_with_exact_source_aliases_and_boundaries(): void
    {
        $this->assertSame('a6dedda591505fcfebb97597afbf21a5c392c46b177630b4d35f80e86c53253e', hash_file('sha256', base_path('content_assets/riasec/'.Compiler::ENGLISH_NEAR_TIE_ASSET)));
        $this->assertSame('956e96c3cf16b030f9f65e7d4d4a44ed4555c18634f265ede188dd9f01265b36', hash_file('sha256', base_path('content_assets/riasec/runtime_surface_copy_v1.en.json')));
        $compiled = app(Compiler::class)->compile();
        $source = $compiled['payload']['assets'][Compiler::ENGLISH_NEAR_TIE_ASSET];
        $rows = array_column($source['states'], null, 'state');
        foreach (['riasec_60', 'riasec_140'] as $form) {
            foreach (['normal', 'caution'] as $quality) {
                foreach (array_slice(Compiler::ENGLISH_NEAR_TIE_STATES, 0, 3) as $state) {
                    $result = $this->resultFixture($form, $quality, $state);
                    $before = $result->toArray();
                    $projection = app(Projection::class)->buildV2FromResult($result, 'en', true);
                    $this->assertSame($state, $projection['interpretation_state']['near_tie_state']['state']);
                    $this->assertSame($quality, $projection['quality']['quality_state']);
                    $this->assertNull($projection['private_result_authority']);
                    $slots = $this->nearSlots($projection);
                    $this->assertSame([$state, 'alternate_code_available'], array_column(array_column($slots, 'state'), 'slot_name'));
                    foreach ($slots as $slot) {
                        $name = $slot['state']['slot_name'];
                        $this->assertSame('collapsed', $slot['slot_visibility']);
                        $this->assertSame('interpretation_state_copy', $slot['slot_group']);
                        $this->assertSame('result_reading_boundary', $slot['module_key']);
                        $this->assertSame('en', $slot['locale']);
                        $this->assertSame('draft', $slot['review_status']);
                        $this->assertSame('contract_only', $slot['evidence_level']);
                        $this->assertSame('docs_only_candidate', $slot['source_status']);
                        $this->assertSame($name, $slot['state']['near_tie_copy_state']);
                        $this->assertSame($rows[$name]['title'], $slot['content']['title']);
                        foreach (['summary', 'body', 'copy'] as $field) {
                            $this->assertSame($rows[$name]['copy'], $slot['content'][$field]);
                        }
                        $this->assertSame($source['user_visible_boundary'], $slot['boundaries']['user_visible_boundary']);
                        $this->assertFalse($slot['frontend_fallback_allowed']);
                        $this->assertFalse($slot['boundaries']['result_override_allowed']);
                    }
                    $this->assertSame($before, $result->toArray());
                    $stateWithoutAlternate = $projection['interpretation_state'];
                    $stateWithoutAlternate['alternate_code']['show'] = false;
                    $selected = (new \ReflectionMethod(Projection::class, 'selectedInterpretationStateCopySlots'))->invoke(app(Projection::class), $stateWithoutAlternate, $quality);
                    $selected = array_values(array_filter($selected, fn ($slot) => $slot['slot_key'] === 'near_tie_alternate_code_copy'));
                    $this->assertSame([$state], array_column($selected, 'slot_name'));
                }
            }
            $low = app(Projection::class)->buildV2FromResult($this->resultFixture($form, 'low_quality', 'multi_near_tie'), 'en', true);
            $this->assertSame('retake_recommended', $low['interpretation_state']['reading_strength']);
            $this->assertSame([], $this->nearSlots($low));
            foreach (['none', 'exact'] as $case) {
                $this->assertSame([], $this->nearSlots(app(Projection::class)->buildV2FromResult($this->resultFixture($form, 'normal', $case), 'en', true)));
            }
        }
    }

    public function test_invalid_reader_machine_identity_locale_and_source_fail_closed(): void
    {
        $assets = app(Compiler::class)->compile()['payload']['assets'];
        foreach (['missing', 'extra', 'reordered', 'unknown', 'missing_title', 'empty_copy', 'type', 'han', 'compat', 'extended', 'boundary', 'boundary_han', 'locale', 'asset_id', 'metadata', 'fallback', 'source_drift'] as $case) {
            $bad = $assets;
            $asset = &$bad[Compiler::ENGLISH_NEAR_TIE_ASSET];
            match ($case) {
                'missing' => $asset['states'] = [],
                'extra' => $asset['states'][] = $asset['states'][0],
                'reordered' => $asset['states'] = array_reverse($asset['states']),
                'unknown' => $asset['states'][0]['state'] = 'unknown',
                'missing_title' => $asset['states'][0] = array_diff_key($asset['states'][0], ['title' => true]),
                'empty_copy' => $asset['states'][0]['copy'] = ' ',
                'type' => $asset['states'][0]['title'] = [],
                'han' => $asset['states'][0]['copy'] = '中文',
                'compat' => $asset['states'][0]['copy'] = "\u{F900}",
                'extended' => $asset['states'][0]['copy'] = "\u{20000}",
                'boundary' => $asset['user_visible_boundary'] = '',
                'boundary_han' => $asset['user_visible_boundary'] = "\u{20000}",
                'locale' => $asset['locale'] = 'zh-CN',
                'asset_id' => $asset['asset_id'] = 'other.en',
                'metadata' => $asset['review_status'] = 'approved',
                'fallback' => $asset['frontend_fallback_allowed'] = true,
                'source_drift' => $bad['near_tie_alternate_code_copy_v1.zh-CN.json']['states'][0]['title'] = 'Changed',
            };
            unset($asset);
            try {
                app(Compiler::class)->assertSelectedEnglishSources($bad);
                $this->fail('Invalid near-tie source accepted: '.$case);
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_registry_omits_missing_wrong_locale_and_invalid_leaves_without_chinese_fallback(): void
    {
        $payload = app(Compiler::class)->compile()['payload'];
        foreach (['missing', 'locale', 'identity', 'title', 'copy', 'han', 'boundary'] as $case) {
            $bad = $payload;
            $asset = &$bad['assets'][Compiler::ENGLISH_NEAR_TIE_ASSET];
            match ($case) {
                'missing' => $asset = [],
                'locale' => $asset['locale'] = 'zh-CN',
                'identity' => $asset['states'][0]['state'] = 'unknown',
                'title' => $asset['states'][0]['title'] = '',
                'copy' => $asset['states'][0]['copy'] = '',
                'han' => $asset['states'][0]['copy'] = "\u{20000}",
                'boundary' => $asset['user_visible_boundary'] = '',
            };
            unset($asset);
            $registry = $this->registryFor($bad);
            $slot = $registry->resolveForLocale($registry->resolveInterpretationStateCopySlot('near_tie_alternate_code_copy', 'top1_top2_near_tie'), 'en');
            $this->assertSame('unavailable', $slot['content_status']);
            $this->assertSame('locale_content_unavailable', $slot['reason']);
            $this->assertFalse($slot['frontend_fallback_allowed']);
        }
    }

    public function test_original_1_7_package_remains_exact_and_strict_without_the_new_english_asset(): void
    {
        $compiled = app(Compiler::class)->compile();
        $compiled['payload']['compiler']['version'] = '1.7.0';
        $old = $this->rebind($compiled);
        $this->assertSame('bcf2432eaa77b0b1436e9866c8440bd6aae15d72aa0a2604134c98fa19a4cbd2', $old['payload']['source_hash']);
        $this->assertSame('43ee8b826e5b2ba5675b607dd6ea8835405c8b587a829ad203d15f3b25a386b6', $old['payload']['compiled_hash']);
        $this->assertSame('ea91b48f05596f16f9f27377465f6af3c43fb8d3f0fa8fef1a83c51dd49dbe99', hash('sha256', $old['bytes']));
        app(Loader::class)->assertPackage($old['manifest'], $old['payload'], $old['bytes']);
        $registry = $this->registryFor($old['payload']);
        foreach (Compiler::ENGLISH_NEAR_TIE_STATES as $state) {
            $this->assertSame('unavailable', $registry->resolveForLocale($registry->resolveInterpretationStateCopySlot('near_tie_alternate_code_copy', $state), 'en')['content_status']);
        }
        $bad = $old['payload']['assets'];
        $bad['runtime_surface_copy_v1.en.json']['generic_slots']['aspirations']['intro']['title'] = '';
        $this->expectException(RuntimeException::class);
        app(Compiler::class)->assertSelectedEnglishSources($bad, '1.7.0');
    }

    public function test_loader_rejects_invalid_leaves_machine_fields_and_coverage_after_hash_rebinding(): void
    {
        foreach (['leaf', 'extended', 'machine', 'coverage'] as $case) {
            $compiled = app(Compiler::class)->compile();
            match ($case) {
                'leaf' => $compiled['payload']['assets'][Compiler::ENGLISH_NEAR_TIE_ASSET]['states'][1]['copy'] = '',
                'extended' => $compiled['payload']['assets'][Compiler::ENGLISH_NEAR_TIE_ASSET]['states'][1]['copy'] = "\u{20000}",
                'machine' => $compiled['payload']['assets'][Compiler::ENGLISH_NEAR_TIE_ASSET]['required_boundaries'] = [],
                'coverage' => $compiled['payload']['coverage']['english_selected_sources']['near_tie_states'] = [],
            };
            $bad = $this->rebind($compiled);
            try {
                app(Loader::class)->assertPackage($bad['manifest'], $bad['payload'], $bad['bytes']);
                $this->fail('Invalid near-tie package accepted: '.$case);
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function resultFixture(string $form, string $quality, string $state): Result
    {
        $scores = match ($state) {
            'top1_top2_near_tie' => [85, 82, 60],
            'top2_top3_near_tie' => [85, 65, 62],
            'multi_near_tie' => [85, 82, 79],
            'exact' => [85, 85, 60],
            default => [85, 70, 55],
        };

        return new Result(['scale_code' => 'RIASEC', 'type_code' => 'RIA', 'scores_pct' => array_combine(['R', 'I', 'A', 'S', 'E', 'C'], [...$scores, 40, 25, 10]), 'result_json' => ['top_code' => 'RIA', 'form_code' => $form, 'answer_count' => ($form === 'riasec_60' ? 60 : 140) - ($quality === 'low_quality' ? 1 : 0), 'too_fast' => $quality === 'caution']]);
    }

    private function nearSlots(array $projection): array
    {
        return array_values(array_filter($projection['deep_content_slots_v1']['slots'], fn ($slot) => $slot['slot_key'] === 'near_tie_alternate_code_copy'));
    }

    private function registryFor(array $payload): Registry
    {
        $source = new Source;
        (new \ReflectionProperty($source, 'loaded'))->setValue($source, ['en' => Loader::localeProjection($payload, 'en'), 'zh-CN' => Loader::localeProjection($payload, 'zh-CN')]);

        return new Registry(privateResultSource: $source);
    }

    private function rebind(array $compiled): array
    {
        $canonical = fn ($value) => (new \ReflectionMethod(Compiler::class, 'canonicalJson'))->invoke(app(Compiler::class), $value);
        $payload = $compiled['payload'];
        $manifest = $compiled['manifest'];
        if ($payload['compiler']['version'] !== Compiler::COMPILER_VERSION) {
            unset($payload['assets']['report_surface_copy_v1.en.json'], $payload['coverage']['english_report_sources']);
            $manifest['source_files'] = array_values(array_filter($manifest['source_files'], fn ($row) => $row['path'] !== 'report_surface_copy_v1.en.json'));
        }
        if (! isset(Compiler::sourceContract($payload['compiler']['version'])[Compiler::ENGLISH_NEAR_TIE_ASSET])) {
            unset($payload['assets'][Compiler::ENGLISH_NEAR_TIE_ASSET], $payload['coverage']['english_selected_sources']['near_tie_states']);
            $manifest['source_files'] = array_values(array_filter($manifest['source_files'], fn ($row) => $row['path'] !== Compiler::ENGLISH_NEAR_TIE_ASSET));
        }
        foreach ($manifest['source_files'] as &$row) {
            $row['sha256'] = hash('sha256', $canonical($payload['assets'][$row['path']]));
        }
        unset($row);
        $payload['source_hash'] = $manifest['source_hash'] = $manifest['content_hash'] = hash('sha256', implode('', array_map(fn ($row) => $row['path']."\0".$row['sha256']."\n", $manifest['source_files'])));
        unset($payload['compiled_hash']);
        $payload['compiled_hash'] = $manifest['compiled_hash'] = hash('sha256', $canonical($payload));
        $bytes = $canonical($payload)."\n";
        $manifest['compiler'] = $payload['compiler'];
        $manifest['coverage'] = $payload['coverage'];
        $manifest['artifacts'][0]['sha256'] = hash('sha256', $bytes);

        return compact('payload', 'manifest', 'bytes');
    }
}
