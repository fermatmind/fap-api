<?php

declare(strict_types=1);

namespace App\Services\Attempts;

use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;
use Carbon\CarbonImmutable;

final class AttemptRateLimitService
{
    public function assertRetakeAllowed(
        int $orgId,
        string $scaleCode,
        ?string $userId,
        ?string $anonId,
        int $cooldownHours,
        int $maxAttemptsPer30Days
    ): void {
        $identity = $this->resolveIdentity($userId, $anonId);
        if ($identity === null) {
            return;
        }

        $query = Attempt::query()
            ->where('org_id', $orgId)
            ->where('scale_code', strtoupper(trim($scaleCode)));

        if ($identity['type'] === 'user') {
            $query->where('user_id', $identity['value']);
        } else {
            $query->where('anon_id', $identity['value']);
        }

        $now = CarbonImmutable::now();

        if ($cooldownHours > 0) {
            $latest = (clone $query)
                ->orderByDesc('started_at')
                ->orderByDesc('created_at')
                ->first(['id', 'started_at', 'created_at']);
            if ($latest !== null) {
                $latestAt = $latest->started_at ?? $latest->created_at;
                if ($latestAt !== null) {
                    $latestTs = CarbonImmutable::parse((string) $latestAt);
                    $cooldownEndsAt = $latestTs->addHours($cooldownHours);
                    if ($cooldownEndsAt->greaterThan($now)) {
                        $retryAfterSeconds = $now->diffInSeconds($cooldownEndsAt, false);
                        if ($retryAfterSeconds < 0) {
                            $retryAfterSeconds = 0;
                        }

                        throw new ApiProblemException(
                            429,
                            'RETAKE_COOLDOWN',
                            'retake cooldown active.',
                            [
                                'retry_after_seconds' => $retryAfterSeconds,
                                'cooldown_hours' => $cooldownHours,
                            ]
                        );
                    }
                }
            }
        }

        if ($maxAttemptsPer30Days > 0) {
            $windowStart = $now->subDays(30);
            $attemptsCount = (clone $query)
                ->where(function ($sub) use ($windowStart): void {
                    $sub->where('started_at', '>=', $windowStart)
                        ->orWhere('submitted_at', '>=', $windowStart)
                        ->orWhere('created_at', '>=', $windowStart);
                })
                ->count();

            if ($attemptsCount >= $maxAttemptsPer30Days) {
                throw new ApiProblemException(
                    429,
                    'RETAKE_LIMIT_EXCEEDED',
                    'retake limit reached in last 30 days.',
                    [
                        'max_attempts_per_30_days' => $maxAttemptsPer30Days,
                        'attempts_in_window' => $attemptsCount,
                    ]
                );
            }
        }
    }

    /**
     * @return array{type:'user'|'anon',value:string}|null
     */
    private function resolveIdentity(?string $userId, ?string $anonId): ?array
    {
        $uid = trim((string) $userId);
        if ($uid !== '') {
            return ['type' => 'user', 'value' => $uid];
        }

        $aid = trim((string) $anonId);
        if ($aid !== '') {
            return ['type' => 'anon', 'value' => $aid];
        }

        return null;
    }
}

