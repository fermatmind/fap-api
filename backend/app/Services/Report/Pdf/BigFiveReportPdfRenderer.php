<?php

declare(strict_types=1);

namespace App\Services\Report\Pdf;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;

final class BigFiveReportPdfRenderer
{
    public const SURFACE_VERSION = 'big5.private_result_pdf.v2';

    private const SECTIONS = [
        'hero_summary' => ['结果摘要', 'Result summary'],
        'domains_overview' => ['五维总览', 'Five-domain overview'],
        'domain_deep_dive' => ['维度详情', 'Domain details'],
        'facet_details' => ['细分维度', 'Facet details'],
        'core_portrait' => ['画像结构', 'Core portrait'],
        'norms_comparison' => ['常模参照', 'Norm reference'],
        'action_plan' => ['行动', 'Actions'],
        'methodology_and_access' => ['方法与权限', 'Methodology and access'],
    ];

    private const DOMAINS = [
        'O' => ['开放性', 'Openness'], 'C' => ['尽责性', 'Conscientiousness'],
        'E' => ['外向性', 'Extraversion'], 'A' => ['宜人性', 'Agreeableness'],
        'N' => ['情绪性', 'Neuroticism'],
    ];

    public function render(array $report, string $locale, bool $locked): string
    {
        $html = $this->html($report, $locale, $locked);
        $tempDir = storage_path('framework/cache/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }
        $pdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $tempDir,
            'autoScriptToLang' => true, 'autoLangToFont' => true,
            'margin_left' => 18, 'margin_right' => 18, 'margin_top' => 18, 'margin_bottom' => 18,
        ]);
        $pdf->SetTitle(str_starts_with($locale, 'zh') ? '大五人格报告' : 'Big Five Report');
        $pdf->SetAuthor('FermatMind');
        $pdf->SetHTMLFooter('<div style="text-align:right;color:#64748b;font-size:9pt">FermatMind · {PAGENO} / {nbpg}</div>');
        $pdf->WriteHTML($html);

        return $pdf->Output('', Destination::STRING_RETURN);
    }

    public function html(array $report, string $locale, bool $locked): string
    {
        $language = str_starts_with(strtolower($locale), 'zh') ? 0 : 1;
        $precise = ! $locked && ($report['norms']['comparison_allowed'] ?? false) === true;
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'body{font-family:sans-serif;color:#172033;font-size:11pt;line-height:1.6}'
            .'h1{font-size:25pt;color:#123d34}h2{font-size:18pt;border-bottom:2px solid #d9eadf;padding-bottom:8px;page-break-after:avoid}'
            .'h3{font-size:12pt;margin:12px 0 6px;page-break-after:avoid}p{margin:0 0 9px}li{margin:4px 0}'
            .'.chapter{page-break-before:always}.block{margin-bottom:14px}.metric{color:#475569}'
            .'</style></head><body><p>FermatMind · 费马测试</p><h1>'
            .($language === 0 ? '大五人格报告' : 'Big Five Report').'</h1>';
        $count = 0;
        // Only the gate-selected sections are reader content. The _meta engine may contain full, locked material.
        foreach ((array) ($report['sections'] ?? []) as $section) {
            $canonical = isset($section['section_key']);
            $key = $section['section_key'] ?? $section['key'] ?? '';
            $title = self::SECTIONS[$key][$language] ?? $section['title'] ?? '';
            if (! is_string($title) || trim($title) === '' || ($section['status'] ?? '') === 'locked'
                || ($locked && ($canonical
                    ? ! in_array($key, ['hero_summary', 'domains_overview', 'methodology_and_access'], true)
                    : ($section['access_level'] ?? 'free') === 'paid'))) {
                continue;
            }
            $html .= '<section'.($count++ > 0 ? ' class="chapter"' : '').'><h2>'.$this->escape($title).'</h2>';
            foreach ((array) ($section['blocks'] ?? []) as $block) {
                $copy = is_array($block['resolved_copy'] ?? null) ? $block['resolved_copy'] : ($canonical ? [] : $block);
                $trait = (string) data_get($block, 'analytics.trait_code', '');
                $html .= '<div class="block">';
                if (($block['kind'] ?? '') === 'trait_atomic' && isset(self::DOMAINS[$trait])) {
                    $html .= '<h3>'.self::DOMAINS[$trait][$language].'</h3>';
                    $percentile = data_get($block, 'analytics.percentile');
                    if ($precise && is_numeric($percentile)) {
                        $html .= '<p class="metric">'.($language === 0 ? '百分位：' : 'Percentile: ').$this->escape((string) $percentile).'</p>';
                    }
                }
                $html .= $this->copyHtml($copy).'</div>';
            }
            $html .= '</section>';
        }
        if ($count === 0) {
            throw new RuntimeException('Big Five report has no readable canonical sections.');
        }

        return $html.'</body></html>';
    }

    private function copyHtml(array $copy): string
    {
        $html = '';
        if (($copy['comparison_allowed'] ?? false) === true) {
            foreach (['sample_label', 'match_label', 'norm_version', 'sample_n'] as $field) {
                $value = $copy[$field] ?? '';
                if ((is_string($value) || is_int($value)) && (string) $value !== '') {
                    $html .= '<p>'.$this->escape((string) $value).'</p>';
                }
            }
        }
        // Explicit display fields, matching the web report; never dump provenance, analytics, IDs or raw payloads.
        foreach (['label', 'title', 'label_zh', 'headline', 'identity', 'status_label'] as $field) {
            if (is_string($copy[$field] ?? null) && trim($copy[$field]) !== '') {
                $html .= '<h3>'.$this->escape($copy[$field]).'</h3>';
            }
        }
        foreach (['body', 'body_core', 'snapshot_line', 'definition', 'gloss', 'default_style', 'relative_meaning',
            'priority_hint', 'mechanism', 'tradeoffs', 'context_boundary', 'action_bridge', 'why', 'why_it_matters',
            'daily_meaning', 'daily_life', 'access_note', 'strength_sentence', 'risk_sentence', 'action_hook',
            'why_priority', 'why_recommended', 'completion_signal', 'time_horizon_label', 'difficulty_label',
            'interpretation_qualifier', 'percentile_explanation', 'unavailable_explanation'] as $field) {
            if (is_string($copy[$field] ?? null) && trim($copy[$field]) !== '') {
                $html .= '<p>'.nl2br($this->escape($copy[$field])).'</p>';
            }
        }
        foreach (['strengths', 'costs', 'items', 'bullets', 'tips', 'tags'] as $field) {
            $value = $copy[$field] ?? [];
            if (is_string($value)) {
                $html .= '<p>'.$this->escape($value).'</p>';
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if (is_string($item)) {
                        $html .= '<p>• '.$this->escape($item).'</p>';
                    } elseif (is_array($item)) {
                        $html .= '<div class="block">'.$this->copyHtml($item).'</div>';
                    }
                }
            }
        }

        return $html;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
