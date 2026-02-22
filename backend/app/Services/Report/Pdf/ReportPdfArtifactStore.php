<?php

declare(strict_types=1);

namespace App\Services\Report\Pdf;

use App\Services\Storage\ArtifactStore;
use Illuminate\Support\Facades\Storage;

final class ReportPdfArtifactStore
{
    public function __construct(
        private readonly ArtifactStore $artifactStore
    ) {
    }

    public function path(string $scaleCode, string $attemptId, string $manifestHash, string $variant): string
    {
        return $this->artifactStore->pdfPath($scaleCode, $attemptId, $manifestHash, $variant);
    }

    public function exists(string $path): bool
    {
        return Storage::disk('local')->exists($path);
    }

    public function put(string $path, string $bytes): void
    {
        Storage::disk('local')->put($path, $bytes);
    }

    public function get(string $path): ?string
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            if (preg_match('#^artifacts/pdf/([^/]+)/([^/]+)/([^/]+)/report_(free|full)\\.pdf$#i', $path, $m) === 1) {
                $fallback = $this->artifactStore->getPdf(
                    (string) ($m[1] ?? ''),
                    (string) ($m[2] ?? ''),
                    (string) ($m[3] ?? ''),
                    (string) ($m[4] ?? 'free')
                );

                if (is_array($fallback) && is_string($fallback['binary'] ?? null)) {
                    return $fallback['binary'];
                }
            }

            return null;
        }

        $contents = $disk->get($path);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }
}
