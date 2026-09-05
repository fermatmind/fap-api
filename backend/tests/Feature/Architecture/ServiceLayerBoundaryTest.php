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
        $scan = require base_path('scripts/ci/php_source_calls.php');

        $violations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($servicesRoot, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                continue;
            }

            $path = (string) $fileInfo->getPathname();
            foreach ($scan((string) file_get_contents($path), ['request', 'abort', 'response'], ['jsonresponse']) as $match) {
                $violations[] = sprintf('%s:%d => %s', $path, $match['line'], $match['name']);
            }
        }

        $this->assertEmpty(
            $violations,
            "Service layer must stay HTTP-free.\n".implode("\n", $violations)
        );
    }
}
