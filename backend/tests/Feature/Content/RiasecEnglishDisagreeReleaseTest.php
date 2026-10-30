<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\Result;
use App\Services\Content\RiasecPrivateResultCompileService as Compiler;
use App\Services\Content\RiasecPrivateResultPackLoader as Loader;
use App\Services\Riasec\RiasecDeepCopySlotRegistry as Registry;
use App\Services\Riasec\RiasecPrivateResultSourceRepository as Source;
use App\Services\Riasec\RiasecPublicProjectionService;
use RuntimeException;
use Tests\TestCase;

final class RiasecEnglishDisagreeReleaseTest extends TestCase
{
    public function test_approved_seven_leaves_reach_both_form_projections_without_promoting_authority(): void
    {
        $bytes = file_get_contents(base_path('content_assets/riasec/runtime_surface_copy_v1.en.json'));
        $restored = substr($bytes, 0, 10799).substr($bytes, 11000);
        $restored = substr($restored, 0, 9467).substr($restored, 10564);
        $this->assertSame('c3d9409f9ae3bc40f6c02e1874833f739616f01d08cdfbbc5828ff3b4dd3021d', hash('sha256', $restored));
        $compiled = app(Compiler::class)->compile();
        $this->assertSame(Compiler::COMPILER_VERSION, $compiled['payload']['compiler']['version']);
        $this->assertSame(3, $compiled['payload']['coverage']['english_selected_sources']['generic_disagree_cards']);
        $runtime = $compiled['payload']['assets']['runtime_surface_copy_v1.en.json'];
        foreach (['riasec_60', 'riasec_140'] as $form) {
            foreach (['normal', 'caution'] as $quality) {
                $result = $this->resultFixture($form, $quality);
                $before = $result->toArray();
                $projection = app(RiasecPublicProjectionService::class)->buildV2FromResult($result, 'en', true);
                $this->assertSame($quality, $projection['quality']['quality_state']);
                $this->assertNull($projection['private_result_authority']);
                $slots = $this->disagreeSlots($projection);
                $this->assertSame(Compiler::ENGLISH_DISAGREE_CARDS, array_column(array_column($slots, 'state'), 'slot_name'));
                foreach ($slots as $slot) {
                    $name = $slot['state']['slot_name'];
                    $this->assertSame('collapsed', $slot['slot_visibility']);
                    $this->assertSame('en', $slot['locale']);
                    $this->assertSame('draft', $slot['review_status']);
                    $this->assertSame('contract_only', $slot['evidence_level']);
                    $this->assertSame('docs_only_candidate', $slot['source_status']);
                    foreach (['title', 'summary'] as $field) {
                        $this->assertSame($runtime['generic_slots']['disagree'][$name][$field], $slot['content'][$field]);
                    }
                    $this->assertSame($runtime['generic_slots']['disagree'][$name]['disagree_state'], $slot['state']['disagree_state']);
                    $this->assertSame($runtime['boundaries']['disagree'], $slot['boundaries']['user_visible_boundary']);
                    $this->assertFalse($slot['frontend_fallback_allowed']);
                    $this->assertFalse($slot['boundaries']['result_override_allowed']);
                    $this->assertDoesNotMatchRegularExpression('/\p{Han}/u', json_encode($slot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                }
                $this->assertSame($before, $result->toArray());
            }
            $low = app(RiasecPublicProjectionService::class)->buildV2FromResult($this->resultFixture($form, 'low_quality'), 'en', true);
            $this->assertSame('low_quality', $low['quality']['quality_state']);
            $this->assertSame('retake_recommended', $low['quality']['reading_strength']);
            $this->assertSame([], $this->disagreeSlots($low));
        }
    }

    public function test_incomplete_wrong_identity_and_drifted_source_leaves_fail_closed(): void
    {
        $assets = app(Compiler::class)->compile()['payload']['assets'];
        foreach (['missing', 'extra', 'missing_title', 'empty_title', 'array_summary', 'cjk', 'compat_title', 'extended_summary', 'state', 'metadata', 'missing_boundary', 'empty_boundary', 'cjk_boundary', 'compat_boundary', 'extended_boundary', 'extra_boundary', 'source_drift'] as $case) {
            $bad = $assets;
            $runtime = &$bad['runtime_surface_copy_v1.en.json'];
            $card = &$runtime['generic_slots']['disagree']['next_step'];
            match ($case) {
                'missing' => $runtime['generic_slots']['disagree'] = [],
                'extra' => $runtime['generic_slots']['disagree']['unknown'] = $card,
                'missing_title' => $card = array_diff_key($card, ['title' => true]),
                'empty_title' => $card['title'] = ' ',
                'array_summary' => $card['summary'] = [],
                'cjk' => $card['summary'] = '中文',
                'compat_title' => $card['title'] = "\u{F900}",
                'extended_summary' => $card['summary'] = "\u{20000}",
                'state' => $card['disagree_state'] = 'disagrees_quality_normal',
                'metadata' => $card['review_status'] = 'approved_for_staging',
                'missing_boundary' => $runtime['boundaries'] = [],
                'empty_boundary' => $runtime['boundaries']['disagree'] = '',
                'cjk_boundary' => $runtime['boundaries']['disagree'] = '中文',
                'compat_boundary' => $runtime['boundaries']['disagree'] = "\u{F900}",
                'extended_boundary' => $runtime['boundaries']['disagree'] = "\u{20000}",
                'extra_boundary' => $runtime['boundaries']['unknown'] = 'Unreviewed',
                'source_drift' => $bad['runtime_surface_copy_v1.zh-CN.json']['generic_slots']['disagree']['next_step']['title'] = 'Changed',
            };
            unset($card, $runtime);
            try {
                app(Compiler::class)->assertSelectedEnglishSources($bad);
                $this->fail('Invalid disagreement source accepted: '.$case);
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('English', $exception->getMessage());
            }
        }
    }

    public function test_registry_never_falls_back_to_chinese_for_missing_or_invalid_english_cards(): void
    {
        $payload = app(Compiler::class)->compile()['payload'];
        foreach (['missing', 'empty', 'cjk', 'compat', 'extended', 'state', 'boundary', 'compat_boundary', 'extended_boundary'] as $case) {
            $bad = $payload;
            $runtime = &$bad['assets']['runtime_surface_copy_v1.en.json'];
            match ($case) {
                'missing' => $runtime['generic_slots']['disagree']['next_step'] = null,
                'empty' => $runtime['generic_slots']['disagree']['next_step']['summary'] = '',
                'cjk' => $runtime['generic_slots']['disagree']['next_step']['title'] = '中文',
                'compat' => $runtime['generic_slots']['disagree']['next_step']['summary'] = "\u{F900}",
                'extended' => $runtime['generic_slots']['disagree']['next_step']['summary'] = "\u{20000}",
                'state' => $runtime['generic_slots']['disagree']['next_step']['disagree_state'] = 'unknown',
                'boundary' => $runtime['boundaries']['disagree'] = '',
                'compat_boundary' => $runtime['boundaries']['disagree'] = "\u{F900}",
                'extended_boundary' => $runtime['boundaries']['disagree'] = "\u{20000}",
            };
            unset($runtime);
            $registry = $this->registryFor($bad);
            $slot = $registry->resolveForLocale($registry->resolveDisagreePathSlot('next_step'), 'en');
            $this->assertSame('unavailable', $slot['content_status']);
            $this->assertSame('locale_content_unavailable', $slot['reason']);
            $this->assertFalse($slot['frontend_fallback_allowed']);
        }
    }

    public function test_original_1_5_package_and_existing_slots_remain_identical(): void
    {
        $compiled = app(Compiler::class)->compile();
        $compiled = \Tests\Unit\Services\Riasec\RiasecEnglishReportSourceContractTest::restoreLegacyReaderCopy($compiled);
        // Rebuild immutable releases with their original reader copy, not today's source.
        foreach (json_decode(file_get_contents(base_path('tests/Fixtures/riasec_activity_reader_copy_precleanup.v1.json')), true, 512, JSON_THROW_ON_ERROR) as $original) {
            data_set($compiled['payload']['assets'][Compiler::ENGLISH_ACTIVITY_ASSET], $original['row'].'.'.$original['path'], $original['copy']);
        }
        $runtime = &$compiled['payload']['assets']['runtime_surface_copy_v1.en.json'];
        unset($runtime['generic_slots']['disagree'], $runtime['generic_slots']['aspirations'], $runtime['boundaries']);
        unset($runtime);
        unset($compiled['payload']['coverage']['english_selected_sources']['generic_disagree_cards'], $compiled['payload']['coverage']['english_selected_sources']['generic_aspirations_cards']);
        $compiled['payload']['compiler']['version'] = '1.5.0';
        $legacy = $this->rebind($compiled);
        $this->assertSame('8693713a223755bbd0f1914a9cf4dddaec6490db0b1eb83db4d6c6c88641e827', $legacy['payload']['source_hash']);
        $this->assertSame('55ed0cdad79f10519a238223ce0cf66fbd30f81f1305761957411d4d891e390b', $legacy['payload']['compiled_hash']);
        $this->assertSame('993d59f18814d93051b77d3c8fc5f60a59c504657fd58652ccb0ab47b146322e', hash('sha256', $legacy['bytes']));
        app(Loader::class)->assertPackage($legacy['manifest'], $legacy['payload'], $legacy['bytes']);
        $registry = $this->registryFor($legacy['payload']);
        foreach (Compiler::ENGLISH_DISAGREE_CARDS as $name) {
            $this->assertSame('unavailable', $registry->resolveForLocale($registry->resolveDisagreePathSlot($name), 'en')['content_status']);
        }
        $current = app(Registry::class);
        foreach (Compiler::englishOrderedCodeCoverage('1.5.0') as $code) {
            $this->assertSame($registry->resolveForLocale($registry->resolveTop3ChainSlot($code), 'en'), $current->resolveForLocale($current->resolveTop3ChainSlot($code), 'en'));
        }
        $bad = $legacy['payload']['assets'];
        $bad['top3_code_chain_strategy_v1.en.jsonl'][1]['likely_tension'] = '';
        $this->expectException(RuntimeException::class);
        app(Compiler::class)->assertSelectedEnglishSources($bad, '1.5.0');
    }

    public function test_loader_rejects_invalid_leaf_and_coverage_even_after_hashes_are_rebound(): void
    {
        foreach (['leaf', 'compat', 'extended', 'coverage'] as $case) {
            $compiled = app(Compiler::class)->compile();
            if ($case === 'leaf') {
                $compiled['payload']['assets']['runtime_surface_copy_v1.en.json']['generic_slots']['disagree']['next_step']['summary'] = '';
            } elseif (in_array($case, ['compat', 'extended'], true)) {
                $compiled['payload']['assets']['runtime_surface_copy_v1.en.json']['generic_slots']['disagree']['next_step']['summary'] = $case === 'compat' ? "\u{F900}" : "\u{20000}";
            } else {
                $compiled['payload']['coverage']['english_selected_sources']['generic_disagree_cards'] = 4;
            }
            $bad = $this->rebind($compiled);
            try {
                app(Loader::class)->assertPackage($bad['manifest'], $bad['payload'], $bad['bytes']);
                $this->fail('Invalid disagreement package accepted: '.$case);
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function resultFixture(string $form, string $quality): Result
    {
        return new Result(['scale_code' => 'RIASEC', 'type_code' => 'RIA', 'scores_pct' => ['R' => 85, 'I' => 70, 'A' => 55, 'S' => 40, 'E' => 25, 'C' => 10], 'result_json' => ['top_code' => 'RIA', 'form_code' => $form, 'answer_count' => ($form === 'riasec_60' ? 60 : 140) - ($quality === 'low_quality' ? 1 : 0), 'too_fast' => $quality === 'caution']]);
    }

    private function disagreeSlots(array $projection): array
    {
        return array_values(array_filter($projection['deep_content_slots_v1']['slots'], fn ($slot) => $slot['slot_key'] === 'disagree_path_copy'));
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
