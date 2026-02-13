<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class MetricsWeeklyValidity extends Command
{
    protected $signature = 'metrics:weekly-validity
        {--week= : ISO week, e.g. 2026-W03}
        {--top=10 : Top K for tags/type_code/keywords}';

    protected $description = 'Weekly validity feedback metrics (Markdown output)';

    public function handle(): int
    {
        if ((int) \App\Support\RuntimeConfig::value('WEEKLY_METRICS_ENABLED', 0) !== 1) {
            $this->line(json_encode(['ok' => false, 'error' => 'NOT_ENABLED']));
            return 2;
        }

        $weekKey = $this->normalizeWeekKey((string) $this->option('week'));
        if ($weekKey === null) {
            $this->error('Invalid --week. Expect ISO week like 2026-W03.');
            return 1;
        }

        $topK = max(1, (int) $this->option('top'));
        [$windowStart, $windowEnd] = $this->weekWindowUtc($weekKey);

        $rows = DB::table('validity_feedbacks')
            ->select([
                'pack_id',
                'pack_version',
                'report_version',
                'score',
                'reason_tags_json',
                'type_code',
                'free_text',
            ])
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<', $windowEnd)
            ->get();

        $groups = $this->buildGroups($rows, $topK);

        $markdown = $this->renderMarkdown($weekKey, $windowStart, $windowEnd, $groups, $topK);

        $dir = storage_path('app/ops/weekly');
        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $path = $dir . DIRECTORY_SEPARATOR . "validity_{$weekKey}.md";
        File::put($path, $markdown);

        $this->info("OK: wrote {$path}");
        return self::SUCCESS;
    }

    private function normalizeWeekKey(string $weekOpt): ?string
    {
        $weekOpt = trim($weekOpt);
        if ($weekOpt === '') {
            return CarbonImmutable::now('UTC')->format('o-\WW');
        }

        if (!preg_match('/^(\d{4})-W(\d{2})$/', $weekOpt, $m)) {
            return null;
        }

        $year = (int) $m[1];
        $week = (int) $m[2];
        if ($week < 1 || $week > 53) {
            return null;
        }

        $candidate = sprintf('%04d-W%02d', $year, $week);
        $check = CarbonImmutable::now('UTC')->setISODate($year, $week, 1)->format('o-\WW');
        if ($check !== $candidate) {
            return null;
        }

        return $candidate;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function weekWindowUtc(string $weekKey): array
    {
        [$year, $week] = explode('-W', $weekKey);
        $start = CarbonImmutable::now('UTC')
            ->setISODate((int) $year, (int) $week, 1)
            ->startOfDay();
        $end = $start->addWeek();

        return [
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param iterable<int, object> $rows
     * @return array<string, array<string, mixed>>
     */
    private function buildGroups(iterable $rows, int $topK): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $packId = (string) $row->pack_id;
            $packVersion = (string) $row->pack_version;
            $reportVersion = (string) $row->report_version;
            $key = $packId . '|' . $packVersion . '|' . $reportVersion;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'pack_id' => $packId,
                    'pack_version' => $packVersion,
                    'report_version' => $reportVersion,
                    'n' => 0,
                    'score_sum' => 0.0,
                    'distribution' => [
                        1 => 0,
                        2 => 0,
                        3 => 0,
                        4 => 0,
                        5 => 0,
                    ],
                    'low_score_n' => 0,
                    'low_score_tags' => [],
                    'low_score_type_code' => [],
                    'low_score_texts' => [],
                ];
            }

            $score = (int) $row->score;
            if ($score < 1 || $score > 5) {
                continue;
            }

            $groups[$key]['n'] += 1;
            $groups[$key]['score_sum'] += $score;
            $groups[$key]['distribution'][$score] += 1;

            if ($score <= 2) {
                $groups[$key]['low_score_n'] += 1;

                $tags = json_decode((string) $row->reason_tags_json, true);
                if (is_array($tags)) {
                    foreach ($tags as $tag) {
                        if (!is_string($tag)) {
                            continue;
                        }
                        $tag = trim($tag);
                        if ($tag === '') {
                            continue;
                        }
                        $groups[$key]['low_score_tags'][$tag] = ($groups[$key]['low_score_tags'][$tag] ?? 0) + 1;
                    }
                }

                $typeCode = trim((string) $row->type_code);
                if ($typeCode !== '') {
                    $groups[$key]['low_score_type_code'][$typeCode] = ($groups[$key]['low_score_type_code'][$typeCode] ?? 0) + 1;
                }

                $freeText = trim((string) $row->free_text);
                if ($freeText !== '') {
                    $groups[$key]['low_score_texts'][] = $freeText;
                }
            }
        }

        foreach ($groups as $key => $group) {
            $n = (int) $group['n'];
            $avg = $n > 0 ? round($group['score_sum'] / $n, 2) : 0.0;
            $groups[$key]['avg_score'] = $avg;
            $groups[$key]['top_tags'] = $this->topCounts($group['low_score_tags'], $topK);
            $groups[$key]['top_type_code'] = $this->topCounts($group['low_score_type_code'], $topK);
            $groups[$key]['keywords'] = $this->topKeywords($group['low_score_texts'], $topK);
        }

        return $groups;
    }

    /**
     * @param array<string,int> $counts
     * @return array<int, array{key:string,count:int}>
     */
    private function topCounts(array $counts, int $k): array
    {
        $items = [];
        foreach ($counts as $key => $count) {
            $items[] = ['key' => (string) $key, 'count' => (int) $count];
        }

        usort($items, function (array $a, array $b): int {
            if ($a['count'] === $b['count']) {
                return strcmp($a['key'], $b['key']);
            }
            return $b['count'] <=> $a['count'];
        });

        return array_slice($items, 0, $k);
    }

    /**
     * @param array<int,string> $texts
     * @return array<int,string>
     */
    private function topKeywords(array $texts, int $k): array
    {
        $counts = [];
        $stopwords = $this->stopwords();

        foreach ($texts as $text) {
            if (!is_string($text) || trim($text) === '') {
                continue;
            }
            $clean = mb_strtolower($text, 'UTF-8');
            $clean = preg_replace('/[0-9]+/u', ' ', $clean);
            $clean = preg_replace('/[^\p{L}\s]+/u', ' ', $clean);
            $words = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($words)) {
                continue;
            }
            foreach ($words as $word) {
                if (mb_strlen($word, 'UTF-8') < 2) {
                    continue;
                }
                if (isset($stopwords[$word])) {
                    continue;
                }
                $counts[$word] = ($counts[$word] ?? 0) + 1;
            }
        }

        $items = $this->topCounts($counts, $k);
        return array_map(fn (array $item) => $item['key'], $items);
    }

    /**
     * @return array<string,bool>
     */
    private function stopwords(): array
    {
        static $stopwords = null;
        if ($stopwords !== null) {
            return $stopwords;
        }

        $stopwords = array_fill_keys([
            'a', 'an', 'and', 'are', 'as', 'at', 'be', 'been', 'being', 'but',
            'by', 'can', 'could', 'did', 'do', 'does', 'done', 'for', 'from',
            'had', 'has', 'have', 'he', 'her', 'him', 'his', 'i', 'if', 'in',
            'into', 'is', 'it', 'its', 'me', 'my', 'no', 'not', 'of', 'on',
            'or', 'our', 'she', 'so', 'than', 'that', 'the', 'their', 'them',
            'then', 'there', 'these', 'they', 'this', 'to', 'too', 'was',
            'we', 'were', 'what', 'when', 'where', 'which', 'who', 'why',
            'will', 'with', 'would', 'you', 'your',
        ], true);

        $zh = json_decode('["\u7684","\u4e86","\u662f"]', true);
        if (is_array($zh)) {
            foreach ($zh as $word) {
                $stopwords[$word] = true;
            }
        }

        return $stopwords;
    }

    /**
     * @param array<string, array<string,mixed>> $groups
     */
    private function renderMarkdown(
        string $weekKey,
        string $windowStart,
        string $windowEnd,
        array $groups,
        int $topK
    ): string {
        $totalN = 0;
        foreach ($groups as $group) {
            $totalN += (int) $group['n'];
        }

        $lines = [];
        $lines[] = "# Weekly Validity Metrics ({$weekKey})";
        $lines[] = '';
        $lines[] = "- Window (UTC): {$windowStart} ~ {$windowEnd}";
        $lines[] = '- Generated at (UTC): ' . CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $lines[] = '- Total groups: ' . count($groups);
        $lines[] = '- Total N: ' . $totalN;
        $lines[] = '';

        if (count($groups) === 0) {
            $lines[] = '## No data';
            $lines[] = '';
            $lines[] = '- N: 0';
            $lines[] = '';
            return implode("\n", $lines);
        }

        foreach ($groups as $group) {
            $lines[] = '## ' . $group['pack_id'] . ' | ' . $group['pack_version'] . ' | ' . $group['report_version'];
            $lines[] = '';
            $lines[] = '- N: ' . (int) $group['n'];
            $lines[] = '- avg_score: ' . number_format((float) $group['avg_score'], 2, '.', '');
            $lines[] = '';
            $lines[] = '### Distribution';
            $lines[] = '';
            $lines[] = '| score | count | pct |';
            $lines[] = '|---|---:|---:|';
            foreach ([1, 2, 3, 4, 5] as $score) {
                $count = (int) ($group['distribution'][$score] ?? 0);
                $pct = $this->percent($count, (int) $group['n']);
                $lines[] = '| ' . $score . ' | ' . $count . ' | ' . $pct . '% |';
            }
            $lines[] = '';

            $lowN = (int) $group['low_score_n'];
            $lines[] = '### Low score (<=2)';
            $lines[] = '';
            $lines[] = '- low_score_N: ' . $lowN;
            $lines[] = '';

            $lines[] = '#### low_score_top_tags (top ' . $topK . ')';
            $lines[] = '';
            $lines[] = $this->renderTopTable($group['top_tags'] ?? [], $lowN);
            $lines[] = '';

            $lines[] = '#### low_score_top_type_code (top ' . $topK . ')';
            $lines[] = '';
            $lines[] = $this->renderTopTable($group['top_type_code'] ?? [], $lowN);
            $lines[] = '';

            $lines[] = '#### free_text_keywords (top ' . $topK . ')';
            $lines[] = '';
            $lines[] = $this->renderKeywordList($group['keywords'] ?? []);
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{key:string,count:int}> $items
     */
    private function renderTopTable(array $items, int $denom): string
    {
        if (count($items) === 0) {
            return '*(none)*';
        }

        $lines = [];
        $lines[] = '| value | count | pct |';
        $lines[] = '|---|---:|---:|';
        foreach ($items as $item) {
            $pct = $this->percent((int) $item['count'], $denom);
            $lines[] = '| ' . $item['key'] . ' | ' . (int) $item['count'] . ' | ' . $pct . '% |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int,string> $keywords
     */
    private function renderKeywordList(array $keywords): string
    {
        if (count($keywords) === 0) {
            return '*(none)*';
        }

        $lines = [];
        foreach ($keywords as $word) {
            $lines[] = '- ' . $word;
        }

        return implode("\n", $lines);
    }

    private function percent(int $count, int $denom): string
    {
        if ($denom <= 0) {
            return '0.00';
        }

        return number_format(($count * 100) / $denom, 2, '.', '');
    }
}
