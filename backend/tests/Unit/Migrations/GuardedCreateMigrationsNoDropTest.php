<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GuardedCreateMigrationsNoDropTest extends TestCase
{
    #[Test]
    public function migration_file_must_not_contain_has_table_and_drop_if_exists_together(): void
    {
        $violations = [];

        foreach ($this->migrationFiles() as $filePath) {
            $source = (string) file_get_contents($filePath);

            if (str_contains($source, 'Schema::hasTable') && str_contains($source, 'Schema::dropIfExists')) {
                $violations[] = $filePath;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Found guarded-create rollback risk migrations:\n" . implode("\n", $violations)
        );
    }

    /**
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        $files = glob(base_path('database/migrations/*.php'));

        if (!is_array($files)) {
            return [];
        }

        sort($files);

        return array_values($files);
    }
}
