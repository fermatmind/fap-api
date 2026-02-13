<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class NoFailOpenThrowableCatchTest extends TestCase
{
    #[Test]
    public function fm_token_auth_does_not_fail_open_on_throwable(): void
    {
        $source = (string) file_get_contents(base_path('app/Http/Middleware/FmTokenAuth.php'));
        self::assertStringContainsString('catch (\\Throwable', $source);
        self::assertSame(
            0,
            preg_match('/catch\\s*\\(\\s*\\\\?Throwable\\b[^\\)]*\\)\\s*\\{[^\\}]*return\\s+true\\s*;/s', $source),
            'FmTokenAuth Throwable catch must not return true.'
        );
    }

    #[Test]
    public function key_paths_throwable_catch_requires_logging_for_fallback(): void
    {
        $targets = [
            base_path('app/Services/Report'),
            base_path('app/Internal/Commerce'),
            base_path('app/Services/Attempts'),
            base_path('app/Services/Benefits'),
            base_path('app/Services/Legacy/Mbti'),
        ];

        $offenders = [];

        foreach ($targets as $target) {
            if (!is_dir($target)) {
                continue;
            }

            foreach ($this->phpFiles($target) as $filePath) {
                $relative = ltrim(str_replace(base_path() . DIRECTORY_SEPARATOR, '', $filePath), DIRECTORY_SEPARATOR);
                $source = (string) file_get_contents($filePath);

                foreach ($this->extractThrowableCatchBlocks($source) as $block) {
                    $body = $block['body'];
                    $line = $block['line'];

                    if (str_contains($body, 'return true;')) {
                        $offenders[] = "{$relative}:{$line} return true forbidden";
                        continue;
                    }

                    $hasFallback = str_contains($body, 'return null;') || str_contains($body, 'continue;');
                    if ($hasFallback && !str_contains($body, 'Log::')) {
                        $offenders[] = "{$relative}:{$line} fallback without Log::";
                    }
                }
            }
        }

        if ($offenders !== []) {
            sort($offenders);
            self::fail("Throwable catch rule violations:\n" . implode("\n", $offenders));
        }

        self::assertTrue(true);
    }

    /**
     * @return array<int, array{signature:string, body:string, line:int}>
     */
    private function extractThrowableCatchBlocks(string $source): array
    {
        $tokens = token_get_all($source);
        $blocks = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_CATCH) {
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

            $normalized = preg_replace('/\s+/', '', $signature);
            if (!is_string($normalized) || preg_match('/^\(\\?Throwable(?:\$[A-Za-z_][A-Za-z0-9_]*)?\)$/', $normalized) !== 1) {
                continue;
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

    /**
     * @return array<int, string>
     */
    private function phpFiles(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    /**
     * @param string|array{int, string, int} $token
     */
    private function tokenText(string|array $token): string
    {
        return is_string($token) ? $token : $token[1];
    }
}
