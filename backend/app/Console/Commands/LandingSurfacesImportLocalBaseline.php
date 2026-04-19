<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LandingSurface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LandingSurfacesImportLocalBaseline extends Command
{
    protected $signature = 'landing-surfaces:import-local-baseline
        {--dry-run : Validate and diff without writing to the database}
        {--upsert : Update existing records instead of create-missing only}
        {--status=published : Force imported records to draft or published}
        {--source-dir= : Override the committed baseline source directory}';

    protected $description = 'Import committed landing surface and page block baselines into CMS tables.';

    public function handle(): int
    {
        try {
            $dryRun = (bool) $this->option('dry-run');
            $upsert = (bool) $this->option('upsert');
            $status = trim((string) $this->option('status'));
            if (! in_array($status, [LandingSurface::STATUS_DRAFT, LandingSurface::STATUS_PUBLISHED], true)) {
                throw new RuntimeException('Unsupported --status value: '.$status);
            }

            $sourceDir = $this->resolveSourceDir((string) ($this->option('source-dir') ?? ''));
            $files = glob($sourceDir.'/*.json') ?: [];
            sort($files);

            $surfaces = [];
            foreach ($files as $file) {
                $decoded = json_decode((string) file_get_contents($file), true);
                if (! is_array($decoded)) {
                    throw new RuntimeException('Invalid landing surface baseline JSON: '.$file);
                }

                $rows = array_is_list($decoded) ? $decoded : [$decoded];
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        $surfaces[] = $this->normalizeSurface($row, $status);
                    }
                }
            }

            $summary = [
                'files_found' => count($files),
                'surfaces_found' => count($surfaces),
                'will_create' => 0,
                'will_update' => 0,
                'will_skip' => 0,
            ];

            foreach ($surfaces as $surface) {
                $existing = LandingSurface::query()
                    ->withoutGlobalScopes()
                    ->where('org_id', $surface['record']['org_id'])
                    ->where('surface_key', $surface['record']['surface_key'])
                    ->where('locale', $surface['record']['locale'])
                    ->first();

                if (! $existing instanceof LandingSurface) {
                    $summary['will_create']++;
                    if (! $dryRun) {
                        DB::transaction(function () use ($surface): void {
                            $created = LandingSurface::query()->withoutGlobalScopes()->create($surface['record']);
                            $this->replaceBlocks($created, $surface['blocks']);
                        });
                    }

                    continue;
                }

                if (! $upsert) {
                    $summary['will_skip']++;

                    continue;
                }

                $summary['will_update']++;
                if (! $dryRun) {
                    DB::transaction(function () use ($existing, $surface): void {
                        $existing->fill($surface['record']);
                        $existing->save();
                        $this->replaceBlocks($existing, $surface['blocks']);
                    });
                }
            }

            $this->line('baseline_source_dir='.$sourceDir);
            $this->line('dry_run='.($dryRun ? '1' : '0'));
            $this->line('upsert='.($upsert ? '1' : '0'));
            $this->line('status_mode='.$status);
            foreach ($summary as $key => $value) {
                $this->line($key.'='.(string) $value);
            }
            $this->info($dryRun ? 'dry-run complete' : 'import complete');

            return 0;
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());

            return 1;
        }
    }

    private function resolveSourceDir(string $override): string
    {
        $candidate = trim($override) !== ''
            ? base_path(trim($override))
            : base_path('../content_baselines/landing_surfaces');

        $real = realpath($candidate);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('Landing surface baseline source directory not found: '.$candidate);
        }

        return $real;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array{record: array<string,mixed>, blocks: list<array<string,mixed>>}
     */
    private function normalizeSurface(array $row, string $status): array
    {
        $surfaceKey = strtolower(trim((string) ($row['surfaceKey'] ?? $row['surface_key'] ?? '')));
        $locale = $this->normalizeLocale((string) ($row['locale'] ?? 'en'));
        if ($surfaceKey === '') {
            throw new RuntimeException('Landing surface baseline row is missing surface_key.');
        }

        $blocks = [];
        foreach (($row['pageBlocks'] ?? $row['page_blocks'] ?? []) as $index => $block) {
            if (! is_array($block)) {
                continue;
            }
            $blockKey = strtolower(trim((string) ($block['blockKey'] ?? $block['block_key'] ?? '')));
            if ($blockKey === '') {
                throw new RuntimeException('Landing surface baseline block is missing block_key: '.$surfaceKey);
            }
            $blocks[] = [
                'block_key' => $blockKey,
                'block_type' => $this->nullableString($block['blockType'] ?? $block['block_type'] ?? null) ?? 'json',
                'title' => $this->nullableString($block['title'] ?? null),
                'payload_json' => is_array($block['payloadJson'] ?? $block['payload_json'] ?? null)
                    ? ($block['payloadJson'] ?? $block['payload_json'])
                    : [],
                'sort_order' => (int) ($block['sortOrder'] ?? $block['sort_order'] ?? $index),
                'is_enabled' => (bool) ($block['isEnabled'] ?? $block['is_enabled'] ?? true),
            ];
        }

        return [
            'record' => [
                'org_id' => (int) ($row['orgId'] ?? $row['org_id'] ?? 0),
                'surface_key' => $surfaceKey,
                'locale' => $locale,
                'title' => $this->nullableString($row['title'] ?? null),
                'description' => $this->nullableString($row['description'] ?? null),
                'schema_version' => $this->nullableString($row['schemaVersion'] ?? $row['schema_version'] ?? null) ?? 'v1',
                'payload_json' => is_array($row['payloadJson'] ?? $row['payload_json'] ?? null)
                    ? ($row['payloadJson'] ?? $row['payload_json'])
                    : [],
                'status' => $status,
                'is_public' => (bool) ($row['isPublic'] ?? $row['is_public'] ?? true),
                'is_indexable' => (bool) ($row['isIndexable'] ?? $row['is_indexable'] ?? true),
                'published_at' => $this->nullableString($row['publishedAt'] ?? $row['published_at'] ?? null),
                'scheduled_at' => $this->nullableString($row['scheduledAt'] ?? $row['scheduled_at'] ?? null),
            ],
            'blocks' => $blocks,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $blocks
     */
    private function replaceBlocks(LandingSurface $surface, array $blocks): void
    {
        $surface->blocks()->delete();
        foreach ($blocks as $block) {
            $surface->blocks()->create($block);
        }
    }

    private function normalizeLocale(string $locale): string
    {
        $normalized = strtolower(str_replace('_', '-', trim($locale)));

        return str_starts_with($normalized, 'zh') ? 'zh-CN' : 'en';
    }

    private function nullableString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
