<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('content_pages')
            ->where('kind', 'help')
            ->where('slug', 'like', 'help-%')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $slug = trim((string) ($row->slug ?? ''));
                    if ($slug === '' || ! str_starts_with($slug, 'help-')) {
                        continue;
                    }

                    $publicPath = '/help/'.substr($slug, 5);
                    $currentPath = trim((string) ($row->path ?? ''));
                    $currentCanonical = trim((string) ($row->canonical_path ?? ''));

                    $pathUpdate = $currentPath === '' || $currentPath === '/'.$slug ? $publicPath : $currentPath;
                    $canonicalUpdate = $currentCanonical === '' || $currentCanonical === '/'.$slug ? $publicPath : $currentCanonical;

                    DB::table('content_pages')
                        ->where('id', (int) $row->id)
                        ->update([
                            'path' => $pathUpdate,
                            'canonical_path' => $canonicalUpdate,
                        ]);
                }
            });

        DB::table('cms_translation_revisions')
            ->where('content_type', 'content_page')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $payload = json_decode((string) ($row->payload_json ?? '{}'), true);
                    if (! is_array($payload)) {
                        $payload = [];
                    }

                    $slug = trim((string) ($payload['slug'] ?? ''));
                    $kind = trim((string) ($payload['kind'] ?? ''));
                    if ($kind !== 'help' || $slug === '' || ! str_starts_with($slug, 'help-')) {
                        continue;
                    }

                    $publicPath = '/help/'.substr($slug, 5);
                    $currentPath = trim((string) ($payload['path'] ?? ''));
                    $currentCanonical = trim((string) ($payload['canonical_path'] ?? ''));

                    if ($currentPath !== '' && $currentPath !== '/'.$slug && $currentCanonical !== '' && $currentCanonical !== '/'.$slug) {
                        continue;
                    }

                    $payload['path'] = $currentPath === '' || $currentPath === '/'.$slug ? $publicPath : $currentPath;
                    $payload['canonical_path'] = $currentCanonical === '' || $currentCanonical === '/'.$slug ? $publicPath : $currentCanonical;

                    DB::table('cms_translation_revisions')
                        ->where('id', (int) $row->id)
                        ->update([
                            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Forward-only normalization.
    }
};
