<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class IqSvgProvenanceVerificationTest extends TestCase
{
    public function test_build_script_reproduces_committed_legacy_demo_manifests(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/iq/build_legacy_svg_provenance_manifest.php'),
            '--check',
        ], base_path());
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            $process->getErrorOutput().$process->getOutput()
        );
    }

    public function test_verify_script_accepts_committed_iq_legacy_demo_manifests(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/iq/verify_legacy_svg_provenance.php'),
            '--json',
        ], base_path());
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            $process->getErrorOutput().$process->getOutput()
        );

        $payload = json_decode($process->getOutput(), true);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $this->assertCount(2, $payload['packs'] ?? []);
        $this->assertSame(30, data_get($payload, 'packs.0.item_count'));
        $this->assertSame(30, data_get($payload, 'packs.1.item_count'));
    }

    public function test_verify_script_detects_inline_svg_hash_mismatch_on_tampered_pack_copy(): void
    {
        $sourceDir = base_path('../content_packages/default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO');
        $tempRoot = sys_get_temp_dir().'/iq_svg_provenance_'.bin2hex(random_bytes(4));
        $tempPackDir = $tempRoot.'/IQ-RAVEN-CN-v0.3.0-DEMO';

        $this->copyDirectory($sourceDir, $tempPackDir);

        $questionsPath = $tempPackDir.'/questions.json';
        $questions = json_decode((string) file_get_contents($questionsPath), true);
        $this->assertIsArray($questions);
        $questions['items'][0]['stem']['svg']['paths'][0]['d'] .= ' M0 0';
        file_put_contents(
            $questionsPath,
            json_encode($questions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL
        );

        $process = new Process([
            PHP_BINARY,
            base_path('scripts/iq/verify_legacy_svg_provenance.php'),
            '--pack-dir='.$tempPackDir,
        ], base_path());
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('asset hash mismatch', $process->getErrorOutput());

        $this->removeDirectory($tempRoot);
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (! is_dir($target) && ! mkdir($target, 0777, true) && ! is_dir($target)) {
            $this->fail('failed to create temp directory: '.$target);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $destination = $target.DIRECTORY_SEPARATOR.$iterator->getSubPathName();
            if ($item->isDir()) {
                if (! is_dir($destination) && ! mkdir($destination, 0777, true) && ! is_dir($destination)) {
                    $this->fail('failed to create copied directory: '.$destination);
                }

                continue;
            }

            if (! copy($item->getPathname(), $destination)) {
                $this->fail('failed to copy file: '.$item->getPathname());
            }
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $target = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($target);

                continue;
            }

            @unlink($target);
        }

        @rmdir($path);
    }
}
