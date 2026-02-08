<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MigrationSafetyTest extends TestCase
{
    #[Test]
    public function migration_catch_blocks_must_not_be_empty_after_comment_strip(): void
    {
        foreach ($this->migrationFiles() as $filePath) {
            $source = (string) file_get_contents($filePath);

            foreach ($this->extractCatchBodies($source) as $catchBlock) {
                $signature = $catchBlock['signature'];
                if (!$this->isThrowableOrExceptionCatch($signature)) {
                    continue;
                }

                $bodyWithoutComments = trim($this->stripComments($catchBlock['body']));
                $this->assertNotSame('', $bodyWithoutComments, "Empty catch block found in {$filePath}");
            }
        }
    }

    #[Test]
    public function pseudo_create_migrations_must_not_drop_tables_in_down(): void
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

            $upBodyWithoutComments = $this->stripComments($upBody);
            $downBodyWithoutComments = $this->stripComments($downBody);
            $hasHasTableGuard = str_contains($upBodyWithoutComments, 'Schema::hasTable(')
                && preg_match('/\breturn\s*;/', $upBodyWithoutComments) === 1;
            $hasDropStatement = preg_match('/\bSchema::dropIfExists\s*\(/', $downBodyWithoutComments) === 1
                || preg_match('/\bSchema::drop\s*\(/', $downBodyWithoutComments) === 1;

            if ($hasHasTableGuard) {
                $this->assertFalse(
                    $hasDropStatement,
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

    /**
     * @return array<int, array{signature: string, body: string}>
     */
    private function extractCatchBodies(string $source): array
    {
        $tokens = token_get_all($source);
        $blocks = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_CATCH) {
                continue;
            }

            $signature = '';
            $i++;
            while ($i < $count && $this->tokenText($tokens[$i]) !== '{') {
                $signature .= $this->tokenText($tokens[$i]);
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

            $blocks[] = [
                'signature' => $signature,
                'body' => $body,
            ];
        }

        return $blocks;
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

    private function isThrowableOrExceptionCatch(string $signature): bool
    {
        return preg_match('/\\\\?(Throwable|Exception)\b/', $signature) === 1;
    }

    private function stripComments(string $source): string
    {
        $code = str_starts_with(ltrim($source), '<?php') ? $source : "<?php\n{$source}";
        $tokens = token_get_all($code);
        $output = '';

        foreach ($tokens as $token) {
            if (is_string($token)) {
                $output .= $token;
                continue;
            }

            $tokenId = $token[0];
            if ($tokenId === T_COMMENT || $tokenId === T_DOC_COMMENT || $tokenId === T_OPEN_TAG) {
                continue;
            }

            $output .= $token[1];
        }

        return $output;
    }
}
