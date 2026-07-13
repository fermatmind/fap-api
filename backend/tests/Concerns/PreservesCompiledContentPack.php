<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;

trait PreservesCompiledContentPack
{
    /** @var array<string, array{path: string, backup: string, existed: bool}> */
    private array $compiledContentPackBackups = [];

    protected function preserveCompiledContentPack(string $packId, string $version): void
    {
        $key = $packId.'/'.$version;
        if (isset($this->compiledContentPackBackups[$key])) {
            return;
        }

        $path = base_path('content_packs/'.$packId.'/'.$version.'/compiled');
        $backup = storage_path('framework/testing/content-pack-backups/'.bin2hex(random_bytes(12)));
        $existed = File::isDirectory($path);

        if ($existed) {
            File::ensureDirectoryExists(dirname($backup));
            File::copyDirectory($path, $backup);
        }

        $this->compiledContentPackBackups[$key] = [
            'path' => $path,
            'backup' => $backup,
            'existed' => $existed,
        ];
    }

    protected function tearDown(): void
    {
        try {
            foreach (array_reverse($this->compiledContentPackBackups) as $snapshot) {
                File::deleteDirectory($snapshot['path']);
                if ($snapshot['existed']) {
                    File::copyDirectory($snapshot['backup'], $snapshot['path']);
                }
                File::deleteDirectory($snapshot['backup']);
            }
        } finally {
            parent::tearDown();
        }
    }
}
