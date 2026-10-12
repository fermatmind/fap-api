<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

final class DeployedRevisionController
{
    public function __construct(
        private readonly ?string $revisionPath = null,
    ) {}

    public function __invoke(): JsonResponse
    {
        $revision = $this->readRevision();
        $headers = [
            'Cache-Control' => 'no-store, max-age=0',
            'CDN-Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($revision === null) {
            return response()->json([
                'ok' => false,
                'error' => 'revision_unavailable',
            ], 503, $headers);
        }

        return response()->json([
            'revision' => $revision,
        ], 200, [
            ...$headers,
            'X-Revision' => $revision,
        ]);
    }

    private function readRevision(): ?string
    {
        $path = $this->revisionPath ?? dirname(base_path()).'/REVISION';
        if ($path === '' || is_link($path)) {
            return null;
        }

        $stat = @lstat($path);
        if (! is_array($stat)
            || ($stat['mode'] & 0170000) !== 0100000
            || $stat['size'] < 40
            || $stat['size'] > 42) {
            return null;
        }

        $contents = @file_get_contents($path);
        if (! is_string($contents)
            || preg_match('/\A(?<revision>[0-9a-f]{40})(?:\r?\n)?\z/D', $contents, $matches) !== 1) {
            return null;
        }

        return $matches['revision'];
    }
}
