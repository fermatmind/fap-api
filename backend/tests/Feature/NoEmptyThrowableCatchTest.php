<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class NoEmptyThrowableCatchTest extends TestCase
{
    #[Test]
    public function app_php_files_do_not_contain_empty_throwable_catch_blocks(): void
    {
        $offenders = [];

        foreach ($this->appPhpFiles() as $filePath) {
            $source = (string) file_get_contents($filePath);
            $relativePath = ltrim(str_replace(base_path(), '', $filePath), DIRECTORY_SEPARATOR);

            foreach ($this->extractCatchBlocks($source) as $block) {
                if (! $this->isThrowableCatchSignature($block['signature'])) {
                    continue;
                }

                $bodyWithoutComments = trim($this->stripComments($block['body']));
                if ($bodyWithoutComments !== '') {
                    continue;
                }

                $offenders[] = "{$relativePath}:{$block['line']}";
            }
        }

        if ($offenders !== []) {
            sort($offenders);

            self::fail(
                "Empty Throwable catch blocks found:\n".implode("\n", $offenders)
            );
        }

        self::assertTrue(true);
    }

    /**
     * @return array<int, string>
     */
    private function appPhpFiles(): array
    {
        $root = base_path('app');
        $files = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<int, array{signature:string, body:string, line:int}>
     */
    private function extractCatchBlocks(string $source): array
    {
        $tokens = token_get_all($source);
        $blocks = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token) || $token[0] !== T_CATCH) {
                continue;
            }

            $line = (int) $token[2];

            while ($i < $count && $this->tokenText($tokens[$i]) !== '(') {
                $i++;
            }
            if ($i >= $count || $this->tokenText($tokens[$i]) !== '(') {
                continue;
            }

            $signature = '(';
            $parenDepth = 1;
            $i++;
            for (; $i < $count; $i++) {
                $text = $this->tokenText($tokens[$i]);
                $signature .= $text;

                if ($text === '(') {
                    $parenDepth++;
                } elseif ($text === ')') {
                    $parenDepth--;
                    if ($parenDepth === 0) {
                        break;
                    }
                }
            }

            while ($i < $count && $this->tokenText($tokens[$i]) !== '{') {
                $i++;
            }
            if ($i >= $count || $this->tokenText($tokens[$i]) !== '{') {
                continue;
            }

            $body = '';
            $braceDepth = 1;
            $i++;
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
                'line' => $line,
            ];
        }

        return $blocks;
    }

    private function isThrowableCatchSignature(string $signature): bool
    {
        $normalized = preg_replace('/\s+/', '', $signature);
        if (! is_string($normalized)) {
            return false;
        }

        return preg_match('/^\(\\\\?Throwable(?:\\$[A-Za-z_][A-Za-z0-9_]*)?\)$/', $normalized) === 1;
    }

    /**
     * @param  string|array{int, string, int}  $token
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
