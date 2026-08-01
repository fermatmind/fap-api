<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use PHPUnit\Framework\TestCase;

final class RiasecEnglishDimensionAssetsPackageTest extends TestCase
{
    private const PACKAGE_DIR = __DIR__.'/../../../../content_assets/en-content-parity/W4-riasec/dimension-assets-01';

    public function test_dimension_candidate_package_is_exactly_two_groups_and_forty_two_rows(): void
    {
        $manifest = $this->json('package_manifest.json');
        $map = $this->json('translation_map.json');
        $assets = $this->json('assets.json');

        $this->assertSame(['W4-G01', 'W4-G02'], $manifest['groups']);
        $this->assertSame(2, $manifest['logical_group_count']);
        $this->assertSame(42, $manifest['atomic_row_count']);
        $this->assertCount(42, $map['rows']);
        $this->assertCount(42, array_unique(array_column($map['rows'], 'row_id')));
        $this->assertCount(6, $assets['dimensions']);
    }

    public function test_candidate_is_english_only_and_not_runtime_or_release_ready(): void
    {
        $assets = $this->json('assets.json');
        $review = $this->json('editorial_review.json');
        $payload = json_encode([$assets, $review], JSON_THROW_ON_ERROR);

        $this->assertSame('en', $assets['locale']);
        $this->assertFalse($assets['runtime_ready']);
        $this->assertSame('pending_independent_w9', $review['status']);
        $this->assertSame(0, preg_match('/[一-龥]/u', $payload));
        foreach ($assets['permissions'] as $allowed) {
            $this->assertFalse($allowed);
        }
    }

    public function test_manifest_binds_every_payload_hash_and_aggregate_hash(): void
    {
        $manifest = $this->json('package_manifest.json');
        $aggregate = '';

        foreach ($manifest['files'] as $file) {
            self::assertIsArray($file);
            $path = (string) ($file['path'] ?? '');
            $declaredHash = (string) ($file['sha256'] ?? '');

            $this->assertSame($declaredHash, hash_file('sha256', self::PACKAGE_DIR.'/'.$path));
            $aggregate .= $path."\0".strtolower($declaredHash)."\n";
        }

        $this->assertSame($manifest['package_sha256'], hash('sha256', $aggregate));
    }

    /** @return array<string,mixed> */
    private function json(string $filename): array
    {
        $decoded = json_decode((string) file_get_contents(self::PACKAGE_DIR.'/'.$filename), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
