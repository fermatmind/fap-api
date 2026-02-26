<?php

declare(strict_types=1);

namespace App\Jobs\Ops;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TouchFmTokenLastUsedAtJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 10, 20];

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(public string $tokenHash) {}

    public function uniqueId(): string
    {
        return 'fm_token_touch:'.$this->tokenHash;
    }

    public function handle(): void
    {
        $tokenHash = trim($this->tokenHash);
        if ($tokenHash === '') {
            return;
        }

        $threshold = now()->subMinutes(5);
        $now = now();

        try {
            DB::table('auth_tokens')
                ->where('token_hash', $tokenHash)
                ->where(function ($query) use ($threshold): void {
                    $query->whereNull('last_used_at')->orWhere('last_used_at', '<', $threshold);
                })
                ->update([
                    'last_used_at' => $now,
                    'updated_at' => $now,
                ]);
        } catch (\Throwable $e) {
            Log::warning('[SEC] auth_tokens_touch_failed', [
                'token_hash_prefix' => substr($tokenHash, 0, 8),
                'exception' => $e::class,
            ]);
        }

        DB::table('fm_tokens')
            ->where('token_hash', $tokenHash)
            ->where(function ($query) use ($threshold): void {
                $query->whereNull('last_used_at')->orWhere('last_used_at', '<', $threshold);
            })
            ->update([
                'last_used_at' => $now,
                'updated_at' => $now,
            ]);
    }
}
