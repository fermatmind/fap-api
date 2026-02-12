<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class ServiceLayerBoundaryTest extends TestCase
{
    public function test_service_layer_has_no_http_dependencies(): void
    {
        $servicesRoot = app_path('Services');
        $forbiddenTokens = [
            'request(',
            'abort(',
            'response(',
            'JsonResponse',
        ];

        $violations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($servicesRoot, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                continue;
            }

            $path = (string) $fileInfo->getPathname();
            $lines = @file($path);
            if (! is_array($lines)) {
                continue;
            }

            foreach ($lines as $index => $line) {
                foreach ($forbiddenTokens as $token) {
                    if (str_contains($line, $token)) {
                        $violations[] = sprintf('%s:%d => %s', $path, $index + 1, $token);
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Service layer must stay HTTP-free.\n".implode("\n", $violations)
        );
    }
}
