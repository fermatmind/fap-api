<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class QualityDailySummary extends Command
{
    protected $signature = 'quality:daily-summary {--day= : YYYY-MM-DD, defaults to yesterday}';

    protected $description = 'Aggregate daily quality stats (crisis/speeding/straightlining) by scale.';

    public function handle(): int
    {
        if (! Schema::hasTable('scale_quality_daily_stats')) {
            $this->error('Missing table scale_quality_daily_stats. Run migrations first.');

            return self::FAILURE;
        }

        $day = trim((string) $this->option('day'));
        if ($day === '') {
            $day = now()->subDay()->toDateString();
        }

        try {
            $start = CarbonImmutable::parse($day)->startOfDay();
        } catch (\Throwable) {
            $this->error('Invalid --day format, expected YYYY-MM-DD.');

            return self::FAILURE;
        }

        $end = $start->addDay();

        $attemptsTotalByScale = DB::table('attempts')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw('scale_code, COUNT(*) AS total')
            ->groupBy('scale_code')
            ->pluck('total', 'scale_code')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        $submittedAttempts = DB::table('attempts')
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $start)
            ->where('submitted_at', '<', $end)
            ->get(['id', 'scale_code']);

        $attemptIds = [];
        foreach ($submittedAttempts as $attempt) {
            $attemptId = trim((string) ($attempt->id ?? ''));
            if ($attemptId !== '') {
                $attemptIds[] = $attemptId;
            }
        }

        $resultJsonByAttempt = [];
        if ($attemptIds !== []) {
            $rows = DB::table('results')
                ->whereIn('attempt_id', $attemptIds)
                ->get(['attempt_id', 'result_json']);
            foreach ($rows as $row) {
                $attemptId = trim((string) ($row->attempt_id ?? ''));
                if ($attemptId === '') {
                    continue;
                }
                $resultJsonByAttempt[$attemptId] = $row->result_json;
            }
        }

        $qualityStats = [];
        foreach ($submittedAttempts as $attempt) {
            $scaleCode = strtoupper(trim((string) ($attempt->scale_code ?? '')));
            if ($scaleCode === '') {
                continue;
            }

            if (! isset($qualityStats[$scaleCode])) {
                $qualityStats[$scaleCode] = $this->blankScaleStats();
            }

            $qualityStats[$scaleCode]['attempts_submitted']++;

            $attemptId = trim((string) ($attempt->id ?? ''));
            $payload = $this->decodeJson($resultJsonByAttempt[$attemptId] ?? null);
            $quality = $this->extractQualityNode($payload);
            if ($quality === []) {
                continue;
            }

            $level = strtoupper(trim((string) ($quality['level'] ?? '')));
            if (isset($qualityStats[$scaleCode]['quality_level_dist'][$level])) {
                $qualityStats[$scaleCode]['quality_level_dist'][$level]++;
            }

            if ((bool) ($quality['crisis_alert'] ?? false) === true) {
                $qualityStats[$scaleCode]['crisis_count']++;
            }

            $flags = [];
            foreach ((array) ($quality['flags'] ?? []) as $flag) {
                $flagValue = strtoupper(trim((string) $flag));
                if ($flagValue !== '') {
                    $flags[$flagValue] = true;
                }
            }

            if (isset($flags['SPEEDING'])) {
                $qualityStats[$scaleCode]['speeding_count']++;
            }
            if (isset($flags['STRAIGHTLINING'])) {
                $qualityStats[$scaleCode]['straightlining_count']++;
            }
        }

        $scaleCodes = array_values(array_unique(array_merge(
            array_keys($attemptsTotalByScale),
            array_keys($qualityStats)
        )));
        sort($scaleCodes);

        $now = now();
        foreach ($scaleCodes as $scaleCode) {
            $stats = $qualityStats[$scaleCode] ?? $this->blankScaleStats();
            $submitted = max(0, (int) $stats['attempts_submitted']);

            DB::table('scale_quality_daily_stats')->updateOrInsert(
                [
                    'day' => $start->toDateString(),
                    'scale_code' => $scaleCode,
                ],
                [
                    'attempts_total' => max(0, (int) ($attemptsTotalByScale[$scaleCode] ?? 0)),
                    'attempts_submitted' => $submitted,
                    'crisis_rate' => $this->rate((int) $stats['crisis_count'], $submitted),
                    'speeding_rate' => $this->rate((int) $stats['speeding_count'], $submitted),
                    'straightlining_rate' => $this->rate((int) $stats['straightlining_count'], $submitted),
                    'quality_level_dist_json' => json_encode($stats['quality_level_dist'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $this->info(sprintf('quality summary generated day=%s scales=%d', $start->toDateString(), count($scaleCodes)));

        return self::SUCCESS;
    }

    /**
     * @return array{attempts_submitted:int,crisis_count:int,speeding_count:int,straightlining_count:int,quality_level_dist:array{A:int,B:int,C:int,D:int}}
     */
    private function blankScaleStats(): array
    {
        return [
            'attempts_submitted' => 0,
            'crisis_count' => 0,
            'speeding_count' => 0,
            'straightlining_count' => 0,
            'quality_level_dist' => [
                'A' => 0,
                'B' => 0,
                'C' => 0,
                'D' => 0,
            ],
        ];
    }

    private function rate(int $count, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round($count / $total, 4);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function extractQualityNode(array $payload): array
    {
        $quality = $payload['quality'] ?? null;
        if (is_array($quality)) {
            return $quality;
        }

        $normed = $payload['normed_json'] ?? null;
        if (is_array($normed) && is_array($normed['quality'] ?? null)) {
            return $normed['quality'];
        }

        return [];
    }
}
