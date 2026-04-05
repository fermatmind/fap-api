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
        int $maxAttemptsPer30Days,
        ?string $formCode = null
    ): void {
        $identity = $this->resolveIdentity($userId, $anonId);
        if ($identity === null) {
            return;
        }

        $normalizedScaleCode = strtoupper(trim($scaleCode));
        $isBigFive = $normalizedScaleCode === 'BIG5_OCEAN';
        $normalizedBigFiveFormCode = $isBigFive ? $this->canonicalizeBigFiveFormCode($formCode) : null;

        $query = Attempt::query()
            ->where('org_id', $orgId)
            ->where('scale_code', $normalizedScaleCode);

        if ($identity['type'] === 'user') {
            $query->where('user_id', $identity['value']);
        } else {
            $query->where('anon_id', $identity['value']);
        }

        $now = CarbonImmutable::now();

        if ($isBigFive) {
            $attempts = (clone $query)
                ->orderByDesc('started_at')
                ->orderByDesc('created_at')
                ->get([
                    'id',
                    'started_at',
                    'submitted_at',
                    'created_at',
                    'dir_version',
                    'answers_summary_json',
                ]);

            $formScopedAttempts = $attempts->filter(function (Attempt $attempt) use ($normalizedBigFiveFormCode): bool {
                return $this->resolveBigFiveAttemptFormCode($attempt) === $normalizedBigFiveFormCode;
            })->values();

            if ($cooldownHours > 0) {
                /** @var Attempt|null $latest */
                $latest = $formScopedAttempts->first();
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
                                    'code' => 'RETAKE_COOLDOWN',
                                    'reason_code' => 'RETAKE_COOLDOWN',
                                    'form_code' => $normalizedBigFiveFormCode,
                                    'retry_after_seconds' => $retryAfterSeconds,
                                    'cooldown_hours' => $cooldownHours,
                                    'scope_key' => 'org+scale+identity+form',
                                ]
                            );
                        }
                    }
                }
            }

            if ($maxAttemptsPer30Days > 0) {
                $windowStart = $now->subDays(30);
                $attemptsCount = $formScopedAttempts->filter(function (Attempt $attempt) use ($windowStart): bool {
                    $timestamps = [
                        $attempt->started_at,
                        $attempt->submitted_at,
                        $attempt->created_at,
                    ];
                    foreach ($timestamps as $timestamp) {
                        if ($timestamp === null) {
                            continue;
                        }

                        $candidate = CarbonImmutable::parse((string) $timestamp);
                        if ($candidate->greaterThanOrEqualTo($windowStart)) {
                            return true;
                        }
                    }

                    return false;
                })->count();

                if ($attemptsCount >= $maxAttemptsPer30Days) {
                    throw new ApiProblemException(
                        429,
                        'RETAKE_LIMIT_EXCEEDED',
                        'retake limit reached in last 30 days.',
                        [
                            'code' => 'RETAKE_LIMIT_EXCEEDED',
                            'reason_code' => 'RETAKE_LIMIT_EXCEEDED',
                            'form_code' => $normalizedBigFiveFormCode,
                            'retry_after_seconds' => null,
                            'max_attempts_per_30_days' => $maxAttemptsPer30Days,
                            'attempts_in_window' => $attemptsCount,
                            'limit_window' => '30_days',
                            'scope_key' => 'org+scale+identity+form',
                        ]
                    );
                }
            }

            return;
        }

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

    private function canonicalizeBigFiveFormCode(?string $formCode): string
    {
        $normalized = strtolower(trim((string) $formCode));
        if ($normalized === '' || in_array($normalized, ['big5_120', '120', 'big5-120', 'standard_120', 'default'], true)) {
            return 'big5_120';
        }
        if (in_array($normalized, ['big5_90', '90', 'big5-90', 'standard_90', 'short_90'], true)) {
            return 'big5_90';
        }

        return 'big5_120';
    }

    private function resolveBigFiveAttemptFormCode(Attempt $attempt): string
    {
        $summary = $attempt->answers_summary_json;
        if (is_array($summary)) {
            $meta = is_array($summary['meta'] ?? null) ? $summary['meta'] : [];
            $fromMeta = $this->canonicalizeBigFiveFormCode(
                is_scalar($meta['form_code'] ?? null) ? (string) $meta['form_code'] : null
            );
            if ($fromMeta === 'big5_90') {
                return 'big5_90';
            }
        }

        $dirVersion = strtolower(trim((string) ($attempt->dir_version ?? '')));
        if ($dirVersion === 'v1-form-90' || str_contains($dirVersion, 'form-90')) {
            return 'big5_90';
        }

        return 'big5_120';
    }
}
