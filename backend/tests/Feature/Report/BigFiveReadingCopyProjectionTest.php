<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Services\BigFive\BigFiveReadingCopyProjection;
use App\Services\BigFive\ReportEngine\BigFiveReportEngine;
use App\Services\Content\BigFivePrivateResultPackLoader;
use App\Services\Report\Pdf\BigFiveReportPdfRenderer;
use Tests\TestCase;

final class BigFiveReadingCopyProjectionTest extends TestCase
{
    private function reports(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/big5_precloseout_reading_reports_20261030.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function knownBefore(): array
    {
        $constant = (new \ReflectionClass(BigFiveReadingCopyProjection::class))->getReflectionConstant('FINGERPRINTS');

        return array_column(json_decode($constant->getValue(), true, 64, JSON_THROW_ON_ERROR), 'before');
    }

    private function oldPointers(mixed $value, array $before, string $path = ''): array
    {
        if (is_string($value)) {
            return in_array($value, $before, true) ? [$path] : [];
        }
        $found = [];
        foreach (is_array($value) ? $value : [] as $key => $child) {
            $found = array_merge($found, $this->oldPointers($child, $before, $path.'/'.$key));
        }

        return $found;
    }

    public function test_four_saved_report_consumers_receive_current_copy_without_replacing_original_contracts(): void
    {
        $service = app(BigFiveReadingCopyProjection::class);
        foreach ($this->reports() as $case => $report) {
            $bytes = json_encode($report, JSON_THROW_ON_ERROR);
            $this->assertNotEmpty($this->oldPointers($report, $this->knownBefore()), $case);
            $view = $service->project($report);
            $this->assertSame(BigFiveReadingCopyProjection::REVISION, $view['revision'], $case);
            $this->assertSame($report['_meta']['big5_private_result_authority'], $view['original_authority']);
            $this->assertNotSame($view['original_report_sha256'], $view['projected_report_sha256']);
            $reading = $view['report'];
            $this->assertSame([], $this->oldPointers($reading, $this->knownBefore()), $case);
            foreach (['norms', 'quality', 'variant', 'report_snapshot_identity'] as $key) {
                $this->assertSame($report[$key] ?? null, $reading[$key] ?? null, $case.'.'.$key);
            }
            foreach (['score_vector', 'norm_evidence', 'quality', 'engine_decisions', 'report_snapshot_identity'] as $key) {
                $this->assertSame(data_get($report, '_meta.big5_report_engine_v2.'.$key), data_get($reading, '_meta.big5_report_engine_v2.'.$key), $case.'.engine.'.$key);
            }
            $this->assertSame($report['_meta']['big5_private_result_authority'], $reading['_meta']['big5_private_result_authority']);
            $this->assertSame($bytes, json_encode($report, JSON_THROW_ON_ERROR));
            $this->assertNull($service->project($reading), 'Already revised copy must be idempotent.');
            $html = app(BigFiveReportPdfRenderer::class)->html($reading, explode('/', $case)[0], false);
            $this->assertStringNotContainsString('nor is you', $html);
        }
    }

    public function test_supported_response_alias_keeps_its_identity_and_receives_the_same_reading_copy(): void
    {
        $original = $this->reports()['en/big5_90'];
        $alias = $original;
        $alias['scale_code'] = 'BIG_FIVE_OCEAN_MODEL';
        $view = app(BigFiveReadingCopyProjection::class)->project($alias);
        $this->assertSame('BIG_FIVE_OCEAN_MODEL', $view['report']['scale_code']);
        $this->assertSame([], $this->oldPointers($view['report'], $this->knownBefore()));
        $this->assertSame($original['_meta']['big5_private_result_authority'], $view['original_authority']);
    }

    public function test_missing_comparison_removes_only_identified_norm_atomic_copy_and_preserves_evidence(): void
    {
        $report = $this->reports()['en/big5_90'];
        foreach ($report['sections'] as &$section) {
            if (($section['section_key'] ?? null) !== 'norms_comparison') {
                continue;
            }
            foreach ($section['blocks'] as &$block) {
                if (($block['component'] ?? null) === 'BigFiveNormEvidenceCard') {
                    unset($block['resolved_copy']['comparison_allowed']);
                    $block['resolved_copy']['status'] = 'CALIBRATED';
                    $card = $block;
                }
            }
            unset($block);
            $beforeBlocks = count($section['blocks']);
        }
        unset($section);
        $view = app(BigFiveReadingCopyProjection::class)->project($report);
        $norm = collect($view['report']['sections'])->firstWhere('section_key', 'norms_comparison');
        $this->assertCount(1, $norm['blocks']);
        $this->assertGreaterThan(1, $beforeBlocks);
        $this->assertSame($card, $norm['blocks'][0]);
        $this->assertSame($report['norms'], $view['report']['norms']);
        $this->assertSame($report['quality'], $view['report']['quality']);
    }

    public function test_unknown_text_and_mismatched_asset_identity_never_receive_approved_copy(): void
    {
        $report = $this->reports()['en/big5_90'];
        $index = null;
        foreach ($report['sections'][0]['blocks'] as $position => $block) {
            if ($this->oldPointers($block, $this->knownBefore()) !== []) {
                $index = $position;
                break;
            }
        }
        $this->assertNotNull($index);
        $target = &$report['sections'][0]['blocks'][$index];
        $target['block_uid'] = 'unknown.source.identity';
        $before = $target;
        unset($target);
        $view = app(BigFiveReadingCopyProjection::class)->project($report);
        $this->assertSame('unavailable', $view['status']);
        $this->assertSame('unmatched_copy_identity', $view['reason']);
        $this->assertSame($before, $report['sections'][0]['blocks'][$index]);
        $report['_meta']['big5_report_engine_v2']['locale'] = 'zh-CN';
        $view = app(BigFiveReadingCopyProjection::class)->project($report);
        $this->assertSame('unavailable', $view['status']);
        $this->assertSame('locale_invalid_or_conflicting', $view['reason']);
        $this->assertArrayNotHasKey('report', $view);
        $structural = $this->reports()['en/big5_90'];
        $structural['sections'] = null;
        $this->assertNotEmpty($structural['_meta']['big5_report_engine_v2']['sections']);
        $view = app(BigFiveReadingCopyProjection::class)->project($structural);
        $this->assertSame('unavailable', $view['status']);
        $this->assertSame('sections_missing_or_invalid', $view['reason']);
        $this->assertArrayNotHasKey('report', $view);
        $this->assertNull(app(BigFiveReadingCopyProjection::class)->project(['scale_code' => 'RIASEC']));
    }

    public function test_normal_quality_action_items_keep_parent_provenance_in_sections_selected_copies_and_pdf(): void
    {
        $rows = json_decode((new \ReflectionClass(BigFiveReadingCopyProjection::class))->getReflectionConstant('FINGERPRINTS')->getValue(), true, 64, JSON_THROW_ON_ERROR);
        $covered = [];
        foreach (['en', 'zh-CN'] as $locale) {
            $loaded = app(BigFivePrivateResultPackLoader::class)->load($locale);
            $oldRegistry = $loaded['registry'];
            $actionRows = array_values(array_filter($rows, static fn ($row) => $row['locale'] === $locale && $row['registry_path'][0] === 'action_rules'));
            foreach ($actionRows as $row) {
                $target = &$oldRegistry;
                foreach ($row['registry_path'] as $key) {
                    $target = &$target[$key];
                }
                $target = $row['before'];
                unset($target);
            }
            // Synthetic score contexts exercise the real resolver/assembler; no question answers or scoring change.
            foreach ([20, 90] as $n) {
                $input = json_decode((string) file_get_contents(base_path('content_packs/BIG5_OCEAN/v2/registry/fixtures/canonical_n_slice_sensitive_independent.context.json')), true, 512, JSON_THROW_ON_ERROR);
                $input['locale'] = $locale;
                $input['quality'] = ['level' => 'A'];
                foreach (['O' => 90, 'C' => 80, 'E' => 90, 'A' => 50, 'N' => $n] as $trait => $percentile) {
                    $input['score_vector']['domains'][$trait]['percentile'] = $percentile;
                    $input['score_vector']['domains'][$trait]['band'] = $percentile >= 70 ? 'high' : ($percentile <= 30 ? 'low' : 'mid');
                }
                $engine = app(BigFiveReportEngine::class)->generateWithRegistry($input, $oldRegistry);
                $report = ['scale_code' => 'BIG5_OCEAN', 'locale' => $locale, 'sections' => $engine['sections'],
                    '_meta' => ['big5_private_result_authority' => $loaded['authority'], 'big5_report_engine_v2' => $engine]];
                $original = json_encode($report, JSON_THROW_ON_ERROR);
                $view = app(BigFiveReadingCopyProjection::class)->project($report);
                $this->assertSame('ready', $view['status'], $locale.'.'.$n);
                $this->assertSame([], $this->oldPointers($view['report'], array_column($actionRows, 'before')));
                $this->assertSame($engine['score_vector'], $view['report']['_meta']['big5_report_engine_v2']['score_vector']);
                $this->assertSame($engine['engine_decisions'], $view['report']['_meta']['big5_report_engine_v2']['engine_decisions']);
                foreach ($actionRows as $row) {
                    if ($this->oldPointers($engine['sections'], [$row['before']]) !== []) {
                        $covered[$locale.'.'.implode('.', $row['registry_path'])] = true;
                        $this->assertNotEmpty($this->oldPointers($engine['action_matrix'], [$row['before']]));
                    }
                }
                $html = app(BigFiveReportPdfRenderer::class)->html($view['report'], $locale, false);
                foreach ($actionRows as $row) {
                    $this->assertStringNotContainsString($row['before'], $html);
                }
                $this->assertSame($original, json_encode($report, JSON_THROW_ON_ERROR));
                $this->assertNull(app(BigFiveReadingCopyProjection::class)->project($view['report']));
            }
            // The current resolver has no stress-recovery continue rule, so that template is not emitted.
            $this->assertSame([], array_values(array_filter($oldRegistry['action_rules']['stress_recovery']['rules'], static fn ($rule) => $rule['bucket'] === 'continue')));
        }
        $this->assertCount(15, $covered);
    }
}
