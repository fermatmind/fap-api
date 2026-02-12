<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class NoErrorKeyInApiResponsesTest extends TestCase
{
    public function test_api_response_builders_use_error_code_key_only(): void
    {
        $scanTargets = [
            app_path('Http/Controllers/API/V0_3'),
            app_path('Http/Controllers/API/V0_2/MeController.php'),
            app_path('Services/Org/InviteService.php'),
        ];

        $forbiddenTokens = [
            "'error' =>",
            '"error" =>',
        ];

        $violations = [];

        foreach ($scanTargets as $target) {
            if (is_file($target)) {
                $this->collectViolations($target, $forbiddenTokens, $violations);
                continue;
            }

            if (!is_dir($target)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                    continue;
                }

                $this->collectViolations((string) $fileInfo->getPathname(), $forbiddenTokens, $violations);
            }
        }

        $this->assertEmpty(
            $violations,
            "API response code paths must not emit legacy error key.\n".implode("\n", $violations)
        );
    }

    /**
     * @param array<int, string> $forbiddenTokens
     * @param array<int, string> $violations
     */
    private function collectViolations(string $path, array $forbiddenTokens, array &$violations): void
    {
        $lines = @file($path);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $index => $line) {
            foreach ($forbiddenTokens as $token) {
                if (str_contains($line, $token)) {
                    $violations[] = sprintf('%s:%d => %s', $path, $index + 1, $token);
                }
            }
        }
    }
}
