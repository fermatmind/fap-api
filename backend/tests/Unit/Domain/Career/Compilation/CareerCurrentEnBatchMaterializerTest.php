<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerCurrentEnBatchMaterializer;
use App\Domain\Career\Compilation\CareerTenBlockCompileFailure;
use Tests\TestCase;

final class CareerCurrentEnBatchMaterializerTest extends TestCase
{
    public function test_command_exposes_no_alternate_current_package_write_path(): void
    {
        $source = (string) file_get_contents(base_path('app/Console/Commands/CareerMaterializeCurrentEnBatch.php'));

        self::assertStringNotContainsString('--target=', $source);
        self::assertStringContainsString("['local', 'testing']", (string) file_get_contents(
            base_path('app/Domain/Career/Compilation/CareerCurrentEnBatchMaterializer.php'),
        ));
        self::assertStringContainsString('$slug === \'software-developers\'', (string) file_get_contents(
            base_path('app/Domain/Career/Compilation/CareerCurrentEnBatchMaterializer.php'),
        ));
    }

    public function test_it_rejects_a_source_with_the_wrong_aggregate(): void
    {
        $sourceRoot = $this->temporaryDirectory('career-en-wrong-source-');
        $planRoot = $this->temporaryDirectory('career-en-unused-plan-');
        $outputRoot = $this->temporaryDirectory('career-en-unused-output-');
        try {
            $this->expectFailure('CURRENT_EN_AUTHORITY_HASH_MISMATCH', fn () => app(CareerCurrentEnBatchMaterializer::class)->materialize(
                $sourceRoot,
                $planRoot,
                'batch-001',
                str_repeat('0', 64),
                base_path(),
                $outputRoot,
                false,
                'testing',
            ));
        } finally {
            $this->removeTree($outputRoot);
            $this->removeTree($planRoot);
            $this->removeTree($sourceRoot);
        }
    }

    private function expectFailure(string $safeCode, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected materialization failure '.$safeCode);
        } catch (CareerTenBlockCompileFailure $failure) {
            self::assertSame($safeCode, $failure->safeCode);
        }
    }

    private function temporaryDirectory(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        self::assertIsString($path);
        self::assertTrue(unlink($path));
        self::assertTrue(mkdir($path, 0700));

        return $path;
    }

    private function removeTree(string $root): void
    {
        if (! is_dir($root) || is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }
}
