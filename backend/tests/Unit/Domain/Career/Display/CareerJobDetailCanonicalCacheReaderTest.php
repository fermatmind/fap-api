<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerCurrentAuthorityPackageLoader;
use App\Domain\Career\Display\CareerJobDetailCanonicalCacheReader;
use Tests\TestCase;

final class CareerJobDetailCanonicalCacheReaderTest extends TestCase
{
    public function test_it_reads_gzip_and_legacy_payloads_with_identical_content_v3_hydration(): void
    {
        $reader = app(CareerJobDetailCanonicalCacheReader::class);
        $authority = app(CareerCurrentAuthorityPackageLoader::class)->loadShardedForPublish(base_path());
        $row = $authority['rows']['accountants-and-auditors'];
        $payload = ['display_surface_v1' => app(\App\Domain\Career\Display\CareerCurrentAuthorityPackage::class)
            ->publicProjection($row, 'en')];
        $compact = $reader->withoutDerivedContentV3($payload, 'accountants-and-auditors', 'en');

        $gzip = $reader->read($reader->encode($compact), 'accountants-and-auditors', 'en');
        $legacy = $reader->read($compact, 'accountants-and-auditors', 'en');

        self::assertTrue($reader->isSupportedEnvelope($reader->encode($compact)));
        self::assertFalse($reader->isSupportedEnvelope($compact));
        self::assertSame($payload, $gzip);
        self::assertSame($gzip, $legacy);
        self::assertSame('enhanced', data_get($gzip, 'display_surface_v1.content_v3.content_state'));
    }

    public function test_it_fails_closed_for_a_corrupt_checksum_or_unknown_envelope(): void
    {
        $reader = app(CareerJobDetailCanonicalCacheReader::class);
        $authority = app(CareerCurrentAuthorityPackageLoader::class)->loadShardedForPublish(base_path());
        $row = $authority['rows']['actors'];
        $payload = ['display_surface_v1' => app(\App\Domain\Career\Display\CareerCurrentAuthorityPackage::class)
            ->publicProjection($row, 'zh-CN')];
        $stored = $reader->encode($reader->withoutDerivedContentV3($payload, 'actors', 'zh-CN'));
        $stored['sha256'] = str_repeat('0', 64);

        self::assertNull($reader->read($stored, 'actors', 'zh-CN'));
        self::assertNull($reader->read([
            'codec' => 'career.job-detail.unknown.v2',
            'payload' => '',
            'sha256' => str_repeat('0', 64),
        ], 'actors', 'zh-CN'));
    }

    public function test_it_normalizes_reviews_and_validates_snapshot_identity(): void
    {
        $reader = app(CareerJobDetailCanonicalCacheReader::class);
        $normalized = $reader->normalizeReviewContainer([
            'reviewer_status' => 'human_reviewed',
            'reviewed_at' => '2026-08-28T00:00:00Z',
        ]);

        self::assertSame('approved', $normalized['review_state']);
        self::assertNull($normalized['reviewer']);
        self::assertTrue($reader->snapshotIsValid(
            ['slug' => 'actors', 'locale' => 'en', 'state' => 'published'],
            'actors',
            'en',
            static fn (array $snapshot): bool => $snapshot['state'] === 'published',
        ));
        self::assertFalse($reader->snapshotIsValid(
            ['slug' => 'actors', 'locale' => 'zh-CN', 'state' => 'published'],
            'actors',
            'en',
            static fn (): bool => true,
        ));
    }
}
