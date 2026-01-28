<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QuantifiedSelfSeeder extends Seeder
{
    public function run(): void
    {
        $userId = 1;
        $now = now()->startOfDay();

        mt_srand(13);

        if (!Schema::hasTable('sleep_samples') || !Schema::hasTable('health_samples') || !Schema::hasTable('screen_time_samples')) {
            $this->command?->warn('QuantifiedSelfSeeder skipped: missing tables.');
            return;
        }

        for ($i = 0; $i < 30; $i++) {
            $day = $now->copy()->subDays($i);
            $dateStr = $day->format('Y-m-d');

            // Sleep sample (nightly)
            $sleepStart = $day->copy()->subHours(7 + ($i % 3))->setTime(23, 0);
            $sleepEnd = $day->copy()->setTime(7, 0);
            $durationMinutes = $sleepEnd->diffInMinutes($sleepStart);
            DB::table('sleep_samples')->insert([
                'user_id' => $userId,
                'source' => 'seed',
                'recorded_at' => $day->copy()->setTime(8, 0),
                'value_json' => json_encode([
                    'start' => $sleepStart->toDateTimeString(),
                    'end' => $sleepEnd->toDateTimeString(),
                    'duration_minutes' => $durationMinutes,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'confidence' => 0.95,
                'raw_payload_hash' => hash('sha256', $dateStr . '|sleep'),
                'ingest_batch_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Health samples: steps (daily)
            $steps = 4000 + ($i * 137) % 6000;
            DB::table('health_samples')->insert([
                'user_id' => $userId,
                'source' => 'seed',
                'domain' => 'steps',
                'recorded_at' => $day->copy()->setTime(12, 0),
                'value_json' => json_encode([
                    'steps' => $steps,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'confidence' => 0.98,
                'raw_payload_hash' => hash('sha256', $dateStr . '|steps'),
                'ingest_batch_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Health samples: heart rate (5 samples)
            for ($j = 0; $j < 5; $j++) {
                $minute = 10 + $j * 8;
                $bpm = 62 + (($i + $j) % 12);
                DB::table('health_samples')->insert([
                    'user_id' => $userId,
                    'source' => 'seed',
                    'domain' => 'heart_rate',
                    'recorded_at' => $day->copy()->setTime(9, $minute),
                    'value_json' => json_encode([
                        'bpm' => $bpm,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'confidence' => 0.96,
                    'raw_payload_hash' => hash('sha256', $dateStr . '|hr|' . $j),
                    'ingest_batch_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Screen time sample (daily)
            $screenMinutes = 120 + (($i * 23) % 180);
            DB::table('screen_time_samples')->insert([
                'user_id' => $userId,
                'source' => 'seed',
                'recorded_at' => $day->copy()->setTime(22, 0),
                'value_json' => json_encode([
                    'total_screen_minutes' => $screenMinutes,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'confidence' => 0.9,
                'raw_payload_hash' => hash('sha256', $dateStr . '|screen'),
                'ingest_batch_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
