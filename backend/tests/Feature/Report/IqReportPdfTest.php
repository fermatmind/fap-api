<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\IqReportBuilder;
use App\Services\Report\Pdf\IqReportPdfProjection;
use App\Services\Report\Pdf\IqReportPdfRenderer;
use App\Services\Report\Pdf\ReportPdfDocumentService;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IqReportPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_bilingual_full_projection_and_real_renderer_keep_counts_time_and_reference_distinct(): void
    {
        foreach (['zh-CN', 'en'] as $locale) {
            [$attempt, $result, $gate] = $this->fixture($locale);
            $view = app(IqReportPdfProjection::class)->project($attempt, $result, $gate);
            $this->assertSame(21, $view['correct']);
            $this->assertSame(70.0, $view['percent']);
            $this->assertSame(130123.0, $view['duration_ms']);
            $this->assertSame([8, 6, 7], array_column($view['dimensions'], 'correct'));
            $this->assertSame('beta_reference', $view['reference']['kind']);
            $this->assertSame(132, $view['reference']['score']);
            $renderer = app(IqReportPdfRenderer::class);
            $html = $renderer->html($view);
            foreach (['21 / 30', '70%', '02:10', '8 / 10', '6 / 10', '7 / 10', '73-145', 'owner30_design_anchors_2026_10_14_v1'] as $text) {
                $this->assertStringContainsString($text, $html);
            }
            $this->assertStringNotContainsString('SYNTHETIC_PRIVATE_FIELD', $html);
            $this->assertStringNotContainsString((string) $attempt->id, $html);
            if ($locale === 'en') {
                $this->assertDoesNotMatchRegularExpression('/[\x{3400}-\x{9fff}]/u', $html);
            }
            $this->assertStringStartsWith('%PDF-', $renderer->render($view));
        }
    }

    public function test_missing_reference_does_not_reinterpret_legacy_scores_or_unknowns_as_zero(): void
    {
        [$a, $r, $g] = $this->fixture('en', 'missing');
        $raw = $r->result_json;
        $raw['normed_json']['iq_estimate'] = 999;
        $raw['normed_json']['duration_ms'] = null;
        $r->result_json = $raw;
        $g['report']['quality'] = [];
        $v = app(IqReportPdfProjection::class)->project($a, $r, $g);
        $this->assertNull($v['reference']);
        $this->assertNull($v['duration_ms']);
        $this->assertNull($v['quality']);
        $html = app(IqReportPdfRenderer::class)->html($v);
        $this->assertStringContainsString('An eligible reference score was not provided', $html);
        $this->assertStringContainsString('Logic / verbal', $html);
        $this->assertStringNotContainsString('999', $html);
        $this->assertStringNotContainsString('Display range:', $html);
    }

    public function test_normative_snapshot_is_atomic_and_rare_percentiles_keep_the_zero_to_hundred_scale(): void
    {
        [$a, $r, $g] = $this->fixture('en', 'normative');
        $projection = app(IqReportPdfProjection::class);
        $v = $projection->project($a, $r, $g);
        $this->assertSame('normative_reasoning', $v['reference']['kind']);
        $this->assertSame(0.5, $v['reference']['percentile']);
        $html = app(IqReportPdfRenderer::class)->html($v);
        $this->assertStringContainsString('Reference-sample percentile: 0.5%', $html);
        $this->assertStringContainsString('55-145', $html);
        $g['report']['summary']['normative_reasoning'] = null;
        $this->assertNull($projection->project($a, $r, $g)['reference']);
        unset($g['report']['summary']['normative_reasoning']);
        $raw = $r->result_json;
        unset($raw['normed_json']['normative_reasoning']['reference_population']['label_en']);
        $r->result_json = $raw;
        $this->assertNull($projection->project($a, $r, $g)['reference']);
    }

    public function test_zero_correct_is_valid_and_is_not_confused_with_unmeasured_dimensions(): void
    {
        [$a, $r, $g] = $this->fixture('en');
        $raw = $r->result_json;
        $raw['normed_json']['raw_score'] = 0;
        foreach ($raw['normed_json']['dimension_scores'] as &$row) {
            $row['correct_count'] = $row['raw_score'] = 0;
        }
        unset($row);
        $r->result_json = $raw;
        $g['report'] = app(IqReportBuilder::class)->build($a, $r, 'full');
        $v = app(IqReportPdfProjection::class)->project($a, $r, $g);
        $this->assertSame(0, $v['correct']);
        $this->assertSame(0.0, $v['percent']);
        $this->assertSame(73, $v['reference']['score']);
        $html = app(IqReportPdfRenderer::class)->html($v);
        $rare = $v;
        $rare['reference']['percentile'] = 0.001;
        $this->assertStringContainsString('&lt;0.01%', app(IqReportPdfRenderer::class)->html($rare));
        $this->assertStringNotContainsString('Model-reference percentile: 0.00%', $html);
    }

    public function test_locked_partial_pending_wrong_bank_and_invalid_counts_fail_closed_before_cache(): void
    {
        [$a, $r, $g] = $this->fixture('en');
        $service = app(ReportPdfDocumentService::class);
        Storage::fake('local');
        $generated = $service->getOrGenerate($a, $g, $r);
        $this->assertTrue(Storage::disk('local')->exists($generated['storage_path']));
        $cases = [
            ['locked' => true], ['variant' => 'partial'], ['modules_allowed' => ['iq_core']],
            ['generating' => true], ['snapshot_error' => true],
            ['meta' => ['generating' => true]], ['meta' => ['snapshot_error' => true]],
        ];
        foreach ($cases as $patch) {
            try {
                $service->getOrGenerate($a, array_replace($g, $patch), $r);
                $this->fail('Rejected gate reached cached PDF.');
            } catch (ApiProblemException $e) {
                $this->assertSame(409, $e->getStatusCode());
                $this->assertSame('IQ_PDF_NOT_AVAILABLE', $e->errorCode());
            }
        }
        foreach (['status' => 'blocked_unscored', 'bank_id' => 'IQ_BETA_50_ORIGINAL', 'raw_score' => 31, 'expected_item_count' => 12, 'technical_failure' => true] as $key => $value) {
            $json = $r->result_json;
            $json['normed_json'][$key] = $value;
            $bad = new Result(['result_json' => $json]);
            try {
                $service->getOrGenerate($a, $g, $bad);
                $this->fail('Invalid source was accepted: '.$key);
            } catch (ApiProblemException $e) {
                $this->assertSame('IQ_PDF_NOT_AVAILABLE', $e->errorCode());
            }
        }
    }

    public function test_legacy_cache_is_ignored_and_current_source_changes_invalidate_the_pdf_key(): void
    {
        [$a, $r, $g] = $this->fixture('en');
        Storage::fake('local');
        Storage::disk('local')->put('artifacts/pdf/IQ_RAVEN/'.$a->id.'/nohash/report_full.pdf', 'OLD_METADATA_ONLY');
        $s = app(ReportPdfDocumentService::class);
        $first = $s->getOrGenerate($a, $g, $r);
        $this->assertFalse($first['cached']);
        $this->assertStringStartsWith('%PDF-', $first['binary']);
        $this->assertTrue($s->getOrGenerate($a, $g, $r)['cached']);
        $this->assertNull($s->readArtifact($a, 'full', $r));
        $json = $r->result_json;
        $json['normed_json']['duration_ms']++;
        $r->result_json = $json;
        $changed = $s->getOrGenerate($a, $g, $r);
        $this->assertFalse($changed['cached']);
        $this->assertNotSame($first['manifest_hash'], $changed['manifest_hash']);
        $this->assertNotSame($first['storage_path'], $changed['storage_path']);
        $g['report']['quality']['level'] = 'B';
        $reportRevision = $s->getOrGenerate($a, $g, $r);
        $this->assertFalse($reportRevision['cached']);
        $this->assertNotSame($changed['manifest_hash'], $reportRevision['manifest_hash']);
    }

    public function test_owner_http_pdf_and_readiness_work_but_other_owner_and_anonymous_do_not(): void
    {
        [$a, $r] = $this->fixture('en', persist: true);
        $headers = $this->ownerHeaders((string) $a->anon_id);
        $this->withHeaders($headers)->getJson('/api/v0.3/attempts/'.$a->id.'/report-access')
            ->assertOk()->assertJsonPath('pdf_state', 'ready');
        $response = $this->withHeaders($headers)->get('/api/v0.3/attempts/'.$a->id.'/report.pdf');
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Pdf-Surface-Version', IqReportPdfRenderer::SURFACE_VERSION);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString((string) $a->id, $response->headers->get('Content-Disposition'));
        $this->withHeaders($this->ownerHeaders('synthetic-other-owner'))->get('/api/v0.3/attempts/'.$a->id.'/report.pdf')->assertNotFound();
        $this->flushHeaders()->get('/api/v0.3/attempts/'.$a->id.'/report.pdf')->assertNotFound();
    }

    public function test_canonical_iq_identity_retains_the_owner_pdf_route_and_safe_filename(): void
    {
        [$a, $r] = $this->fixture('zh-CN', persist: true);
        $a->scale_code = $r->scale_code = 'IQ_INTELLIGENCE_QUOTIENT';
        $a->save();
        $r->save();
        $response = $this->withHeaders($this->ownerHeaders((string) $a->anon_id))
            ->get('/api/v0.3/attempts/'.$a->id.'/report.pdf');
        $response->assertOk()->assertHeader('X-Pdf-Surface-Version', IqReportPdfRenderer::SURFACE_VERSION);
        $this->assertStringContainsString('fermatmind-iq-original30-zh.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_generation_failure_is_private_sanitized_and_does_not_write_an_artifact(): void
    {
        [$a, $r] = $this->fixture('en', persist: true);
        Storage::fake('local');
        $this->mock(IqReportPdfRenderer::class)->shouldReceive('render')->once()->andThrow(new \RuntimeException('SYNTHETIC_PRIVATE_FIELD'));
        $response = $this->withHeaders($this->ownerHeaders((string) $a->anon_id))->get('/api/v0.3/attempts/'.$a->id.'/report.pdf');
        $response->assertStatus(503)->assertJsonPath('error_code', 'IQ_PDF_GENERATION_FAILED');
        $this->assertStringNotContainsString('SYNTHETIC_PRIVATE_FIELD', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame([], Storage::disk('local')->allFiles('artifacts/pdf'));
    }

    public function test_blocked_http_report_cannot_reach_a_pdf_and_does_not_advertise_readiness(): void
    {
        [$a, $r] = $this->fixture('en', persist: true);
        $json = $r->result_json;
        $json['normed_json']['status'] = 'blocked_unscored';
        $r->result_json = $json;
        $r->save();
        $headers = $this->ownerHeaders((string) $a->anon_id);
        $this->withHeaders($headers)->getJson('/api/v0.3/attempts/'.$a->id.'/report-access')
            ->assertOk()->assertJsonPath('pdf_state', 'missing');
        $response = $this->withHeaders($headers)->get('/api/v0.3/attempts/'.$a->id.'/report.pdf');
        $response->assertStatus(409)->assertJsonPath('error_code', 'IQ_PDF_NOT_AVAILABLE');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_mismatched_locale_null_category_and_changed_category_snapshot_fail_closed(): void
    {
        [$a, $r, $g] = $this->fixture('en');
        $projection = app(IqReportPdfProjection::class);
        $cases = [];
        $wrongLocale = $g;
        $wrongLocale['report']['locale'] = 'zh-CN';
        $cases[] = $wrongLocale;
        $nullCount = $g;
        $nullCount['report']['dimensions']['visual_spatial_insight']['correct_count'] = null;
        $nullCount['report']['dimensions']['visual_spatial_insight']['raw_score'] = 6;
        $cases[] = $nullCount;
        $stale = $g;
        $stale['report']['dimensions']['visual_spatial_insight']['correct_count'] = 7;
        $stale['report']['dimensions']['visual_spatial_pattern_reasoning']['correct_count'] = 7;
        $cases[] = $stale;
        foreach ($cases as $gate) {
            try {
                $projection->project($a, $r, $gate);
                $this->fail('A mismatched snapshot reached a complete PDF.');
            } catch (ApiProblemException $e) {
                $this->assertSame('IQ_PDF_NOT_AVAILABLE', $e->errorCode());
            }
        }
    }

    public function test_extreme_numeric_sources_cannot_overflow_to_a_valid_zero_or_a_reversed_reference(): void
    {
        [$a, $r, $g] = $this->fixture('en', 'normative');
        $raw = $r->result_json;
        $raw['normed_json']['duration_ms'] = 1.0e300;
        $raw['normed_json']['normative_reasoning']['standard_score'] = 1.0e300;
        $r->result_json = $raw;
        $projection = app(IqReportPdfProjection::class);
        $v = $projection->project($a, $r, $g);
        $this->assertNull($v['duration_ms']);
        $this->assertSame(145, $v['reference']['score']);
        $raw['normed_json']['raw_score'] = 1.0e300;
        $r->result_json = $raw;
        $g['report']['summary']['raw_score'] = 1.0e300;
        $this->expectException(ApiProblemException::class);
        $projection->project($a, $r, $g);
    }

    public function test_iq_versioned_paths_are_in_the_existing_purge_descriptor_and_isolate_other_attempts(): void
    {
        [$a, $r, $g] = $this->fixture('en');
        Storage::fake('local');
        $pdf = app(ReportPdfDocumentService::class);
        $first = $pdf->getOrGenerate($a, $g, $r);
        $g['report']['quality']['level'] = 'B';
        $next = $pdf->getOrGenerate($a, $g, $r);
        $other = str_replace((string) $a->id, (string) Str::uuid(), $next['storage_path']);
        Storage::disk('local')->put($other, 'OTHER_ATTEMPT');
        $unrelated = dirname($next['storage_path']).'/unrelated.txt';
        Storage::disk('local')->put($unrelated, 'UNRELATED');
        $descriptor = app(\App\Services\Storage\ArtifactPurgeService::class)->describeAttemptArtifacts($a, $r);
        $paths = array_values($descriptor['canonical_paths']);
        $this->assertContains($first['storage_path'], $paths);
        $this->assertContains($next['storage_path'], $paths);
        $this->assertNotContains($other, $paths);
        $this->assertNotContains($unrelated, $paths);
        try {
            $pdf->resolveArtifactPath($a, 'full', $r);
            $this->fail('An unqualified path prediction was allowed.');
        } catch (\LogicException) {
            $this->assertNull($pdf->readArtifact($a, 'full', $r));
        }
    }

    private function fixture(string $locale, string $reference = 'beta', bool $persist = false): array
    {
        if ($persist) {
            config()->set('fap.features.report_snapshot_strict_v2', false);
            config()->set('fap.free_full_report_assessments', ['IQ_RAVEN']);
            (new ScaleRegistrySeeder)->run();
        }
        $a = new Attempt(['id' => (string) Str::uuid(), 'org_id' => 0, 'anon_id' => 'synthetic-iq-pdf-owner',
            'scale_code' => 'IQ_RAVEN', 'scale_version' => 'v0.3', 'locale' => $locale, 'region' => 'CN_MAINLAND',
            'question_count' => 30, 'client_platform' => 'synthetic-local-only', 'answers_summary_json' => [],
            'pack_id' => 'IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO', 'dir_version' => 'IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO',
            'content_package_version' => 'v0.3.0-demo', 'scoring_spec_version' => '2026.03',
            'started_at' => now()->subMinutes(3), 'submitted_at' => now()]);
        $score = ['status' => 'scored', 'bank_id' => IqReportPdfProjection::BANK, 'raw_score' => 21,
            'expected_item_count' => 30, 'answer_count' => 30, 'duration_ms' => 130123,
            'scoring_engine_version' => 'iq_scoring_v2', 'quality' => ['level' => 'A', 'flags' => []],
            'result_stability' => ['status' => 'stable'],
            'norms' => ['status' => 'unavailable_without_norm_table'],
            'dimension_scores' => ['VSPR' => ['item_count' => 10, 'correct_count' => 8],
                'VSI' => ['item_count' => 10, 'correct_count' => 6], 'NPR' => ['item_count' => 10, 'correct_count' => 7]],
            'answer_key' => ['SYNTHETIC_ONLY' => 'SYNTHETIC_PRIVATE_FIELD']];
        if ($reference !== 'beta') {
            $score['beta_reference'] = null;
        }
        if ($reference === 'normative') {
            $score['normative_reasoning'] = ['status' => 'available', 'eligible' => true,
                'standard_score' => 118.3, 'percentile' => 0.5, 'norm_table_version' => 'synthetic-norm-v1',
                'model_version' => 'synthetic-model-v1', 'reference_population' => ['label_en' => 'Synthetic reference fixture only', 'label_zh' => '仅合成参照夹具']];
        }
        $r = new Result(['id' => (string) Str::uuid(), 'org_id' => 0, 'attempt_id' => $a->id,
            'scale_code' => 'IQ_RAVEN', 'scale_version' => 'v0.3', 'type_code' => '',
            'scores_json' => [], 'scores_pct' => [], 'axis_states' => [], 'result_json' => ['normed_json' => $score],
            'content_package_version' => 'v0.3.0-demo', 'pack_id' => $a->pack_id, 'dir_version' => $a->dir_version,
            'scoring_spec_version' => '2026.03', 'report_engine_version' => 'v1.2', 'is_valid' => true, 'computed_at' => now()]);
        if ($persist) {
            $a->save();
            $r->save();
        }

        return [$a, $r, ['ok' => true, 'locked' => false, 'variant' => 'full', 'modules_allowed' => ['iq_core', 'iq_full'],
            'report' => app(IqReportBuilder::class)->build($a, $r, 'full')]];
    }

    private function ownerHeaders(string $anon): array
    {
        $token = 'fm_'.Str::uuid();
        DB::table('fm_tokens')->insert(['token' => $token, 'token_hash' => hash('sha256', $token), 'anon_id' => $anon,
            'user_id' => null, 'org_id' => 0, 'role' => 'public', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        return ['Authorization' => 'Bearer '.$token, 'X-Anon-Id' => $anon];
    }
}
