<?php

declare(strict_types=1);

namespace App\Services\Report\Pdf;

use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;
use App\Services\Eq\Eq60ResultPageProjection;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class CompleteAssessmentPdf
{
    public const VERSION = 'assessment.full_pdf.v1';

    private const TITLES = [
        'ENNEAGRAM' => ['九型人格报告', 'Enneagram report'],
        'EQ_60' => ['情绪能力报告', 'Emotional intelligence report'],
        'RIASEC' => ['霍兰德职业兴趣报告', 'Holland career interest report'],
        'JUNG_ARCHETYPES' => ['荣格十二原型报告', 'Jungian archetypes report'],
        'JUNG_COGNITIVE_FUNCTIONS' => ['荣格八维认知功能报告', 'Jungian cognitive functions report'],
        'DISC_BEHAVIOR' => ['DISC 行为风格报告', 'DISC behavioral style report'],
    ];

    public static function supports(string $scale): bool
    {
        return isset(self::TITLES[$scale]);
    }

    public function html(Attempt $attempt, array $gate, ?string $displayLocale): string
    {
        if (($gate['ok'] ?? false) !== true || ($gate['locked'] ?? true) !== false
            || ($gate['variant'] ?? '') !== 'full' || ($gate['generating'] ?? false)
            || ($gate['snapshot_error'] ?? false)) {
            throw $this->unavailable();
        }

        $scale = (string) $attempt->scale_code;
        $locale = $displayLocale ?? (str_starts_with(strtolower((string) $attempt->locale), 'zh') ? 'zh-CN' : 'en');
        $zh = $locale !== 'en';
        $report = (array) ($gate['report'] ?? []);
        $chapters = match ($scale) {
            'ENNEAGRAM' => $this->enneagram((array) data_get($report, '_meta.enneagram_report_v2', []), $zh),
            'EQ_60' => $this->eq($report, $locale),
            'RIASEC' => $this->riasec((array) data_get($report, '_meta.riasec_public_projection_v2', []), $zh),
            'JUNG_ARCHETYPES', 'JUNG_COGNITIVE_FUNCTIONS' => $this->jung((array) ($report['jung'] ?? []), $zh),
            'DISC_BEHAVIOR' => $this->disc((array) ($report['disc'] ?? []), $zh),
        };

        if ($chapters === []) {
            throw $this->unavailable();
        }

        $display = $locale === 'zh-TW' ? new BigFiveTaiwanPdfCopy : null;
        $text = static fn (string $line): string => htmlspecialchars($display?->text($line) ?? $line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html lang="'.$locale.'"><head><meta charset="utf-8"><style>'
            .'body{font-family:'.($zh ? 'sun-exta' : 'dejavusans').';color:#182739;font-size:11pt;line-height:1.6}'
            .'h1{color:#164d52;font-size:25pt}h2{color:#164d52;font-size:16pt;border-bottom:1px solid #b7d3d5;page-break-after:avoid}'
            .'p{margin:0 0 9pt}.kicker{color:#3e5f66;font-size:10pt}</style></head><body>'
            .'<p class="kicker">FermatMind</p><h1>'.$text(self::TITLES[$scale][$zh ? 0 : 1]).'</h1>';
        if ((int) $attempt->question_count > 0) {
            $html .= '<p class="kicker">'.$text((int) $attempt->question_count.($zh ? ' 题' : ' questions')).'</p>';
        }
        foreach ($chapters as [$heading, $paragraphs]) {
            $html .= '<h2>'.$text($heading).'</h2>';
            foreach ($paragraphs as $paragraph) {
                $html .= '<p>'.$text($paragraph).'</p>';
            }
        }

        return $html.'</body></html>';
    }

    public function render(string $html): string
    {
        $directory = storage_path('framework/cache/mpdf');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException('PDF cache directory unavailable.');
        }
        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $directory,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'margin_left' => 18,
            'margin_right' => 18,
            'margin_top' => 18,
            'margin_bottom' => 18,
        ]);
        $pdf->SetAuthor('FermatMind');
        $pdf->SetHTMLFooter('<div style="text-align:right;color:#64748b;font-size:9pt">FermatMind · {PAGENO} / {nbpg}</div>');
        $pdf->WriteHTML($html);

        return $pdf->Output('', Destination::STRING_RETURN);
    }

    private function enneagram(array $report, bool $zh): array
    {
        $chapters = [];
        if (($report['registry']['content_maturity'] ?? '') === 'unavailable') {
            return [];
        }
        $candidates = (array) ($report['candidates'] ?? []);
        foreach ($candidates as $candidate) {
            $name = trim(($candidate['type_name'] ?? '').' · '.($candidate['score_display'] ?? ''), ' ·');
            foreach ((array) ($candidate['sections'] ?? []) as $section) {
                $this->chapter($chapters, $name.' · '.($section['title'] ?? ''), $this->lines([
                    $section['lead'] ?? null, $section['paragraphs'] ?? [], $section['points'] ?? [], $section['reflection_question'] ?? null,
                ]));
            }
            foreach ((array) ($candidate['growth_actions'] ?? []) as $action) {
                $this->chapter($chapters, $name.' · '.($action['title'] ?? ($zh ? '成长练习' : 'Growth practice')), $this->readerLines($action));
            }
        }
        if ($chapters === []) {
            return [];
        }

        $overview = collect($report['modules'] ?? [])->firstWhere('module_key', 'result_overview')['content'] ?? [];
        $preface = [];
        $this->chapter($preface, $zh ? '本次结果与题本' : 'Results and form', $this->lines([
            $overview['body'] ?? null, data_get($overview, 'form_badge.body'), $overview['methodology_copy'] ?? null,
            $overview['score_space_boundary'] ?? null,
            array_map(static fn (array $candidate): string => trim(($candidate['type_name'] ?? '').' · '.($candidate['score_display'] ?? ''), ' ·'), $candidates),
        ]));
        $comparison = collect($report['modules'] ?? [])->firstWhere('module_key', 'candidate_pair_comparison')['content'] ?? [];
        $comparisonLines = $this->lines([$comparison['short_compare_copy'] ?? null, $comparison['seven_day_observation_question'] ?? null]);
        foreach ((array) ($comparison['dimensions'] ?? []) as $dimension) {
            foreach ((array) ($dimension['sides'] ?? []) as $side) {
                array_push($comparisonLines, ...$this->readerLines($side));
            }
        }
        $this->chapter($preface, $zh ? '候选类型对照' : 'Comparing the candidates', $comparisonLines);
        $observation = collect($report['modules'] ?? [])->firstWhere('module_key', 'seven_day_observation')['content']['steps'] ?? [];
        $this->chapter($chapters, $zh ? '七天观察' : 'Seven-day observation', array_merge(...array_map(fn (array $step): array => $this->lines([
            $step['prompt'] ?? null, $step['suggested_next_action'] ?? null,
        ]), $observation)));

        return [...$preface, ...$chapters];
    }

    private function eq(array $report, string $locale): array
    {
        $projection = app(Eq60ResultPageProjection::class)->project($report);
        $chapters = [];
        $copy = (array) ($projection['copy'][$locale] ?? []);
        if (($projection['result_id'] ?? '') === 'low_confidence_result') {
            $quality = (array) ($projection['quality'][$locale] ?? []);
            $this->chapter($chapters, $quality['summary_title'] ?? ($locale === 'en' ? 'Response quality' : '作答质量'), $this->readerLines($quality));

            return $chapters;
        }
        $this->chapter($chapters, (string) ($copy['summary_title'] ?? ''), $this->lines([$copy['summary'] ?? null]));
        foreach (['dimensions', 'careers', 'work', 'stress', 'money', 'relationships', 'people', 'insights', 'plan', 'science'] as $index => $key) {
            $section = (array) ($copy[$key] ?? []);
            $heading = (string) ($copy['sections'][$index] ?? $copy['chart_title'] ?? '');
            if ($key === 'plan') {
                $this->chapter($chapters, $heading, $this->lines([$copy['plan_intro'] ?? null]));
            }
            foreach (array_is_list($section) ? $section : [$section] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $this->chapter($chapters, (string) ($entry['title'] ?? $entry['name'] ?? $entry['heading'] ?? $heading),
                    $this->readerLines($entry));
            }
        }

        return $chapters;
    }

    private function riasec(array $projection, bool $zh): array
    {
        $chapters = [];
        $body = [];
        foreach ((array) data_get($projection, 'deep_content_slots_v1.slots', []) as $slot) {
            if (! in_array($slot['slot_key'] ?? '', [
                'dimension_deep_copy', 'triad_blend_copy', 'pair_blend_copy', 'profile_shape_copy', 'cautious_reading_copy',
                'structural_difference_copy', 'top_code_confidence_copy', '140q_task_card_copy',
                '140q_environment_card_copy', '140q_role_card_copy', '140q_layer_agreement_copy',
            ], true)
                || ! is_array($slot['content'] ?? null)) {
                continue;
            }
            $content = $slot['content'];
            $heading = $content['title'] ?? $content['pair_label'] ?? $content['strategy_label'] ?? ($zh ? '兴趣组合解读' : 'Interest combination');
            $this->chapter($body, $heading, $this->readerLines($content));
        }
        if ($body === []) {
            return [];
        }
        $dimensions = (array) data_get($projection, 'scores.dimensions', []);
        $this->chapter($chapters, $zh ? '六维兴趣与霍兰德代码' : 'Interest dimensions and Holland code', $this->lines([
            data_get($projection, 'holland_code.code'),
            array_map(static fn (array $row): string => ($row['label'] ?? $row['code'] ?? '').' · '.($row['score'] ?? ''), $dimensions),
        ]));

        return [...$chapters, ...$body];
    }

    private function jung(array $report, bool $zh): array
    {
        $chapters = [];
        $scores = (array) ($report['scores'] ?? []);
        $archetypes = (array) data_get($report, 'reading.archetypes', []);
        $functions = (array) data_get($report, 'reading.functions', []);
        $language = $zh ? 0 : 1;
        $scoreLines = [];
        foreach ($scores as $code => $score) {
            if (is_numeric($score)) {
                $scoreLines[] = ($archetypes[$code][$language] ?? $code).' · '.$score.' / 100';
            }
        }
        $this->chapter($chapters, $zh ? '本次维度分布' : 'Score profile', $scoreLines);

        if (count($scores) === 12) {
            $leaders = array_unique(array_merge((array) ($report['primary'] ?? []), (array) ($report['secondary'] ?? []), (array) ($report['near_leaders'] ?? [])));
            foreach ($leaders === [] ? array_keys($scores) : $leaders as $code) {
                $copy = $archetypes[$code] ?? [];
                $this->chapter($chapters, (string) ($copy[$language] ?? $code), $this->lines(array_map(
                    static fn (int $index): ?string => $copy[$index + $language] ?? null, [2, 4, 6, 8]
                )));
            }
        } else {
            foreach (array_keys($scores) as $code) {
                $this->chapter($chapters, $code, $this->lines([$functions[$code][$language] ?? null]));
            }
            $this->chapter($chapters, $zh ? '四组功能轴' : 'Four function axes', array_map(
                static fn (array $axis): string => ($axis['left'] ?? '').' / '.($axis['right'] ?? '').' · '.($axis['difference'] ?? ''), (array) ($report['axes'] ?? [])
            ));
            $this->chapter($chapters, $zh ? '功能栈候选' : 'Type candidates by stack fit', array_map(
                static fn (array $candidate): string => ($candidate['type'] ?? '').' · '.implode(' → ', (array) ($candidate['stack'] ?? [])), (array) ($report['candidates'] ?? [])
            ));
        }

        return count($chapters) > 1 ? $chapters : [];
    }

    private function disc(array $report, bool $zh): array
    {
        $chapters = [];
        $key = $zh ? 'zh' : 'en';
        $profile = (array) ($report['profile'] ?? []);
        $this->chapter($chapters, (string) data_get($profile, 'title.'.$key, 'DISC'), $this->lines([
            data_get($profile, 'headline.'.$key), data_get($profile, 'summary.'.$key), data_get($profile, 'scoreReading.'.$key),
        ]));
        $scores = [];
        foreach (['DOMINANCE' => 'D', 'INFLUENCE' => 'I', 'STEADINESS' => 'S', 'CONSCIENTIOUSNESS' => 'C'] as $code => $label) {
            $score = data_get($report, 'counts.'.$code);
            if (is_numeric($score)) {
                $scores[] = $label.' · '.$score.' / 12';
            }
        }
        $this->chapter($chapters, $zh ? '本次四维计数' : 'Your counts', $scores);
        foreach ((array) ($profile['sections'] ?? []) as $section) {
            $this->chapter($chapters, (string) data_get($section, 'title.'.$key), $this->lines(array_column((array) ($section['paragraphs'] ?? []), $key)));
        }

        return count($chapters) > 2 ? $chapters : [];
    }

    private function chapter(array &$chapters, string $heading, array $paragraphs): void
    {
        if ($paragraphs !== []) {
            $chapters[] = [$heading, array_values(array_unique($paragraphs))];
        }
    }

    private function lines(array $values): array
    {
        $lines = [];
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $lines[] = trim($value);
            } elseif (is_array($value) && array_is_list($value)) {
                array_push($lines, ...$this->lines($value));
            }
        }

        return $lines;
    }

    private function readerLines(array $content): array
    {
        $fields = [
            'body', 'text', 'copy', 'summary', 'description', 'intro', 'lead', 'paragraphs', 'points', 'examples', 'example',
            'prompt', 'question', 'advice', 'action', 'reading', 'label', 'score', 'band_label', 'high_score_reading',
            'medium_score_reading', 'low_score_reading', 'core_drive', 'positive_value', 'real_world_cost',
            'work_activity_examples', 'common_misread', 'action_advice', 'activity_chain', 'core_reading',
            'first_experiment', 'primary_activity_chain', 'secondary_support_line', 'tertiary_stabilizer', 'likely_tension',
            'chemistry', 'activities_to_validate', 'meaning', 'high', 'low', 'tip', 'scenario', 'reflection', 'steps',
            'strength', 'strengths', 'risk', 'work', 'relationship', 'growth', 'items', 'detail', 'interpretation',
            'patterns', 'pattern', 'methods', 'cards', 'tips', 'takeaway', 'experiment', 'why', 'reasons', 'try',
            'atlas_reading', 'low_risk_validation', 'handling', 'tension', 'suggested_action', 'signal', 'mechanism',
        ];
        $lines = [];
        foreach (array_intersect_key($content, array_fill_keys($fields, true)) as $value) {
            if (is_array($value)) {
                foreach (array_is_list($value) ? $value : [$value] as $entry) {
                    array_push($lines, ...($this->lines([$entry]) ?: (is_array($entry) ? $this->readerLines($entry) : [])));
                }
            } else {
                array_push($lines, ...$this->lines([is_numeric($value) ? (string) $value : $value]));
            }
        }

        return $lines;
    }

    private function unavailable(): ApiProblemException
    {
        return new ApiProblemException(409, 'COMPLETE_PDF_UNAVAILABLE', 'The complete report is not available for PDF export.', headers: ['Cache-Control' => 'private, no-store']);
    }
}
