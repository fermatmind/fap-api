<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MigrationsNoGuardedDropRollbackTest extends TestCase
{
    #[Test]
    public function guarded_migration_must_not_drop_table_in_down(): void
    {
        $violations = [];

        foreach ($this->migrationFiles() as $filePath) {
            $source = (string) file_get_contents($filePath);

            if (!str_contains($source, 'Schema::hasTable')) {
                continue;
            }

            $downBody = $this->extractDownBody($source);
            if ($downBody === null) {
                continue;
            }

            if (str_contains($downBody, 'Schema::dropIfExists')) {
                $violations[] = $filePath;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Guarded migration must not drop table in down()\n" . implode("\n", $violations)
        );
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

    private function extractDownBody(string $source): ?string
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $j = $i + 1;
            while ($j < $count) {
                $nameToken = $tokens[$j];
                if (is_array($nameToken) && $nameToken[0] === T_STRING) {
                    break;
                }
                $j++;
            }

            if ($j >= $count || !is_array($tokens[$j]) || strtolower($tokens[$j][1]) !== 'down') {
                continue;
            }

            while ($j < $count && $this->tokenText($tokens[$j]) !== '{') {
                $j++;
            }

            if ($j >= $count || $this->tokenText($tokens[$j]) !== '{') {
                return null;
            }

            $braceDepth = 1;
            $j++;
            $body = '';

            for (; $j < $count; $j++) {
                $text = $this->tokenText($tokens[$j]);

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
