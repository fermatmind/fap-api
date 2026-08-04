<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MigrationSafetyTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const BLOCKED_PATTERNS = [
        'dropIfExists(',
        'dropColumn(',
        'dropTable(',
        'renameColumn(',
        '->change(',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const PATTERN_EXCEPTIONS = [
        'dropColumn(' => ['RETIREMENT_EVIDENCE_ID'],
        // widen_content_pack_release_hash_columns uses ->change() to widen
        // char/varchar hash columns from 64 to 71 chars for the sha256: prefix.
        // This is a non-destructive width increase with a symmetrical down()
        // that restores the original width.
        '->change(' => ['widen_content_pack_release_hash_columns'],
    ];

    #[Test]
    public function migrations_must_not_include_destructive_rollback_statements(): void
    {
        $files = glob(base_path('database/migrations/*.php'));
        if (! is_array($files)) {
            $this->fail('unable to read migration files');
        }

        sort($files);

        foreach ($files as $filePath) {
            $source = file_get_contents($filePath);
            $this->assertIsString($source, 'unable to read migration file: '.$filePath);

            foreach (self::BLOCKED_PATTERNS as $pattern) {
                foreach (self::PATTERN_EXCEPTIONS[$pattern] ?? [] as $exceptionMarker) {
                    if (str_contains($filePath, $exceptionMarker) || str_contains($source, $exceptionMarker)) {
                        continue 2;
                    }
                }

                $this->assertStringNotContainsString(
                    $pattern,
                    $source,
                    sprintf('migration safety violation: file=%s pattern=%s', $filePath, $pattern)
                );
            }
        }
    }
}
