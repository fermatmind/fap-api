<?php

namespace App\Services\Agent\Triggers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SleepVolatilityTrigger
{
    public function evaluate(int $userId, array $settings = []): array
    {
        if (!Schema::hasTable('sleep_samples')) {
            return ['ok' => false, 'fired' => false, 'reason' => 'sleep_samples_missing'];
        }

        $days = (int) ($settings['days'] ?? config('agent.triggers.sleep_volatility.days', 7));
        $threshold = (float) ($settings['stddev_threshold'] ?? config('agent.triggers.sleep_volatility.stddev_threshold', 1.5));

        $rows = DB::table('sleep_samples')
            ->where('user_id', $userId)
            ->where('recorded_at', '>=', now()->subDays($days))
            ->orderByDesc('recorded_at')
            ->get();

        $values = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) ($row->value_json ?? ''), true);
            if (is_array($payload) && isset($payload['duration_hours'])) {
                $values[] = (float) $payload['duration_hours'];
            }
        }

        if (count($values) < 3) {
            return ['ok' => true, 'fired' => false, 'reason' => 'insufficient_data'];
        }

        $mean = array_sum($values) / count($values);
        $variance = 0.0;
        foreach ($values as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $variance = $variance / count($values);
        $stddev = sqrt($variance);

        if ($stddev >= $threshold) {
            return [
                'ok' => true,
                'fired' => true,
                'trigger_type' => 'sleep_volatility',
                'metrics' => [
                    'stddev' => round($stddev, 3),
                    'mean' => round($mean, 3),
                    'sample_count' => count($values),
                ],
                'source_refs' => [
                    ['type' => 'sleep_samples', 'days' => $days],
                ],
            ];
        }

        return ['ok' => true, 'fired' => false, 'reason' => 'below_threshold'];
    }
}
