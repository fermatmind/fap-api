<?php

declare(strict_types=1);

namespace App\Services\Ops;

class OpsRiskEngine
{
    /**
     * @param  array<string, mixed>  $context
     * @return array{score: int, level: string}
     */
    public static function evaluate(array $context): array
    {
        $risk = 0;

        if (($context['ip_reputation'] ?? 'untrusted') !== 'trusted') {
            $risk += 10;
        }

        if ((int) ($context['failed_login_count'] ?? 0) > 3) {
            $risk += 30;
        }

        $risk += max(0, (int) ($context['external_risk_score'] ?? 0));

        return [
            'score' => $risk,
            'level' => $risk > 50 ? 'HIGH' : 'LOW',
        ];
    }
}
