<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\CareerCliArtifactPathGuard;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class CareerCliArtifactPathGuardTest extends TestCase
{
    public function test_blank_output_path_is_ignored(): void
    {
        $this->assertNull(CareerCliArtifactPathGuard::outputPath(null));
        $this->assertNull(CareerCliArtifactPathGuard::outputPath('  '));
    }

    public function test_safe_json_output_is_written_under_existing_parent(): void
    {
        $dir = $this->tempDir();
        $path = $dir.'/report.json';

        $safePath = CareerCliArtifactPathGuard::writeJsonOutput($path, ['status' => 'pass']);

        $this->assertSame($path, $safePath);
        $this->assertSame('pass', json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['status']);
    }

    public function test_output_path_rejects_null_bytes_and_stream_wrappers(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a local filesystem path');

        CareerCliArtifactPathGuard::outputPath('php://filter/resource=/tmp/report.json');
    }

    public function test_output_path_rejects_missing_parent_directory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('parent directory must exist');

        CareerCliArtifactPathGuard::outputPath($this->tempDir().'/missing/report.json');
    }

    public function test_output_path_rejects_symlink_targets(): void
    {
        $dir = $this->tempDir();
        $target = $dir.'/target.json';
        $link = $dir.'/link.json';
        File::put($target, '{}');

        if (! @symlink($target, $link)) {
            $this->markTestSkipped('Symlink creation is not available in this environment.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not point to a symlink');

        CareerCliArtifactPathGuard::outputPath($link);
    }

    public function test_output_path_rejects_symlink_targets_with_trailing_separator(): void
    {
        $dir = $this->tempDir();
        $target = $dir.'/target.json';
        $link = $dir.'/link.json';
        File::put($target, '{}');

        if (! @symlink($target, $link)) {
            $this->markTestSkipped('Symlink creation is not available in this environment.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not point to a symlink');

        CareerCliArtifactPathGuard::outputPath($link.'/');
    }

    public function test_output_path_rejects_symlink_parent_directories(): void
    {
        $dir = $this->tempDir();
        $realParent = $dir.'/real';
        $linkParent = $dir.'/linked';
        File::makeDirectory($realParent);

        if (! @symlink($realParent, $linkParent)) {
            $this->markTestSkipped('Symlink creation is not available in this environment.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('parent directory must not be a symlink');

        CareerCliArtifactPathGuard::outputPath($linkParent.'/report.json');
    }

    private function tempDir(): string
    {
        $dir = storage_path('framework/testing/career-cli-path-'.Str::random(12));
        File::makeDirectory($dir, 0755, true);

        return $dir;
    }
}
