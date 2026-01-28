<?php

namespace App\Http\Middleware;

use App\Services\AI\BudgetLedger;
use App\Services\AI\BudgetLedgerException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAiBudget
{
    private const MAX_ESTIMATED_TOKENS = 20000;

    public function handle(Request $request, Closure $next): Response
    {
        if (!(bool) config('ai.enabled', true) || !(bool) config('ai.insights_enabled', true)) {
            return response()->json([
                'ok' => false,
                'error' => 'AI_DISABLED',
                'error_code' => 'AI_DISABLED',
                'message' => 'AI insights are currently disabled.',
            ], 503);
        }

        if (!(bool) config('ai.breaker_enabled', true)) {
            return $next($request);
        }

        $provider = (string) config('ai.provider', 'mock');
        $model = (string) config('ai.model', 'mock-model');
        $subject = $this->resolveSubject($request);

        $estimatedTokens = $this->estimateTokens($request);
        $estimatedCost = $this->estimateCost($estimatedTokens);

        $ledger = app(BudgetLedger::class);

        try {
            $ledger->checkAndThrow($provider, $model, $subject, $estimatedTokens, $estimatedCost, 'day');
            $ledger->checkAndThrow($provider, $model, $subject, $estimatedTokens, $estimatedCost, 'month');
        } catch (BudgetLedgerException $e) {
            $code = $e->errorCode();
            $status = $code === 'AI_BUDGET_EXCEEDED' ? 429 : 503;

            return response()->json([
                'ok' => false,
                'error' => $code,
                'error_code' => $code,
                'message' => $code === 'AI_BUDGET_EXCEEDED'
                    ? 'AI budget exceeded. Try again later.'
                    : 'AI budget ledger unavailable.',
            ], $status);
        }

        return $next($request);
    }

    private function resolveSubject(Request $request): string
    {
        $userId = trim((string) $request->attributes->get('fm_user_id', ''));
        if ($userId !== '') {
            return 'user:' . $userId;
        }

        $anonId = trim((string) $request->attributes->get('anon_id', ''));
        if ($anonId === '') {
            $anonId = trim((string) $request->input('anon_id', ''));
        }

        if ($anonId !== '') {
            return 'anon:' . $anonId;
        }

        return 'unknown';
    }

    private function estimateTokens(Request $request): int
    {
        $estimated = (int) $request->input('estimated_tokens', 0);
        if ($estimated > 0) {
            return $this->capEstimatedTokens($estimated);
        }

        $body = (string) $request->getContent();
        if ($body === '') {
            $body = (string) json_encode($request->all());
        }

        $chars = strlen($body);
        $tokens = (int) ceil($chars / 4);
        if ($tokens <= 0) {
            $tokens = 1;
        }

        return $this->capEstimatedTokens($tokens);
    }

    private function capEstimatedTokens(int $tokens): int
    {
        if ($tokens > self::MAX_ESTIMATED_TOKENS) {
            return self::MAX_ESTIMATED_TOKENS;
        }
        if ($tokens < 1) {
            return 1;
        }
        return $tokens;
    }

    private function estimateCost(int $estimatedTokens): float
    {
        $rate = (float) config('ai.cost_per_1k_tokens_usd', 0.0);
        if ($rate <= 0) {
            return 0.0;
        }

        return round(($estimatedTokens / 1000.0) * $rate, 6);
    }
}
