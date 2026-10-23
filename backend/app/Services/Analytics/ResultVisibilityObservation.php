<?php

namespace App\Services\Analytics;

use App\Models\Attempt;
use App\Models\Result;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/** Private read-to-ack interval; never a public SEO event or a client clock. */
final class ResultVisibilityObservation
{
    public const VERSION = 'result-visible.owner-interval.v1';

    public const EVENT = 'result_visible_observed';

    public const SCALES = ['MBTI', 'BIG5_OCEAN', 'IQ_RAVEN', 'EQ_60', 'ENNEAGRAM', 'RIASEC'];

    public function issue(Attempt $attempt, Result $result): ?string
    {
        if (! in_array(strtoupper((string) $attempt->scale_code), self::SCALES, true)
            || ! $result->is_valid || ! $attempt->submitted_at || ! $result->computed_at) {
            return null;
        }

        return Crypt::encryptString(json_encode([
            'version' => self::VERSION, 'org_id' => (int) $attempt->org_id,
            'attempt_id' => (string) $attempt->id, 'result_version' => $this->resultVersion($result),
            'read_at' => CarbonImmutable::now('UTC')->toISOString(), 'nonce' => (string) Str::uuid(),
        ], JSON_THROW_ON_ERROR));
    }

    public function verify(string $receipt, Attempt $attempt, Result $result): ?array
    {
        try {
            $proof = json_decode(Crypt::decryptString($receipt), true, flags: JSON_THROW_ON_ERROR);
            $read = CarbonImmutable::parse($proof['read_at']);
            if (($proof['version'] ?? null) !== self::VERSION
                || ($proof['org_id'] ?? null) !== (int) $attempt->org_id
                || ($proof['attempt_id'] ?? null) !== (string) $attempt->id
                || ($proof['result_version'] ?? null) !== $this->resultVersion($result)
                || ! Str::isUuid($proof['nonce'] ?? '') || $read->isFuture()
                || ! $result->is_valid || ! $attempt->submitted_at || ! $result->computed_at
                || $read->lessThan(CarbonImmutable::parse($result->computed_at))) {
                return null;
            }

            return $proof;
        } catch (Throwable) {
            return null;
        }
    }

    private function resultVersion(Result $result): string
    {
        return hash('sha256', json_encode([$result->id, (string) $result->computed_at, (string) $result->updated_at]));
    }
}
