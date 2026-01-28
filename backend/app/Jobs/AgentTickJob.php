<?php

namespace App\Jobs;

use App\Services\Agent\AgentOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AgentTickJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        if (!(bool) config('agent.enabled', false)) {
            return;
        }

        if (!Schema::hasTable('user_agent_settings')) {
            return;
        }

        $users = DB::table('user_agent_settings')
            ->where('enabled', true)
            ->limit(200)
            ->get();

        $orchestrator = app(AgentOrchestrator::class);
        foreach ($users as $user) {
            $thresholds = json_decode((string) ($user->thresholds_json ?? ''), true) ?? [];
            $settings = [
                'max_messages_per_day' => (int) ($user->max_messages_per_day ?? config('agent.max_messages_per_day', 2)),
                'cooldown_minutes' => (int) ($user->cooldown_minutes ?? config('agent.cooldown_minutes', 240)),
                'quiet_hours' => json_decode((string) ($user->quiet_hours_json ?? ''), true) ?? [],
                'sleep_volatility' => $thresholds['sleep_volatility'] ?? [],
                'low_mood_streak' => $thresholds['low_mood_streak'] ?? [],
                'no_activity' => $thresholds['no_activity'] ?? [],
            ];

            $orchestrator->runForUser((int) $user->user_id, $settings);
        }
    }
}
