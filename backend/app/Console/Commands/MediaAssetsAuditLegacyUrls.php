<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Console\Command;

final class MediaAssetsAuditLegacyUrls extends Command
{
    protected $signature = 'media-assets:audit-legacy-urls
        {--fail-on-findings : Return a non-zero exit code when legacy URLs are found}';

    protected $description = 'Dry-run audit for legacy Tencent/COS URLs in Media Library records.';

    public function handle(): int
    {
        $assetFindings = MediaAsset::query()
            ->withoutGlobalScopes()
            ->whereNotNull('url')
            ->get(['id', 'org_id', 'asset_key', 'url'])
            ->filter(fn (MediaAsset $asset): bool => PublicMediaUrlGuard::isBlockedUrl((string) $asset->url))
            ->values();

        $variantFindings = MediaVariant::query()
            ->whereNotNull('url')
            ->get(['id', 'media_asset_id', 'variant_key', 'url'])
            ->filter(fn (MediaVariant $variant): bool => PublicMediaUrlGuard::isBlockedUrl((string) $variant->url))
            ->values();

        $total = $assetFindings->count() + $variantFindings->count();

        $this->line('dry_run=1');
        $this->line('media_asset_legacy_url_count='.$assetFindings->count());
        $this->line('media_variant_legacy_url_count='.$variantFindings->count());
        $this->line('legacy_url_count='.$total);

        foreach ($assetFindings->take(20) as $asset) {
            $this->line(sprintf(
                'media_asset id=%s org_id=%s asset_key=%s url=%s',
                (string) $asset->id,
                (string) $asset->org_id,
                (string) $asset->asset_key,
                (string) $asset->url
            ));
        }

        foreach ($variantFindings->take(20) as $variant) {
            $this->line(sprintf(
                'media_variant id=%s media_asset_id=%s variant_key=%s url=%s',
                (string) $variant->id,
                (string) $variant->media_asset_id,
                (string) $variant->variant_key,
                (string) $variant->url
            ));
        }

        if ($total > 0) {
            $this->warn('legacy media URLs found; run a reviewed cleanup migration before treating Phase 5 data cleanup as complete.');

            return (bool) $this->option('fail-on-findings') ? 1 : 0;
        }

        $this->info('no legacy media URLs found');

        return 0;
    }
}
