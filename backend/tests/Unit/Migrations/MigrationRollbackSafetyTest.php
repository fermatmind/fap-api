<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MigrationRollbackSafetyTest extends TestCase
{
    #[Test]
    public function create_migrations_with_has_table_guard_must_not_drop_in_down(): void
    {
        foreach ($this->migrationFiles() as $filePath) {
            if (!str_contains(basename($filePath), 'create_')) {
                continue;
            }

            $source = (string) file_get_contents($filePath);
            $upBody = $this->methodBody($source, 'up');
            $downBody = $this->methodBody($source, 'down');

            if ($upBody === null || $downBody === null) {
                continue;
            }

            $hasHasTableGuard = str_contains($upBody, 'Schema::hasTable(');
            $hasDropIfExists = preg_match('/^\s*Schema::dropIfExists\s*\(/m', $downBody) === 1;

            if ($hasHasTableGuard) {
                $this->assertFalse(
                    $hasDropIfExists,
                    "Pseudo-create migration must not drop table in down(): {$filePath}"
                );
            }
        }
    }

    /**
     * @return array<int, string>
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
