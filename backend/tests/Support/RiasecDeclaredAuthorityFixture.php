<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;

/** Reconstruct only the exact declared historical snapshot in an isolated test directory. */
final class RiasecDeclaredAuthorityFixture
{
    public static function restore(string $directory): void
    {
        $evidence = json_decode(
            (string) File::get($directory.'/external_package_evidence.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $scopeManifestPath = $directory.'/scope_manifest.json';
        $scopeManifest = (string) File::get($scopeManifestPath);
        $scopeManifest = str_replace(
            [
                '"current_en_count": 14',
                '"remaining_en_count": 0',
                '"parity_state": "en_complete"',
                '"notes": "14/14 groups complete. All EN draft payloads created. Pending independent W9 QA before runtime activation."',
            ],
            [
                '"current_en_count": 0',
                '"remaining_en_count": 14',
                '"parity_state": "en_missing"',
                '"notes": "The reader boundary is career-interest direction, not precise career matching."',
            ],
            $scopeManifest,
        );
        File::put($scopeManifestPath, rtrim($scopeManifest)."\n");

        foreach ((array) data_get($evidence, 'authority_snapshot.segment_payloads', []) as $segment) {
            $path = $directory.'/'.(string) ($segment['path'] ?? '');
            $expectedHash = (string) ($segment['sha256'] ?? '');
            $bytes = (string) File::get($path);
            if (hash_equals($expectedHash, hash('sha256', $bytes))) {
                continue;
            }

            $lines = array_values(array_filter(
                explode("\n", trim($bytes)),
                static fn (string $line): bool => $line !== '',
            ));
            $declaredLines = array_slice($lines, 0, (int) ($segment['row_count'] ?? 0));
            $declaredBytes = implode("\n", $declaredLines)."\n";
            Assert::assertSame($expectedHash, hash('sha256', $declaredBytes));
            File::put($path, $declaredBytes);
        }
    }
}
