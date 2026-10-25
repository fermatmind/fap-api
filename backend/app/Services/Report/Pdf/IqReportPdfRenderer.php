<?php

declare(strict_types=1);

namespace App\Services\Report\Pdf;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class IqReportPdfRenderer
{
    public const SURFACE_VERSION = 'iq.original30.full_pdf.v1';

    public function render(array $view): string
    {
        $dir = storage_path('framework/cache/mpdf');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $dir,
            'autoScriptToLang' => true, 'autoLangToFont' => true,
            'default_font' => $view['locale'] === 'zh-CN' ? 'sun-exta' : 'dejavusans',
            'margin_left' => 18, 'margin_right' => 18, 'margin_top' => 18, 'margin_bottom' => 18]);
        $pdf->SetTitle($view['locale'] === 'en' ? 'Original 30-item matrix reasoning report' : '原创30题矩阵推理报告');
        $pdf->SetAuthor('FermatMind');
        $pdf->SetHTMLFooter('<div style="text-align:right;color:#64748b;font-size:9pt">FermatMind · {PAGENO} / {nbpg}</div>');
        $pdf->WriteHTML($this->html($view));

        return $pdf->Output('', Destination::STRING_RETURN);
    }

    public function html(array $v): string
    {
        $zh = $v['locale'] === 'zh-CN';
        $l = static fn (string $a, string $b): string => $zh ? $a : $b;
        $unknown = $l('未提供', 'Not provided');
        $duration = $v['duration_ms'] === null ? $unknown : sprintf('%02d:%02d', (int) floor($v['duration_ms'] / 60000), (int) floor($v['duration_ms'] / 1000) % 60);
        $h = '<!doctype html><html lang="'.$v['locale'].'"><head><meta charset="utf-8"><style>body{font-family:'.($zh ? 'sun-exta' : 'dejavusans').';color:#172b29;font-size:11pt;line-height:1.55}h1{font-size:25pt;color:#145344}h2{font-size:17pt;border-bottom:1px solid #bed8cf;padding-bottom:7px;page-break-after:avoid}h3{font-size:12pt;page-break-after:avoid}.note{background:#eff6f2;padding:12px}.metric{font-size:19pt;color:#145344}table{width:100%;border-collapse:collapse;margin:12px 0}td,th{padding:9px;border-bottom:1px solid #d5e3df;text-align:left}p{margin:8px 0}tr{page-break-inside:avoid}</style></head><body><p>FermatMind</p><h1>'
            .$l('原创30题矩阵推理报告', 'Original 30-item matrix reasoning report').'</h1><h2>'.$l('本题本表现', 'Performance on this form').'</h2>'
            .'<p class="metric">'.$v['correct'].' / 30 · '.$this->escape($this->percent($v['percent'])).'</p><p>'.$l('实际用时（分:秒）：', 'Elapsed time (mm:ss): ').$duration.'</p>'
            .'<p class="note">'.$l('正确率描述本次题目表现，不代表人群排名。逻辑与言语未独立测量；未测不等于0分。用时仅在返回时显示，不是强制时限。', 'Accuracy describes these items, not population rank. Logic and verbal ability were not separately measured; unmeasured is not zero. Elapsed time is shown only when supplied and is not a time limit.').'</p>'
            .'<h2>'.$l('已测类别的正确率', 'Accuracy in the measured categories').'</h2><p>'.$l('图表范围：0-100%；不是能力百分位。', 'Chart range: 0-100% accuracy; not ability percentiles.').'</p>';
        foreach ($v['dimensions'] as $row) {
            $width = $row['percent'] * 4;
            $h .= '<p><b>'.$this->escape($row['label']).'</b> · '.$row['correct'].'/'.$row['total'].' · '.$this->escape($this->percent($row['percent'])).'</p>'
                .'<svg width="450" height="22" viewBox="0 0 450 22"><rect x="0" y="3" width="400" height="13" fill="#e6eeeb"/><rect x="0" y="3" width="'.$width.'" height="13" fill="#247e65"/></svg>';
        }
        $h .= '<table><thead><tr><th>'.$l('类别', 'Category').'</th><th>'.$l('正确题数 / 题数', 'Correct / items').'</th><th>'.$l('正确率', 'Accuracy').'</th></tr></thead><tbody>';
        foreach ($v['dimensions'] as $row) {
            $h .= '<tr><td>'.$this->escape($row['label']).'</td><td>'.$row['correct'].' / '.$row['total'].'</td><td>'.$this->escape($this->percent($row['percent'])).'</td></tr>';
        }
        $h .= '<tr><td>'.$l('逻辑 / 言语', 'Logic / verbal').'</td><td colspan="2">'.$l('未独立测量', 'Not separately measured').'</td></tr></tbody></table><pagebreak /><h2>'.$l('参照与适用范围', 'Reference and scope').'</h2>';
        $r = $v['reference'];
        if ($r === null) {
            $h .= '<p class="note">'.$l('未提供合格参照分；有效正确数和本题本表现仍可阅读。缺失参照不表示零分。', 'An eligible reference score was not provided. Valid correct-answer counts and form-specific performance remain readable. Missing references do not mean zero.').'</p>';
        } else {
            $beta = $r['kind'] === 'beta_reference';
            $title = $beta ? $l('Beta参考分', 'Beta reference score') : $l('推理标准分', 'Reasoning standard score');
            $h .= '<h3>'.$title.'</h3><p class="metric">'.$r['score'].'</p>'
                .'<p>'.($beta ? $l('模型参考百分位：', 'Model-reference percentile: ') : $l('参考样本百分位：', 'Reference-sample percentile: '))
                .($beta && $r['percentile'] >= 0.01 && $r['percentile'] <= 99.99 ? number_format($r['percentile'], 2, '.', '').'%' : $this->escape($this->percent($r['percentile']))).'</p>'
                .'<p>'.$l('显示范围：', 'Display range: ').$r['minimum'].'-'.$r['maximum'].'</p>'
                .'<svg width="450" height="32" viewBox="0 0 450 32"><rect x="0" y="10" width="400" height="12" fill="#e6eeeb"/><rect x="'.(($r['score'] - $r['minimum']) / ($r['maximum'] - $r['minimum']) * 394).'" y="4" width="6" height="24" fill="#247e65"/></svg>'
                .'<p>'.$l('参照来源 / 版本：', 'Reference source / versions: ').$this->escape($r['source'].' / '.$r['model_version'].($r['norm_version'] ? ' / '.$r['norm_version'] : '')).'</p>';
            if ($r['population'] !== null) {
                $h .= '<p>'.$l('参考人群：', 'Reference population: ').$this->escape($r['population']).'</p>';
            }
        }
        $h .= '<h2>'.$l('作答质量与未知项', 'Response quality and unknowns').'</h2><p>'.$l('作答质量：', 'Response quality: ').$this->escape($v['quality'] ?? $unknown).'</p>';
        foreach ($v['quality_flags'] as $flag) {
            $h .= '<p>'.$this->escape($flag).'</p>';
        }
        if ($v['other_quality_flags'] > 0) {
            $h .= '<p>'.$l('未解释的质量标记：', 'Uninterpreted quality flags: ').$v['other_quality_flags'].'</p>';
        }
        $h .= '<p>'.$l('稳定性状态：', 'Stability status: ').$this->escape($v['stability'] ?? $unknown).'</p><p>'.$l('计分版本：', 'Scoring version: ').$this->escape($v['scoring_version'] ?? $unknown).'</p>'
            .'<p>'.$l('质量说明缺失不表示质量良好；请结合实际作答条件阅读。', 'Missing quality information does not establish good response quality; consider the conditions of this attempt.').'</p>'
            .'<h2>'.$l('解释限制', 'Interpretation limits').'</h2><p>'.$l('正确率只描述本次题目表现。Beta设计参考分/模型百分位不是人口排名、正式IQ或门萨资格；合格常模解释也限本报告来源。未提供信息为未知，不由旧字段换算。', 'Accuracy describes this form only. Designed Beta scores/model percentiles are not population ranks, formal IQ or Mensa eligibility. Eligible normative interpretation is limited to this report’s source. Missing information is unknown and is not derived from legacy fields.').'</p>'
            .'<p>'.$l('中断、疲劳、图形显示及类似题型经验需由本人补充。逻辑、言语等未测能力不可推断；图表可访问性不证明等价非视觉智测。原题、答卷和答案键均不导出。', 'The user may add interruptions, fatigue, display issues and similar-task experience. Do not infer unmeasured logic or verbal ability. Accessible charts do not establish an equivalent nonvisual test. No questions, responses or answer keys are exported.').'</p></body></html>';

        return $h;
    }

    private function percent(float $value): string
    {
        if ($value > 0 && $value < 0.01) {
            return '<0.01%';
        }
        if ($value > 99.99 && $value < 100) {
            return '>99.99%';
        }

        return rtrim(rtrim(number_format($value, $value < 1 || $value > 99 ? 2 : 1, '.', ''), '0'), '.').'%';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
