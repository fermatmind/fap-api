<?php

namespace App\Services\Legacy\Me;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MeMetricsService
{
    /**
     * @var array<string, bool>|null
     */
    private static ?array $tableCache = null;

    /**
     * @var array<string, bool>
     */
    private array $tables;

    public function __construct()
    {
        if (self::$tableCache === null) {
            self::$tableCache = [
                'sleep_samples' => \App\Support\SchemaBaseline::hasTable('sleep_samples'),
                'health_samples' => \App\Support\SchemaBaseline::hasTable('health_samples'),
                'screen_time_samples' => \App\Support\SchemaBaseline::hasTable('screen_time_samples'),
            ];
        }

        $this->tables = self::$tableCache;
    }

    public function sleepData(?string $userId, int $days): array
    {
        if (!$this->tables['sleep_samples']) {
            return [
                'items' => [],
                'note' => 'sleep_samples table not found; sleep data empty.',
            ];
        }

        if ($userId === null) {
            return [
                'items' => [],
                'note' => 'anon_id present but no user_id bound to samples.',
            ];
        }

        $rows = DB::table('sleep_samples')
            ->where('user_id', (int) $userId)
            ->where('recorded_at', '>=', now()->subDays($days))
            ->orderByDesc('recorded_at')
            ->get();

        return [
            'items' => $this->aggregateByDay($rows, function (object $row): float {
                $value = $this->decodeValueJson($row->value_json ?? null);
                $minutes = $this->extractNumeric($value, ['duration_minutes', 'duration_min', 'duration', 'total_minutes']);
                return $minutes ?? 0.0;
            }, 'total_minutes', false),
        ];
    }

    public function moodData(?string $userId, int $days): array
    {
        if (!$this->tables['health_samples']) {
            return [
                'items' => [],
                'note' => 'health_samples table not found; mood data empty.',
            ];
        }

        if ($userId === null) {
            return [
                'items' => [],
                'note' => 'anon_id present but no user_id bound to samples.',
            ];
        }

        $rows = DB::table('health_samples')
            ->where('user_id', (int) $userId)
            ->where('domain', 'mood')
            ->where('recorded_at', '>=', now()->subDays($days))
            ->orderByDesc('recorded_at')
            ->get();

        return [
            'items' => $this->aggregateByDay($rows, function (object $row): float {
                $value = $this->decodeValueJson($row->value_json ?? null);
                $score = $this->extractNumeric($value, ['score', 'value', 'mood']);
                return $score ?? 0.0;
            }, 'avg_score', true),
        ];
    }

    public function screenTimeData(?string $userId, int $days): array
    {
        if (!$this->tables['screen_time_samples']) {
            return [
                'items' => [],
                'note' => 'screen_time_samples table not found; screen time data empty.',
            ];
        }

        if ($userId === null) {
            return [
                'items' => [],
                'note' => 'anon_id present but no user_id bound to samples.',
            ];
        }

        $rows = DB::table('screen_time_samples')
            ->where('user_id', (int) $userId)
            ->where('recorded_at', '>=', now()->subDays($days))
            ->orderByDesc('recorded_at')
            ->get();

        return [
            'items' => $this->aggregateByDay($rows, function (object $row): float {
                $value = $this->decodeValueJson($row->value_json ?? null);
                $minutes = $this->extractNumeric($value, ['total_screen_minutes', 'screen_minutes', 'minutes']);
                return $minutes ?? 0.0;
            }, 'total_minutes', false),
        ];
    }

    /**
     * @param Collection<int, object> $rows
     * @return array<int, array<string, mixed>>
     */
    private function aggregateByDay(Collection $rows, callable $valueExtractor, string $metricKey, bool $average): array
    {
        $bucket = [];

        foreach ($rows as $row) {
            $day = substr((string) ($row->recorded_at ?? ''), 0, 10);
            if ($day === '') {
                continue;
            }

            if (!isset($bucket[$day])) {
                $bucket[$day] = [
                    'date' => $day,
                    'count' => 0,
                    $metricKey => 0.0,
                ];
            }

            $bucket[$day]['count']++;
            $bucket[$day][$metricKey] += (float) $valueExtractor($row);
        }

        $items = array_values($bucket);
        usort($items, static function (array $left, array $right): int {
            return strcmp((string) $right['date'], (string) $left['date']);
        });

        if ($average) {
            foreach ($items as &$item) {
                if ((int) ($item['count'] ?? 0) > 0) {
                    $item[$metricKey] = ((float) $item[$metricKey]) / ((int) $item['count']);
                }
            }
            unset($item);
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeValueJson(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function extractNumeric(array $value, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $value) && is_numeric($value[$key])) {
                return (float) $value[$key];
            }
        }

        return null;
    }
}
