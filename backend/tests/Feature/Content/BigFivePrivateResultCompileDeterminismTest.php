<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\BigFivePrivateResultCompileService;
use Tests\TestCase;

final class BigFivePrivateResultCompileDeterminismTest extends TestCase
{
    public function test_compile_is_byte_identical_and_hash_bound_to_canonical_sources(): void
    {
        $compiler = app(BigFivePrivateResultCompileService::class);
        $first = $compiler->compile();
        $second = $compiler->compile();

        $this->assertSame($first['bytes'], $second['bytes']);
        $this->assertSame($first['source_hash'], $second['source_hash']);
        $this->assertSame($first['compiled_hash'], $second['compiled_hash']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first['source_hash']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first['compiled_hash']);
        $this->assertSame(BigFivePrivateResultCompileService::SCHEMA, $first['payload']['schema']);
        $this->assertSame($first['source_hash'], $first['payload']['source_hash']);
        $this->assertSame($first['compiled_hash'], $first['payload']['compiled_hash']);

        $sourceFiles = $first['manifest']['source_files'];
        $paths = array_column($sourceFiles, 'path');
        $sortedPaths = $paths;
        sort($sortedPaths, SORT_STRING);
        $this->assertSame($sortedPaths, $paths);
        $this->assertNotContains('manifest.json', $paths);
        $this->assertFalse((bool) array_filter($paths, static fn (string $path): bool => str_starts_with($path, 'en/')));
        $this->assertFalse((bool) array_filter($paths, static fn (string $path): bool => str_contains($path, 'fixtures/')));

        foreach ($sourceFiles as $sourceFile) {
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $sourceFile['sha256']);
        }

        $committedManifest = json_decode(
            (string) file_get_contents(base_path('content_packs/BIG5_OCEAN/v2/registry/manifest.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame($first['manifest'], $committedManifest);

        $committedEnglishManifest = json_decode(
            (string) file_get_contents(base_path('content_packs/BIG5_OCEAN/v2/registry/en/manifest.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame($first['english_manifest'], $committedEnglishManifest);
    }
}
