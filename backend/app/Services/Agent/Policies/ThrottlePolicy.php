<?php

namespace App\Services\Agent\Policies;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

final class ThrottlePolicy
{
    public function check(int $userId, array $settings = []): array
    {
        $maxPerDay = (int) ($settings['max_messages_per_day'] ?? config('agent.max_messages_per_day', 2));
        $cooldownMinutes = (int) ($settings['cooldown_minutes'] ?? config('agent.cooldown_minutes', 240));
        $quietHours = $settings['quiet_hours'] ?? config('agent.quiet_hours', []);

        if ($this->inQuietHours($quietHours)) {
            return [
                'ok' => true,
                'allowed' => false,
                'reason' => 'quiet_hours',
            ];
        }

        if (!\App\Support\SchemaBaseline::hasTable('agent_messages')) {
            return [
                'ok' => false,
                'allowed' => false,
                'reason' => 'agent_messages_missing',
            ];
        }

        $today = now()->startOfDay();
        $count = DB::table('agent_messages')
            ->where('user_id', $userId)
            ->where('created_at', '>=', $today)
            ->count();

        if ($maxPerDay > 0 && $count >= $maxPerDay) {
            return [
                'ok' => true,
                'allowed' => false,
                'reason' => 'max_per_day',
            ];
        }

        $last = DB::table('agent_messages')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->first();

        if ($last && !empty($last->created_at)) {
            $lastAt = Carbon::parse($last->created_at);
            $diff = $lastAt->diffInMinutes(now());
            if ($diff < $cooldownMinutes) {
                return [
                    'ok' => true,
                    'allowed' => false,
                    'reason' => 'cooldown',
                ];
            }
        }

        return [
            'ok' => true,
            'allowed' => true,
        ];
    }

    private function inQuietHours(array $quietHours): bool
    {
        $start = (string) ($quietHours['start'] ?? config('agent.quiet_hours.start', '22:00'));
        $end = (string) ($quietHours['end'] ?? config('agent.quiet_hours.end', '07:00'));
        $tz = (string) ($quietHours['timezone'] ?? config('agent.quiet_hours.timezone', 'UTC'));

        try {
            $now = now($tz);
            $startAt = Carbon::createFromFormat('H:i', $start, $tz);
            $endAt = Carbon::createFromFormat('H:i', $end, $tz);
        } catch (\Throwable $e) {
            return false;
        }

        if ($startAt->eq($endAt)) {
            return false;
        }

        if ($startAt->lt($endAt)) {
            return $now->between($startAt, $endAt);
        }

        return $now->gte($startAt) || $now->lte($endAt);
    }
}
