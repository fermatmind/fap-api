<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MediaAssetsImportLocalBaseline extends Command
{
    protected $signature = 'media-assets:import-local-baseline
        {--dry-run : Validate and diff without writing to the database}
        {--upsert : Update existing records instead of create-missing only}
        {--status=published : Force imported records to draft or published}
        {--source-dir= : Override the committed baseline source directory}';

    protected $description = 'Import committed media asset and variant baselines into Media Library tables.';

    public function handle(): int
    {
        try {
            $dryRun = (bool) $this->option('dry-run');
            $upsert = (bool) $this->option('upsert');
            $status = trim((string) $this->option('status'));
            if (! in_array($status, [MediaAsset::STATUS_DRAFT, MediaAsset::STATUS_PUBLISHED], true)) {
                throw new RuntimeException('Unsupported --status value: '.$status);
            }

            $sourceDir = $this->resolveSourceDir((string) ($this->option('source-dir') ?? ''));
            $files = glob($sourceDir.'/*.json') ?: [];
            sort($files);

            $assets = [];
            foreach ($files as $file) {
                $decoded = json_decode((string) file_get_contents($file), true);
                if (! is_array($decoded)) {
                    throw new RuntimeException('Invalid media baseline JSON: '.$file);
                }

                $rows = array_is_list($decoded) ? $decoded : [$decoded];
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        $assets[] = $this->normalizeAsset($row, $status);
                    }
                }
            }

            $summary = [
                'files_found' => count($files),
                'assets_found' => count($assets),
                'will_create' => 0,
                'will_update' => 0,
                'will_skip' => 0,
            ];

            foreach ($assets as $asset) {
                $existing = MediaAsset::query()
                    ->withoutGlobalScopes()
                    ->where('org_id', $asset['record']['org_id'])
                    ->where('asset_key', $asset['record']['asset_key'])
                    ->first();

                if (! $existing instanceof MediaAsset) {
                    $summary['will_create']++;
                    if (! $dryRun) {
                        DB::transaction(function () use ($asset): void {
                            $created = MediaAsset::query()->withoutGlobalScopes()->create($asset['record']);
                            $this->replaceVariants($created, $asset['variants']);
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
                    DB::transaction(function () use ($existing, $asset): void {
                        $existing->fill($asset['record']);
                        $existing->save();
                        $this->replaceVariants($existing, $asset['variants']);
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
            : base_path('../content_baselines/media_assets');

        $real = realpath($candidate);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('Media baseline source directory not found: '.$candidate);
        }

        return $real;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array{record: array<string,mixed>, variants: list<array<string,mixed>>}
     */
    private function normalizeAsset(array $row, string $status): array
    {
        $assetKey = strtolower(trim((string) ($row['assetKey'] ?? $row['asset_key'] ?? '')));
        if ($assetKey === '') {
            throw new RuntimeException('Media baseline row is missing asset_key.');
        }

        $variants = [];
        foreach (($row['variants'] ?? []) as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $variantKey = strtolower(trim((string) ($variant['variantKey'] ?? $variant['variant_key'] ?? '')));
            if ($variantKey === '') {
                throw new RuntimeException('Media baseline variant is missing variant_key: '.$assetKey);
            }
            $variants[] = [
                'variant_key' => $variantKey,
                'path' => $this->nullableString($variant['path'] ?? null),
                'url' => $this->nullableString($variant['url'] ?? null),
                'mime_type' => $this->nullableString($variant['mimeType'] ?? $variant['mime_type'] ?? null),
                'width' => $variant['width'] ?? null,
                'height' => $variant['height'] ?? null,
                'bytes' => $variant['bytes'] ?? null,
                'payload_json' => is_array($variant['payloadJson'] ?? $variant['payload_json'] ?? null)
                    ? ($variant['payloadJson'] ?? $variant['payload_json'])
                    : [],
            ];
        }

        return [
            'record' => [
                'org_id' => (int) ($row['orgId'] ?? $row['org_id'] ?? 0),
                'asset_key' => $assetKey,
                'disk' => $this->nullableString($row['disk'] ?? null) ?? 'public_static',
                'path' => $this->nullableString($row['path'] ?? null),
                'url' => $this->nullableString($row['url'] ?? null),
                'mime_type' => $this->nullableString($row['mimeType'] ?? $row['mime_type'] ?? null),
                'width' => $row['width'] ?? null,
                'height' => $row['height'] ?? null,
                'bytes' => $row['bytes'] ?? null,
                'alt' => $this->nullableString($row['alt'] ?? null),
                'caption' => $this->nullableString($row['caption'] ?? null),
                'credit' => $this->nullableString($row['credit'] ?? null),
                'status' => $status,
                'is_public' => (bool) ($row['isPublic'] ?? $row['is_public'] ?? true),
                'payload_json' => is_array($row['payloadJson'] ?? $row['payload_json'] ?? null)
                    ? ($row['payloadJson'] ?? $row['payload_json'])
                    : [],
            ],
            'variants' => $variants,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $variants
     */
    private function replaceVariants(MediaAsset $asset, array $variants): void
    {
        $asset->variants()->delete();
        foreach ($variants as $variant) {
            $asset->variants()->create($variant);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
