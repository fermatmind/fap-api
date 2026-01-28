<?php

namespace App\Services\Agent\Triggers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NoActivityTrigger
{
    public function evaluate(int $userId, array $settings = []): array
    {
        $days = (int) ($settings['days'] ?? config('agent.triggers.no_activity.days', 5));

        if (!Schema::hasTable('events')) {
            return ['ok' => false, 'fired' => false, 'reason' => 'events_missing'];
        }

        $count = DB::table('events')
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays($days))
            ->count();

        if ($count === 0) {
            return [
                'ok' => true,
                'fired' => true,
                'trigger_type' => 'no_activity',
                'metrics' => [
                    'days' => $days,
                    'event_count' => 0,
                ],
                'source_refs' => [
                    ['type' => 'events', 'days' => $days],
                ],
            ];
        }

        return ['ok' => true, 'fired' => false, 'reason' => 'has_activity'];
    }
}
