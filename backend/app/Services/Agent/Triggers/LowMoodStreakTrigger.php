<?php

namespace App\Services\Agent\Triggers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class LowMoodStreakTrigger
{
    public function evaluate(int $userId, array $settings = []): array
    {
        $days = (int) ($settings['days'] ?? config('agent.triggers.low_mood_streak.days', 5));
        $minScore = (float) ($settings['min_score'] ?? config('agent.triggers.low_mood_streak.min_score', 2.0));

        $rows = $this->fetchMoodSamples($userId, $days);
        if (empty($rows)) {
            return ['ok' => true, 'fired' => false, 'reason' => 'no_mood_data'];
        }

        $lowCount = 0;
        $scores = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) ($row->value_json ?? ''), true);
            $score = null;
            if (is_array($payload)) {
                if (isset($payload['mood'])) {
                    $score = (float) $payload['mood'];
                } elseif (isset($payload['score'])) {
                    $score = (float) $payload['score'];
                }
            }
            if ($score !== null) {
                $scores[] = $score;
                if ($score <= $minScore) {
                    $lowCount++;
                }
            }
        }

        if (empty($scores)) {
            return ['ok' => true, 'fired' => false, 'reason' => 'no_mood_scores'];
        }

        if ($lowCount >= min($days, count($scores))) {
            return [
                'ok' => true,
                'fired' => true,
                'trigger_type' => 'low_mood_streak',
                'metrics' => [
                    'low_days' => $lowCount,
                    'days' => $days,
                    'min_score' => $minScore,
                ],
                'source_refs' => [
                    ['type' => 'health_samples', 'days' => $days],
                ],
            ];
        }

        return ['ok' => true, 'fired' => false, 'reason' => 'no_streak'];
    }

    private function fetchMoodSamples(int $userId, int $days): array
    {
        if (!Schema::hasTable('health_samples')) {
            return [];
        }

        return DB::table('health_samples')
            ->where('user_id', $userId)
            ->where('recorded_at', '>=', now()->subDays($days))
            ->orderByDesc('recorded_at')
            ->limit($days * 2)
            ->get()
            ->all();
    }
}
