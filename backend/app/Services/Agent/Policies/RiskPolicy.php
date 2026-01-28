<?php

namespace App\Services\Agent\Policies;

final class RiskPolicy
{
    public function assess(array $payload): array
    {
        $text = mb_strtolower((string) ($payload['text'] ?? ''), 'UTF-8');
        $highRiskKeywords = ['自杀', '自残', '伤害自己', 'suicide', 'self-harm'];
        foreach ($highRiskKeywords as $keyword) {
            if ($keyword !== '' && mb_strpos($text, $keyword) !== false) {
                return [
                    'level' => 'high',
                    'reason' => 'keyword_match',
                ];
            }
        }

        return [
            'level' => 'low',
            'reason' => 'default',
        ];
    }
}
