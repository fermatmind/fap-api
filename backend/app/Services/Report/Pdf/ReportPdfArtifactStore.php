<?php

declare(strict_types=1);

namespace App\Services\Report\Pdf;

use App\Services\Storage\ArtifactStore;

final class ReportPdfArtifactStore
{
    public function __construct(
        private readonly ArtifactStore $artifactStore,
    ) {}

    public function path(string $scaleCode, string $attemptId, string $manifestHash, string $variant): string
    {
        return $this->artifactStore->pdfCanonicalPath($scaleCode, $attemptId, $manifestHash, $variant);
    }

    /**
     * @return list<string>
     */
    public function legacyPaths(string $scaleCode, string $attemptId, string $manifestHash, string $variant): array
    {
        // Big Five v1 PDFs were metadata-only and cannot satisfy the reader report contract.
        if (strtoupper(trim($scaleCode)) === 'BIG5_OCEAN') {
            return [];
        }

        return $this->artifactStore->pdfLegacyPaths($scaleCode, $attemptId, $manifestHash, $variant);
    }

    public function exists(string $path): bool
    {
        return $this->artifactStore->exists($path);
    }

    public function put(string $path, string $bytes): void
    {
        $this->artifactStore->putPdf($path, $bytes);
    }

    public function get(string $path): ?string
    {
        return $this->artifactStore->get($path);
    }

    /**
     * @param  list<string>  $paths
     */
    public function getFirst(array $paths): ?string
    {
        return $this->artifactStore->getFirstFile($paths);
    }
}
