<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AttemptSubmitServiceConstructorLimitTest extends TestCase
{
    #[Test]
    public function constructor_type_hint_count_does_not_exceed_limit(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/Attempts/AttemptSubmitService.php'));

        if (!preg_match('/function\s+__construct\s*\((.*?)\)\s*\{/s', $source, $matches)) {
            self::fail('AttemptSubmitService::__construct not found.');
        }

        $rawParams = trim((string) ($matches[1] ?? ''));
        if ($rawParams === '') {
            self::assertTrue(true);
            return;
        }

        $parts = array_filter(array_map('trim', explode(',', $rawParams)), static fn (string $part): bool => $part !== '');
        $typedCount = 0;
        foreach ($parts as $part) {
            if (preg_match('/\$[A-Za-z_][A-Za-z0-9_]*/', $part) !== 1) {
                continue;
            }
            if (preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\s+\$/', $part) === 1) {
                $typedCount++;
            }
        }

        self::assertLessThanOrEqual(8, $typedCount, "AttemptSubmitService constructor dependencies must be <= 8, got {$typedCount}");
    }
}
