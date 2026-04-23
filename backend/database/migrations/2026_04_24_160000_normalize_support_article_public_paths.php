<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('support_articles')
            ->where(function ($query): void {
                $query->whereNull('canonical_path')
                    ->orWhere('canonical_path', '')
                    ->orWhereRaw("canonical_path = CONCAT('/support/', slug)");
            })
            ->update([
                'canonical_path' => DB::raw("CONCAT('/support/articles/', slug)"),
            ]);

        DB::table('cms_translation_revisions')
            ->where('content_type', 'support_article')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $payload = json_decode((string) ($row->payload_json ?? '{}'), true);
                    if (! is_array($payload)) {
                        $payload = [];
                    }

                    $slug = trim((string) ($payload['slug'] ?? ''));
                    $canonicalPath = trim((string) ($payload['canonical_path'] ?? ''));

                    if ($slug === '') {
                        $contentRow = DB::table('support_articles')->where('id', (int) $row->content_id)->first(['slug']);
                        $slug = trim((string) ($contentRow->slug ?? ''));
                    }

                    if ($slug === '') {
                        continue;
                    }

                    if ($canonicalPath !== '' && $canonicalPath !== '/support/'.$slug) {
                        continue;
                    }

                    $payload['canonical_path'] = '/support/articles/'.$slug;

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
