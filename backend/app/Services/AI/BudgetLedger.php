<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Carbon;

final class BudgetLedger
{
    private const DAY_TTL_SECONDS = 172800;   // 2 days
    private const MONTH_TTL_SECONDS = 3456000; // 40 days
    private ?\Redis $directClient = null;

    public function incrementTokens(
        string $provider,
        string $model,
        string $subject,
        int $tokensIn,
        int $tokensOut,
        float $costUsd,
        ?\DateTimeInterface $now = null
    ): array {
        $now = $this->normalizeNow($now);
        $dayKey = $this->buildKey('day', $now->format('Y-m-d'), $provider, $model, $subject);
        $monthKey = $this->buildKey('month', $now->format('Y-m'), $provider, $model, $subject);

        $day = $this->incrementKey($dayKey, $tokensIn, $tokensOut, $costUsd, self::DAY_TTL_SECONDS);
        $month = $this->incrementKey($monthKey, $tokensIn, $tokensOut, $costUsd, self::MONTH_TTL_SECONDS);

        return [
            'day' => $day,
            'month' => $month,
        ];
    }

    public function getUsage(
        string $provider,
        string $model,
        string $subject,
        string $period,
        ?\DateTimeInterface $now = null
    ): array {
        $now = $this->normalizeNow($now);
        $period = $period === 'month' ? 'month' : 'day';
        $key = $this->buildKey(
            $period,
            $period === 'month' ? $now->format('Y-m') : $now->format('Y-m-d'),
            $provider,
            $model,
            $subject
        );

        return $this->readKey($key);
    }

    public function checkAndThrow(
        string $provider,
        string $model,
        string $subject,
        int $addTokens,
        float $addCostUsd,
        string $period,
        ?\DateTimeInterface $now = null
    ): void {
        if (!(bool) config('ai.breaker_enabled', true)) {
            return;
        }

        $period = $period === 'month' ? 'month' : 'day';
        $now = $this->normalizeNow($now);
        $key = $this->buildKey(
            $period,
            $period === 'month' ? $now->format('Y-m') : $now->format('Y-m-d'),
            $provider,
            $model,
            $subject
        );
        try {
            $usage = $this->readWith($this->directClient(), $key);
        } catch (\Throwable $e) {
            $this->handleRedisFailure($e);
            $usage = [
                'tokens_in' => 0,
                'tokens_out' => 0,
                'cost_usd' => 0.0,
            ];
        }

        $tokensLimit = (int) config('ai.budgets.' . ($period === 'month' ? 'monthly_tokens' : 'daily_tokens'), 0);
        $costLimit = (float) config('ai.budgets.' . ($period === 'month' ? 'monthly_usd' : 'daily_usd'), 0);

        $tokensNow = (int) ($usage['tokens_in'] + $usage['tokens_out']);
        $costNow = (float) ($usage['cost_usd'] ?? 0.0);

        if ($tokensLimit > 0 && ($tokensNow + $addTokens) > $tokensLimit) {
            throw new BudgetLedgerException('AI_BUDGET_EXCEEDED', 'AI budget exceeded (tokens).');
        }

        if ($costLimit > 0 && ($costNow + $addCostUsd) > $costLimit) {
            throw new BudgetLedgerException('AI_BUDGET_EXCEEDED', 'AI budget exceeded (cost).');
        }
    }

    private function incrementKey(string $key, int $tokensIn, int $tokensOut, float $costUsd, int $ttlSeconds): array
    {
        try {
            return $this->incrementWith($this->client(), $key, $tokensIn, $tokensOut, $costUsd, $ttlSeconds);
        } catch (\Throwable $e) {
            try {
                return $this->incrementWith($this->directClient(), $key, $tokensIn, $tokensOut, $costUsd, $ttlSeconds);
            } catch (\Throwable $e2) {
                $this->handleRedisFailure($e2);
                return [
                    'ok' => false,
                    'tokens_in' => 0,
                    'tokens_out' => 0,
                    'cost_usd' => 0.0,
                    'requests' => 0,
                ];
            }
        }
    }

    private function readKey(string $key): array
    {
        try {
            return $this->readWith($this->client(), $key);
        } catch (\Throwable $e) {
            try {
                return $this->readWith($this->directClient(), $key);
            } catch (\Throwable $e2) {
                $this->handleRedisFailure($e2);
                return [
                    'ok' => false,
                    'tokens_in' => 0,
                    'tokens_out' => 0,
                    'cost_usd' => 0.0,
                    'requests' => 0,
                ];
            }
        }
    }

    private function handleRedisFailure(\Throwable $e): void
    {
        if (!(bool) config('ai.breaker_enabled', true)) {
            return;
        }

        $failOpen = (bool) config('ai.fail_open_when_redis_down', false);
        if (!$failOpen) {
            $env = \App\Support\RuntimeConfig::raw('AI_FAIL_OPEN_WHEN_REDIS_DOWN');
            if ($env !== false && $env !== '') {
                $failOpen = filter_var($env, FILTER_VALIDATE_BOOLEAN);
            }
        }
        if ($failOpen) {
            return;
        }

        throw new BudgetLedgerException('AI_BUDGET_LEDGER_UNAVAILABLE', 'AI budget ledger unavailable.', 0, $e);
    }

    private function buildKey(string $granularity, string $period, string $provider, string $model, string $subject): string
    {
        $prefix = (string) config('ai.redis_prefix', 'ai:budget');

        $granularity = $granularity === 'month' ? 'month' : 'day';
        $provider = $this->sanitizeSegment($provider, 32);
        $model = $this->sanitizeSegment($model, 64);
        $subject = $this->sanitizeSegment($subject, 128);

        return $prefix . ':' . $granularity . ':' . $period . ':' . $provider . ':' . $model . ':' . $subject;
    }

    private function sanitizeSegment(string $value, int $maxLen): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 'unknown';
        }

        $safe = preg_replace('/[^a-zA-Z0-9_:\\-\\.]/', '_', $trimmed);
        $safe = $safe === null ? 'unknown' : $safe;

        return substr($safe, 0, $maxLen);
    }

    private function normalizeNow(?\DateTimeInterface $now): Carbon
    {
        if ($now instanceof Carbon) {
            return $now;
        }

        if ($now instanceof \DateTimeInterface) {
            return Carbon::instance($now);
        }

        return now();
    }

    private function readWith($redis, string $key): array
    {
        $data = $redis->hMGet($key, ['tokens_in', 'tokens_out', 'cost_usd', 'requests']);
        $tokensIn = (int) ($data['tokens_in'] ?? 0);
        $tokensOut = (int) ($data['tokens_out'] ?? 0);
        $costUsd = (float) ($data['cost_usd'] ?? 0.0);
        $requests = (int) ($data['requests'] ?? 0);

        return [
            'ok' => true,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'cost_usd' => $costUsd,
            'requests' => $requests,
        ];
    }

    private function incrementWith($redis, string $key, int $tokensIn, int $tokensOut, float $costUsd, int $ttlSeconds): array
    {
        $redis->hIncrBy($key, 'tokens_in', $tokensIn);
        $redis->hIncrBy($key, 'tokens_out', $tokensOut);
        $redis->hIncrByFloat($key, 'cost_usd', $costUsd);
        $redis->hIncrBy($key, 'requests', 1);
        $redis->expire($key, $ttlSeconds);

        return $this->readWith($redis, $key);
    }

    private function client()
    {
        return $this->directClient();
    }

    private function directClient(): \Redis
    {
        if ($this->directClient instanceof \Redis) {
            return $this->directClient;
        }

        $host = (string) config('database.redis.default.host', '127.0.0.1');
        $port = (int) config('database.redis.default.port', 6379);
        $password = trim((string) (config('database.redis.default.password') ?? ''));
        if (strtolower($password) === 'null') {
            $password = '';
        }
        $database = (int) config('database.redis.default.database', 0);

        $client = new \Redis();
        $client->connect($host, $port, 1.5);

        if ($password !== '') {
            $client->auth($password);
        }
        if ($database > 0) {
            $client->select($database);
        }

        $this->directClient = $client;

        return $client;
    }
}
