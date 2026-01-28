<?php

namespace App\Services\AI\Embeddings;

use App\Services\AI\BudgetLedger;
use App\Services\AI\BudgetLedgerException;

final class EmbeddingClient
{
    private const DEFAULT_DIM = 12;

    public function embed(string $text, array $context = []): array
    {
        $provider = (string) ($context['provider'] ?? config('ai.provider', 'mock'));
        $model = (string) ($context['model'] ?? config('ai.model', 'mock-embedding'));
        $subject = (string) ($context['subject'] ?? 'embedding');
        $dim = (int) ($context['dim'] ?? self::DEFAULT_DIM);

        $tokensIn = $this->estimateTokens($text);
        $tokensOut = 0;
        $costPer1k = (float) config('ai.cost_per_1k_tokens_usd', 0.0);
        $costUsd = ($tokensIn / 1000.0) * $costPer1k;

        $ledger = app(BudgetLedger::class);
        try {
            $ledger->checkAndThrow($provider, $model, $subject, $tokensIn, $costUsd, 'day');
        } catch (BudgetLedgerException $e) {
            return [
                'ok' => false,
                'error_code' => $e->getCode() ?: $e->getMessage(),
                'error' => $e->getMessage(),
                'provider' => $provider,
                'model' => $model,
                'subject' => $subject,
                'dim' => $dim,
                'vector' => [],
                'degraded' => true,
            ];
        }

        $vector = $this->deterministicVector($text, $dim);
        $ledger->incrementTokens($provider, $model, $subject, $tokensIn, $tokensOut, $costUsd);

        return [
            'ok' => true,
            'provider' => $provider,
            'model' => $model,
            'subject' => $subject,
            'dim' => $dim,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'cost_usd' => $costUsd,
            'vector' => $vector,
            'degraded' => false,
        ];
    }

    private function deterministicVector(string $text, int $dim): array
    {
        $dim = $dim > 0 ? $dim : self::DEFAULT_DIM;
        $hash = hash('sha256', $text, true);
        $bytes = array_values(unpack('C*', $hash));

        $vector = [];
        $count = count($bytes);
        for ($i = 0; $i < $dim; $i++) {
            $byte = $bytes[$i % $count] ?? 0;
            $value = (($byte / 255.0) * 2.0) - 1.0;
            $vector[] = round($value, 6);
        }

        return $vector;
    }

    private function estimateTokens(string $text): int
    {
        $len = mb_strlen($text, 'UTF-8');
        $tokens = (int) ceil($len / 4);

        return max(1, $tokens);
    }
}
