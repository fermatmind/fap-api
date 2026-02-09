<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CreateMigrationsMustConvergeTest extends TestCase
{
    #[Test]
    public function guarded_create_migration_must_include_schema_table_patch_in_up(): void
    {
        $violations = [];

        foreach ($this->migrationFiles() as $filePath) {
            $source = (string) file_get_contents($filePath);
            $upBody = $this->methodBody($source, 'up');

            if ($upBody === null) {
                continue;
            }

            $matched = preg_match_all(
                "/if\\s*\\(\\s*Schema::hasTable\\('([^']+)'\\)\\s*\\)\\s*\\{\\s*return;\\s*\\}/m",
                $upBody,
                $matches
            );

            if ($matched === false || $matched === 0) {
                continue;
            }

            foreach ($matches[1] as $tableName) {
                $tablePattern = "/Schema::table\\s*\\(\\s*'" . preg_quote((string) $tableName, '/') . "'\\s*,/m";
                if (preg_match($tablePattern, $upBody) !== 1) {
                    $violations[] = "{$filePath} -> {$tableName}";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Found non-convergent guarded create migrations (missing Schema::table patch in up()):\n" . implode("\n", $violations)
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

    private function methodBody(string $source, string $methodName): ?string
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $name = null;
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (is_array($next) && $next[0] === T_STRING) {
                    $name = $next[1];
                    $i = $j;
                    break;
                }
            }

            if ($name !== $methodName) {
                continue;
            }

            while ($i < $count && $this->tokenText($tokens[$i]) !== '{') {
                $i++;
            }

            if ($i >= $count || $this->tokenText($tokens[$i]) !== '{') {
                return null;
            }

            $braceDepth = 1;
            $i++;
            $body = '';

            for (; $i < $count; $i++) {
                $text = $this->tokenText($tokens[$i]);

                if ($text === '{') {
                    $braceDepth++;
                    $body .= $text;
                    continue;
                }

                if ($text === '}') {
                    $braceDepth--;
                    if ($braceDepth === 0) {
                        return $body;
                    }
                    $body .= $text;
                    continue;
                }

                $body .= $text;
            }

            return null;
        }

        return null;
    }

    /**
     * @param string|array{int, string, int} $token
     */
    private function tokenText(string|array $token): string
    {
        if (is_string($token)) {
            return $token;
        }

        return $token[1];
    }
}
