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
    ];

    #[Test]
    public function migrations_must_not_include_destructive_rollback_statements(): void
    {
        $files = glob(base_path('database/migrations/*.php'));
        if (!is_array($files)) {
            $this->fail('unable to read migration files');
        }

        sort($files);

        foreach ($files as $filePath) {
            $source = file_get_contents($filePath);
            $this->assertIsString($source, 'unable to read migration file: ' . $filePath);

            foreach (self::BLOCKED_PATTERNS as $pattern) {
                $this->assertStringNotContainsString(
                    $pattern,
                    $source,
                    sprintf('migration safety violation: file=%s pattern=%s', $filePath, $pattern)
                );
            }
        }
    }
}
