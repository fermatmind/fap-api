<?php

declare(strict_types=1);

namespace App\Services\Career\Dataset;

use App\Domain\Career\Production\CareerAssetBatchManifestBuilder;
use App\Domain\Career\Publish\FirstWaveBlockedRegistryReader;
use App\Domain\Career\Publish\FirstWaveManifestReader;

final class CareerPublishTrackResolver
{
    /**
     * @var list<string>
     */
    private const BATCH_MANIFEST_PATHS = [
        'docs/career/batches/batch_2_manifest.json',
        'docs/career/batches/batch_3_manifest.json',
        'docs/career/batches/batch_4_manifest.json',
    ];

    /** @var array<string, string>|null */
    private ?array $batchTracks = null;

    /** @var array<string, string>|null */
    private ?array $firstWaveTracks = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $reconciliation = null;

    public function __construct(
        private readonly CareerAssetBatchManifestBuilder $batchManifestBuilder,
        private readonly FirstWaveManifestReader $firstWaveManifestReader,
        private readonly FirstWaveBlockedRegistryReader $blockedRegistryReader,
        private readonly CareerPublishTrackReconciliationReader $reconciliationReader,
    ) {}

    /**
     * Resolution order is intentionally fixed: current batch manifest, current
     * first-wave authority, runtime projection, then explicit reconciliation.
     *
     * @param  array<string, mixed>|null  $runtimeProjectionItem
     */
    public function resolve(string $slug, ?array $runtimeProjectionItem = null): ?string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }

        $batchTrack = $this->batchTracks()[$slug] ?? null;
        if ($batchTrack !== null) {
            return $batchTrack;
        }

        $firstWaveTrack = $this->firstWaveTracks()[$slug] ?? null;
        if ($firstWaveTrack !== null) {
            return $firstWaveTrack;
        }

        if ($runtimeProjectionItem !== null) {
            return 'runtime_publish_projection';
        }

        $reconciliation = $this->reconciliation()[$slug] ?? null;

        if (is_array($reconciliation)) {
            return $this->normalizeTrack($reconciliation['publish_track'] ?? null) ?? 'review_needed';
        }

        // A newly observed member must never regain an unknown track merely
        // because the explicit audit record has not caught up yet.
        return 'review_needed';
    }

    /** @return array<string, string> */
    private function batchTracks(): array
    {
        if ($this->batchTracks !== null) {
            return $this->batchTracks;
        }

        $this->batchTracks = [];
        foreach (self::BATCH_MANIFEST_PATHS as $path) {
            $manifest = $this->batchManifestBuilder->fromPath($path);
            foreach ($manifest->members as $member) {
                $slug = strtolower(trim((string) $member->canonicalSlug));
                $track = $this->normalizeTrack($member->expectedPublishTrack);
                if ($slug !== '' && $track !== null) {
                    $this->batchTracks[$slug] = $track;
                }
            }
        }

        return $this->batchTracks;
    }

    /** @return array<string, string> */
    private function firstWaveTracks(): array
    {
        if ($this->firstWaveTracks !== null) {
            return $this->firstWaveTracks;
        }

        $blocked = $this->blockedRegistryReader->bySlug();
        $this->firstWaveTracks = [];
        foreach ($this->firstWaveManifestReader->read()['occupations'] as $row) {
            $slug = strtolower(trim((string) ($row['canonical_slug'] ?? '')));
            $track = $this->normalizeTrack($row['wave_classification'] ?? null);
            if ($slug === '' || $track === null) {
                continue;
            }

            // A historical classification cannot imply current approval when a
            // current blocked record requires review.
            $this->firstWaveTracks[$slug] = isset($blocked[$slug]) ? 'review_needed' : $track;
        }

        return $this->firstWaveTracks;
    }

    /** @return array<string, array<string, mixed>> */
    private function reconciliation(): array
    {
        return $this->reconciliation ??= $this->reconciliationReader->bySlug();
    }

    private function normalizeTrack(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $track = trim((string) $value);

        return $track === '' ? null : $track;
    }
}
