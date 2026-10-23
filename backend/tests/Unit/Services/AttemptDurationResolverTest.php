<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Attempts\AttemptDurationResolver;
use PHPUnit\Framework\TestCase;

final class AttemptDurationResolverTest extends TestCase
{
    public function test_server_time_uses_forward_elapsed_seconds_and_rejects_reversed_or_missing_time(): void
    {
        $resolver = new AttemptDurationResolver;
        $start = '2026-10-23T06:14:18Z';
        $end = '2026-10-23T06:24:34Z';
        $this->assertSame(616, $resolver->resolveServerSecondsFromValues($start, $end));
        $this->assertSame(0, $resolver->resolveServerSecondsFromValues($end, $start));
        $this->assertSame(0, $resolver->resolveServerSecondsFromValues(null, $end));
    }

    public function test_storage_bounds_preserve_valid_client_time_and_recover_overflow_from_server_time(): void
    {
        $resolver = new AttemptDurationResolver;

        foreach ([0, 120000, AttemptDurationResolver::MAX_DURATION_MS] as $duration) {
            $this->assertSame($duration, $resolver->normalizeClientMilliseconds($duration, 616));
        }
        $this->assertSame(616000, $resolver->normalizeClientMilliseconds(2_952_103_728, 616));
        $this->assertSame(0, $resolver->normalizeClientMilliseconds(PHP_INT_MAX, 0));
        $this->assertSame(AttemptDurationResolver::MAX_DURATION_MS, $resolver->normalizeClientMilliseconds(PHP_INT_MAX, PHP_INT_MAX));
    }
}
