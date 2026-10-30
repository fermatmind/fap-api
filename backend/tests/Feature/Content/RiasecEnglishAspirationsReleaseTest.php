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

final class RiasecEnglishAspirationsReleaseTest extends TestCase
{
    public function test_approved_seven_leaves_reach_both_form_projections_without_promoting_authority(): void
    {
        $this->assertSame('aa9568d769f4ecb0300237bce37be9438649bd96d93858e30e9e8de3848907c8', hash_file('sha256', base_path('content_assets/riasec/runtime_surface_copy_v1.en.json')));
        $compiled = app(Compiler::class)->compile();
        $this->assertSame(Compiler::COMPILER_VERSION, $compiled['payload']['compiler']['version']);
        $this->assertSame(3, $compiled['payload']['coverage']['english_selected_sources']['generic_aspirations_cards']);
        $runtime = $compiled['payload']['assets']['runtime_surface_copy_v1.en.json'];
        foreach (['riasec_60', 'riasec_140'] as $form) {
            foreach (['normal', 'caution'] as $quality) {
                $result = $this->resultFixture($form, $quality);
                $before = $result->toArray();
                $projection = app(RiasecPublicProjectionService::class)->buildV2FromResult($result, 'en', true);
                $this->assertSame($quality, $projection['quality']['quality_state']);
                $this->assertNull($projection['private_result_authority']);
                $slots = $this->aspirationsSlots($projection);
                $this->assertSame(Compiler::ENGLISH_ASPIRATIONS_CARDS, array_column(array_column($slots, 'state'), 'slot_name'));
                foreach ($slots as $slot) {
                    $name = $slot['state']['slot_name'];
                    $this->assertSame('collapsed', $slot['slot_visibility']);
                    $this->assertSame('en', $slot['locale']);
                    $this->assertSame('draft', $slot['review_status']);
                    $this->assertSame('contract_only', $slot['evidence_level']);
                    $this->assertSame('docs_only_candidate', $slot['source_status']);
                    foreach (['title', 'summary'] as $field) {
                        $this->assertSame($runtime['generic_slots']['aspirations'][$name][$field], $slot['content'][$field]);
                    }
                    $this->assertSame($runtime['generic_slots']['aspirations'][$name]['aspirations_state'], $slot['state']['aspirations_state']);
                    $this->assertSame($runtime['boundaries']['aspirations'], $slot['boundaries']['user_visible_boundary']);
                    $this->assertFalse($slot['frontend_fallback_allowed']);
                    $this->assertFalse($slot['boundaries']['result_override_allowed']);
                    $this->assertDoesNotMatchRegularExpression('/\p{Han}/u', json_encode($slot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                }
                $this->assertSame($before, $result->toArray());
            }
            $low = app(RiasecPublicProjectionService::class)->buildV2FromResult($this->resultFixture($form, 'low_quality'), 'en', true);
            $this->assertSame('low_quality', $low['quality']['quality_state']);
            $this->assertSame('retake_recommended', $low['quality']['reading_strength']);
            $this->assertSame([], $this->aspirationsSlots($low));
        }
    }

    public function test_incomplete_wrong_identity_and_drifted_source_leaves_fail_closed(): void
    {
        $assets = app(Compiler::class)->compile()['payload']['assets'];
        foreach (['missing', 'extra', 'missing_title', 'empty_title', 'array_summary', 'cjk', 'compat_title', 'extended_summary', 'state', 'metadata', 'missing_boundary', 'empty_boundary', 'cjk_boundary', 'compat_boundary', 'extended_boundary', 'extra_boundary', 'source_drift'] as $case) {
            $bad = $assets;
            $runtime = &$bad['runtime_surface_copy_v1.en.json'];
            $card = &$runtime['generic_slots']['aspirations']['no_score_mutation_boundary'];
            match ($case) {
                'missing' => $runtime['generic_slots']['aspirations'] = [],
                'extra' => $runtime['generic_slots']['aspirations']['unknown'] = $card,
                'missing_title' => $card = array_diff_key($card, ['title' => true]),
                'empty_title' => $card['title'] = ' ',
                'array_summary' => $card['summary'] = [],
                'cjk' => $card['summary'] = '中文',
                'compat_title' => $card['title'] = "\u{F900}",
                'extended_summary' => $card['summary'] = "\u{20000}",
                'state' => $card['aspirations_state'] = 'provided',
                'metadata' => $card['review_status'] = 'approved_for_staging',
                'missing_boundary' => $runtime['boundaries'] = [],
                'empty_boundary' => $runtime['boundaries']['aspirations'] = '',
                'cjk_boundary' => $runtime['boundaries']['aspirations'] = '中文',
                'compat_boundary' => $runtime['boundaries']['aspirations'] = "\u{F900}",
                'extended_boundary' => $runtime['boundaries']['aspirations'] = "\u{20000}",
                'extra_boundary' => $runtime['boundaries']['unknown'] = 'Unreviewed',
                'source_drift' => $bad['runtime_surface_copy_v1.zh-CN.json']['generic_slots']['aspirations']['no_score_mutation_boundary']['title'] = 'Changed',
            };
            unset($card, $runtime);
            try {
                app(Compiler::class)->assertSelectedEnglishSources($bad);
                $this->fail('Invalid aspirations source accepted: '.$case);
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
                'missing' => $runtime['generic_slots']['aspirations']['no_score_mutation_boundary'] = null,
                'empty' => $runtime['generic_slots']['aspirations']['no_score_mutation_boundary']['summary'] = '',
                'cjk' => $runtime['generic_slots']['aspirations']['no_score_mutation_boundary']['title'] = '中文',
                'compat' => $runtime['generic_slots']['aspirations']['no_score_mutation_boundary']['summary'] = "\u{F900}",
                'extended' => $runtime['generic_slots']['aspirations']['no_score_mutation_boundary']['summary'] = "\u{20000}",
                'state' => $runtime['generic_slots']['aspirations']['no_score_mutation_boundary']['aspirations_state'] = 'unknown',
                'boundary' => $runtime['boundaries']['aspirations'] = '',
                'compat_boundary' => $runtime['boundaries']['aspirations'] = "\u{F900}",
                'extended_boundary' => $runtime['boundaries']['aspirations'] = "\u{20000}",
            };
            unset($runtime);
            $registry = $this->registryFor($bad);
            $slot = $registry->resolveForLocale($registry->resolveAspirationsSlot('no_score_mutation_boundary'), 'en');
            $this->assertSame('unavailable', $slot['content_status']);
            $this->assertSame('locale_content_unavailable', $slot['reason']);
            $this->assertFalse($slot['frontend_fallback_allowed']);
        }
    }

    public function test_original_1_6_package_and_disagree_contract_survive_reviewed_copy_updates(): void
    {
        $compiled = app(Compiler::class)->compile();
        $compiled = \Tests\Unit\Services\Riasec\RiasecEnglishReportSourceContractTest::restoreLegacyReaderCopy($compiled);
        // Rebuild immutable releases with their original reader copy, not today's source.
        foreach (json_decode(file_get_contents(base_path('tests/Fixtures/riasec_activity_reader_copy_precleanup.v1.json')), true, 512, JSON_THROW_ON_ERROR) as $original) {
            data_set($compiled['payload']['assets'][Compiler::ENGLISH_ACTIVITY_ASSET], $original['row'].'.'.$original['path'], $original['copy']);
        }
        $runtime = &$compiled['payload']['assets']['runtime_surface_copy_v1.en.json'];
        unset($runtime['generic_slots']['aspirations'], $runtime['boundaries']['aspirations']);
        unset($runtime);
        unset($compiled['payload']['coverage']['english_selected_sources']['generic_aspirations_cards']);
        $compiled['payload']['compiler']['version'] = '1.6.0';
        $legacy = $this->rebind($compiled);
        $this->assertSame('2dfd5e959ef395b27f67990e2b36190479aa51baf65f453ef24caee8d7a5ac7d', $legacy['payload']['source_hash']);
        $this->assertSame('89e45dfc3f9e6aaea6603f7a57cbf8d1af51f4bd936667f60c914c49a538375e', $legacy['payload']['compiled_hash']);
        $this->assertSame('5cd41c27f70ee36bead8109d499bdca7796d6810d18b8e56ce1b46566d459a47', hash('sha256', $legacy['bytes']));
        app(Loader::class)->assertPackage($legacy['manifest'], $legacy['payload'], $legacy['bytes']);
        $registry = $this->registryFor($legacy['payload']);
        foreach (Compiler::ENGLISH_ASPIRATIONS_CARDS as $name) {
            $this->assertSame('unavailable', $registry->resolveForLocale($registry->resolveAspirationsSlot($name), 'en')['content_status']);
        }
        $current = app(Registry::class);
        $reviewedSummaries = [
            'user_not_wrong_message' => 'You can disagree with this result. Check response quality, close scores, and the activities you pictured while answering. You may record your own observations in the optional trial notes; this page does not use that text to change the measured result.',
            'next_step' => 'First check response quality and close scores. Then choose whether to retake when ready or try a small activity. You may write a preferred direction in your own notes and copy it before leaving; those notes stay only in this page’s memory.',
        ];
        foreach (Compiler::ENGLISH_DISAGREE_CARDS as $name) {
            $expected = $registry->resolveForLocale($registry->resolveDisagreePathSlot($name), 'en');
            if (isset($reviewedSummaries[$name])) {
                $expected['summary'] = $reviewedSummaries[$name];
            }
            $this->assertSame($expected, $current->resolveForLocale($current->resolveDisagreePathSlot($name), 'en'));
        }
        $bad = $legacy['payload']['assets'];
        $bad['top3_code_chain_strategy_v1.en.jsonl'][1]['likely_tension'] = '';
        $this->expectException(RuntimeException::class);
        app(Compiler::class)->assertSelectedEnglishSources($bad, '1.6.0');
    }

    public function test_loader_rejects_invalid_leaf_and_coverage_even_after_hashes_are_rebound(): void
    {
        foreach (['leaf', 'compat', 'extended', 'coverage'] as $case) {
            $compiled = app(Compiler::class)->compile();
            if ($case === 'leaf') {
                $compiled['payload']['assets']['runtime_surface_copy_v1.en.json']['generic_slots']['aspirations']['no_score_mutation_boundary']['summary'] = '';
            } elseif (in_array($case, ['compat', 'extended'], true)) {
                $compiled['payload']['assets']['runtime_surface_copy_v1.en.json']['generic_slots']['aspirations']['no_score_mutation_boundary']['summary'] = $case === 'compat' ? "\u{F900}" : "\u{20000}";
            } else {
                $compiled['payload']['coverage']['english_selected_sources']['generic_aspirations_cards'] = 4;
            }
            $bad = $this->rebind($compiled);
            try {
                app(Loader::class)->assertPackage($bad['manifest'], $bad['payload'], $bad['bytes']);
                $this->fail('Invalid aspirations package accepted: '.$case);
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function resultFixture(string $form, string $quality): Result
    {
        return new Result(['scale_code' => 'RIASEC', 'type_code' => 'RIA', 'scores_pct' => ['R' => 85, 'I' => 70, 'A' => 55, 'S' => 40, 'E' => 25, 'C' => 10], 'result_json' => ['top_code' => 'RIA', 'form_code' => $form, 'answer_count' => ($form === 'riasec_60' ? 60 : 140) - ($quality === 'low_quality' ? 1 : 0), 'too_fast' => $quality === 'caution']]);
    }

    private function aspirationsSlots(array $projection): array
    {
        return array_values(array_filter($projection['deep_content_slots_v1']['slots'], fn ($slot) => $slot['slot_key'] === 'aspirations_calibration_copy'));
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
