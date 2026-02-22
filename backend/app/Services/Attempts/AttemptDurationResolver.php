<?php

declare(strict_types=1);

namespace App\Services\Attempts;

use App\Models\Attempt;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class AttemptDurationResolver
{
    public function resolveServerSeconds(Attempt $attempt): int
    {
        return $this->resolveServerSecondsFromValues($attempt->started_at, $attempt->submitted_at);
    }

    public function resolveServerSecondsFromValues(mixed $startedAt, mixed $submittedAt): int
    {
        $started = $this->normalizeToCarbon($startedAt);
        $submitted = $this->normalizeToCarbon($submittedAt);

        if ($started === null || $submitted === null) {
            return 0;
        }

        return max(0, $submitted->diffInSeconds($started));
    }

    private function normalizeToCarbon(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value));
        }

        if (is_string($value) || is_numeric($value)) {
            $normalized = trim((string) $value);
            if ($normalized === '') {
                return null;
            }

            try {
                return Carbon::parse($normalized);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
