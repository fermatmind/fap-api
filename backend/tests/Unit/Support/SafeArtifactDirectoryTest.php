<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SafeArtifactDirectory;
use RuntimeException;
use Tests\TestCase;

final class SafeArtifactDirectoryTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->roots) as $root) {
            $this->removePath($root);
        }

        parent::tearDown();
    }

    public function test_temporary_directory_uses_unpredictable_path_and_ignores_predictable_symlink(): void
    {
        $root = $this->makeRoot();
        $finalDir = $root.DIRECTORY_SEPARATOR.'release';
        $predictableTmp = $finalDir.'.tmp';
        $victim = $root.DIRECTORY_SEPARATOR.'victim';
        file_put_contents($victim, 'do-not-touch');
        $this->assertTrue(symlink($victim, $predictableTmp));

        $tmpDir = SafeArtifactDirectory::createTemporaryDirectory($root, $finalDir);

        $this->assertDirectoryExists($tmpDir);
        $this->assertNotSame($predictableTmp, $tmpDir);
        $this->assertTrue(is_link($predictableTmp));
        $this->assertSame('do-not-touch', file_get_contents($victim));
    }

    public function test_finalize_refuses_to_replace_symlinked_final_path(): void
    {
        $root = $this->makeRoot();
        $finalDir = $root.DIRECTORY_SEPARATOR.'release';
        $victim = $root.DIRECTORY_SEPARATOR.'victim';
        mkdir($victim);

        $tmpDir = SafeArtifactDirectory::createTemporaryDirectory($root, $finalDir);
        file_put_contents($tmpDir.DIRECTORY_SEPARATOR.'artifact.json', '{"ok":true}');
        $this->assertTrue(symlink($victim, $finalDir));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists or is a symlink');

        try {
            SafeArtifactDirectory::finalize($tmpDir, $finalDir);
        } finally {
            $this->assertFileDoesNotExist($victim.DIRECTORY_SEPARATOR.'artifact.json');
            $this->assertTrue(is_link($finalDir));
        }
    }

    private function makeRoot(): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'safe-artifact-directory-'.bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $this->roots[] = $root;

        return $root;
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removePath($path.DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($path);
    }
}
