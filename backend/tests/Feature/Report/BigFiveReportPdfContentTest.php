<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Attempt;
use App\Services\Report\Pdf\BigFiveReportPdfRenderer;
use App\Services\Report\Pdf\ReportPdfArtifactStore;
use App\Services\Report\Pdf\ReportPdfDocumentService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class BigFiveReportPdfContentTest extends TestCase
{
    private function report(): array
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/big5_engine/expected_live_bridge_report_payload.json')), true, 512, JSON_THROW_ON_ERROR);

        return ['sections' => $fixture['big5_report_engine_v2']['sections'], 'norms' => ['comparison_allowed' => true]];
    }

    public function test_full_pdf_contains_real_report_copy_and_excludes_internal_fields(): void
    {
        $report = $this->report();
        $report['sections'][0]['blocks'][0]['provenance'] = ['secret' => 'PRIVATE_PROVENANCE'];
        $report['sections'][0]['blocks'][0]['resolved_copy']['title'] = '<script>unsafe()</script>';
        $html = app(BigFiveReportPdfRenderer::class)->html($report, 'zh-CN', false);

        foreach ($report['sections'] as $section) {
            foreach ($section['blocks'] as $block) {
                $copy = $block['resolved_copy'];
                foreach (['body', 'body_core', 'definition', 'relative_meaning', 'access_note'] as $field) {
                    if (isset($copy[$field])) {
                        $this->assertStringContainsString(htmlspecialchars($copy[$field], ENT_QUOTES, 'UTF-8'), $html);
                    }
                }
            }
        }
        $this->assertStringContainsString('五维总览', $html);
        $this->assertStringContainsString('方法与权限', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('PRIVATE_PROVENANCE', $html);
        $this->assertStringNotContainsString('attempt_live_bridge_fixture', $html);
    }

    public function test_locked_pdf_cannot_export_full_engine_or_paid_sections(): void
    {
        $report = $this->report();
        $report['_meta']['big5_report_engine_v2']['sections'][0]['blocks'][0]['resolved_copy']['body'] = 'META_FULL_SECRET';
        $report['sections'][2]['blocks'][0]['resolved_copy']['body'] = 'PAID_SECTION_SECRET';
        $html = app(BigFiveReportPdfRenderer::class)->html($report, 'en', true);

        $this->assertStringContainsString('Result summary', $html);
        $this->assertStringContainsString('Methodology and access', $html);
        $this->assertStringNotContainsString('PAID_SECTION_SECRET', $html);
        $this->assertStringNotContainsString('META_FULL_SECRET', $html);
        $this->assertStringNotContainsString('Percentile:', $html);

        $report['sections'][0]['status'] = 'locked';
        $report['sections'][0]['blocks'][0]['resolved_copy']['body'] = 'LOCKED_BLOCK_SECRET';
        $this->assertStringNotContainsString('LOCKED_BLOCK_SECRET', app(BigFiveReportPdfRenderer::class)->html($report, 'en', false));
    }

    public function test_immutable_legacy_snapshot_remains_readable_without_recomposing_it(): void
    {
        $report = ['sections' => [
            ['key' => 'summary', 'title' => '原报告摘要', 'access_level' => 'free', 'blocks' => [
                ['title' => '原标题', 'body' => '原解释正文', 'bullets' => ['原建议'], 'provenance' => 'PRIVATE_LEGACY_ID'],
            ]],
            ['key' => 'facets_deepdive', 'title' => '原付费章节', 'access_level' => 'paid', 'blocks' => [
                ['body' => 'LEGACY_PAID_CONTENT'],
            ]],
        ]];
        $renderer = app(BigFiveReportPdfRenderer::class);
        $html = $renderer->html($report, 'zh-CN', false);
        foreach (['原报告摘要', '原标题', '原解释正文', '原建议', 'LEGACY_PAID_CONTENT'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('PRIVATE_LEGACY_ID', $html);
        $this->assertStringNotContainsString('LEGACY_PAID_CONTENT', $renderer->html($report, 'zh-CN', true));
    }

    public function test_metadata_only_cache_is_replaced_by_multipage_pdf_with_embedded_fonts(): void
    {
        Storage::fake('local');
        $attempt = new Attempt;
        $attempt->forceFill([
            'id' => 'big5_pdf_reader_fixture', 'scale_code' => 'BIG5_OCEAN', 'locale' => 'zh-CN',
            'answers_summary_json' => ['meta' => ['pack_release_manifest_hash' => 'original-manifest']],
        ]);
        $store = app(ReportPdfArtifactStore::class);
        $oldPath = $store->path('BIG5_OCEAN', $attempt->id, 'original-manifest', 'full');
        $store->put($oldPath, '%PDF-1.4 OLD_METADATA_ONLY');
        $service = app(ReportPdfDocumentService::class);
        $gate = ['variant' => 'full', 'locked' => false, 'report' => $this->report()];
        $generated = $service->getOrGenerate($attempt, $gate);

        $this->assertFalse($generated['cached']);
        $this->assertNotSame($oldPath, $generated['storage_path']);
        $this->assertStringContainsString(BigFiveReportPdfRenderer::SURFACE_VERSION, $generated['manifest_hash']);
        $this->assertStringStartsWith('%PDF-', $generated['binary']);
        $this->assertStringContainsString('/FontFile2', $generated['binary']);
        $this->assertMatchesRegularExpression('/\/Type\s*\/Pages\s*\n\/Kids.*?\/Count\s+(?:[2-9]|\d{2,})/s', $generated['binary']);
        $this->assertStringNotContainsString('OLD_METADATA_ONLY', $generated['binary']);
        $this->assertSame([], $store->legacyPaths('BIG5_OCEAN', $attempt->id, 'original-manifest', 'full'));
        $this->assertTrue($service->getOrGenerate($attempt, $gate)['cached']);
        $this->assertSame($generated['binary'], $service->readArtifact($attempt, 'full'));
    }
}
