<?php

namespace App\Jobs;

use App\Services\Agent\AgentOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAgentMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private int $userId)
    {
    }

    public function handle(): void
    {
        $orchestrator = app(AgentOrchestrator::class);
        $orchestrator->runForUser($this->userId, []);
    }
}
