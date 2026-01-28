<?php

declare(strict_types=1);

namespace App\Services\AI;

final class InsightGenerator
{
    public function generate(object $insight, array $evidence): array
    {
        $provider = (string) config('ai.provider', 'mock');
        $model = (string) config('ai.model', 'mock-model');

        $inputPayload = [
            'period_type' => (string) ($insight->period_type ?? ''),
            'period_start' => (string) ($insight->period_start ?? ''),
            'period_end' => (string) ($insight->period_end ?? ''),
            'prompt_version' => (string) config('ai.prompt_version', 'v1.0.0'),
            'evidence' => $evidence,
        ];

        $inputJson = json_encode($inputPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($inputJson === false) {
            $inputJson = '{}';
        }

        $tokensIn = $this->estimateTokens($inputJson);
        $tokensOut = $this->estimateTokensOut($tokensIn);
        $costUsd = $this->estimateCostUsd($tokensIn + $tokensOut);

        $output = $this->mockOutput($inputPayload);

        return [
            'output_json' => $output,
            'evidence_json' => $evidence,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'cost_usd' => $costUsd,
            'provider' => $provider,
            'model' => $model,
        ];
    }

    private function estimateTokens(string $payload): int
    {
        $chars = strlen($payload);
        $tokens = (int) ceil($chars / 4);
        if ($tokens < 1) {
            $tokens = 1;
        }
        return $tokens;
    }

    private function estimateTokensOut(int $tokensIn): int
    {
        if ($tokensIn > 1200) {
            return 200;
        }
        if ($tokensIn > 600) {
            return 180;
        }
        return 160;
    }

    private function estimateCostUsd(int $totalTokens): float
    {
        $rate = (float) config('ai.cost_per_1k_tokens_usd', 0.0);
        if ($rate <= 0) {
            return 0.0;
        }
        return round(($totalTokens / 1000.0) * $rate, 6);
    }

    private function mockOutput(array $inputPayload): array
    {
        $periodType = (string) ($inputPayload['period_type'] ?? '');
        $periodStart = (string) ($inputPayload['period_start'] ?? '');
        $periodEnd = (string) ($inputPayload['period_end'] ?? '');

        $summary = 'Mock AI insight generated from available structured evidence.';
        if ($periodType !== '' && $periodStart !== '' && $periodEnd !== '') {
            $summary = "Mock AI insight for {$periodType} period {$periodStart} → {$periodEnd}.";
        }

        return [
            'summary' => $summary,
            'strengths' => [
                'Pattern recognition and structured reasoning appear consistent.',
                'Scores suggest stable preference signals.',
            ],
            'risks' => [
                'Over-reliance on a single context may bias conclusions.',
            ],
            'actions' => [
                'Review recent attempts for consistency across contexts.',
                'Re-run assessments after major life changes to verify stability.',
            ],
            'disclaimer' => 'This AI-generated insight is for informational purposes only and not medical or psychological advice.',
        ];
    }
}
