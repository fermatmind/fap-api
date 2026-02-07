<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MigrationsNoSilentCatchTest extends TestCase
{
    #[Test]
    public function migration_catch_blocks_are_not_empty_or_comment_only(): void
    {
        foreach ($this->migrationFiles() as $filePath) {
            $source = (string) file_get_contents($filePath);
            foreach ($this->extractCatchBodies($source) as $catchBody) {
                $bodyWithoutComments = trim($this->stripComments($catchBody));
                $this->assertNotSame('', $bodyWithoutComments, "Empty catch block found in {$filePath}");
            }
        }
    }

    #[Test]
    public function migration_catch_blocks_include_classifier_or_throw(): void
    {
        foreach ($this->migrationFiles() as $filePath) {
            $source = (string) file_get_contents($filePath);
            foreach ($this->extractCatchBodies($source) as $catchBody) {
                $bodyWithoutComments = $this->stripComments($catchBody);
                $hasClassifier = preg_match('/SchemaIndex::is(?:Duplicate|Missing)IndexException/', $bodyWithoutComments) === 1;
                $hasThrow = preg_match('/\bthrow\b/', $bodyWithoutComments) === 1;

                $this->assertTrue(
                    $hasClassifier || $hasThrow,
                    "Catch block must include classifier-or-throw in {$filePath}"
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

    /**
     * @return array<int, string>
     */
    private function extractCatchBodies(string $source): array
    {
        $tokens = token_get_all($source);
        $bodies = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_CATCH) {
                continue;
            }

            while ($i < $count && $this->tokenText($tokens[$i]) !== '{') {
                $i++;
            }

            if ($i >= $count || $this->tokenText($tokens[$i]) !== '{') {
                continue;
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
                        break;
                    }
                    $body .= $text;
                    continue;
                }

                $body .= $text;
            }

            $bodies[] = $body;
        }

        return $bodies;
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

    private function stripComments(string $source): string
    {
        $withoutBlock = preg_replace('/\/\*.*?\*\//s', '', $source);
        $withoutLine = preg_replace('/\/\/[^\n]*|#[^\n]*/', '', (string) $withoutBlock);

        return (string) $withoutLine;
    }
}
