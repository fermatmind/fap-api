<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

final class V0_3TraitReferenceTest extends TestCase
{
    public function test_v03_controller_traits_are_loadable(): void
    {
        $controllerRoot = app_path('Http/Controllers/API/V0_3');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($controllerRoot, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $scanned = 0;

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                continue;
            }

            $path = (string) $fileInfo->getPathname();
            $fqcn = $this->extractClassFqcn($path);
            if ($fqcn === null) {
                continue;
            }

            $this->assertTrue(class_exists($fqcn), "Controller class should be loadable: {$fqcn}");

            $reflection = new ReflectionClass($fqcn);
            foreach ($reflection->getTraitNames() as $traitName) {
                $this->assertTrue(
                    trait_exists($traitName),
                    "Trait [{$traitName}] referenced by [{$fqcn}] should exist."
                );
            }

            $scanned++;
        }

        $this->assertGreaterThan(0, $scanned, 'No v0.3 controller classes were scanned.');
    }

    private function extractClassFqcn(string $path): ?string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        if (!preg_match('/^namespace\s+([^;]+);/m', $contents, $namespaceMatch)) {
            return null;
        }

        if (!preg_match('/^(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $contents, $classMatch)) {
            return null;
        }

        $namespace = trim((string) ($namespaceMatch[1] ?? ''));
        $class = trim((string) ($classMatch[1] ?? ''));
        if ($namespace === '' || $class === '') {
            return null;
        }

        return $namespace.'\\'.$class;
    }
}
