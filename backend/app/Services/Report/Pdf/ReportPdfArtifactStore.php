<?php

declare(strict_types=1);

namespace App\Services\Report\Pdf;

use Illuminate\Support\Facades\Storage;

final class ReportPdfArtifactStore
{
    public function path(string $scaleCode, string $attemptId, string $manifestHash, string $variant): string
    {
        $scale = strtoupper(trim($scaleCode));
        if ($scale === '') {
            $scale = 'UNKNOWN';
        }
        $scale = preg_replace('/[^A-Z0-9_]/', '_', $scale) ?? 'UNKNOWN';

        $attempt = trim($attemptId);
        if ($attempt === '') {
            $attempt = 'unknown_attempt';
        }
        $attempt = preg_replace('/[^a-zA-Z0-9\\-_]/', '_', $attempt) ?? 'unknown_attempt';

        $hash = trim($manifestHash) !== '' ? trim($manifestHash) : 'nohash';
        $hash = preg_replace('/[^a-zA-Z0-9\\-_\\.]/', '', $hash) ?? 'nohash';
        if ($hash === '') {
            $hash = 'nohash';
        }

        $variant = strtolower(trim($variant));
        $variant = in_array($variant, ['free', 'full'], true) ? $variant : 'free';

        return "private/reports/{$scale}/{$attempt}/{$hash}/report_{$variant}.pdf";
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
            return null;
        }

        $contents = $disk->get($path);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }
}
