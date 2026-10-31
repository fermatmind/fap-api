<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Services\Enneagram\EnneagramReadingCopyProjection;
use Tests\TestCase;

final class EnneagramReadingCopyProjectionTest extends TestCase
{
    private function reports(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/enneagram_precloseout_reading_reports_20261030.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function expectedCandidateFacts(array $candidate): array
    {
        unset($candidate['sections']);
        foreach ((array) ($candidate['growth_actions'] ?? []) as $index => $action) {
            if (($candidate['type_id'] ?? null) === '8' && ($action['action_id'] ?? null) === 'type-8-action-01'
                && ($action['instruction'] ?? null) === 'In the next disagreement, ask three questions before stating a position: what the other person sees, fears, and wants decided. Clarify without hiding rebuttal inside the questions.') {
                $candidate['growth_actions'][$index]['instruction'] = 'In the next disagreement, ask three questions before stating a position: what the other person sees, what they are concerned about, and who they think should decide. Clarify without hiding rebuttal inside the questions.';
            }
        }

        return $candidate;
    }

    private function oldStrings(): array
    {
        static $strings = null;
        if ($strings !== null) {
            return $strings;
        }
        $value = (new \ReflectionClass(EnneagramReadingCopyProjection::class))->getReflectionConstant('FINGERPRINTS')->getValue();

        return $strings = array_column(json_decode($value, true, 64, JSON_THROW_ON_ERROR), 'before');
    }

    private function oldCount(mixed $value): int
    {
        if (is_string($value)) {
            return in_array($value, $this->oldStrings(), true) ? 1 : 0;
        }

        return array_sum(array_map(fn ($child) => $this->oldCount($child), is_array($value) ? $value : []));
    }

    public function test_existing_four_fixed_canonical_snapshots_receive_a_separate_current_reading_view(): void
    {
        $service = app(EnneagramReadingCopyProjection::class);
        foreach ($this->reports() as $case => $report) {
            $before = json_encode($report, JSON_THROW_ON_ERROR);
            $original = $report['_meta']['enneagram_report_v2'];
            $this->assertGreaterThan(0, $this->oldCount($original), $case);
            $view = $service->project($report);
            $this->assertSame('ready', $view['status'], $case.':'.($view['reason'] ?? ''));
            $reading = $view['report_v2'];
            $this->assertSame(0, $this->oldCount($reading), $case);
            foreach (['schema_version', 'scale_code', 'locale', 'form', 'registry', 'classification', 'distribution', 'provenance'] as $key) {
                $this->assertSame($original[$key], $reading[$key], $case.'.'.$key);
            }
            foreach ($original['candidates'] as $i => $candidate) {
                $copy = $reading['candidates'][$i];
                unset($copy['sections']);
                $this->assertSame($this->expectedCandidateFacts($candidate), $copy);
            }
            $this->assertSame($report['_meta']['enneagram_private_result_authority'], $view['original_authority']);
            $this->assertSame($before, json_encode($report, JSON_THROW_ON_ERROR));
            $current = $report;
            $current['_meta']['enneagram_report_v2'] = $reading;
            $this->assertNull($service->project($current), 'Already revised view is idempotent.');
        }
    }

    public function test_locale_binding_and_broken_canonical_structure_do_not_fall_back_to_old_copy(): void
    {
        $report = $this->reports()['en/enneagram_likert_105'];
        $report['_meta']['enneagram_report_v2']['locale'] = 'zh';
        $view = app(EnneagramReadingCopyProjection::class)->project($report);
        $this->assertSame('unavailable', $view['status']);
        $this->assertArrayNotHasKey('report_v2', $view);
        $report = $this->reports()['en/enneagram_likert_105'];
        unset($report['_meta']['enneagram_report_v2']);
        $this->assertSame('unavailable', app(EnneagramReadingCopyProjection::class)->project($report)['status']);
    }

    public function test_documented_v2_response_alias_keeps_the_same_canonical_reading_view(): void
    {
        $service = app(EnneagramReadingCopyProjection::class);
        foreach ($this->reports() as $report) {
            $legacy = $service->project($report);
            $report['scale_code'] = 'ENNEAGRAM_PERSONALITY_TEST';
            $report['scale_code_legacy'] = 'ENNEAGRAM';
            $report['scale_code_v2'] = 'ENNEAGRAM_PERSONALITY_TEST';
            $this->assertSame($legacy, $service->project($report));
            $this->assertSame('ENNEAGRAM_PERSONALITY_TEST', $report['scale_code']);
        }
    }

    public function test_real_composer_sections_cover_all_types_and_reversed_pair_without_reassigning_facts(): void
    {
        $packs = json_decode((string) file_get_contents(base_path('tests/Fixtures/enneagram_precloseout_semantic_registry.json')), true, 512, JSON_THROW_ON_ERROR);
        $composer = app(\App\Services\Report\EnneagramReportComposer::class);
        $call = static fn (string $name, ...$args) => (new \ReflectionMethod($composer, $name))->invoke($composer, ...$args);
        foreach (['en', 'zh-CN'] as $locale) {
            $indexes = $call('indexRegistryPack', $packs[$locale]);
            // Explicit local selectors exercise the unchanged composer, not the scoring algorithm or answer bank.
            foreach ([[1, 4, 2], [4, 1, 3], [5, 6, 7], [8, 9, 2]] as $types) {
                $report = $this->reports()[$locale.'/enneagram_likert_105'];
                $projection = $report['_meta']['enneagram_public_projection_v2'];
                foreach ($types as $i => $type) {
                    $projection['scores']['top_types'][$i]['type'] = (string) $type;
                }
                $projection['classification']['interpretation_scope'] = $types[0] < 5 ? 'close_call' : 'low_quality';
                $original = &$report['_meta']['enneagram_report_v2'];
                $original['classification']['interpretation_scope'] = $projection['classification']['interpretation_scope'];
                $original['candidates'] = $call('buildCanonicalCandidates', $projection, $indexes);
                $original['modules'] = [
                    $call('buildResultOverviewModule', $projection, $indexes, $locale === 'en' ? 'en' : 'zh'),
                    $call('buildCandidatePairComparisonModule', $projection, $indexes),
                ];
                $original['pages'] = [['modules' => $original['modules']]];
                $view = app(EnneagramReadingCopyProjection::class)->project($report);
                $this->assertSame('ready', $view['status'], $locale.':'.implode(',', $types).':'.($view['reason'] ?? ''));
                $this->assertSame(0, $this->oldCount($view['report_v2']));
                $this->assertSame(array_map('strval', $types), array_column($view['report_v2']['candidates'], 'type_id'));
                $this->assertSame($original['classification'], $view['report_v2']['classification']);
                foreach ($original['candidates'] as $i => $candidate) {
                    $reading = $view['report_v2']['candidates'][$i];
                    unset($reading['sections']);
                    $this->assertSame($this->expectedCandidateFacts($candidate), $reading);
                }
                $pair = $view['report_v2']['modules'][1];
                if ($types[0] < 5) {
                    $this->assertSame('1_4', $pair['content']['pair_key']);
                    $this->assertSame(array_map('strval', array_slice($types, 0, 2)), array_column($pair['content']['candidate_order'], 'type_id'));
                } else {
                    $this->assertSame('unavailable', $pair['visibility']);
                }
                unset($original);
            }
        }
    }

    public function test_two_canonical_english_meaning_corrections_reach_candidates_and_mirrored_actions_without_changing_facts(): void
    {
        $packs = json_decode((string) file_get_contents(base_path('tests/Fixtures/enneagram_precloseout_semantic_registry.json')), true, 512, JSON_THROW_ON_ERROR);
        $composer = app(\App\Services\Report\EnneagramReportComposer::class);
        $call = static fn (string $name, ...$args) => (new \ReflectionMethod($composer, $name))->invoke($composer, ...$args);
        $indexes = $call('indexRegistryPack', $packs['en']);
        foreach (['enneagram_likert_105', 'enneagram_forced_choice_144'] as $form) {
            $report = $this->reports()['en/'.$form];
            $projection = $report['_meta']['enneagram_public_projection_v2'];
            foreach ([6, 8, 2] as $i => $type) {
                $projection['scores']['top_types'][$i]['type'] = (string) $type;
            }
            $original = &$report['_meta']['enneagram_report_v2'];
            $original['candidates'] = $call('buildCanonicalCandidates', $projection, $indexes);
            $original['modules'] = [$call('buildGrowthActionsModule', $projection, $indexes)];
            $original['pages'] = [['modules' => $original['modules']]];
            $before = $report;
            $view = app(EnneagramReadingCopyProjection::class)->project($report);
            $this->assertSame('ready', $view['status'], $form.':'.($view['reason'] ?? ''));
            $reading = $view['report_v2'];
            $section = array_values(array_filter($reading['candidates'][0]['sections'], static fn ($s) => $s['section_id'] === '4.2'))[0];
            $this->assertSame('You want to check whether the other person is reliable, but repeated testing may leave them feeling distrusted. Replace indirect tests with an explicit request: what information, updates, or actions would help you feel more at ease?', $section['paragraphs'][0]);
            $instruction = 'In the next disagreement, ask three questions before stating a position: what the other person sees, what they are concerned about, and who they think should decide. Clarify without hiding rebuttal inside the questions.';
            $this->assertSame($instruction, $reading['candidates'][1]['growth_actions'][0]['instruction']);
            $this->assertSame($instruction, $reading['modules'][0]['content']['candidates'][1]['actions'][0]['instruction']);
            $this->assertSame($instruction, $reading['pages'][0]['modules'][0]['content']['candidates'][1]['actions'][0]['instruction']);
            foreach ($original['candidates'] as $i => $candidate) {
                $copy = $reading['candidates'][$i];
                unset($copy['sections']);
                $this->assertSame($this->expectedCandidateFacts($candidate), $copy);
            }
            $this->assertSame($before, $report);
            foreach (['action_id', 'type_id'] as $field) {
                $bad = $report;
                if ($field === 'action_id') {
                    $bad['_meta']['enneagram_report_v2']['candidates'][1]['growth_actions'][0]['action_id'] = 'unknown';
                } else {
                    $bad['_meta']['enneagram_report_v2']['candidates'][0]['type_id'] = 'unknown';
                }
                $this->assertSame('unmatched_copy_identity', app(EnneagramReadingCopyProjection::class)->project($bad)['reason']);
            }
            unset($original);
        }
    }

    public function test_unknown_copy_identity_is_unavailable_and_unversioned_history_is_preserved(): void
    {
        $report = $this->reports()['en/enneagram_likert_105'];
        $report['_meta']['enneagram_report_v2']['candidates'][0]['type_id'] = 'unknown';
        $this->assertSame('unmatched_copy_identity', app(EnneagramReadingCopyProjection::class)->project($report)['reason']);
        $report = $this->reports()['en/enneagram_likert_105'];
        $report['_meta']['enneagram_private_result_authority']['mode'] = 'immutable_legacy_snapshot';
        $before = $report;
        $this->assertNull(app(EnneagramReadingCopyProjection::class)->project($report));
        $this->assertSame($before, $report);
    }
}
